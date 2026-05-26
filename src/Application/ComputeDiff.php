<?php

declare(strict_types=1);

namespace ZoneMirror\Application;

use ZoneMirror\Domain\DnsDiff;
use ZoneMirror\Domain\DnsDiffEntry;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
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
 * Pairing rule: records are matched by (type, lowercased name) plus a
 * type-specific discriminator that prevents distinct rows from
 * collapsing onto each other:
 *   - MX:  match on (type, name, target) — multiple MX with different
 *          targets are real and independent.
 *   - SRV: match on (type, name, target, port) — many services use the
 *          same _service._proto.host owner.
 *   - CAA: match on (type, name, tag, value).
 *   - everything else (A/AAAA/CNAME/TXT): match on (type, name). cPanel
 *     and Cloudflare both allow only one such record per (type, name)
 *     pair in practice.
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

        // Index by pairing key so we can do an O(n+m) merge.
        $localByKey = [];
        foreach ($localRecords as $r) {
            $key = $this->keyForLocal($r);
            $localByKey[$key] = $r;
        }
        $remoteByKey = [];
        foreach ($remoteRecords as $r) {
            $key = $this->keyForRemote($r);
            $remoteByKey[$key] = $r;
        }

        $entries = [];
        foreach ($localByKey as $key => $local) {
            if (isset($remoteByKey[$key])) {
                $remote = $remoteByKey[$key];
                $entries[] = new DnsDiffEntry(
                    key: $key,
                    status: $this->recordsMatch($local, $remote)
                        ? DnsDiff::STATUS_IDENTICAL
                        : DnsDiff::STATUS_DIFFERENT,
                    type: $local->type->value,
                    name: $local->name,
                    local: $local,
                    remote: $remote,
                );
                unset($remoteByKey[$key]);
            } else {
                $entries[] = new DnsDiffEntry(
                    key: $key,
                    status: DnsDiff::STATUS_CPANEL_ONLY,
                    type: $local->type->value,
                    name: $local->name,
                    local: $local,
                    remote: null,
                );
            }
        }
        foreach ($remoteByKey as $key => $remote) {
            $entries[] = new DnsDiffEntry(
                key: $key,
                status: DnsDiff::STATUS_CLOUDFLARE_ONLY,
                type: (string) $remote['type'],
                name: (string) $remote['name'],
                local: null,
                remote: $remote,
            );
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
        );
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
            if (($localPayload['content'] ?? null) !== ($remote['content'] ?? null)) {
                if (
                    $local->type === RecordType::CNAME
                    && strtolower((string) ($localPayload['content'] ?? '')) ===
                        strtolower((string) ($remote['content'] ?? ''))
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
