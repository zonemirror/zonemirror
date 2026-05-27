<?php

declare(strict_types=1);

namespace ZoneMirror\Application;

use ZoneMirror\Infrastructure\Cpanel\BindZoneWriter;
use ZoneMirror\Infrastructure\Storage\LocalRewriteState;
use ZoneMirror\Infrastructure\Storage\LockStorage;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;

/**
 * Orchestrates the "local DMARC rewrite" feature: scan /var/named for
 * zones, decide which ones the server policy applies to, and either
 * report the plan (preview / dry-run) or execute it.
 *
 * Designed as one entry point with two modes so the CLI, the WHM UI
 * "Preview" / "Apply" buttons, the on_email_auth hook, and the future
 * daemon tick all share identical gate logic. The state-mutating apply
 * path is the only one that touches /var/named or LocalRewriteState.
 */
final class ApplyLocalDmarc
{
    /**
     * Reason codes returned in plan entries. Stable so callers can match
     * on them and we can render translated labels later.
     */
    public const REASON_FEATURE_DISABLED = 'feature_disabled';
    public const REASON_NO_TEMPLATE      = 'no_template';
    public const REASON_NO_OWNER         = 'no_owner';
    public const REASON_USER_NOT_ALLOWED = 'user_not_allowed';
    public const REASON_EXCLUDED_ZONE    = 'excluded_zone';
    public const REASON_HAS_CUSTOM_DMARC = 'has_custom_dmarc';
    public const REASON_LOCKED           = 'locked';
    public const REASON_CUSTOM_RUA       = 'has_custom_rua_ruf';
    public const REASON_ALREADY          = 'already_matches_template';
    public const REASON_NO_DMARC_RECORD  = 'no_dmarc_record_in_zone';
    public const REASON_WOULD_APPLY      = 'would_apply';
    public const REASON_APPLIED          = 'applied';
    public const REASON_APPLY_ERROR      = 'apply_error';

    public function __construct(
        private readonly SystemConfigStorage $systemConfig = new SystemConfigStorage(),
        private readonly LockStorage $lockStorage = new LockStorage(),
        private readonly BindZoneWriter $writer = new BindZoneWriter(),
        private readonly LocalRewriteState $state = new LocalRewriteState(),
    ) {
    }

    /**
     * Compute the plan for every zone the server knows about. Pure read;
     * no /var/named writes, no state mutation. Caller chooses what to do
     * with the rows.
     *
     * @return array{template: string, summary: array<string, int>, zones: list<array<string, mixed>>}
     */
    public function preview(): array
    {
        return $this->plan(apply: false, dryRun: true);
    }

    /**
     * Execute the plan: rewrite every zone whose row resolves to
     * "would_apply", record what was done in LocalRewriteState, return
     * a per-zone outcome table.
     *
     * @return array{template: string, summary: array<string, int>, zones: list<array<string, mixed>>}
     */
    public function apply(string $appliedBy = 'cli'): array
    {
        $plan = $this->plan(apply: false, dryRun: false);
        $zones = [];
        $applied = $skipped = $error = 0;

        foreach ($plan['zones'] as $row) {
            if ($row['reason'] !== self::REASON_WOULD_APPLY) {
                $skipped++;
                $zones[] = $row + ['error' => null, 'reload_method' => ''];

                continue;
            }
            $outcome = $this->writer->applyToZone($row['zone'], $plan['template']);
            if ($outcome['error'] !== null && $outcome['changes'] === []) {
                $error++;
                $zones[] = array_merge($row, [
                    'reason'        => self::REASON_APPLY_ERROR,
                    'error'         => $outcome['error'],
                    'reload_method' => $outcome['reload_method'],
                ]);

                continue;
            }
            foreach ($outcome['changes'] as $change) {
                $this->state->record(
                    zone: $row['zone'],
                    ownerName: $change['owner'],
                    previousContent: $change['previous'],
                    appliedContent: $change['applied'],
                    appliedBy: $appliedBy,
                );
            }
            $applied++;
            $zones[] = array_merge($row, [
                'reason'        => self::REASON_APPLIED,
                'error'         => null,
                'reload_method' => $outcome['reload_method'],
            ]);
        }

        return [
            'template' => $plan['template'],
            'summary'  => [
                'applied' => $applied,
                'skipped' => $skipped,
                'error'   => $error,
                'total'   => count($plan['zones']),
            ],
            'zones' => $zones,
        ];
    }

    /**
     * Revert every rewrite the plugin has recorded. Each record is
     * restored to its `previous_content`. Returns a per-record outcome.
     *
     * @return array{summary: array{reverted: int, error: int, total: int}, records: list<array{zone: string, owner: string, previous: string, error: ?string, reload_method: string}>}
     */
    public function revert(): array
    {
        $records = [];
        $reverted = $error = 0;
        foreach ($this->state->all() as $zone => $byOwner) {
            foreach ($byOwner as $owner => $row) {
                $result = $this->writer->revertSingle($zone, $owner, $row['previous_content']);
                if ($result['ok']) {
                    $this->state->forget($zone, $owner);
                    $reverted++;
                    $records[] = [
                        'zone'          => $zone,
                        'owner'         => $owner,
                        'previous'      => $row['previous_content'],
                        'error'         => $result['error'],
                        'reload_method' => $result['reload_method'],
                    ];
                } else {
                    $error++;
                    $records[] = [
                        'zone'          => $zone,
                        'owner'         => $owner,
                        'previous'      => $row['previous_content'],
                        'error'         => $result['error'],
                        'reload_method' => $result['reload_method'],
                    ];
                }
            }
        }

        return [
            'summary' => ['reverted' => $reverted, 'error' => $error, 'total' => count($records)],
            'records' => $records,
        ];
    }

    /**
     * @return array{template: string, summary: array<string, int>, zones: list<array<string, mixed>>}
     */
    private function plan(bool $apply, bool $dryRun): array
    {
        $cfg = $this->systemConfig->load();
        $template = trim((string) ($cfg['email_normalization']['dmarc_template'] ?? ''));
        $local = $cfg['local_rewrite'];

        if ($template === '') {
            return [
                'template' => '',
                'summary'  => ['would_apply' => 0, 'skipped' => 0, 'error' => 0, 'total' => 0],
                'zones'    => [['zone' => '*', 'owner' => null, 'current' => null, 'reason' => self::REASON_NO_TEMPLATE, 'target' => '', 'record_owner' => null, 'all_records' => []]],
            ];
        }
        if (!$local['enabled']) {
            return [
                'template' => $template,
                'summary'  => ['would_apply' => 0, 'skipped' => 0, 'error' => 0, 'total' => 0],
                'zones'    => [['zone' => '*', 'owner' => null, 'current' => null, 'reason' => self::REASON_FEATURE_DISABLED, 'target' => $template, 'record_owner' => null, 'all_records' => []]],
            ];
        }

        $zones = $this->discoverZones();
        $userDomains = $this->loadUserDomains();
        $rows = [];
        $wouldApply = $skipped = 0;

        foreach ($zones as $zone) {
            $owner = $userDomains[$zone] ?? null;
            $rowBase = [
                'zone'         => $zone,
                'owner'        => $owner,
                'current'      => null,
                'target'       => $template,
                'record_owner' => null,
                'all_records'  => [],
            ];

            // Exclusion list takes precedence over everything else so the
            // admin can rescue a zone with a single line in system.json
            // even if the user's locks or has_custom_dmarc flag would
            // otherwise also fire — making the skip reason unambiguous.
            if (in_array($zone, $local['exclude_zones'], true)) {
                $rows[] = $rowBase + ['reason' => self::REASON_EXCLUDED_ZONE];
                $skipped++;

                continue;
            }
            if ($local['respect_has_custom_dmarc'] && $this->hasCustomDmarcFlag($zone)) {
                $rows[] = $rowBase + ['reason' => self::REASON_HAS_CUSTOM_DMARC];
                $skipped++;

                continue;
            }

            $path = Paths::bindZoneFile($zone);
            $contents = @file_get_contents($path);
            if ($contents === false) {
                $rows[] = $rowBase + ['reason' => self::REASON_NO_DMARC_RECORD];
                $skipped++;

                continue;
            }
            $dmarcRecords = $this->writer->findDmarcRecords($contents);
            $rowBase['all_records'] = array_map(
                static fn (array $r): array => ['owner' => $r['owner'], 'previous' => $r['previous']],
                $dmarcRecords,
            );
            if ($dmarcRecords === []) {
                $rows[] = $rowBase + ['reason' => self::REASON_NO_DMARC_RECORD];
                $skipped++;

                continue;
            }

            // Per-record gate evaluation: a zone goes "would_apply" if
            // ANY of its _dmarc records would change. Each record is
            // judged independently so a zone with `_dmarc` (already
            // matching) and `_dmarc.agent` (still placeholder) gets
            // marked would_apply on the second one.
            $reason = $this->evaluateRecords(
                zone: $zone,
                owner: $owner,
                template: $template,
                local: $local,
                records: $dmarcRecords,
            );
            $rows[] = array_merge($rowBase, [
                'reason'       => $reason['reason'],
                'current'      => $reason['current'],
                'record_owner' => $reason['record_owner'],
            ]);
            if ($reason['reason'] === self::REASON_WOULD_APPLY) {
                $wouldApply++;
            } else {
                $skipped++;
            }
        }

        return [
            'template' => $template,
            'summary'  => [
                'would_apply' => $wouldApply,
                'skipped'     => $skipped,
                'error'       => 0,
                'total'       => count($rows),
            ],
            'zones' => $rows,
        ];
    }

    /**
     * @param list<array{owner: string, previous: string, lineno: int}> $records
     * @param array{enabled: bool, exclude_zones: list<string>, overwrite_custom_rua: bool, respect_has_custom_dmarc: bool, respect_user_locks: bool} $local
     * @return array{reason: string, current: ?string, record_owner: ?string}
     */
    private function evaluateRecords(string $zone, ?string $owner, string $template, array $local, array $records): array
    {
        $firstCustom = null;
        $firstAlready = null;
        $firstLocked = null;
        foreach ($records as $rec) {
            if ($rec['previous'] === $template) {
                $firstAlready = $firstAlready ?? $rec;

                continue;
            }
            if ($local['respect_user_locks'] && $owner !== null && $this->isLocked($owner, $zone, $rec)) {
                $firstLocked = $firstLocked ?? $rec;

                continue;
            }
            if (!$local['overwrite_custom_rua'] && $this->hasCustomRua($rec['previous'])) {
                $firstCustom = $firstCustom ?? $rec;

                continue;
            }

            return [
                'reason'       => self::REASON_WOULD_APPLY,
                'current'      => $rec['previous'],
                'record_owner' => $rec['owner'],
            ];
        }
        // No record passed every gate. Surface the most informative skip
        // reason: locked beats custom-rua beats already-matches (so the
        // operator sees "locked" rather than the less actionable "already").
        if ($firstLocked !== null) {
            return ['reason' => self::REASON_LOCKED, 'current' => $firstLocked['previous'], 'record_owner' => $firstLocked['owner']];
        }
        if ($firstCustom !== null) {
            return ['reason' => self::REASON_CUSTOM_RUA, 'current' => $firstCustom['previous'], 'record_owner' => $firstCustom['owner']];
        }
        if ($firstAlready !== null) {
            return ['reason' => self::REASON_ALREADY, 'current' => $firstAlready['previous'], 'record_owner' => $firstAlready['owner']];
        }

        return ['reason' => self::REASON_NO_DMARC_RECORD, 'current' => null, 'record_owner' => null];
    }

    /**
     * @return list<string>
     */
    private function discoverZones(): array
    {
        $dir = Paths::bindDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.db');
        if ($files === false || $files === []) {
            return [];
        }
        $zones = [];
        foreach ($files as $f) {
            $base = basename($f, '.db');
            // /var/named/<dom>.db.bak / .save / .last get a glob hit too —
            // .db only catches the actual zone files but be defensive.
            if ($base === '' || str_starts_with($base, '.')) {
                continue;
            }
            $zones[] = strtolower($base);
        }
        sort($zones);

        return $zones;
    }

    /**
     * Build a "domain → owner" map from /etc/userdomains (cPanel's
     * canonical mapping). Lines look like:
     *   example.com: alice
     * Returns an empty map if the file isn't readable (tests, or hosts
     * without cPanel) — the plan still proceeds, just without owner
     * resolution; user-lock checks degrade safely to "no lock".
     *
     * @return array<string, string>
     */
    private function loadUserDomains(): array
    {
        $override = getenv('ZONEMIRROR_USERDOMAINS_FILE');
        $path = is_string($override) && $override !== '' ? $override : '/etc/userdomains';
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return [];
        }
        $map = [];
        $lines = preg_split('/\R/', $raw);
        if ($lines === false) {
            return [];
        }
        foreach ($lines as $line) {
            if (preg_match('/^([^:#\s][^:]*):\s*(\S+)\s*$/', $line, $m) !== 1) {
                continue;
            }
            $map[strtolower($m[1])] = $m[2];
        }

        return $map;
    }

    private function hasCustomDmarcFlag(string $zone): bool
    {
        $override = getenv('ZONEMIRROR_HAS_CUSTOM_DMARC_DIR');
        $dir = is_string($override) && $override !== '' ? $override : '/var/cpanel/has_custom_dmarc';

        return is_file($dir . '/' . $zone);
    }

    /**
     * @param array{owner: string, previous: string, lineno: int} $rec
     */
    private function isLocked(string $owner, string $zone, array $rec): bool
    {
        // Locks are stored per (cPanel user, CF zone). Resolve the CF
        // zone_id for $zone from the user's config; if the user has no
        // connection for that zone (admin-only sync via WHM-side token,
        // for instance) there's no per-zone lock file to consult.
        $zoneId = $this->cfZoneIdFor($owner, $zone);
        if ($zoneId === '') {
            return false;
        }
        $locks = $this->lockStorage->all($owner, $zoneId);
        if ($locks === []) {
            return false;
        }
        $entry = [
            'type'   => 'TXT',
            // The lock checks compare lowercased FQDNs, so build it now.
            'name'   => $rec['owner'] === '_dmarc' || $rec['owner'] === $zone
                ? '_dmarc.' . $zone
                : (str_ends_with($rec['owner'], '.' . $zone) || $rec['owner'] === $zone
                    ? $rec['owner']
                    : $rec['owner'] . '.' . $zone),
            'local'  => ['content' => $rec['previous']],
            'remote' => null,
        ];

        return LockStorage::entryMatchesAny($locks, $entry);
    }

    /**
     * Resolve the Cloudflare zone id for $zone in $user's multi-zone
     * config. The local DMARC rewrite is keyed by the BIND zone name
     * (= the cPanel domain), but locks live per CF zone — so we have
     * to bridge between the two namespaces. Returns '' when the user
     * has no connection for this zone, which the caller treats as
     * "no zone-specific locks apply".
     */
    private function cfZoneIdFor(string $user, string $zone): string
    {
        // Lazy-load through a fresh ConfigCrypto+KeyStore each time we
        // need to read a user's config. The plan() loop iterates many
        // zones across many users so a static cache here would be
        // worthwhile, but the current callers (CLI preview/apply,
        // hooks) run as one-shot processes — premature optimisation.
        try {
            $storage = new \ZoneMirror\Infrastructure\Storage\UserConfigStorage(
                new \ZoneMirror\Infrastructure\Storage\ConfigCrypto(
                    new \ZoneMirror\Infrastructure\Storage\KeyStore(Paths::userKeyFile($user))
                )
            );
            $cfg = $storage->load($user);
        } catch (\Throwable) {
            return '';
        }
        $hit = \ZoneMirror\Infrastructure\Storage\UserConfigStorage::findZoneByName($cfg, $zone);

        return $hit === null ? '' : $hit['zone_id'];
    }

    private function hasCustomRua(string $content): bool
    {
        // A DMARC record carries a "custom" reporting address if it
        // mentions rua= or ruf= AT ALL. The plugin's own template also
        // includes them, but that case is caught earlier by the
        // already-matches branch — by the time we reach hasCustomRua,
        // the record is known to differ from our template, so any rua/
        // ruf in it is by definition a customer-set value.
        return stripos($content, 'rua=') !== false || stripos($content, 'ruf=') !== false;
    }
}
