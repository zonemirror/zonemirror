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
 * Background daemon. Iterates the enrolled users, drains each per-user queue
 * up to a small batch per cycle, then sleeps. Two important optimizations
 * keep us inside Cloudflare's per-token rate limit (1,200 requests / 5 min)
 * even at ~50 enrolled users:
 *
 * - One full `listRecords` per user per cycle (ZoneSnapshot), reused for
 *   every event in the batch and updated in-place after each mutation.
 * - WHM-admin `rate_limit_rps` budget enforced as an inter-call sleep.
 *
 * Persistent state cached across cycles to avoid filesystem hammering:
 * - SqliteQueue per user (and the underlying PDO + WAL setup).
 * - System config + enrolled-user list with a TTL of CONFIG_RELOAD_SECONDS.
 *
 * Designed to be supervised by systemd: any uncaught error exits with status
 * 1 so systemd restarts the service rather than letting it run in a broken
 * state silently.
 */
final class WorkerLoop
{
    private const CONFIG_RELOAD_SECONDS = 30;
    private const ZONE_INDEX_REFRESH_SECONDS = 3600;

    private bool $stop = false;

    /** @var array<string, SqliteQueue> */
    private array $queueCache = [];

    /**
     * Per-user last-seen mtime of the zone file. We poll this every cycle
     * so when something outside our hookable surface area writes to
     * /var/named/<zone>.db (AutoSSL DCV's _acme-challenge TXT, scripts
     * that bypass UAPI, future cPanel features we haven't reverse-
     * engineered yet) we still notice within sleepSeconds and recompute.
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
                // Each user has their own master.key under ~user/.zonemirror/
                // so one user's compromise cannot decrypt another user's
                // Cloudflare token. The daemon runs as root and can read
                // every per-user key.
                $userStorage = new UserConfigStorage(
                    new ConfigCrypto(new KeyStore(Paths::userKeyFile($user)))
                );
                $userCfg = $userStorage->load($user);
                if (!$userCfg['enabled'] || $userCfg['zone_id'] === '') {
                    continue;
                }

                // Resolve the Cloudflare token to use for this user/domain:
                //   - source=user: the user pasted their own token (v0.1 flow).
                //   - source=admin: no per-user token; the daemon looks the
                //     zone up in the zone index, identifies the admin token
                //     that owns it, and decrypts that token (root can read
                //     every admin master.key by design).
                $plainToken = $userCfg['token'];
                if ($plainToken === '' && $userCfg['source'] === UserConfigStorage::SOURCE_ADMIN) {
                    $hit = $zoneIndex->findByDomain($userCfg['zone_name']);
                    if ($hit === null) {
                        $this->log->info('worker: admin zone no longer covered, skipping', [
                            'user' => $user,
                            'zone' => $userCfg['zone_name'],
                        ]);

                        continue;
                    }
                    $plainToken = $adminTokens->plaintextFor($hit['admin_token_id']) ?? '';
                    if ($plainToken === '') {
                        $this->log->warning('worker: admin token undecryptable, skipping', [
                            'user' => $user,
                            'admin_token_id' => $hit['admin_token_id'],
                        ]);

                        continue;
                    }
                }
                if ($plainToken === '') {
                    continue;
                }

                // Detect out-of-band writes to /var/named/<zone>.db (DCV
                // DNS-01, EmailAuth, anything that talks straight to
                // Cpanel::DnsUtils::Install without going through a UAPI
                // hook we cover). When the mtime advances we flag the
                // user as pending_diff so the runDiff below picks the new
                // state up in the same tick — the daemon doesn't have to
                // wait another cycle to notice.
                if ($this->zoneFileChangedSinceLastSeen($user, $userCfg['zone_name'])) {
                    if (
                        $userCfg['sync_state'] !== UserConfigStorage::STATE_PENDING_DIFF
                        && $userCfg['sync_state'] !== UserConfigStorage::STATE_COMPUTING_DIFF
                    ) {
                        $this->log->info('worker: zone file changed, forcing diff', [
                            'user' => $user,
                            'zone' => $userCfg['zone_name'],
                        ]);
                        $this->writeSyncState($userStorage, $user, $userCfg, UserConfigStorage::STATE_PENDING_DIFF);
                        $userCfg = $userStorage->load($user);
                    }
                }

                // Diff review: if the user just connected or asked for a
                // refresh, recompute the diff against Cloudflare before we
                // touch the queue. The UI sits on `awaiting_review` until
                // the user explicitly applies rows; we never auto-mutate —
                // except for ACME DCV TXTs, which live seconds and must
                // race their way to Cloudflare or the cert request fails.
                if ($userCfg['sync_state'] === UserConfigStorage::STATE_PENDING_DIFF) {
                    $this->runDiff($user, $userCfg, $plainToken, $userStorage);
                    $didWork = true;
                    $userCfg = $userStorage->load($user);
                    $this->autoApplyAcmeChallenges($user, $userCfg);
                }

                $queue = $this->queueFor($user);
                if ($queue->depth() === 0) {
                    continue;
                }

                $client = new CloudflareApiClient($plainToken);
                $snapshot = new ZoneSnapshot($client->listRecords($userCfg['zone_id']));
                $processor = new ProcessEvent($client, $this->log, dryRun: $sysCache['dry_run']);

                for ($i = 0; $i < $this->batchPerUser; $i++) {
                    if ($this->isStopRequested()) {
                        break;
                    }
                    $claim = $queue->claim();
                    if ($claim === null) {
                        break;
                    }
                    $didWork = true;

                    try {
                        $processor->handle(
                            $userCfg['zone_id'],
                            $claim['action'],
                            $claim['record'],
                            $snapshot,
                            $claim['target_cloudflare_id'],
                        );
                        $queue->ack($claim['id']);
                    } catch (CloudflareException $e) {
                        $this->log->warning('cloudflare error', [
                            'user' => $user,
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
                        $this->log->error('worker error', ['user' => $user, 'msg' => $e->getMessage()]);
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

    private function queueFor(string $user): SqliteQueue
    {
        if (!isset($this->queueCache[$user])) {
            $this->queueCache[$user] = new SqliteQueue($user);
        }

        return $this->queueCache[$user];
    }

    /**
     * Returns true when /var/named/<zone>.db has been touched since the
     * last time we saw it for this user. The first observation in a
     * daemon's lifetime never counts as a change — otherwise every
     * startup would force a full recompute for every enrolled user.
     */
    private function zoneFileChangedSinceLastSeen(string $user, string $zoneName): bool
    {
        if ($zoneName === '') {
            return false;
        }
        $path = '/var/named/' . $zoneName . '.db';
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return false;
        }
        $previous = $this->zoneMtime[$user] ?? null;
        $this->zoneMtime[$user] = $mtime;
        if ($previous === null) {
            return false;
        }

        return $mtime > $previous;
    }

    /**
     * Auto-apply rule for ACME DCV TXTs (_acme-challenge.* TXT records).
     * cPanel writes them straight to the zone file via
     * Cpanel::DnsUtils::Install, so no UAPI/Api2 hook ever fires, and
     * they live for only the few seconds Let's Encrypt's validator needs
     * to query them. The "review-first" UX that protects normal records
     * would race the cert request and lose; ACME challenges are safe to
     * auto-sync because they're owned by cPanel end-to-end, opaque
     * tokens, and impossible to mistake for anything a human would want
     * to keep.
     *
     * We act on the diff that runDiff just persisted: every entry whose
     * name starts with `_acme-challenge.` and is either cpanel_only or
     * cloudflare_only gets enqueued as an Upsert or Delete respectively.
     * different status (cPanel and CF both have one but the value moved)
     * gets enqueued as an Upsert too so the latest token wins.
     *
     * @param array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token: string, source: string, sync_state: string, last_error: string} $cfg
     */
    private function autoApplyAcmeChallenges(string $user, array $cfg): void
    {
        if (!$cfg['enabled']) {
            return;
        }
        $diff = (new DiffStorage())->load($user);
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
        $locks = (new LockStorage())->all($user);

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
                    domain: $cfg['zone_name'],
                    action: EventAction::Upsert,
                    record: $record,
                    idempotencyKey: 'acme:' . $now . ':push:' . $key,
                    createdAt: $now,
                    targetCloudflareId: $targetId,
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
                    domain: $cfg['zone_name'],
                    action: EventAction::Delete,
                    record: $placeholder,
                    idempotencyKey: 'acme:' . $now . ':del:' . $key,
                    createdAt: $now,
                    targetCloudflareId: $remoteId,
                ));
                $deleted++;
            }
        }

        if ($pushed > 0 || $deleted > 0 || $lockedSkipped > 0) {
            $this->log->info('auto-applying ACME DCV records', [
                'user'           => $user,
                'zone'           => $cfg['zone_name'],
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
     * Build a DnsRecord from a diff entry's `local` block (the same shape
     * the apply UI hydrates from). Returns null when the type is unknown
     * or required fields are missing.
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
     * Compute one diff pass and persist the result. Failures mark the
     * config `failed` (with `last_error`) rather than leaving it `pending`,
     * so we don't burn API quota retrying a broken setup every cycle; the
     * user can press Refresh to retry from the cPanel UI.
     *
     * @param array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token: string, source: string, sync_state: string, last_error: string} $cfg
     */
    private function runDiff(string $user, array $cfg, string $plainToken, UserConfigStorage $storage): void
    {
        $this->writeSyncState($storage, $user, $cfg, UserConfigStorage::STATE_COMPUTING_DIFF);

        try {
            $diff = (new ComputeDiff())->compute(
                zoneName: $cfg['zone_name'],
                zoneId: $cfg['zone_id'],
                cloudflareToken: $plainToken,
                log: $this->log,
            );
            (new DiffStorage())->save($user, $diff);
            $this->writeSyncState($storage, $user, $cfg, UserConfigStorage::STATE_AWAITING_REVIEW);
            $this->log->info('diff: ready for review', [
                'user' => $user,
                'zone' => $cfg['zone_name'],
                'summary' => $diff->summary(),
            ]);
        } catch (\Throwable $e) {
            $this->writeSyncState(
                $storage,
                $user,
                $cfg,
                UserConfigStorage::STATE_FAILED,
                $e->getMessage(),
            );
            $this->log->error('diff: failed', [
                'user' => $user,
                'zone' => $cfg['zone_name'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token: string, source: string, sync_state: string, last_error: string} $cfg
     */
    private function writeSyncState(
        UserConfigStorage $storage,
        string $user,
        array $cfg,
        string $newState,
        string $errorMessage = '',
    ): void {
        $storage->save($user, [
            'enabled' => $cfg['enabled'],
            'zone_id' => $cfg['zone_id'],
            'zone_name' => $cfg['zone_name'],
            'defaults' => $cfg['defaults'],
            'source' => $cfg['source'],
            'sync_state' => $newState,
            'last_error' => $errorMessage,
            // Preserve the user-pasted token if we have one. The save() method
            // re-loads the existing ciphertext when no plaintext is supplied
            // for SOURCE_USER, but being explicit costs nothing.
            'token' => $cfg['token'],
        ]);
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
     * Wrapping the volatile signal-handler flag in a function call defeats
     * static analysis loop-narrowing that would otherwise believe the flag
     * can never flip mid-loop.
     */
    private function isStopRequested(): bool
    {
        return $this->stop;
    }
}
