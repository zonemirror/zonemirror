<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

/**
 * Per-user persistence for "record locks" — opt-in immutability markers
 * a user sets on a (type, name) pair to tell ZoneMirror "never touch
 * this slot". Locks are intentionally coarse: identifying by
 * (type, name) — not content — means the lock survives renaming the
 * value (which is what the user usually wants: "I curate DMARC by
 * hand, leave it alone whatever it says"), and matches the user's
 * mental model in the UI (every card shows TYPE @ NAME, that's what
 * they pick when they click the padlock).
 *
 * A side effect of the coarse granularity: when multiple records share
 * the same (type, name) — e.g. round-robin A, or two distinct TXTs at
 * the apex — locking ONE locks them all. This is the safer default;
 * per-record locking is a future refinement if we ever need it.
 *
 * Locks gate three places:
 *
 *  - The interactive Apply path in UserController: a locked entry is
 *    silently dropped from push_keys/delete_keys before enqueueing,
 *    even if the user ticked it (defence against fat-fingering a
 *    bulk-select that swept a protected row in).
 *  - The daemon's auto-apply for ACME DCV TXTs: a locked _acme-
 *    challenge.* TXT is never pushed or deleted (unlikely to happen in
 *    practice, but the rule is symmetric).
 *  - The UI: each card knows whether it's locked and renders the
 *    padlock + "Locked" affordance instead of the apply checkbox.
 *
 * File layout — JSON, owned by the cPanel user, plaintext (locks are
 * metadata, not secrets):
 *
 *   {
 *     "version": 1,
 *     "locks": {
 *       "<lock_id>": {
 *         "type": "TXT",
 *         "name": "_dmarc.example.com",
 *         "content": "v=DMARC1; p=quarantine",
 *         "reason": "managed by the Postmaster, do not touch",
 *         "created_at": 1779800000
 *       },
 *       ...
 *     }
 *   }
 *
 * The file is created lazily on the first add(). Missing file is a
 * valid "no locks" state, never an error.
 */
final class LockStorage
{
    private const VERSION = 1;

    /**
     * @return array<string, array{type: string, name: string, content: ?string, reason: string, created_at: int}>
     */
    public function all(string $user): array
    {
        $path = Paths::userLocksFile($user);
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $locks = is_array($decoded['locks'] ?? null) ? $decoded['locks'] : [];
        $out = [];
        foreach ($locks as $id => $row) {
            if (!is_string($id) || !is_array($row)) {
                continue;
            }
            $out[$id] = [
                'type'       => (string) ($row['type'] ?? ''),
                'name'       => (string) ($row['name'] ?? ''),
                'content'    => isset($row['content']) ? (string) $row['content'] : null,
                'reason'     => (string) ($row['reason'] ?? ''),
                'created_at' => (int) ($row['created_at'] ?? 0),
            ];
        }

        return $out;
    }

    public function isLocked(string $user, string $lockId): bool
    {
        return array_key_exists($lockId, $this->all($user));
    }

    /**
     * Stable identifier for a (type, name) slot. Deterministic and human-
     * readable so the JSON file is easy to inspect; case-folded so a
     * lock on "TXT:_DMARC" matches a diff entry of "TXT:_dmarc".
     */
    public static function lockIdFor(string $type, string $name): string
    {
        return strtoupper($type) . ':' . strtolower(rtrim($name, '.'));
    }

    /**
     * Build the lock id from a diff entry. Pulls type+name from the
     * entry itself (which ComputeDiff always populates) so it works for
     * any status — different / cpanel_only / cloudflare_only / identical.
     *
     * @param array<string, mixed> $entry
     */
    public static function lockIdForEntry(array $entry): string
    {
        $local  = is_array($entry['local']  ?? null) ? $entry['local'] : null;
        $remote = is_array($entry['remote'] ?? null) ? $entry['remote'] : null;
        $source = $local ?? $remote ?? [];
        $type   = (string) ($entry['type'] ?? ($source['type'] ?? ''));
        $name   = (string) ($entry['name'] ?? ($source['name'] ?? ''));

        return self::lockIdFor($type, $name);
    }

    /**
     * Add a lock for the (type, name) slot. Idempotent — re-adding
     * updates the reason and bumps created_at so the user sees a fresh
     * timestamp. content is stored only as informational metadata (it
     * is NOT part of the lock id) so the JSON file is easier to audit.
     */
    public function add(
        string $user,
        string $type,
        string $name,
        ?string $content = null,
        string $reason = '',
    ): string {
        $id = self::lockIdFor($type, $name);
        $current = $this->loadRaw($user);
        $current['locks'][$id] = [
            'type'       => strtoupper($type),
            'name'       => rtrim($name, '.'),
            'content'    => $content,
            'reason'     => $reason,
            'created_at' => time(),
        ];
        $this->saveRaw($user, $current);

        return $id;
    }

    public function remove(string $user, string $lockId): bool
    {
        $current = $this->loadRaw($user);
        if (!isset($current['locks'][$lockId])) {
            return false;
        }
        unset($current['locks'][$lockId]);
        $this->saveRaw($user, $current);

        return true;
    }

    /**
     * @return array{version: int, locks: array<string, mixed>}
     */
    private function loadRaw(string $user): array
    {
        $path = Paths::userLocksFile($user);
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return [
                        'version' => is_int($decoded['version'] ?? null) ? $decoded['version'] : self::VERSION,
                        'locks'   => is_array($decoded['locks'] ?? null) ? $decoded['locks'] : [],
                    ];
                }
            }
        }

        return ['version' => self::VERSION, 'locks' => []];
    }

    /**
     * @param array{version: int, locks: array<string, mixed>} $data
     */
    private function saveRaw(string $user, array $data): void
    {
        $path = Paths::userLocksFile($user);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // Atomic write: tmp + rename so a daemon racing a UI write never
        // sees a half-flushed file.
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }
}
