<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Ui;

use ZoneMirror\Domain\DnsEvent;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Logging\LogLevel;
use ZoneMirror\Infrastructure\Queue\SqliteQueue;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\DiffStorage;
use ZoneMirror\Infrastructure\Storage\EnrolledUsers;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\LockStorage;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;
use ZoneMirror\Infrastructure\Storage\UserConfigStorage;
use ZoneMirror\Infrastructure\Storage\ZoneIndex;

/**
 * Per-cPanel-user UI controller. The page is multi-zone: a single
 * cPanel account can hold N independent Cloudflare connections (one
 * per addon/parked domain they want to sync). The ViewModel returned
 * from {@see handle()} carries one entry per connected zone — each
 * with its own state, diff, locks and queue depth — plus the list of
 * the user's cPanel domains so the template can offer "Connect" or
 * "Re-enable" buttons for the ones not yet in zones[].
 *
 * State transitions (per zone, not per user):
 *
 *   Available domain → Connect → zone added to zones[], enabled: true,
 *      sync_state: pending_diff. Daemon computes the diff on its next
 *      tick and flips to awaiting_review.
 *
 *   Connected zone → Disconnect → zone stays in zones[] with
 *      enabled: false (soft delete). Locks and diff history are
 *      preserved. If no other zone is enabled, the user is
 *      unenrolled — the daemon stops iterating them altogether.
 *
 *   Disabled zone → Re-enable → enabled: true again, sync_state flips
 *      to pending_diff to refresh against the current zone file.
 *
 * Every action that mutates one specific zone (refresh, apply,
 * toggle_lock, add_lock, remove_lock, disconnect) requires a
 * `zone_id` field in the POST so the template's per-card forms target
 * exactly the zone the user clicked on. Missing or unknown zone_id
 * returns an error to the caller — there is no implicit "current"
 * zone in the multi-zone world.
 *
 * @phpstan-type DomainStatus array{
 *     name: string,
 *     status: string,
 *     zone_id: string,
 *     source: string
 * }
 * @phpstan-type DiffSummary array{
 *     identical: int,
 *     different: int,
 *     cpanel_only: int,
 *     cloudflare_only: int
 * }
 * @phpstan-type ZoneVm array{
 *     zone_id: string,
 *     zone_name: string,
 *     enabled: bool,
 *     sync_state: string,
 *     last_error: string,
 *     source: string,
 *     defaults_proxied: bool,
 *     queue_depth: int,
 *     dead_letters: int,
 *     diff: array<string, mixed>|null,
 *     locks: array<string, array{scope: string, type: string, name: string, content: ?string, priority: ?int, reason: string, created_at: int}>,
 *     locks_count: int,
 *     diff_summary: DiffSummary|null
 * }
 * @phpstan-type ViewModel array{
 *     user: string,
 *     allowed: bool,
 *     saved: bool,
 *     errors: list<string>,
 *     message: string,
 *     token_set: bool,
 *     csrf: string,
 *     test_result: ?string,
 *     zones: list<ZoneVm>,
 *     domains: list<DomainStatus>
 * }
 */
final class UserController
{
    public const DOMAIN_NOT_CONNECTED = 'not-connected';
    public const DOMAIN_AVAILABLE = 'available';
    public const DOMAIN_CONNECTED_ADMIN = 'connected-admin';
    public const DOMAIN_CONNECTED_USER = 'connected-user';
    public const DOMAIN_DISABLED = 'disabled';
    public const DOMAIN_NOT_IN_ZONE = 'not-in-zone';

    private ?UserConfigStorage $storage;
    private readonly SystemConfigStorage $systemStorage;
    private readonly EnrolledUsers $enrolled;
    private ?FileLogger $log;
    private ?ZoneIndex $zoneIndex;

    /**
     * Privileged writer for /var/cpanel/zonemirror/enrolled-users.
     * See AdminBin notes in resources/cpanel/index.live.php.
     *
     * @var (callable(string): void)|null
     */
    private $enrollmentBackend = null;

    /**
     * Side-channel from the last applySelected() call so the JSON
     * response for an AJAX Apply can echo back the exact card keys
     * that were enqueued (and which ones were skipped). The front-end
     * uses this list to mark precisely those cards as "applying"
     * without having to guess from form state.
     *
     * @var array{push_keys: list<string>, delete_keys: list<string>, skipped: list<string>, blocked_by_lock: list<string>, batch_ts: int, zone_id: string}|null
     */
    private ?array $lastApplyMeta = null;

    /**
     * @param (callable(string): void)|null $enrollmentBackend
     */
    public function __construct(
        ?UserConfigStorage $storage = null,
        ?FileLogger $log = null,
        ?ZoneIndex $zoneIndex = null,
        ?callable $enrollmentBackend = null,
    ) {
        $this->storage = $storage;
        $this->log = $log;
        $this->zoneIndex = $zoneIndex;
        $this->systemStorage = new SystemConfigStorage();
        $this->enrolled = new EnrolledUsers();
        $this->enrollmentBackend = $enrollmentBackend;
    }

    /**
     * Mark $user as opted-in. Idempotent; safe to call when the user
     * is already enrolled. Routes through the privileged adminbin
     * when the controller is running in cPanel-user context;
     * otherwise writes the file directly via EnrolledUsers.
     */
    private function markEnrolled(string $user): void
    {
        if ($this->enrollmentBackend !== null) {
            ($this->enrollmentBackend)('enroll');

            return;
        }
        $this->enrolled->enroll($user);
    }

    private function markUnenrolled(string $user): void
    {
        if ($this->enrollmentBackend !== null) {
            ($this->enrollmentBackend)('unenroll');

            return;
        }
        $this->enrolled->remove($user);
    }

    private function storageFor(string $user): UserConfigStorage
    {
        return $this->storage ?? new UserConfigStorage(
            new ConfigCrypto(new KeyStore(Paths::userKeyFile($user)))
        );
    }

    private function logFor(string $user): FileLogger
    {
        return $this->log ?? new FileLogger(Paths::userLogFile($user), LogLevel::Info);
    }

    private function zoneIndex(): ZoneIndex
    {
        return $this->zoneIndex ??= new ZoneIndex(Paths::zoneIndexFile());
    }

    /**
     * @param array<string, mixed> $post
     * @param list<string>         $allDomains  The cPanel user's domains
     *                                          (main + addon + parked + sub).
     *                                          Caller supplies these from
     *                                          UAPI DomainInfo::list_domains
     *                                          so this class stays free of
     *                                          LiveAPI coupling.
     * @return ViewModel
     */
    public function handle(string $user, string $method, array $post, array $allDomains = []): array
    {
        $saved = false;
        $errors = [];
        $message = '';
        $testResult = null;

        $allowed = $this->systemStorage->isUserAllowed($user);

        if ($method === 'POST' && $allowed) {
            if (!Csrf::verify(isset($post['csrf']) ? (string) $post['csrf'] : null)) {
                $errors[] = 'Invalid CSRF token. Please reload the page and try again.';
            } else {
                $action = (string) ($post['action'] ?? 'save');
                if ($action === 'connect_domain') {
                    [$saved, $errors, $message] = $this->connectDomain($user, $post, $allDomains);
                } elseif ($action === 'disconnect') {
                    [$saved, $errors, $message] = $this->disconnect($user, $post);
                } elseif ($action === 'reenable') {
                    [$saved, $errors, $message] = $this->reenable($user, $post);
                } elseif ($action === 'refresh_diff') {
                    [$saved, $errors, $message] = $this->refreshDiff($user, $post);
                } elseif ($action === 'apply') {
                    [$saved, $errors, $message] = $this->applySelected($user, $post);
                } elseif ($action === 'toggle_lock') {
                    [$saved, $errors, $message] = $this->toggleLock($user, $post);
                } elseif ($action === 'add_lock') {
                    [$saved, $errors, $message] = $this->addLock($user, $post);
                } elseif ($action === 'remove_lock') {
                    [$saved, $errors, $message] = $this->removeLock($user, $post);
                } elseif ($action === 'test') {
                    $testResult = $this->testConnection(
                        (string) ($post['token'] ?? ''),
                        (string) ($post['zone_name'] ?? '')
                    );
                } else {
                    [$saved, $errors] = $this->save($user, $post);
                }
            }
        }

        $cfg = $this->storageFor($user)->load($user);
        $zones = [];
        foreach ($cfg['zones'] as $zone) {
            $zones[] = $this->buildZoneVm($user, $zone);
        }

        return [
            'user' => $user,
            'allowed' => $allowed,
            'saved' => $saved,
            'errors' => $errors,
            'message' => $message,
            'token_set' => $cfg['token'] !== '',
            'csrf' => Csrf::token(),
            'test_result' => $testResult,
            'zones' => $zones,
            'domains' => $this->buildDomainsStatus($allDomains, $cfg),
        ];
    }

    /**
     * Per-zone view model. Reads the diff and locks for the zone and
     * annotates each diff entry with whether any lock matches it (the
     * same enrichment the v1 single-zone code did, just scoped to one
     * zone now).
     *
     * @param array{zone_id: string, zone_name: string, enabled: bool, defaults: array{proxied: bool}, source: string, sync_state: string, last_error: string} $zone
     * @return ZoneVm
     */
    private function buildZoneVm(string $user, array $zone): array
    {
        $depth = 0;
        $dead = 0;
        if ($zone['enabled']) {
            try {
                $queue = new SqliteQueue($user);
                $depth = $queue->depth($zone['zone_id']);
                $dead = $queue->deadLetterCount($zone['zone_id']);
            } catch (\Throwable) {
                // Queue not initialised yet (no enqueues this lifetime).
            }
        }

        $diff = $zone['enabled'] ? (new DiffStorage())->load($user, $zone['zone_id']) : null;
        $locks = $zone['enabled'] ? (new LockStorage())->all($user, $zone['zone_id']) : [];

        if (is_array($diff) && isset($diff['entries']) && is_array($diff['entries'])) {
            foreach ($diff['entries'] as $i => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $quickLockId = LockStorage::lockIdForEntry($entry, LockStorage::SCOPE_TYPE_NAME);
                $matchedIds = [];
                $matchedReasons = [];
                foreach ($locks as $lockId => $lock) {
                    if (LockStorage::entryMatches($lock, $entry)) {
                        $matchedIds[] = $lockId;
                        if (($lock['reason'] ?? '') !== '') {
                            $matchedReasons[] = (string) $lock['reason'];
                        }
                    }
                }
                $diff['entries'][$i]['lock_id'] = $quickLockId;
                $diff['entries'][$i]['locked']  = $matchedIds !== [];
                $diff['entries'][$i]['lock_matched_ids'] = $matchedIds;
                $diff['entries'][$i]['lock_reason'] = $matchedReasons === [] ? '' : implode('; ', $matchedReasons);
            }
        }

        $summary = null;
        if (is_array($diff) && isset($diff['summary']) && is_array($diff['summary'])) {
            $s = $diff['summary'];
            $summary = [
                'identical' => (int) ($s['identical'] ?? 0),
                'different' => (int) ($s['different'] ?? 0),
                'cpanel_only' => (int) ($s['cpanel_only'] ?? 0),
                'cloudflare_only' => (int) ($s['cloudflare_only'] ?? 0),
            ];
        }

        return [
            'zone_id' => $zone['zone_id'],
            'zone_name' => $zone['zone_name'],
            'enabled' => $zone['enabled'],
            'sync_state' => $zone['sync_state'],
            'last_error' => $zone['last_error'],
            'source' => $zone['source'],
            'defaults_proxied' => $zone['defaults']['proxied'],
            'queue_depth' => $depth,
            'dead_letters' => $dead,
            'diff' => $diff,
            'locks' => $locks,
            'locks_count' => count($locks),
            'diff_summary' => $summary,
        ];
    }

    /**
     * Walk the user's cPanel domains and mark each one's connection
     * status. A domain can be in one of:
     *   - connected-admin: enabled zone in cfg, source=admin
     *   - connected-user:  enabled zone in cfg, source=user
     *   - disabled:        zone in cfg with enabled=false
     *   - available:       not in cfg, but covered by an admin token
     *   - not-in-zone:     not in cfg and not covered anywhere
     *
     * @param list<string> $allDomains
     * @param array{token: string, zones: list<array{zone_id: string, zone_name: string, enabled: bool, defaults: array{proxied: bool}, source: string, sync_state: string, last_error: string}>} $cfg
     * @return list<DomainStatus>
     */
    private function buildDomainsStatus(array $allDomains, array $cfg): array
    {
        $index = $this->zoneIndex();
        $byDomain = [];
        foreach ($cfg['zones'] as $z) {
            $byDomain[strtolower($z['zone_name'])] = $z;
        }

        $seen = [];
        $clean = [];
        foreach ($allDomains as $d) {
            $name = strtolower(trim((string) $d, " \t\n\r\0\x0B."));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $clean[] = $name;
        }

        $out = [];
        foreach ($clean as $name) {
            $entry = $byDomain[$name] ?? null;
            if ($entry !== null) {
                if ($entry['enabled']) {
                    $status = $entry['source'] === UserConfigStorage::SOURCE_USER
                        ? self::DOMAIN_CONNECTED_USER
                        : self::DOMAIN_CONNECTED_ADMIN;
                } else {
                    $status = self::DOMAIN_DISABLED;
                }
                $out[] = [
                    'name' => $name,
                    'status' => $status,
                    'zone_id' => $entry['zone_id'],
                    'source' => $entry['source'],
                ];

                continue;
            }
            // Not in user's config yet — check whether an admin token
            // covers it (Connect button is offered) or not (no path
            // forward without the user pasting their own token).
            $hit = $index->findByDomain($name);
            $out[] = [
                'name' => $name,
                'status' => $hit === null ? self::DOMAIN_NOT_IN_ZONE : self::DOMAIN_AVAILABLE,
                'zone_id' => $hit === null ? '' : $hit['cf_zone_id'],
                'source' => UserConfigStorage::SOURCE_ADMIN,
            ];
        }

        return $out;
    }

    /**
     * Append a new zone to the user's config (or re-enable an existing
     * disabled one for the same domain). Enrolls the user before
     * writing the config so a failed enroll never leaves a half-state
     * — UserConfig save is the last step, so a failed write doesn't
     * leak a half-deserialised file either.
     *
     * @param array<string, mixed> $post
     * @param list<string>         $allDomains
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function connectDomain(string $user, array $post, array $allDomains): array
    {
        $domain = strtolower(trim((string) ($post['domain'] ?? ''), " \t\n\r\0\x0B."));
        if ($domain === '') {
            return [false, ['No domain provided.'], ''];
        }

        // The domain MUST belong to this cPanel user — otherwise a
        // crafted POST could connect any zone covered by an admin
        // token under any user's identity.
        $ownedLowercase = array_map(
            static fn (string $d): string => strtolower(trim($d, " \t\n\r\0\x0B.")),
            $allDomains
        );
        if (!in_array($domain, $ownedLowercase, true)) {
            return [false, ['That domain does not belong to this cPanel account.'], ''];
        }

        $hit = $this->zoneIndex()->findByDomain($domain);
        if ($hit === null) {
            return [false, ['That domain is not covered by any Cloudflare account on this server.'], ''];
        }

        $storage = $this->storageFor($user);
        $cfg = $storage->load($user);

        // Enroll first — if it fails (adminbin error, permission
        // denied) we throw before save() and the config stays exactly
        // as it was. This is the lesson from the pre-AdminBin half-
        // state bug where save ran but enroll didn't.
        try {
            $this->markEnrolled($user);
        } catch (\Throwable $e) {
            return [false, ['Could not enable plugin for your account: ' . $e->getMessage()], ''];
        }

        $newZone = [
            'zone_id' => $hit['cf_zone_id'],
            'zone_name' => $domain,
            'enabled' => true,
            'defaults' => ['proxied' => false],
            'source' => UserConfigStorage::SOURCE_ADMIN,
            // pending_diff signals the daemon to compute the diff on
            // its next tick; the UI then shows the per-record review.
            'sync_state' => UserConfigStorage::STATE_PENDING_DIFF,
            'last_error' => '',
        ];

        // Re-enable path: same domain already exists but was soft-
        // deleted. Preserve any old defaults so the user gets back to
        // exactly where they were.
        $existing = UserConfigStorage::findZoneByName($cfg, $domain);
        if ($existing !== null) {
            $newZone['defaults'] = $existing['defaults'];
            $newZone['source'] = $existing['source'];
        }

        $cfg = UserConfigStorage::upsertZone($cfg, $newZone);
        $storage->save($user, $cfg);

        $this->logFor($user)->info('domain connected via admin token', [
            'user' => $user,
            'domain' => $domain,
            'zone_id' => $hit['cf_zone_id'],
            'admin_token_id' => $hit['admin_token_id'],
        ]);

        return [true, [], sprintf('%s connected. Computing diff with Cloudflare…', $domain)];
    }

    /**
     * Re-flag a specific zone as pending_diff so the daemon recomputes
     * on its next cycle. The previous diff.json stays on disk until
     * the daemon overwrites it so the UI keeps something to render in
     * the meantime; we just flip the sync_state.
     *
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function refreshDiff(string $user, array $post): array
    {
        $zoneId = (string) ($post['zone_id'] ?? '');
        if ($zoneId === '') {
            return [false, ['Missing zone id.'], ''];
        }
        $storage = $this->storageFor($user);
        $cfg = $storage->load($user);
        $zone = UserConfigStorage::findZone($cfg, $zoneId);
        if ($zone === null || !$zone['enabled']) {
            return [false, ['Zone not connected.'], ''];
        }
        $zone['sync_state'] = UserConfigStorage::STATE_PENDING_DIFF;
        $zone['last_error'] = '';
        $cfg = UserConfigStorage::upsertZone($cfg, $zone);
        $storage->save($user, $cfg);

        return [true, [], 'Refreshing diff with Cloudflare…'];
    }

    /**
     * Apply user-selected diff rows. Same two POST shapes as the v1
     * single-zone path, plus a required `zone_id` so we target the
     * right diff file and the right lock table. Mixed-zone POSTs are
     * not supported — the template's per-card forms emit a single
     * zone_id and only the keys for that zone.
     *
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function applySelected(string $user, array $post): array
    {
        $zoneId = (string) ($post['zone_id'] ?? '');
        if ($zoneId === '') {
            return [false, ['Missing zone id.'], ''];
        }
        $storage = $this->storageFor($user);
        $cfg = $storage->load($user);
        $zone = UserConfigStorage::findZone($cfg, $zoneId);
        if ($zone === null || !$zone['enabled']) {
            return [false, ['Zone not connected.'], ''];
        }

        $diff = (new DiffStorage())->load($user, $zoneId);
        if ($diff === null) {
            return [false, ['No diff available. Press Refresh to recompute.'], ''];
        }

        $byKey = [];
        foreach (($diff['entries'] ?? []) as $e) {
            if (is_array($e) && is_string($e['key'] ?? null)) {
                $byKey[$e['key']] = $e;
            }
        }

        $pushKeys = $this->normaliseKeyList($post['push_keys'] ?? []);
        $deleteKeys = $this->normaliseKeyList($post['delete_keys'] ?? []);

        /** @var array<string, bool> $proxyOverrides */
        $proxyOverrides = [];
        if (isset($post['proxy_override']) && is_array($post['proxy_override'])) {
            foreach ($post['proxy_override'] as $k => $v) {
                if (!is_string($k) || $k === '' || !is_string($v) || $v === '') {
                    continue;
                }
                if ($v === '1' || $v === '0') {
                    $proxyOverrides[$k] = $v === '1';
                    $pushKeys[] = $k;
                }
            }
        }

        $bulkStatus = (string) ($post['apply_status'] ?? '');
        if ($bulkStatus !== '') {
            foreach ($byKey as $key => $e) {
                $status = (string) ($e['status'] ?? '');
                if ($bulkStatus === 'all') {
                    if ($status === 'different' || $status === 'cpanel_only') {
                        $pushKeys[] = $key;
                    }
                } elseif ($bulkStatus === $status) {
                    if ($status === 'cloudflare_only') {
                        $deleteKeys[] = $key;
                    } else {
                        $pushKeys[] = $key;
                    }
                }
            }
        }

        $pushKeys = array_values(array_unique($pushKeys));
        $deleteKeys = array_values(array_unique($deleteKeys));

        $locks = (new LockStorage())->all($user, $zoneId);
        $blockedByLock = [];
        $filter = function (array $keys) use (&$blockedByLock, $byKey, $locks): array {
            $out = [];
            foreach ($keys as $key) {
                $entry = $byKey[$key] ?? null;
                if (is_array($entry) && LockStorage::entryMatchesAny($locks, $entry)) {
                    $blockedByLock[] = $key;

                    continue;
                }
                $out[] = $key;
            }

            return $out;
        };
        $pushKeys = $filter($pushKeys);
        $deleteKeys = $filter($deleteKeys);

        $queue = new SqliteQueue($user);
        $now = time();
        $pushed = 0;
        $deleted = 0;
        $skipped = [];

        foreach ($pushKeys as $key) {
            $entry = $byKey[$key] ?? null;
            if ($entry === null || !is_array($entry['local'] ?? null)) {
                $skipped[] = $key;

                continue;
            }
            $record = $this->hydrateRecordFromDiff($entry['local']);
            if ($record === null) {
                $skipped[] = $key;

                continue;
            }
            if (array_key_exists($key, $proxyOverrides) && $record->type->supportsProxy()) {
                $record = new DnsRecord(
                    type: $record->type,
                    name: $record->name,
                    content: $record->content,
                    ttl: $record->ttl,
                    priority: $record->priority,
                    proxied: $proxyOverrides[$key],
                    data: $record->data,
                );
            }
            $remoteIdForUpsert = null;
            if (is_array($entry['remote'] ?? null)) {
                $rid = (string) ($entry['remote']['id'] ?? '');
                if ($rid !== '') {
                    $remoteIdForUpsert = $rid;
                }
            }
            $queue->enqueue(new DnsEvent(
                domain: (string) ($diff['zone_name'] ?? ''),
                action: EventAction::Upsert,
                record: $record,
                idempotencyKey: 'apply:' . $now . ':push:' . $key,
                createdAt: $now,
                targetCloudflareId: $remoteIdForUpsert,
                zoneId: $zoneId,
            ));
            $pushed++;
        }

        foreach ($deleteKeys as $key) {
            $entry = $byKey[$key] ?? null;
            if ($entry === null || !is_array($entry['remote'] ?? null)) {
                $skipped[] = $key;

                continue;
            }
            $remoteId = (string) ($entry['remote']['id'] ?? '');
            if ($remoteId === '') {
                $skipped[] = $key;

                continue;
            }
            $type = RecordType::tryFromString((string) ($entry['type'] ?? ''));
            if ($type === null) {
                $skipped[] = $key;

                continue;
            }
            $placeholder = new DnsRecord(
                type: $type,
                name: (string) ($entry['name'] ?? ''),
                content: isset($entry['remote']['content']) ? (string) $entry['remote']['content'] : null,
                ttl: (int) ($entry['remote']['ttl'] ?? 300),
                priority: isset($entry['remote']['priority']) ? (int) $entry['remote']['priority'] : null,
                proxied: null,
                data: is_array($entry['remote']['data'] ?? null) ? $entry['remote']['data'] : [],
            );
            $queue->enqueue(new DnsEvent(
                domain: (string) ($diff['zone_name'] ?? ''),
                action: EventAction::Delete,
                record: $placeholder,
                idempotencyKey: 'apply:' . $now . ':del:' . $key,
                createdAt: $now,
                targetCloudflareId: $remoteId,
                zoneId: $zoneId,
            ));
            $deleted++;
        }

        $this->logFor($user)->info('diff: applied', [
            'user' => $user,
            'zone' => $zone['zone_name'],
            'pushed' => $pushed,
            'deleted' => $deleted,
            'skipped' => count($skipped),
        ]);

        $bits = [];
        if ($pushed > 0) {
            $bits[] = sprintf('%d push%s', $pushed, $pushed === 1 ? '' : 'es');
        }
        if ($deleted > 0) {
            $bits[] = sprintf('%d delete%s', $deleted, $deleted === 1 ? '' : 's');
        }
        if ($bits === []) {
            return [false, ['Nothing selected.'], ''];
        }

        $enqueuedPush = array_values(array_filter(
            $pushKeys,
            static fn (string $k): bool => !in_array($k, $skipped, true)
        ));
        $enqueuedDelete = array_values(array_filter(
            $deleteKeys,
            static fn (string $k): bool => !in_array($k, $skipped, true)
        ));
        $this->lastApplyMeta = [
            'push_keys'   => $enqueuedPush,
            'delete_keys' => $enqueuedDelete,
            'skipped'     => $skipped,
            'blocked_by_lock' => array_values(array_unique($blockedByLock)),
            'batch_ts'    => $now,
            'zone_id'     => $zoneId,
        ];

        $msg = 'Queued ' . implode(' and ', $bits) . '. Cloudflare will reflect changes within ~30s.';
        if ($blockedByLock !== []) {
            $n = count(array_unique($blockedByLock));
            $msg .= sprintf(' %d locked row%s ignored.', $n, $n === 1 ? '' : 's');
        }

        return [true, [], $msg];
    }

    /**
     * Quick-toggle (padlock button on each card): flips a
     * SCOPE_TYPE_NAME lock for the row the user clicked on, scoped to
     * the zone the card belongs to. Fine-grained scopes go through
     * the Manage Locks panel and addLock() below.
     *
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function toggleLock(string $user, array $post): array
    {
        $zoneId = (string) ($post['zone_id'] ?? '');
        if ($zoneId === '') {
            return [false, ['Missing zone id.'], ''];
        }
        $cfg = $this->storageFor($user)->load($user);
        $zone = UserConfigStorage::findZone($cfg, $zoneId);
        if ($zone === null || !$zone['enabled']) {
            return [false, ['Zone not connected.'], ''];
        }

        $key = trim((string) ($post['lock_key'] ?? ''));
        if ($key === '') {
            return [false, ['Missing record identifier.'], ''];
        }

        $diff = (new DiffStorage())->load($user, $zoneId);
        if ($diff === null) {
            return [false, ['No diff available.'], ''];
        }
        $entry = null;
        foreach (($diff['entries'] ?? []) as $e) {
            if (is_array($e) && (string) ($e['key'] ?? '') === $key) {
                $entry = $e;

                break;
            }
        }
        if ($entry === null) {
            return [false, ['Record not found in current diff.'], ''];
        }

        $storage = new LockStorage();
        $lockId  = LockStorage::lockIdForEntry($entry, LockStorage::SCOPE_TYPE_NAME);

        if ($storage->isLockedById($user, $zoneId, $lockId)) {
            $storage->remove($user, $zoneId, $lockId);
            $msg = sprintf('Lock removed from %s %s.', (string) ($entry['type'] ?? ''), (string) ($entry['name'] ?? ''));
        } else {
            $storage->add(
                user: $user,
                zoneId: $zoneId,
                scope: LockStorage::SCOPE_TYPE_NAME,
                type: (string) ($entry['type'] ?? ''),
                name: (string) ($entry['name'] ?? ''),
                reason: trim((string) ($post['reason'] ?? '')),
            );
            $msg = sprintf('Lock added to %s %s.', (string) ($entry['type'] ?? ''), (string) ($entry['name'] ?? ''));
        }

        return [true, [], $msg];
    }

    /**
     * Add a lock with an explicit scope (Manage Locks panel).
     *
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function addLock(string $user, array $post): array
    {
        $zoneId = (string) ($post['zone_id'] ?? '');
        if ($zoneId === '') {
            return [false, ['Missing zone id.'], ''];
        }
        $cfg = $this->storageFor($user)->load($user);
        $zone = UserConfigStorage::findZone($cfg, $zoneId);
        if ($zone === null || !$zone['enabled']) {
            return [false, ['Zone not connected.'], ''];
        }

        $scope = trim((string) ($post['scope'] ?? ''));
        if (!in_array($scope, LockStorage::SCOPES, true)) {
            return [false, ['Invalid lock scope.'], ''];
        }
        $type     = trim((string) ($post['type']     ?? ''));
        $name     = trim((string) ($post['name']     ?? ''));
        $content  = $post['content'] ?? null;
        $contentStr = is_string($content) ? $content : null;
        $priority = isset($post['priority']) && $post['priority'] !== '' ? (int) $post['priority'] : null;
        $reason   = trim((string) ($post['reason'] ?? ''));

        if ($scope === LockStorage::SCOPE_ZONE) {
            $type = '';
            $name = '';
            $contentStr = null;
            $priority = null;
        }

        try {
            $id = (new LockStorage())->add(
                user: $user,
                zoneId: $zoneId,
                scope: $scope,
                type: $type,
                name: $name,
                content: $contentStr,
                priority: $priority,
                reason: $reason,
            );
        } catch (\InvalidArgumentException $e) {
            return [false, [$e->getMessage()], ''];
        }

        return [true, [], sprintf('Lock added (%s).', $id)];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function removeLock(string $user, array $post): array
    {
        $zoneId = (string) ($post['zone_id'] ?? '');
        if ($zoneId === '') {
            return [false, ['Missing zone id.'], ''];
        }
        $cfg = $this->storageFor($user)->load($user);
        $zone = UserConfigStorage::findZone($cfg, $zoneId);
        if ($zone === null || !$zone['enabled']) {
            return [false, ['Zone not connected.'], ''];
        }
        $lockId = trim((string) ($post['lock_id'] ?? ''));
        if ($lockId === '') {
            return [false, ['Missing lock id.'], ''];
        }
        $ok = (new LockStorage())->remove($user, $zoneId, $lockId);
        if (!$ok) {
            return [false, ['Lock not found.'], ''];
        }

        return [true, [], 'Lock removed.'];
    }

    /**
     * Metadata recorded by the last call to applySelected() in this
     * request. Null when the current request was not an apply.
     *
     * @return array{push_keys: list<string>, delete_keys: list<string>, skipped: list<string>, blocked_by_lock: list<string>, batch_ts: int, zone_id: string}|null
     */
    public function lastApplyMeta(): ?array
    {
        return $this->lastApplyMeta;
    }

    /**
     * Lightweight read-only snapshot of every connected zone's queue
     * state. Used by the JSON poll endpoint so the front-end can
     * render a live progress bar per card without re-rendering the
     * whole page.
     *
     * @return array{
     *     zones: array<string, array{
     *         sync_state: string,
     *         queue_depth: int,
     *         dead_letters: int,
     *         pending_keys: list<string>
     *     }>,
     *     ts: int
     * }
     */
    public function queueStatus(string $user): array
    {
        $cfg = $this->storageFor($user)->load($user);
        $zones = [];
        $queue = null;

        try {
            $queue = new SqliteQueue($user);
        } catch (\Throwable) {
            $queue = null;
        }
        foreach ($cfg['zones'] as $zone) {
            if (!$zone['enabled']) {
                continue;
            }
            $depth = 0;
            $dead = 0;
            $pending = [];
            if ($queue !== null) {
                try {
                    $depth = $queue->depth($zone['zone_id']);
                    $dead = $queue->deadLetterCount($zone['zone_id']);
                    // pendingKeys is a global pop-front list; the
                    // front-end matches by key on its own cards so a
                    // single list is fine for now.
                    $pending = $queue->pendingKeys();
                } catch (\Throwable) {
                    // queue file may not exist yet — zeros are fine.
                }
            }
            $zones[$zone['zone_id']] = [
                'sync_state' => $zone['sync_state'],
                'queue_depth' => $depth,
                'dead_letters' => $dead,
                'pending_keys' => $pending,
            ];
        }

        return [
            'zones' => $zones,
            'ts' => time(),
        ];
    }

    /**
     * @return list<string>
     */
    private function normaliseKeyList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * Inverse of DnsRecord::toCloudflarePayload().
     *
     * @param array<string, mixed> $payload
     */
    private function hydrateRecordFromDiff(array $payload): ?DnsRecord
    {
        $type = RecordType::tryFromString(isset($payload['type']) ? (string) $payload['type'] : null);
        if ($type === null) {
            return null;
        }

        return new DnsRecord(
            type: $type,
            name: (string) ($payload['name'] ?? ''),
            content: isset($payload['content']) ? (string) $payload['content'] : null,
            ttl: (int) ($payload['ttl'] ?? 300),
            priority: isset($payload['priority']) ? (int) $payload['priority'] : null,
            proxied: array_key_exists('proxied', $payload) ? (bool) $payload['proxied'] : null,
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
        );
    }

    /**
     * Soft-delete a zone: flip enabled to false, keep the entry in
     * zones[] so its locks history survives a re-enable. Drop the
     * cached diff because it's now visually misleading. Unenroll the
     * user only when no other zone is still enabled — otherwise the
     * daemon must keep iterating them for the remaining zones.
     *
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function disconnect(string $user, array $post): array
    {
        $zoneId = (string) ($post['zone_id'] ?? '');
        if ($zoneId === '') {
            return [false, ['Missing zone id.'], ''];
        }
        $storage = $this->storageFor($user);
        $cfg = $storage->load($user);
        $zone = UserConfigStorage::findZone($cfg, $zoneId);
        if ($zone === null) {
            return [false, ['Zone not connected.'], ''];
        }
        if (!$zone['enabled']) {
            return [true, [], sprintf('%s was already disconnected.', $zone['zone_name'])];
        }

        $zone['enabled'] = false;
        $zone['sync_state'] = UserConfigStorage::STATE_IDLE;
        $zone['last_error'] = '';
        $cfg = UserConfigStorage::upsertZone($cfg, $zone);
        $storage->save($user, $cfg);

        (new DiffStorage())->remove($user, $zoneId);

        $stillActive = false;
        foreach ($cfg['zones'] as $z) {
            if ($z['enabled']) {
                $stillActive = true;

                break;
            }
        }
        if (!$stillActive) {
            try {
                $this->markUnenrolled($user);
            } catch (\Throwable $e) {
                $this->logFor($user)->warning('unenroll failed after last zone disconnect', [
                    'user' => $user,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logFor($user)->info('disconnected', [
            'user' => $user,
            'zone' => $zone['zone_name'],
            'still_active_zones' => $stillActive,
        ]);

        return [true, [], sprintf('%s disconnected.', $zone['zone_name'])];
    }

    /**
     * Re-enable a soft-deleted zone. Flip enabled to true and reset
     * sync_state to pending_diff so the daemon recomputes the diff
     * against whatever the zone file looks like now (it may have
     * drifted while the zone was disabled).
     *
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function reenable(string $user, array $post): array
    {
        $zoneId = (string) ($post['zone_id'] ?? '');
        if ($zoneId === '') {
            return [false, ['Missing zone id.'], ''];
        }
        $storage = $this->storageFor($user);
        $cfg = $storage->load($user);
        $zone = UserConfigStorage::findZone($cfg, $zoneId);
        if ($zone === null) {
            return [false, ['Zone not connected.'], ''];
        }

        try {
            $this->markEnrolled($user);
        } catch (\Throwable $e) {
            return [false, ['Could not enable plugin for your account: ' . $e->getMessage()], ''];
        }

        $zone['enabled'] = true;
        $zone['sync_state'] = UserConfigStorage::STATE_PENDING_DIFF;
        $zone['last_error'] = '';
        $cfg = UserConfigStorage::upsertZone($cfg, $zone);
        $storage->save($user, $cfg);

        $this->logFor($user)->info('zone re-enabled', [
            'user' => $user,
            'zone' => $zone['zone_name'],
        ]);

        return [true, [], sprintf('%s re-enabled. Computing diff with Cloudflare…', $zone['zone_name'])];
    }

    /**
     * Legacy "case 2" path: the user pastes their own Cloudflare
     * token and points it at one of their domains. In the multi-zone
     * model this either inserts a new zone with source=user or
     * updates the existing source=user zone for that domain.
     *
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>}
     */
    private function save(string $user, array $post): array
    {
        $errors = [];
        $token = trim((string) ($post['token'] ?? ''));
        $zoneName = strtolower(trim((string) ($post['zone_name'] ?? '')));
        $enabled = isset($post['enabled']) && (string) $post['enabled'] !== '';
        $defaultsProxied = isset($post['defaults_proxied']) && (string) $post['defaults_proxied'] !== '';

        if ($zoneName === '') {
            return [false, ['Zone name is required.']];
        }
        if (preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $zoneName) !== 1) {
            return [false, ['Zone name is not a valid domain.']];
        }

        $storage = $this->storageFor($user);
        $cfg = $storage->load($user);
        $effectiveToken = $token !== '' ? $token : $cfg['token'];
        if ($effectiveToken === '') {
            return [false, ['A Cloudflare API token is required.']];
        }

        $client = new CloudflareApiClient($effectiveToken);
        $zoneId = $client->findZoneId($zoneName);
        if ($zoneId === null) {
            return [false, ['Could not resolve that zone from Cloudflare. Check the token scope and zone name.']];
        }

        try {
            if ($enabled) {
                $this->markEnrolled($user);
            }
        } catch (\Throwable $e) {
            return [false, ['Could not enable plugin for your account: ' . $e->getMessage()]];
        }

        $existing = UserConfigStorage::findZoneByName($cfg, $zoneName);
        $newZone = [
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'enabled' => $enabled,
            'defaults' => ['proxied' => $defaultsProxied],
            'source' => UserConfigStorage::SOURCE_USER,
            'sync_state' => $enabled
                ? UserConfigStorage::STATE_PENDING_DIFF
                : ($existing['sync_state'] ?? UserConfigStorage::STATE_IDLE),
            'last_error' => '',
        ];
        $cfg['token'] = $effectiveToken;
        $cfg = UserConfigStorage::upsertZone($cfg, $newZone);
        $storage->save($user, $cfg);

        // If the user just disabled their only zone, unenroll them.
        if (!$enabled) {
            $stillActive = false;
            foreach ($cfg['zones'] as $z) {
                if ($z['enabled']) {
                    $stillActive = true;

                    break;
                }
            }
            if (!$stillActive) {
                try {
                    $this->markUnenrolled($user);
                } catch (\Throwable) {
                    // tolerate; admin can clean up
                }
            }
        }

        $this->logFor($user)->info('user config saved', [
            'user' => $user,
            'enabled' => $enabled,
            'zone_name' => $zoneName,
            'token_provided' => $token !== '',
        ]);

        return [true, []];
    }

    private function testConnection(string $token, string $zoneName): string
    {
        if ($token === '') {
            return 'Provide a token to test.';
        }
        $client = new CloudflareApiClient($token);
        if (!$client->verifyToken()) {
            return 'Token is not active or has no permissions.';
        }
        if ($zoneName === '') {
            return 'Token verified. Provide a zone to confirm scope.';
        }
        $zoneId = $client->findZoneId($zoneName);

        return $zoneId === null
            ? 'Token verified, but the zone is not visible to this token.'
            : 'Token verified. Zone visible (id: ' . substr($zoneId, 0, 8) . '...).';
    }
}
