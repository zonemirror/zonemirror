<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Worker;

use ZoneMirror\Application\ComputeDiff;
use ZoneMirror\Application\IndexZones;
use ZoneMirror\Application\ProcessEvent;
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
                    $plainToken = (string) ($adminTokens->plaintextFor($hit['admin_token_id']) ?? '');
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

                // Diff review: if the user just connected or asked for a
                // refresh, recompute the diff against Cloudflare before we
                // touch the queue. The UI sits on `awaiting_review` until
                // the user explicitly applies rows; we never auto-mutate.
                if ($userCfg['sync_state'] === UserConfigStorage::STATE_PENDING_DIFF) {
                    $this->runDiff($user, $userCfg, $plainToken, $userStorage);
                    $didWork = true;
                    // Reload to pick up the new state for the rest of this
                    // iteration (queue processing below still runs).
                    $userCfg = $userStorage->load($user);
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
                        $processor->handle($userCfg['zone_id'], $claim['action'], $claim['record'], $snapshot);
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
