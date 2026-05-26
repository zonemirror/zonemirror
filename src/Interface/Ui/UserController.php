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
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;
use ZoneMirror\Infrastructure\Storage\UserConfigStorage;
use ZoneMirror\Infrastructure\Storage\ZoneIndex;

/**
 * Glue between the cPanel UI template and the storage/service layer. Owns
 * input validation, CSRF, and the user-scoped allowlist gate. The template
 * itself stays a thin view: it calls handle() and consumes the returned
 * view-model.
 *
 * The new (v0.2) entry point is the per-domain list: the template passes
 * every domain that belongs to the calling cPanel user, and the view-model
 * carries, for each one, whether it is already connected, available for
 * 1-click connect (admin token covers it), or not in any indexed zone.
 *
 * @phpstan-type DomainStatus array{
 *     name: string,
 *     status: string,
 *     zone_id: string,
 *     admin_token_id: string,
 *     is_current: bool
 * }
 *
 * @phpstan-type ViewModel array{
 *     user: string,
 *     allowed: bool,
 *     saved: bool,
 *     errors: list<string>,
 *     message: string,
 *     enabled: bool,
 *     zone_id: string,
 *     zone_name: string,
 *     source: string,
 *     sync_state: string,
 *     last_error: string,
 *     defaults_proxied: bool,
 *     token_set: bool,
 *     csrf: string,
 *     queue_depth: int,
 *     dead_letters: int,
 *     test_result: ?string,
 *     domains: list<DomainStatus>,
 *     diff: array<string, mixed>|null
 * }
 */
final class UserController
{
    public const DOMAIN_NOT_CONNECTED = 'not-connected';
    public const DOMAIN_AVAILABLE = 'available';
    public const DOMAIN_CONNECTED_ADMIN = 'connected-admin';
    public const DOMAIN_CONNECTED_USER = 'connected-user';
    public const DOMAIN_NOT_IN_ZONE = 'not-in-zone';

    private ?UserConfigStorage $storage;
    private readonly SystemConfigStorage $systemStorage;
    private readonly EnrolledUsers $enrolled;
    private ?FileLogger $log;
    private ?ZoneIndex $zoneIndex;

    /**
     * Side-channel from the last applySelected() call so the JSON response
     * for an AJAX Apply can echo back the exact card keys that were
     * enqueued (and which ones were skipped). The front-end uses this list
     * to mark precisely those cards as "applying" without having to guess
     * from form state.
     *
     * @var array{push_keys: list<string>, delete_keys: list<string>, skipped: list<string>, batch_ts: int}|null
     */
    private ?array $lastApplyMeta = null;

    public function __construct(
        ?UserConfigStorage $storage = null,
        ?FileLogger $log = null,
        ?ZoneIndex $zoneIndex = null,
    ) {
        $this->storage = $storage;
        $this->log = $log;
        $this->zoneIndex = $zoneIndex;
        $this->systemStorage = new SystemConfigStorage();
        $this->enrolled = new EnrolledUsers();
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
                    [$saved, $errors, $message] = $this->disconnect($user);
                } elseif ($action === 'refresh_diff') {
                    [$saved, $errors, $message] = $this->refreshDiff($user);
                } elseif ($action === 'apply') {
                    [$saved, $errors, $message] = $this->applySelected($user, $post);
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
        $depth = 0;
        $dead = 0;
        if ($cfg['enabled']) {
            try {
                $queue = new SqliteQueue($user);
                $depth = $queue->depth();
                $dead = $queue->deadLetterCount();
            } catch (\Throwable) {
                // Queue not yet initialized; show zeros.
            }
        }

        $diff = $cfg['enabled'] ? (new DiffStorage())->load($user) : null;

        return [
            'user' => $user,
            'allowed' => $allowed,
            'saved' => $saved,
            'errors' => $errors,
            'message' => $message,
            'enabled' => $cfg['enabled'],
            'zone_id' => $cfg['zone_id'],
            'zone_name' => $cfg['zone_name'],
            'source' => $cfg['source'],
            'sync_state' => $cfg['sync_state'],
            'last_error' => $cfg['last_error'],
            'defaults_proxied' => $cfg['defaults']['proxied'],
            'token_set' => $cfg['token'] !== '',
            'csrf' => Csrf::token(),
            'queue_depth' => $depth,
            'dead_letters' => $dead,
            'test_result' => $testResult,
            'domains' => $this->buildDomainsStatus($allDomains, $cfg),
            'diff' => $diff,
        ];
    }

    /**
     * @param list<string>                                                                                     $allDomains
     * @param array{enabled: bool, zone_id: string, zone_name: string, source: string, token: string, ...}     $cfg
     * @return list<DomainStatus>
     */
    private function buildDomainsStatus(array $allDomains, array $cfg): array
    {
        $out = [];
        $currentZone = strtolower($cfg['zone_name']);
        $currentSource = $cfg['source'];
        $currentEnabled = $cfg['enabled'];
        $index = $this->zoneIndex();

        // Dedupe + lowercase. cPanel sometimes returns trailing dots or
        // mixed case in sub_domains; normalise once here.
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

        foreach ($clean as $domain) {
            $isCurrent = $currentEnabled && $domain === $currentZone;
            $isCurrentAdmin = $isCurrent && $currentSource === UserConfigStorage::SOURCE_ADMIN;
            $isCurrentUser = $isCurrent && $currentSource === UserConfigStorage::SOURCE_USER;

            $hit = $index->findByDomain($domain);

            if ($isCurrentAdmin) {
                $status = self::DOMAIN_CONNECTED_ADMIN;
            } elseif ($isCurrentUser) {
                $status = self::DOMAIN_CONNECTED_USER;
            } elseif ($hit !== null) {
                $status = self::DOMAIN_AVAILABLE;
            } else {
                $status = self::DOMAIN_NOT_IN_ZONE;
            }

            $out[] = [
                'name' => $domain,
                'status' => $status,
                'zone_id' => $hit['cf_zone_id'] ?? '',
                'admin_token_id' => $hit['admin_token_id'] ?? '',
                'is_current' => $isCurrent,
            ];
        }

        return $out;
    }

    /**
     * The mainstream 1-click path: the user picks one of their cPanel
     * domains, we look it up in the zone index, and persist a
     * source=admin connection with the matching zone id. No token paste,
     * no DNS knowledge required from the user.
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
        // crafted POST could connect any zone covered by an admin token
        // under any user's identity.
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
        $existing = $storage->load($user);
        // sync_state=pending_diff signals the daemon to compute the diff
        // between /var/named/<zone>.db and Cloudflare on its next cycle.
        // The UI then shows the per-record review table; nothing is pushed
        // to Cloudflare until the user explicitly applies rows.
        $storage->save($user, [
            'enabled' => true,
            'zone_id' => $hit['cf_zone_id'],
            'zone_name' => $domain,
            'defaults' => $existing['defaults'],
            'source' => UserConfigStorage::SOURCE_ADMIN,
            'sync_state' => UserConfigStorage::STATE_PENDING_DIFF,
        ]);

        $this->enrolled->enroll($user);
        $this->logFor($user)->info('domain connected via admin token', [
            'user' => $user,
            'domain' => $domain,
            'zone_id' => $hit['cf_zone_id'],
            'admin_token_id' => $hit['admin_token_id'],
        ]);

        return [true, [], sprintf('%s connected. Computing diff with Cloudflare…', $domain)];
    }

    /**
     * Re-flag the user as pending_diff so the daemon recomputes on its
     * next cycle. The previous diff.json stays on disk until the daemon
     * overwrites it so the UI keeps something to render in the meantime;
     * we just flip the sync_state.
     *
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function refreshDiff(string $user): array
    {
        $storage = $this->storageFor($user);
        $cfg = $storage->load($user);
        if (!$cfg['enabled'] || $cfg['zone_id'] === '') {
            return [false, ['No domain connected.'], ''];
        }
        $storage->save($user, [
            'enabled' => $cfg['enabled'],
            'zone_id' => $cfg['zone_id'],
            'zone_name' => $cfg['zone_name'],
            'defaults' => $cfg['defaults'],
            'source' => $cfg['source'],
            'sync_state' => UserConfigStorage::STATE_PENDING_DIFF,
            'token' => $cfg['token'],
        ]);

        return [true, [], 'Refreshing diff with Cloudflare…'];
    }

    /**
     * Apply user-selected diff rows. Two POST shapes are accepted:
     *
     * 1. Per-row selection — `push_keys[]` and `delete_keys[]` arrays of
     *    diff entry keys. The user ticks individual rows in the table
     *    and we enqueue one event per key.
     * 2. Bulk by status — `apply_status` set to "different",
     *    "cpanel_only", or "cloudflare_only" applies every row in that
     *    category. "different" and "cpanel_only" produce Upserts;
     *    "cloudflare_only" produces Deletes.
     *
     * The two shapes can be combined in one POST (e.g. apply_status =
     * cpanel_only + a few extra push_keys); we de-duplicate by key. The
     * sync_state stays in awaiting_review unless every non-identical
     * row has been applied, in which case we flip it to idle.
     *
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function applySelected(string $user, array $post): array
    {
        $storage = $this->storageFor($user);
        $cfg = $storage->load($user);
        if (!$cfg['enabled'] || $cfg['zone_id'] === '') {
            return [false, ['No domain connected.'], ''];
        }

        $diff = (new DiffStorage())->load($user);
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

        // Per-record proxy override. The UI emits proxy_override[KEY]="1"|"0"
        // when the user clicks the cloud toggle on a card. We force-include
        // the key in pushKeys and, below, override the hydrated record's
        // proxied flag before enqueueing. Empty values are ignored — that's
        // the UI's signal for "user toggled twice, back to original".
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

        // Bulk by status: union with per-row picks. We treat cpanel_only
        // and different as Upserts, cloudflare_only as Deletes — matching
        // the per-row defaults in the UI.
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
            // Apply the per-record proxy override on top of the hydrated
            // record. This is what lets the user "Promote to proxied" (or
            // back) on a row that was previously identical, without us
            // having to invent a new event type.
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
            $queue->enqueue(new DnsEvent(
                domain: (string) ($diff['zone_name'] ?? ''),
                action: EventAction::Upsert,
                record: $record,
                idempotencyKey: 'apply:' . $now . ':push:' . $key,
                createdAt: $now,
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
            // For deletes we only need enough of a DnsRecord for the
            // logger; the actual lookup happens on the daemon side via
            // target_cloudflare_id. Use a minimal placeholder.
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
            ));
            $deleted++;
        }

        // We deliberately do NOT flip sync_state to idle here. The diff
        // stays "awaiting_review" until the user explicitly refreshes;
        // the queue depth + dead_letter count tell them how the apply is
        // progressing, and Refresh shows what's left to do.
        $this->logFor($user)->info('diff: applied', [
            'user' => $user,
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

        // Record what we just enqueued for the AJAX response. We expose
        // only the surviving (non-skipped) card keys so the front-end can
        // mark exactly those cards as "applying".
        $enqueuedPush = array_values(array_filter(
            $pushKeys,
            static fn (string $k): bool => !in_array($k, $skipped, true)
        ));
        $enqueuedDelete = array_values(array_filter(
            $deleteKeys,
            static fn (string $k): bool => !in_array($k, $skipped, true)
        ));
        $this->lastApplyMeta = [
            'push_keys' => $enqueuedPush,
            'delete_keys' => $enqueuedDelete,
            'skipped' => $skipped,
            'batch_ts' => $now,
        ];

        return [
            true,
            [],
            'Queued ' . implode(' and ', $bits) . '. Cloudflare will reflect changes within ~30s.',
        ];
    }

    /**
     * Metadata recorded by the last call to applySelected() in this
     * request. Null when the current request was not an apply. Used by
     * the JSON dispatch in the cPanel template.
     *
     * @return array{push_keys: list<string>, delete_keys: list<string>, skipped: list<string>, batch_ts: int}|null
     */
    public function lastApplyMeta(): ?array
    {
        return $this->lastApplyMeta;
    }

    /**
     * Lightweight read-only snapshot of the user's queue state. Used by
     * the JSON poll endpoint so the front-end can render a live progress
     * bar without re-rendering the whole page.
     *
     * @return array{
     *     enabled: bool,
     *     sync_state: string,
     *     queue_depth: int,
     *     dead_letters: int,
     *     pending_keys: list<string>,
     *     ts: int
     * }
     */
    public function queueStatus(string $user): array
    {
        $cfg = $this->storageFor($user)->load($user);
        $depth = 0;
        $dead = 0;
        $pending = [];
        if ($cfg['enabled']) {
            try {
                $queue = new SqliteQueue($user);
                $depth = $queue->depth();
                $dead = $queue->deadLetterCount();
                $pending = $queue->pendingKeys();
            } catch (\Throwable) {
                // queue file may not exist yet — zeros are fine.
            }
        }

        return [
            'enabled' => $cfg['enabled'],
            'sync_state' => $cfg['sync_state'],
            'queue_depth' => $depth,
            'dead_letters' => $dead,
            'pending_keys' => $pending,
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
     * Rebuild a DnsRecord from the `local` block of a diff entry. The
     * shape was produced by DnsRecord::toCloudflarePayload(), so this
     * is essentially the inverse of that method.
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
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function disconnect(string $user): array
    {
        $storage = $this->storageFor($user);
        $existing = $storage->load($user);
        $storage->save($user, [
            'enabled' => false,
            'zone_id' => $existing['zone_id'],
            'zone_name' => $existing['zone_name'],
            'defaults' => $existing['defaults'],
            'source' => $existing['source'],
            'sync_state' => UserConfigStorage::STATE_IDLE,
            // Keep the user's own token on file if they had one, so they
            // can re-enable without re-pasting. For source=admin there
            // is nothing token-ish to keep.
            'token' => $existing['source'] === UserConfigStorage::SOURCE_USER ? $existing['token'] : '',
        ]);

        // The diff is now stale and visually misleading — drop it. A
        // reconnect will trigger pending_diff which recomputes from
        // scratch.
        (new DiffStorage())->remove($user);

        $this->enrolled->remove($user);
        $this->logFor($user)->info('disconnected', ['user' => $user]);

        return [true, [], 'Disconnected. No more changes will be pushed to Cloudflare.'];
    }

    /**
     * Legacy "case 2" path: the user pastes their own Cloudflare token.
     * Kept for advanced users whose domains are not covered by any admin
     * token; the cPanel UI hides this behind an "Advanced" disclosure.
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

        if ($zoneName !== '' && preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $zoneName) !== 1) {
            $errors[] = 'Zone name is not a valid domain.';
        }

        $storage = $this->storageFor($user);
        $current = $storage->load($user);
        $effectiveToken = $token !== '' ? $token : $current['token'];
        $zoneId = $current['zone_id'];

        if ($enabled && $zoneName !== '' && $effectiveToken !== '') {
            $client = new CloudflareApiClient($effectiveToken);
            $resolved = $client->findZoneId($zoneName);
            if ($resolved === null) {
                $errors[] = 'Could not resolve that zone from Cloudflare. Check the token scope and zone name.';
            } else {
                $zoneId = $resolved;
            }
        }

        if ($errors !== []) {
            return [false, $errors];
        }

        $storage->save($user, [
            'enabled' => $enabled,
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'defaults' => ['proxied' => $defaultsProxied],
            'source' => UserConfigStorage::SOURCE_USER,
            'token' => $token,
        ]);

        if ($enabled) {
            $this->enrolled->enroll($user);
        } else {
            $this->enrolled->remove($user);
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
