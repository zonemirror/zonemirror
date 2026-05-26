<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Queue;

use PDO;
use RuntimeException;
use ZoneMirror\Domain\DnsEvent;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Storage\Paths;

/**
 * Per-user SQLite event queue. WAL journaling enables a concurrent reader
 * (worker, runs as root) and writer (hook, runs as the cPanel user) on the
 * same file. Claim is wrapped in an immediate-mode transaction (SQLite lacks
 * SELECT FOR UPDATE) so two worker invocations can never grab the same row.
 *
 * Dead-lettered events stay in the same table with a `dead_at` timestamp so
 * the WHM admin UI can inspect failures without joining a second table.
 */
final class SqliteQueue
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $user,
        private readonly BackoffPolicy $backoff = new BackoffPolicy(),
    ) {
    }

    public function enqueue(DnsEvent $event): void
    {
        $pdo = $this->pdo();
        // The target_cloudflare_id is shoved into the same JSON blob as
        // the record payload (under the reserved `_cf_id` key) to avoid
        // a schema migration on an existing 0600 SQLite that the cPanel
        // user owns. Daemon and queue both stay backwards-compatible
        // with rows that don't carry the key.
        $payload = $event->record->toCloudflarePayload();
        if ($event->targetCloudflareId !== null) {
            $payload['_cf_id'] = $event->targetCloudflareId;
        }
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO events
             (idempotency_key, domain, action, record_json, attempts, next_run_at, created_at)
             VALUES (?, ?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([
            $event->idempotencyKey,
            $event->domain,
            $event->action->value,
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            time(),
            $event->createdAt,
        ]);
    }

    /**
     * Atomically claim the next ready event. Returns null when the queue is
     * idle. Caller is responsible for calling ack() or fail() afterwards.
     *
     * @return array{id: int, domain: string, action: EventAction, record: DnsRecord, attempts: int, idempotency_key: string, target_cloudflare_id: ?string}|null
     */
    public function claim(int $visibilityTimeoutSeconds = 120): ?array
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $now = time();
            $stmt = $pdo->prepare(
                'SELECT id, idempotency_key, domain, action, record_json, attempts
                 FROM events
                 WHERE dead_at IS NULL AND next_run_at <= ?
                 ORDER BY id ASC
                 LIMIT 1'
            );
            $stmt->execute([$now]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false || $row === null) {
                $pdo->commit();

                return null;
            }

            $update = $pdo->prepare('UPDATE events SET next_run_at = ? WHERE id = ?');
            $update->execute([$now + $visibilityTimeoutSeconds, $row['id']]);
            $pdo->commit();

            $decoded = json_decode((string) $row['record_json'], true);
            /** @var array<string, mixed> $payload */
            $payload = is_array($decoded) ? $decoded : [];

            $targetId = null;
            if (isset($payload['_cf_id']) && is_string($payload['_cf_id'])) {
                $targetId = $payload['_cf_id'];
                unset($payload['_cf_id']);
            }

            return [
                'id' => (int) $row['id'],
                'domain' => (string) $row['domain'],
                'action' => EventAction::from((string) $row['action']),
                'record' => $this->hydrateRecord($payload),
                'attempts' => (int) $row['attempts'],
                'idempotency_key' => (string) $row['idempotency_key'],
                'target_cloudflare_id' => $targetId,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function ack(int $id): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM events WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function fail(int $id, int $attempts, string $reason, ?int $retryAfterSeconds = null): void
    {
        $newAttempts = $attempts + 1;
        if ($this->backoff->shouldDeadLetter($newAttempts)) {
            $stmt = $this->pdo()->prepare(
                'UPDATE events SET attempts = ?, dead_at = ?, last_error = ? WHERE id = ?'
            );
            $stmt->execute([$newAttempts, time(), $this->truncate($reason), $id]);

            return;
        }
        // Honor server-supplied Retry-After when it asks for a longer wait
        // than our exponential backoff would (Cloudflare rate-limit response).
        $backoffDelay = $this->backoff->nextDelaySeconds($newAttempts);
        $delay = $retryAfterSeconds !== null ? max($backoffDelay, $retryAfterSeconds) : $backoffDelay;
        $stmt = $this->pdo()->prepare(
            'UPDATE events SET attempts = ?, next_run_at = ?, last_error = ? WHERE id = ?'
        );
        $stmt->execute([$newAttempts, time() + $delay, $this->truncate($reason), $id]);
    }

    public function depth(): int
    {
        $stmt = $this->pdo()->query('SELECT COUNT(*) AS n FROM events WHERE dead_at IS NULL');
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        return (int) ($row['n'] ?? 0);
    }

    public function deadLetterCount(): int
    {
        $stmt = $this->pdo()->query('SELECT COUNT(*) AS n FROM events WHERE dead_at IS NOT NULL');
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        return (int) ($row['n'] ?? 0);
    }

    /**
     * Idempotency keys of every pending (not dead-lettered) event, oldest
     * first. The UI uses these to map "still-in-flight" events back to the
     * cards the user just submitted so it can show per-row applying/done
     * state without exposing the full record payload.
     *
     * Capped at 500 to keep the JSON poll response bounded; a UI batch
     * larger than that will simply not get per-card precision (the
     * aggregate progress bar still works).
     *
     * @return list<string>
     */
    public function pendingKeys(int $limit = 500): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT idempotency_key
             FROM events
             WHERE dead_at IS NULL
             ORDER BY id ASC
             LIMIT ?'
        );
        $stmt->execute([$limit]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = (string) ($row['idempotency_key'] ?? '');
        }

        return $out;
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $path = Paths::userQueueFile($this->user);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create queue dir: ' . $dir);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->query('PRAGMA journal_mode = WAL');
        $pdo->query('PRAGMA synchronous = NORMAL');
        $pdo->query('PRAGMA busy_timeout = 5000');
        $this->migrate($pdo);
        @chmod($path, 0600);

        $this->pdo = $pdo;

        return $pdo;
    }

    private function migrate(PDO $pdo): void
    {
        $pdo->query(
            'CREATE TABLE IF NOT EXISTS events(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                idempotency_key TEXT NOT NULL UNIQUE,
                domain TEXT NOT NULL,
                action TEXT NOT NULL,
                record_json TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                next_run_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                dead_at INTEGER,
                last_error TEXT
            )'
        );
        $pdo->query('CREATE INDEX IF NOT EXISTS idx_events_ready ON events(next_run_at) WHERE dead_at IS NULL');
        $pdo->query('CREATE INDEX IF NOT EXISTS idx_events_dead ON events(dead_at)');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateRecord(array $payload): DnsRecord
    {
        $type = RecordType::tryFromString(isset($payload['type']) ? (string) $payload['type'] : null);

        return new DnsRecord(
            type: $type ?? RecordType::TXT,
            name: (string) ($payload['name'] ?? ''),
            content: isset($payload['content']) ? (string) $payload['content'] : null,
            ttl: (int) ($payload['ttl'] ?? 300),
            priority: isset($payload['priority']) ? (int) $payload['priority'] : null,
            proxied: array_key_exists('proxied', $payload) ? (bool) $payload['proxied'] : null,
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
        );
    }

    private function truncate(string $reason): string
    {
        return mb_substr($reason, 0, 500);
    }
}
