<?php

declare(strict_types=1);

namespace ZoneMirror\Application;

use ZoneMirror\Domain\DnsDiff;
use ZoneMirror\Domain\DnsDiffEntry;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Cloudflare\EmailAuthClassifier;
use ZoneMirror\Infrastructure\Cloudflare\TxtContentNormalizer;
use ZoneMirror\Infrastructure\Cpanel\BindZoneParser;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Mapping\EmailDnsNormalizer;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;

/**
 * Compute the per-record diff between /var/named/<zone>.db and the
 * matching Cloudflare zone. This is the data the cPanel-user review
 * step renders so the user can decide, per row, whether to push, skip,
 * or delete on Cloudflare.
 *
 * Pairing rule: records are first bucketed by (type, lowercased name)
 * plus a type-specific discriminator that pre-splits the obvious
 * multi-row owners:
 *   - MX:  match on (type, name, target) — multiple MX with different
 *          targets are real and independent.
 *   - SRV: match on (type, name, target, port) — many services use the
 *          same _service._proto.host owner.
 *   - CAA: match on (type, name, tag, value).
 *   - everything else (A/AAAA/CNAME/TXT): match on (type, name).
 *
 * The A/AAAA/TXT bucket can legitimately hold more than one record
 * (round-robin A, SPF + DKIM + service verification TXTs at the apex,
 * etc.) and Cloudflare in particular often accumulates duplicates over
 * a zone's lifetime. {@see pairBucket()} therefore matches local and
 * remote records inside a bucket greedily — exact-content first, then
 * leftovers as Update / Create / Delete entries — instead of assuming a
 * single record per bucket and silently dropping the extras.
 *
 * Result is purely advisory: nothing is enqueued or applied here. The
 * UI is the only place that knows what the user wants to do with each
 * row.
 */
final class ComputeDiff
{
    private const TYPES_TO_COMPARE = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];

    public function __construct(
        private readonly BindZoneParser $parser = new BindZoneParser(),
        private readonly EmailDnsNormalizer $normalizer = new EmailDnsNormalizer(),
        private readonly SystemConfigStorage $systemConfig = new SystemConfigStorage(),
        private readonly TxtContentNormalizer $txt = new TxtContentNormalizer(),
        private readonly EmailAuthClassifier $emailAuth = new EmailAuthClassifier(),
    ) {
    }

    /**
     * @throws \RuntimeException if local or remote can't be read.
     */
    public function compute(
        string $zoneName,
        string $zoneId,
        string $cloudflareToken,
        FileLogger $log,
        ?string $zoneFilePath = null,
    ): DnsDiff {
        $path = $zoneFilePath ?? '/var/named/' . $zoneName . '.db';
        if (!is_readable($path)) {
            throw new \RuntimeException('Zone file not readable: ' . $path);
        }
        $contents = (string) file_get_contents($path);
        $localRecords = $this->parser->parse($contents, $zoneName);

        $systemCfg = $this->systemConfig->load();

        // Apply the WHM-admin email DNS policy (DMARC override, SPF extras)
        // BEFORE the comparison runs, so the diff table — and any later
        // apply — uses the normalised payload as the cPanel side. Without
        // this, the user would see "Replace" rows where local and remote
        // are actually going to end up identical once we push.
        $policy = $systemCfg['email_normalization'] ?? [];
        if (is_array($policy)) {
            $localRecords = array_map(
                fn (DnsRecord $r): DnsRecord => $this->normalizer->normalize($r, $zoneName, $policy),
                $localRecords,
            );
            // Synthesize the central DMARC template for zones that have no
            // _dmarc of their own. Otherwise ZoneMirror's own managed DMARC
            // (already on Cloudflare) looks orphaned and the diff offers to
            // DELETE it — the opposite of what the template is for.
            $localRecords = $this->normalizer->ensureDmarc($localRecords, $zoneName, $policy);
        }

        // When the WHM admin opts in to "Auto TTL" (default), rewrite the
        // parsed cPanel TTLs to 1 ("Auto") before comparing. cPanel zone
        // files default to 14400 which propagates as noise into the diff
        // and ultimately into Cloudflare unless we collapse it here.
        // Doing it at compute-time also means the persisted diff entries
        // carry ttl=1, so the later apply pushes that value unchanged.
        $autoTtl = (bool) ($systemCfg['defaults']['auto_ttl'] ?? true);
        if ($autoTtl) {
            $localRecords = array_map(
                static fn (DnsRecord $r): DnsRecord => new DnsRecord(
                    type: $r->type,
                    name: $r->name,
                    content: $r->content,
                    ttl: 1,
                    priority: $r->priority,
                    proxied: $r->proxied,
                    data: $r->data,
                ),
                $localRecords,
            );
        }

        $client = new CloudflareApiClient($cloudflareToken);
        $remoteRecords = $client->listRecords($zoneId);
        // Cloudflare's NS/SOA are authoritative and we never want to mirror
        // them in either direction; drop them up front so they don't show
        // up as cloudflare_only rows.
        $remoteRecords = array_values(array_filter(
            $remoteRecords,
            static fn (array $r): bool => in_array(
                strtoupper((string) ($r['type'] ?? '')),
                self::TYPES_TO_COMPARE,
                true,
            ),
        ));

        $log->info('diff: source counts', [
            'zone' => $zoneName,
            'local' => count($localRecords),
            'remote' => count($remoteRecords),
        ]);

        // Group both sides by pairing key. We use lists (not single
        // values) because Cloudflare allows multiple records under the
        // same (type, name) for rrtypes the pairing key doesn't already
        // discriminate (TXT/A/AAAA, and any pathological CNAME). The
        // previous overwrite-on-collision code silently dropped one of
        // them and the UI then looped forever: Apply would update the
        // visible row, the next diff would surface the still-untouched
        // duplicate as "different", the user would Apply again, etc.
        /** @var array<string, list<DnsRecord>> $localByKey */
        $localByKey = [];
        foreach ($localRecords as $r) {
            $localByKey[$this->keyForLocal($r)][] = $r;
        }
        /** @var array<string, list<array<string, mixed>>> $remoteByKey */
        $remoteByKey = [];
        foreach ($remoteRecords as $r) {
            $remoteByKey[$this->keyForRemote($r)][] = $r;
        }

        $entries = [];
        $allKeys = array_keys($localByKey + $remoteByKey);
        foreach ($allKeys as $key) {
            $locals  = $localByKey[$key]  ?? [];
            $remotes = $remoteByKey[$key] ?? [];
            $entries = array_merge($entries, $this->pairBucket($key, $locals, $remotes));
        }

        usort($entries, static function (DnsDiffEntry $a, DnsDiffEntry $b): int {
            // Surface what the user has to act on first: differences,
            // missing in CF, then missing in local, then identical.
            $order = [
                DnsDiff::STATUS_DIFFERENT => 0,
                DnsDiff::STATUS_CPANEL_ONLY => 1,
                DnsDiff::STATUS_CLOUDFLARE_ONLY => 2,
                DnsDiff::STATUS_IDENTICAL => 3,
            ];

            $statusCmp = $order[$a->status] <=> $order[$b->status];
            if ($statusCmp !== 0) {
                return $statusCmp;
            }
            $typeCmp = $a->type <=> $b->type;
            if ($typeCmp !== 0) {
                return $typeCmp;
            }

            return $a->name <=> $b->name;
        });

        return new DnsDiff(
            zoneName: $zoneName,
            zoneId: $zoneId,
            computedAt: time(),
            entries: $entries,
            advisories: $this->buildAdvisories($zoneName, $entries),
        );
    }

    /**
     * Zone-level notices for the review banner. Fires when email-auth records
     * (SPF/DKIM/DMARC/MX/verification) live only in Cloudflare and not in the
     * cPanel zone — the tell-tale of a Cloudflare-delegated domain whose live
     * records were managed in the dashboard while the local /var/named copy
     * fell behind. The message is enriched when the domain's authoritative NS
     * are in fact Cloudflare.
     *
     * @param list<DnsDiffEntry> $entries
     * @return list<array{level: string, code: string, message: string}>
     */
    private function buildAdvisories(string $zoneName, array $entries): array
    {
        $protectedCfOnly = 0;
        foreach ($entries as $e) {
            if ($e->protected && $e->status === DnsDiff::STATUS_CLOUDFLARE_ONLY) {
                $protectedCfOnly++;
            }
        }
        if ($protectedCfOnly === 0) {
            return [];
        }

        $cf = $this->nsIsCloudflare($zoneName);
        $message = sprintf(
            '%d email-authentication record%s (SPF / DKIM / DMARC / MX / verification) '
            . 'exist only in Cloudflare, not in your cPanel zone%s. They are protected '
            . 'from bulk Delete and Update so a careless apply cannot break this domain\'s '
            . 'mail — review and tick each one individually if you really want to remove it. '
            . 'If Cloudflare is the source of truth here, import these records into cPanel '
            . 'rather than syncing cPanel → Cloudflare.',
            $protectedCfOnly,
            $protectedCfOnly === 1 ? '' : 's',
            $cf ? ', whose authoritative nameservers are Cloudflare' : '',
        );

        return [[
            'level' => 'warning',
            'code' => 'cf_managed_email_auth',
            'message' => $message,
        ]];
    }

    private function nsIsCloudflare(string $zoneName): bool
    {
        $zone = rtrim(strtolower(trim($zoneName)), '.');
        if ($zone === '') {
            return false;
        }
        $records = @dns_get_record($zone, DNS_NS);
        if (!is_array($records)) {
            return false;
        }
        foreach ($records as $r) {
            if (str_contains(strtolower((string) ($r['target'] ?? '')), 'cloudflare')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build diff entries for a single (type, name[, discriminator])
     * bucket. The bucket can contain any combination of local and remote
     * records; we pair them greedily so the user sees the friendliest
     * narrative possible:
     *
     *  - 1 local ↔ 1 remote: one entry, "identical" or "different". The
     *    historical case — keeps the diff readable for vanilla zones.
     *  - N ↔ M with N=M: each local pairs with its exact-content remote
     *    if one exists (identical), otherwise with the next free remote
     *    (different). This avoids surface-area churn for round-robin A
     *    records that just had one value tweaked.
     *  - asymmetric: leftovers become cpanel_only / cloudflare_only and
     *    the user picks the action per row.
     *
     * When more than one entry comes out of a bucket the entry keys are
     * disambiguated with a content hash suffix so the apply path can
     * round-trip each row independently (push_keys[] / delete_keys[]
     * carry the disambiguated key).
     *
     * @param list<DnsRecord>             $locals
     * @param list<array<string, mixed>>  $remotes
     * @return list<DnsDiffEntry>
     */
    private function pairBucket(string $key, array $locals, array $remotes): array
    {
        // Fast path for the common 1:1 case — keep the bare key so the
        // UI's "Update" affordance stays visually compact.
        if (count($locals) === 1 && count($remotes) === 1) {
            $local  = $locals[0];
            $remote = $remotes[0];
            $isMatch = $this->recordsMatch($local, $remote);
            $reason = $isMatch ? '' : $this->protectReason($local, $remote);

            return [new DnsDiffEntry(
                key: $key,
                status: $isMatch
                    ? DnsDiff::STATUS_IDENTICAL
                    : DnsDiff::STATUS_DIFFERENT,
                type: $local->type->value,
                name: $local->name,
                local: $local,
                remote: $remote,
                protected: $reason !== '',
                protectReason: $reason,
            )];
        }

        $multi = (count($locals) + count($remotes)) > 1;
        $out = [];

        // First pass: identical matches (content + ttl-irrelevant parity)
        // get consumed from both lists so they don't compete for the
        // "different" slot below.
        $remoteUsed = [];
        $localUsed  = [];
        foreach ($locals as $li => $local) {
            foreach ($remotes as $ri => $remote) {
                if (isset($remoteUsed[$ri])) {
                    continue;
                }
                if ($this->recordsMatch($local, $remote)) {
                    $out[] = new DnsDiffEntry(
                        key: $this->entryKey($key, $local, null, $multi),
                        status: DnsDiff::STATUS_IDENTICAL,
                        type: $local->type->value,
                        name: $local->name,
                        local: $local,
                        remote: $remote,
                    );
                    $remoteUsed[$ri] = true;
                    $localUsed[$li] = true;

                    break;
                }
            }
        }

        // Second pass: surviving locals pair with the next free remote as
        // "different", so the user sees an Update path rather than a
        // create+delete pair for the row. For TXT we additionally require
        // the remote to share the local's logical identity (SPF↔SPF,
        // DKIM↔DKIM, same verification token↔same token) — two unrelated
        // TXTs at one owner name (e.g. an SPF and a Google site-verification
        // at the apex) must NOT marry into a single Update that would
        // silently overwrite one with the other.
        foreach ($locals as $li => $local) {
            if (isset($localUsed[$li])) {
                continue;
            }
            $pairedRemote = null;
            foreach ($remotes as $ri => $remote) {
                if (isset($remoteUsed[$ri])) {
                    continue;
                }
                if ($local->type === RecordType::TXT && !$this->txtPairable($local, $remote)) {
                    continue;
                }
                $pairedRemote = $remote;
                $remoteUsed[$ri] = true;

                break;
            }
            if ($pairedRemote !== null) {
                $reason = $this->protectReason($local, $pairedRemote);
                $out[] = new DnsDiffEntry(
                    key: $this->entryKey($key, $local, $pairedRemote, $multi),
                    status: DnsDiff::STATUS_DIFFERENT,
                    type: $local->type->value,
                    name: $local->name,
                    local: $local,
                    remote: $pairedRemote,
                    protected: $reason !== '',
                    protectReason: $reason,
                );
            } else {
                $out[] = new DnsDiffEntry(
                    key: $this->entryKey($key, $local, null, $multi),
                    status: DnsDiff::STATUS_CPANEL_ONLY,
                    type: $local->type->value,
                    name: $local->name,
                    local: $local,
                    remote: null,
                );
            }
        }

        // Third pass: leftover remotes (the duplicates the old code used
        // to drop on the floor).
        foreach ($remotes as $ri => $remote) {
            if (isset($remoteUsed[$ri])) {
                continue;
            }
            $reason = $this->protectReason(null, $remote);
            $out[] = new DnsDiffEntry(
                key: $this->entryKey($key, null, $remote, $multi),
                status: DnsDiff::STATUS_CLOUDFLARE_ONLY,
                type: (string) ($remote['type'] ?? ''),
                name: (string) ($remote['name'] ?? ''),
                local: null,
                remote: $remote,
                protected: $reason !== '',
                protectReason: $reason,
            );
        }

        return $out;
    }

    /**
     * Stable, per-row entry key. For the 1:1 case the caller passes
     * $multi=false and we return the bucket key untouched — preserving
     * the historical key shape any persisted state may rely on. For
     * multi-row buckets we suffix a content fingerprint so each card has
     * a key the apply path can target unambiguously.
     *
     * The fingerprint deliberately includes the Cloudflare id when we
     * have it, so two CF rows with identical content (an unlikely but
     * possible state when a third party duplicated a record) still get
     * distinct keys.
     *
     * @param array<string, mixed>|null $remote
     */
    private function entryKey(string $bucketKey, ?DnsRecord $local, ?array $remote, bool $multi): string
    {
        if (!$multi) {
            return $bucketKey;
        }
        $seed = '';
        if ($local !== null) {
            $seed = ($local->content ?? '') . '|' . ($local->priority ?? '');
            if ($local->data !== []) {
                $seed .= '|' . (string) json_encode($local->data);
            }
        } elseif ($remote !== null) {
            $seed = (string) ($remote['content'] ?? '') . '|' . ($remote['priority'] ?? '');
            if (isset($remote['id'])) {
                $seed .= '|id:' . (string) $remote['id'];
            }
        }

        return $bucketKey . '#' . substr(hash('sha256', $seed), 0, 10);
    }

    private function keyForLocal(DnsRecord $r): string
    {
        return $this->key(
            $r->type->value,
            $r->name,
            $r->content,
            $r->priority,
            $r->data,
        );
    }

    /**
     * @param array<string, mixed> $r
     */
    private function keyForRemote(array $r): string
    {
        return $this->key(
            strtoupper((string) ($r['type'] ?? '')),
            (string) ($r['name'] ?? ''),
            isset($r['content']) ? (string) $r['content'] : null,
            isset($r['priority']) ? (int) $r['priority'] : null,
            is_array($r['data'] ?? null) ? $r['data'] : [],
        );
    }

    /**
     * The protection reason for a row (empty string = not protected). A row
     * is protected when either side is an email-authentication / verification
     * record, so the UI keeps it out of bulk Delete/Update. Used only for
     * `different` and `cloudflare_only` rows — creating a record is never
     * destructive, so cpanel_only rows are never protected.
     *
     * @param array<string, mixed>|null $remote
     */
    private function protectReason(?DnsRecord $local, ?array $remote): string
    {
        if ($local !== null) {
            $r = $this->emailAuth->protectReason($local->type->value, $local->name, $local->content);
            if ($r !== null) {
                return $r;
            }
        }
        if ($remote !== null) {
            $r = $this->emailAuth->protectReason(
                (string) ($remote['type'] ?? ''),
                (string) ($remote['name'] ?? ''),
                isset($remote['content']) ? (string) $remote['content'] : null,
            );
            if ($r !== null) {
                return $r;
            }
        }

        return '';
    }

    /**
     * Whether a leftover local TXT and a leftover remote TXT under the same
     * owner name are the same logical record (and so an Update candidate)
     * rather than two independent TXTs that happen to share a name.
     *
     * @param array<string, mixed> $remote
     */
    private function txtPairable(DnsRecord $local, array $remote): bool
    {
        return $this->txt->identity($local->content ?? '')
            === $this->txt->identity((string) ($remote['content'] ?? ''));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function key(string $type, string $name, ?string $content, ?int $priority, array $data): string
    {
        $name = strtolower(rtrim($name, '.'));
        $discriminator = match ($type) {
            'MX' => strtolower(rtrim((string) $content, '.')),
            'SRV' => strtolower((string) ($data['target'] ?? '')) . ':' . ((int) ($data['port'] ?? 0)),
            'CAA' => strtolower((string) ($data['tag'] ?? '')) . '=' . (string) ($data['value'] ?? ''),
            default => '',
        };

        return $type . ':' . $name . ($discriminator === '' ? '' : '|' . $discriminator);
    }

    /**
     * Content/priority/proxied/data parity. TTL is intentionally NOT part
     * of the equality check: Cloudflare uses ttl=1 ("Auto") for every
     * proxied record and lets the dashboard pick conservative defaults
     * for non-proxied ones, so the local 14400 from a stock cPanel zone
     * file would otherwise drown the diff in noise that the user can't
     * meaningfully act on. Proxied state IS compared because it changes
     * how traffic flows even when the IP didn't move.
     *
     * @param array<string, mixed> $remote
     */
    private function recordsMatch(DnsRecord $local, array $remote): bool
    {
        $localPayload = $local->toCloudflarePayload();

        // Content comparison for the simple rrtypes only. SRV/CAA carry
        // their meaningful data in the `data` array (Cloudflare also
        // exposes a synthesised `content` like "0 443 host." but it's
        // computed from `data` and would always look "different" from
        // our local null), so we skip the content check for them and
        // rely on the structured comparison below.
        $usesStructuredData = $local->type === RecordType::SRV || $local->type === RecordType::CAA;
        if (!$usesStructuredData) {
            $localContent = (string) ($localPayload['content'] ?? '');
            $remoteContent = (string) ($remote['content'] ?? '');

            if ($local->type === RecordType::TXT) {
                // Cloudflare returns TXT content quoted (and segmented for
                // long values); cPanel yields the bare concatenation. Fold
                // both to the same canonical form — otherwise every TXT
                // reads as different and unrelated apex TXTs get mis-paired.
                if (
                    $this->txt->canonicalForCompare($localContent)
                    !== $this->txt->canonicalForCompare($remoteContent)
                ) {
                    return false;
                }
            } elseif (($localPayload['content'] ?? null) !== ($remote['content'] ?? null)) {
                if (
                    $local->type === RecordType::CNAME
                    && strtolower($localContent) === strtolower($remoteContent)
                ) {
                    // fall through; content is effectively identical
                } else {
                    return false;
                }
            }
        }

        if ($local->type === RecordType::MX) {
            if ((int) ($localPayload['priority'] ?? 0) !== (int) ($remote['priority'] ?? 0)) {
                return false;
            }
        }

        if ($local->type->supportsProxy()) {
            $lp = $localPayload['proxied'] ?? false;
            $rp = $remote['proxied'] ?? false;
            if ((bool) $lp !== (bool) $rp) {
                return false;
            }
        }

        if ($local->type === RecordType::SRV || $local->type === RecordType::CAA) {
            $ld = $localPayload['data'] ?? [];
            $rd = $remote['data'] ?? [];
            if (!$this->dataMatches((array) $ld, (array) $rd, $local->type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $remote
     */
    private function dataMatches(array $local, array $remote, RecordType $type): bool
    {
        if ($type === RecordType::SRV) {
            $keys = ['priority', 'weight', 'port', 'target'];
        } else {
            // CAA
            $keys = ['flags', 'tag', 'value'];
        }
        foreach ($keys as $k) {
            $l = $local[$k] ?? null;
            $r = $remote[$k] ?? null;
            if (is_string($l) && is_string($r)) {
                $l = strtolower(rtrim($l, '.'));
                $r = strtolower(rtrim($r, '.'));
            }
            if ((string) $l !== (string) $r) {
                return false;
            }
        }

        return true;
    }
}
