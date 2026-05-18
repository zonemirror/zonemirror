<?php

declare(strict_types=1);

namespace CfSync\Interface\Worker;

use CfSync\Application\ProcessEvent;
use CfSync\Infrastructure\Cloudflare\CloudflareApiClient;
use CfSync\Infrastructure\Cloudflare\CloudflareException;
use CfSync\Infrastructure\Cloudflare\ZoneSnapshot;
use CfSync\Infrastructure\Logging\FileLogger;
use CfSync\Infrastructure\Queue\SqliteQueue;
use CfSync\Infrastructure\Storage\ConfigCrypto;
use CfSync\Infrastructure\Storage\EnrolledUsers;
use CfSync\Infrastructure\Storage\KeyStore;
use CfSync\Infrastructure\Storage\Paths;
use CfSync\Infrastructure\Storage\SystemConfigStorage;
use CfSync\Infrastructure\Storage\UserConfigStorage;

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

        $crypto = new ConfigCrypto(new KeyStore(Paths::systemKeyFile()));
        $userStorage = new UserConfigStorage($crypto);
        $systemStorage = new SystemConfigStorage();
        $enrolled = new EnrolledUsers();

        $sysCache = $systemStorage->load();
        $usersCache = $enrolled->all();
        $cacheUntil = time() + self::CONFIG_RELOAD_SECONDS;

        while (!$this->isStopRequested()) {
            $now = time();
            if ($now >= $cacheUntil) {
                $sysCache = $systemStorage->load();
                $usersCache = $enrolled->all();
                $cacheUntil = $now + self::CONFIG_RELOAD_SECONDS;
            }

            $perCallSleepUs = $this->perCallSleepMicroseconds($sysCache['rate_limit_rps']);
            $didWork = false;

            foreach ($usersCache as $user) {
                if ($this->isStopRequested()) {
                    break;
                }
                $userCfg = $userStorage->load($user);
                if (!$userCfg['enabled'] || $userCfg['token'] === '' || $userCfg['zone_id'] === '') {
                    continue;
                }

                $queue = $this->queueFor($user);
                if ($queue->depth() === 0) {
                    continue;
                }

                $client = new CloudflareApiClient($userCfg['token']);
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
