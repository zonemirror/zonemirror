<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Worker;

use ZoneMirror\Application\ComputeDiff;
use ZoneMirror\Application\IndexZones;
use ZoneMirror\Application\ProcessEvent;
use ZoneMirror\Domain\DnsDiff;
use ZoneMirror\Domain\DnsEvent;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareException;
use ZoneMirror\Infrastructure\Cloudflare\ZoneSnapshot;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Queue\SqliteQueue;
use ZoneMirror\Infrastructure\Storage\AdminTokenStorage;
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
 * Background daemon. Iterates the enrolled users, then for each user
 * iterates their enabled zones, draining the per-user queue up to a
 * small batch per cycle and computing pending diffs. Each event in the
 * queue carries a zone_id so a multi-zone user's events route to the
 * right Cloudflare zone without the daemon having to guess.
 *
 * Two important optimizations keep us inside Cloudflare's per-token
 * rate limit (1,200 requests / 5 min) even at ~50 enrolled users:
 *
 *  - One full `listRecords` per (zone, cycle) snapshot, reused for
 *    every event in the batch and updated in-place after each mutation.
 *  - WHM-admin `rate_limit_rps` budget enforced as an inter-call sleep.
 *
 * Persistent state cached across cycles to avoid filesystem hammering:
 *  - SqliteQueue per user (and the underlying PDO + WAL setup).
 *  - System config + enrolled-user list with a TTL of
 *    CONFIG_RELOAD_SECONDS.
 *
 * Designed to be supervised by systemd: any uncaught error exits with
 * status 1 so systemd restarts the service rather than letting it run
 * in a broken state silently.
 */
final class WorkerLoop
{
    private const CONFIG_RELOAD_SECONDS = 30;
    private const ZONE_INDEX_REFRESH_SECONDS = 3600;

    private bool $stop = false;

    /** @var array<string, SqliteQueue> */
    private array $queueCache = [];

    /**
     * Per-(user, zone) last-seen mtime of the zone file. We poll this
     * every cycle so when something outside our hookable surface area
     * writes to /var/named/<zone>.db (AutoSSL DCV's _acme-challenge
     * TXT, scripts that bypass UAPI, future cPanel features we haven't
     * reverse-engineered yet) we still notice within sleepSeconds and
     * recompute. Key shape: "{$user}:{$zoneId}".
     *
     * @var array<string, int>
     */
    private array $zoneMtime = [];

    public function __construct(
        private readonly FileLogger $log,
        private readonly int $sleepSeconds = 2,
        private readonly int $batchPerUser = 25,
    ) {
    }

    public function run(): int
    {
        $this->installSignalHandlers();
        $this->log->info('worker started', ['pid' => getmypid()]);

        $systemStorage = new SystemConfigStorage();
        $enrolled = new EnrolledUsers();
        $adminTokens = new AdminTokenStorage(
            new ConfigCrypto(new KeyStore(Paths::adminKeyFile()))
        );
        $zoneIndex = new ZoneIndex(Paths::zoneIndexFile());
        $zoneIndexer = new IndexZones($adminTokens, $zoneIndex, $this->log);

        $sysCache = $systemStorage->load();
        $usersCache = $enrolled->all();
        $cacheUntil = time() + self::CONFIG_RELOAD_SECONDS;

        // Refresh the zone index on the very first iteration so a fresh
        // install starts with an accurate picture; then on the slow timer.
        $zoneIndexNextRun = 0;

        while (!$this->isStopRequested()) {
            $now = time();
            if ($now >= $cacheUntil) {
                $sysCache = $systemStorage->load();
                $usersCache = $enrolled->all();
                $cacheUntil = $now + self::CONFIG_RELOAD_SECONDS;
            }
            if ($now >= $zoneIndexNextRun) {
                $zoneIndexer->runOnce();
                $zoneIndexNextRun = $now + self::ZONE_INDEX_REFRESH_SECONDS;
            }

            $perCallSleepUs = $this->perCallSleepMicroseconds($sysCache['rate_limit_rps']);
            $didWork = false;

            foreach ($usersCache as $user) {
                if ($this->isStopRequested()) {
                    break;
                }
                // Each user has their own master.key under
                // ~user/.zonemirror/ so one user's compromise cannot
                // decrypt another user's CF token. The daemon runs as
                // root and can read every per-user key.
                $userStorage = new UserConfigStorage(
                    new ConfigCrypto(new KeyStore(Paths::userKeyFile($user)))
                );
                $userCfg = $userStorage->load($user);
                $enabledZones = array_values(array_filter(
                    $userCfg['zones'],
                    static fn (array $z): bool => $z['enabled'] && $z['zone_id'] !== ''
                ));
                if ($enabledZones === []) {
                    continue;
                }

                // Per-zone setup: resolve the token, watch mtime, flip
                // pending_diff if the BIND file moved out from under
                // us, then compute the diff if needed. Done before the
                // queue drain so a single tick can both produce the
                // diff and start applying it.
                $zoneTokens = [];
                foreach ($enabledZones as $zone) {
                    $plainToken = $this->resolveTokenFor(
                        $user, $userCfg, $zone, $adminTokens, $zoneIndex,
                    );
                    if ($plainToken === '') {
                        continue;
                    }
                    $zoneTokens[$zone['zone_id']] = $plainToken;

                    if ($this->zoneFileChangedSinceLastSeen($user, $zone['zone_id'], $zone['zone_name'])) {
                        if (
                            $zone['sync_state'] !== UserConfigStorage::STATE_PENDING_DIFF
                            && $zone['sync_state'] !== UserConfigStorage::STATE_COMPUTING_DIFF
                        ) {
                            $this->log->info('worker: zone file changed, forcing diff', [
                                'user' => $user,
                                'zone' => $zone['zone_name'],
                            ]);
                            $this->writeZoneState($userStorage, $user, $zone['zone_id'], UserConfigStorage::STATE_PENDING_DIFF);
                            $userCfg = $userStorage->load($user);
                            $zone = UserConfigStorage::findZone($userCfg, $zone['zone_id']) ?? $zone;
                        }
                    }

                    if ($zone['sync_state'] === UserConfigStorage::STATE_PENDING_DIFF) {
                        $this->runDiff($user, $zone, $plainToken, $userStorage);
                        $didWork = true;
                        $userCfg = $userStorage->load($user);
                        $refreshed = UserConfigStorage::findZone($userCfg, $zone['zone_id']);
                        if ($refreshed !== null) {
                            $this->autoApplyAcmeChallenges($user, $refreshed);
                        }
                    }
                }

                // Queue drain: events carry their own zone_id. We
                // build per-zone CloudflareApiClient + ZoneSnapshot
                // lazily as the batch encounters each zone, so a user
                // whose batch only touches one zone pays for one
                // listRecords no matter how many zones they have.
                $queue = $this->queueFor($user);
                if ($queue->depth() === 0) {
                    continue;
                }

                /** @var array<string, CloudflareApiClient> $clientByZone */
                $clientByZone = [];
                /** @var array<string, ZoneSnapshot> $snapshotByZone */
                $snapshotByZone = [];
                $processor = null;

                for ($i = 0; $i < $this->batchPerUser; $i++) {
                    if ($this->isStopRequested()) {
                        break;
                    }
                    $claim = $queue->claim();
                    if ($claim === null) {
                        break;
                    }
                    $didWork = true;
                    $zoneId = $claim['zone_id'];

                    // Legacy queue rows without a zone_id (created by
                    // pre-v2 hooks before the migrator ran) backfill
                    // to the user's single enabled zone. Multi-zone
                    // users post-migration always have a non-empty
                    // zone_id on every row.
                    if ($zoneId === '' && count($enabledZones) === 1) {
                        $zoneId = $enabledZones[0]['zone_id'];
                    }
                    if ($zoneId === '' || !isset($zoneTokens[$zoneId])) {
                        $this->log->warning('worker: queue event without a known zone, dropping', [
                            'user' => $user,
                            'event_id' => $claim['id'],
                            'zone_id' => $claim['zone_id'],
                            'domain' => $claim['domain'],
                        ]);
                        $queue->ack($claim['id']);

                        continue;
                    }

                    if (!isset($clientByZone[$zoneId])) {
                        $clientByZone[$zoneId] = new CloudflareApiClient($zoneTokens[$zoneId]);
                        try {
                            $snapshotByZone[$zoneId] = new ZoneSnapshot($clientByZone[$zoneId]->listRecords($zoneId));
                        } catch (\Throwable $e) {
                            $this->log->warning('worker: snapshot failed; deferring batch', [
                                'user' => $user,
                                'zone_id' => $zoneId,
                                'msg' => $e->getMessage(),
                            ]);
                            $queue->fail($claim['id'], $claim['attempts'], $e->getMessage());

                            continue;
                        }
                    }

                    if ($processor === null) {
                        $processor = new ProcessEvent($clientByZone[$zoneId], $this->log, dryRun: $sysCache['dry_run']);
                    } else {
                        // The processor is mostly stateless across
                        // events but it's tied to a CloudflareApiClient
                        // — for the typical case (all events of a tick
                        // hit the same zone), one processor is fine;
                        // for a multi-zone batch we rebuild it per
                        // zone. Cheap; no I/O.
                        $processor = new ProcessEvent($clientByZone[$zoneId], $this->log, dryRun: $sysCache['dry_run']);
                    }

                    try {
                        $processor->handle(
                            $zoneId,
                            $claim['action'],
                            $claim['record'],
                            $snapshotByZone[$zoneId],
                            $claim['target_cloudflare_id'],
                        );
                        $queue->ack($claim['id']);
                    } catch (CloudflareException $e) {
                        $this->log->warning('cloudflare error', [
                            'user' => $user,
                            'zone_id' => $zoneId,
                            'status' => $e->httpStatus,
                            'retryable' => $e->retryable,
                            'retry_after' => $e->retryAfterSeconds,
                            'msg' => $e->getMessage(),
                        ]);
                        if ($e->retryable) {
                            $queue->fail($claim['id'], $claim['attempts'], $e->getMessage(), $e->retryAfterSeconds);
                        } else {
                            $queue->fail($claim['id'], 999, $e->getMessage());
                        }
                    } catch (\Throwable $e) {
                        $this->log->error('worker error', [
                            'user' => $user,
                            'zone_id' => $zoneId,
                            'msg' => $e->getMessage(),
                        ]);
                        $queue->fail($claim['id'], $claim['attempts'], $e->getMessage());
                    }
                    if ($perCallSleepUs > 0) {
                        usleep($perCallSleepUs);
                    }
                }
            }

            if (!$didWork) {
                $this->sleep($this->sleepSeconds);
            }
        }

        $this->log->info('worker stopped');

        return 0;
    }

    /**
     * Resolve the plaintext Cloudflare token to use for $zone:
     *   - source=user: the user pasted their own token (v0.1 flow).
     *     The plaintext lives on the loaded UserConfig at the user
     *     level (one token can back any number of `user` zones).
     *   - source=admin: no per-user token; we look the zone up in the
     *     admin zone-index, identify the admin token that owns it,
     *     and decrypt that token (root can read every admin
     *     master.key by design).
     *
     * Returns '' when the zone can't be authenticated against CF (e.g.
     * the admin token that used to cover it has been removed in WHM).
     *
     * @param array{token: string, zones: list<array{zone_id: string, zone_name: string, enabled: bool, defaults: array{proxied: bool}, source: string, sync_state: string, last_error: string}>} $userCfg
     * @param array{zone_id: string, zone_name: string, enabled: bool, defaults: array{proxied: bool}, source: string, sync_state: string, last_error: string} $zone
     */
    private function resolveTokenFor(
        string $user,
        array $userCfg,
        array $zone,
        AdminTokenStorage $adminTokens,
        ZoneIndex $zoneIndex,
    ): string {
        if ($zone['source'] === UserConfigStorage::SOURCE_USER) {
            return $userCfg['token'];
        }
        $hit = $zoneIndex->findByDomain($zone['zone_name']);
        if ($hit === null) {
            $this->log->info('worker: admin zone no longer covered, skipping', [
                'user' => $user,
                'zone' => $zone['zone_name'],
            ]);

            return '';
        }
        $plain = $adminTokens->plaintextFor($hit['admin_token_id']) ?? '';
        if ($plain === '') {
            $this->log->warning('worker: admin token undecryptable, skipping', [
                'user' => $user,
                'admin_token_id' => $hit['admin_token_id'],
            ]);
        }

        return $plain;
    }

    private function queueFor(string $user): SqliteQueue
    {
        if (!isset($this->queueCache[$user])) {
            $this->queueCache[$user] = new SqliteQueue($user);
        }

        return $this->queueCache[$user];
    }

    /**
     * True when /var/named/<zone>.db has been touched since the last
     * time we saw it for this (user, zone). The first observation in
     * a daemon's lifetime never counts as a change — otherwise every
     * startup would force a full recompute for every enrolled zone.
     */
    private function zoneFileChangedSinceLastSeen(string $user, string $zoneId, string $zoneName): bool
    {
        if ($zoneName === '' || $zoneId === '') {
            return false;
        }
        $path = '/var/named/' . $zoneName . '.db';
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return false;
        }
        $key = $user . ':' . $zoneId;
        $previous = $this->zoneMtime[$key] ?? null;
        $this->zoneMtime[$key] = $mtime;
        if ($previous === null) {
            return false;
        }

        return $mtime > $previous;
    }

    /**
     * Auto-apply rule for ACME DCV TXTs (_acme-challenge.* TXT records).
     * cPanel writes them straight to the zone file via
     * Cpanel::DnsUtils::Install, so no UAPI/Api2 hook ever fires, and
     * they live for only the few seconds Let's Encrypt's validator
     * needs to query them. The "review-first" UX that protects normal
     * records would race the cert request and lose; ACME challenges
     * are safe to auto-sync because they're owned by cPanel end-to-
     * end, opaque tokens, and impossible to mistake for anything a
     * human would want to keep.
     *
     * @param array{zone_id: string, zone_name: string, enabled: bool, defaults: array{proxied: bool}, source: string, sync_state: string, last_error: string} $zone
     */
    private function autoApplyAcmeChallenges(string $user, array $zone): void
    {
        if (!$zone['enabled']) {
            return;
        }
        $diff = (new DiffStorage())->load($user, $zone['zone_id']);
        if (!is_array($diff)) {
            return;
        }
        $entries = is_array($diff['entries'] ?? null) ? $diff['entries'] : [];
        if ($entries === []) {
            return;
        }

        $queue = $this->queueFor($user);
        $now = time();
        $pushed = 0;
        $deleted = 0;
        $lockedSkipped = 0;
        $locks = (new LockStorage())->all($user, $zone['zone_id']);

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = strtoupper((string) ($entry['type'] ?? ''));
            $name = strtolower((string) ($entry['name'] ?? ''));
            if ($type !== 'TXT') {
                continue;
            }
            if (!$this->isAcmeChallengeName($name)) {
                continue;
            }
            $status = (string) ($entry['status'] ?? '');
            $key = (string) ($entry['key'] ?? '');
            if ($key === '') {
                continue;
            }
            // The lock gate also covers ACME challenges. It would be
            // exotic for a human to lock an opaque DCV token, but a
            // coarse SCOPE_ZONE or SCOPE_SUBTREE lock can easily catch
            // one by accident — the contract is "lock means do not
            // touch", no exceptions.
            if (LockStorage::entryMatchesAny($locks, $entry)) {
                $lockedSkipped++;

                continue;
            }

            if ($status === DnsDiff::STATUS_CPANEL_ONLY || $status === DnsDiff::STATUS_DIFFERENT) {
                $local = is_array($entry['local'] ?? null) ? $entry['local'] : null;
                if ($local === null) {
                    continue;
                }
                $record = $this->hydrateDnsRecord($local);
                if ($record === null) {
                    continue;
                }
                $targetId = null;
                if (is_array($entry['remote'] ?? null) && isset($entry['remote']['id'])) {
                    $candidate = (string) $entry['remote']['id'];
                    $targetId = $candidate !== '' ? $candidate : null;
                }
                $queue->enqueue(new DnsEvent(
                    domain: $zone['zone_name'],
                    action: EventAction::Upsert,
                    record: $record,
                    idempotencyKey: 'acme:' . $now . ':push:' . $key,
                    createdAt: $now,
                    targetCloudflareId: $targetId,
                    zoneId: $zone['zone_id'],
                ));
                $pushed++;
            } elseif ($status === DnsDiff::STATUS_CLOUDFLARE_ONLY) {
                $remote = is_array($entry['remote'] ?? null) ? $entry['remote'] : null;
                if ($remote === null) {
                    continue;
                }
                $remoteId = (string) ($remote['id'] ?? '');
                if ($remoteId === '') {
                    continue;
                }
                $placeholder = new DnsRecord(
                    type: RecordType::TXT,
                    name: (string) ($remote['name'] ?? $name),
                    content: isset($remote['content']) ? (string) $remote['content'] : null,
                    ttl: (int) ($remote['ttl'] ?? 60),
                    priority: null,
                    proxied: null,
                    data: [],
                );
                $queue->enqueue(new DnsEvent(
                    domain: $zone['zone_name'],
                    action: EventAction::Delete,
                    record: $placeholder,
                    idempotencyKey: 'acme:' . $now . ':del:' . $key,
                    createdAt: $now,
                    targetCloudflareId: $remoteId,
                    zoneId: $zone['zone_id'],
                ));
                $deleted++;
            }
        }

        if ($pushed > 0 || $deleted > 0 || $lockedSkipped > 0) {
            $this->log->info('auto-applying ACME DCV records', [
                'user'           => $user,
                'zone'           => $zone['zone_name'],
                'pushed'         => $pushed,
                'deleted'        => $deleted,
                'locked_skipped' => $lockedSkipped,
            ]);
        }
    }

    private function isAcmeChallengeName(string $name): bool
    {
        $n = strtolower(rtrim($name, '.'));

        return $n === '_acme-challenge'
            || str_starts_with($n, '_acme-challenge.');
    }

    /**
     * Build a DnsRecord from a diff entry's `local` block (the same
     * shape the apply UI hydrates from). Returns null when the type
     * is unknown or required fields are missing.
     *
     * @param array<string, mixed> $local
     */
    private function hydrateDnsRecord(array $local): ?DnsRecord
    {
        $type = RecordType::tryFromString(isset($local['type']) ? (string) $local['type'] : null);
        if ($type === null) {
            return null;
        }
        $name = (string) ($local['name'] ?? '');
        if ($name === '') {
            return null;
        }

        return new DnsRecord(
            type: $type,
            name: $name,
            content: isset($local['content']) ? (string) $local['content'] : null,
            ttl: (int) ($local['ttl'] ?? 1),
            priority: isset($local['priority']) ? (int) $local['priority'] : null,
            proxied: array_key_exists('proxied', $local) ? (bool) $local['proxied'] : null,
            data: is_array($local['data'] ?? null) ? $local['data'] : [],
        );
    }

    /**
     * Compute one diff pass for $zone and persist the result. Failures
     * mark the zone `failed` (with `last_error`) rather than leaving
     * it `pending`, so we don't burn API quota retrying a broken setup
     * every cycle; the user can press Refresh to retry from the cPanel
     * UI.
     *
     * @param array{zone_id: string, zone_name: string, enabled: bool, defaults: array{proxied: bool}, source: string, sync_state: string, last_error: string} $zone
     */
    private function runDiff(string $user, array $zone, string $plainToken, UserConfigStorage $storage): void
    {
        $this->writeZoneState($storage, $user, $zone['zone_id'], UserConfigStorage::STATE_COMPUTING_DIFF);

        try {
            $diff = (new ComputeDiff())->compute(
                zoneName: $zone['zone_name'],
                zoneId: $zone['zone_id'],
                cloudflareToken: $plainToken,
                log: $this->log,
            );
            (new DiffStorage())->save($user, $zone['zone_id'], $diff);
            $this->writeZoneState($storage, $user, $zone['zone_id'], UserConfigStorage::STATE_AWAITING_REVIEW);
            $this->log->info('diff: ready for review', [
                'user' => $user,
                'zone' => $zone['zone_name'],
                'summary' => $diff->summary(),
            ]);
        } catch (\Throwable $e) {
            $this->writeZoneState(
                $storage,
                $user,
                $zone['zone_id'],
                UserConfigStorage::STATE_FAILED,
                $e->getMessage(),
            );
            $this->log->error('diff: failed', [
                'user' => $user,
                'zone' => $zone['zone_name'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reload-mutate-save for a single zone's sync_state. Re-reads the
     * config first so a concurrent UI write (a Refresh click that
     * landed at the same tick) doesn't get clobbered by the daemon's
     * stale view.
     */
    private function writeZoneState(
        UserConfigStorage $storage,
        string $user,
        string $zoneId,
        string $newState,
        string $errorMessage = '',
    ): void {
        $cfg = $storage->load($user);
        $entry = UserConfigStorage::findZone($cfg, $zoneId);
        if ($entry === null) {
            return;
        }
        $entry['sync_state'] = $newState;
        $entry['last_error'] = $newState === UserConfigStorage::STATE_FAILED ? $errorMessage : '';
        $cfg = UserConfigStorage::upsertZone($cfg, $entry);
        $storage->save($user, $cfg);
    }

    private function perCallSleepMicroseconds(int $rps): int
    {
        if ($rps <= 0) {
            return 0;
        }

        return (int) floor(1_000_000 / $rps);
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stop = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->stop = true;
        });
    }

    private function sleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds; $i++) {
            if ($this->isStopRequested()) {
                return;
            }
            sleep(1);
        }
    }

    /**
     * Explicitly dispatch any queued signals before reading the
     * stop flag. pcntl_async_signals(true) installed in
     * installSignalHandlers() makes signal delivery asynchronous,
     * so this is belt-and-suspenders — but it also tells the
     * runtime to give a SIGTERM that arrived during the previous
     * statement a chance to flip $this->stop before this read.
     * The @phpstan-impure tag is required because pcntl_signal_dispatch
     * doesn't count as a side effect to phpstan; without it phpstan
     * would memoise the first call's return value and silently
     * disable every `if ($this->isStopRequested())` early-exit check.
     *
     * @phpstan-impure
     */
    private function isStopRequested(): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $this->stop;
    }
}
