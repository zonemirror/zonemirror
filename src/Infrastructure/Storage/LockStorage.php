<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

/**
 * Per-user persistence for "record locks" — opt-in immutability markers
 * that tell ZoneMirror never to push or delete on Cloudflare for the
 * matching slice of records. A diff entry is "locked" when ANY lock in
 * the user's table matches it; the apply path drops every locked entry
 * from push_keys / delete_keys before the queue ever sees them.
 *
 * Locks come in five scopes, listed from broadest to narrowest:
 *
 *   - SCOPE_ZONE       — every record of the connected zone.
 *   - SCOPE_SUBTREE    — a name plus everything underneath it
 *                        (lock "foo.example.com" → also locks
 *                         "bar.foo.example.com", "_dmarc.foo…", etc).
 *   - SCOPE_NAME       — one exact FQDN, any record type.
 *   - SCOPE_TYPE_NAME  — one (type, name) pair (e.g. all TXTs at
 *                        _dmarc.example.com).
 *   - SCOPE_EXACT      — one specific record value (e.g. the MX
 *                        whose target is aspmx.l.google.com — useful
 *                        when several MX share an apex and the user
 *                        wants to lock one of them individually).
 *
 * Identity is a legible string so the JSON file is easy to read and
 * the URL/AJAX path can carry it as opaque-but-not-secret:
 *
 *   zone:
 *   subtree:foo.example.com
 *   name:example.com
 *   type_name:TXT:_dmarc.example.com
 *   exact:MX:example.com:10|aspmx.l.google.com
 *
 * File layout — JSON, owned by the cPanel user, plaintext (locks are
 * metadata, not secrets), atomic tmp+rename writes so a daemon racing
 * a UI write never sees a half-flushed file. One file per (user, zone)
 * so two zones never share a lock table:
 *
 *   ~user/.zonemirror/zones/<zone_id>/locks.json
 *
 *   {
 *     "version": 2,
 *     "locks": {
 *       "<lock_id>": {
 *         "scope": "type_name",
 *         "type": "TXT",
 *         "name": "_dmarc.example.com",
 *         "content": null,
 *         "priority": null,
 *         "reason": "managed by the Postmaster",
 *         "created_at": 1779800000
 *       },
 *       ...
 *     }
 *   }
 *
 * Reads transparently upgrade v1 files (which only carried type_name
 * scope under an opaque hash) so existing locks survive an upgrade.
 * They also fall back to the legacy single-file path
 * (`~user/.zonemirror/locks.json`) when the zone-specific file is
 * absent — only matters between the v2 deploy and the migrator
 * running.
 */
final class LockStorage
{
    public const SCOPE_ZONE      = 'zone';
    public const SCOPE_SUBTREE   = 'subtree';
    public const SCOPE_NAME      = 'name';
    public const SCOPE_TYPE_NAME = 'type_name';
    public const SCOPE_EXACT     = 'exact';

    public const SCOPES = [
        self::SCOPE_ZONE,
        self::SCOPE_SUBTREE,
        self::SCOPE_NAME,
        self::SCOPE_TYPE_NAME,
        self::SCOPE_EXACT,
    ];

    private const VERSION = 2;

    /**
     * @return array<string, array{scope: string, type: string, name: string, content: ?string, priority: ?int, reason: string, created_at: int}>
     */
    public function all(string $user, string $zoneId): array
    {
        return $this->loadRaw($user, $zoneId)['locks'];
    }

    public function isLockedById(string $user, string $zoneId, string $lockId): bool
    {
        return array_key_exists($lockId, $this->all($user, $zoneId));
    }

    /**
     * True if any lock in the user's table matches the given diff entry.
     * Cheap: the table is expected to stay small (<100 locks per user
     * in practice) and we don't allocate beyond a single string pass
     * per lock.
     *
     * @param array<string, array{scope: string, type: string, name: string, content: ?string, priority: ?int, reason: string, created_at: int}> $locks
     * @param array<string, mixed> $entry
     */
    public static function entryMatchesAny(array $locks, array $entry): bool
    {
        foreach ($locks as $lock) {
            if (self::entryMatches($lock, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{scope: string, type: string, name: string, content: ?string, priority: ?int, reason: string, created_at: int} $lock
     * @param array<string, mixed> $entry
     */
    public static function entryMatches(array $lock, array $entry): bool
    {
        $entryType = strtoupper((string) ($entry['type'] ?? ''));
        $entryName = strtolower(rtrim((string) ($entry['name'] ?? ''), '.'));
        $lockType  = strtoupper((string) ($lock['type'] ?? ''));
        $lockName  = strtolower(rtrim((string) ($lock['name'] ?? ''), '.'));

        switch ($lock['scope'] ?? '') {
            case self::SCOPE_ZONE:
                return true;

            case self::SCOPE_SUBTREE:
                if ($lockName === '') {
                    return false;
                }

                return $entryName === $lockName
                    || str_ends_with($entryName, '.' . $lockName);

            case self::SCOPE_NAME:
                return $entryName === $lockName;

            case self::SCOPE_TYPE_NAME:
                return $entryType === $lockType && $entryName === $lockName;

            case self::SCOPE_EXACT:
                if ($entryType !== $lockType || $entryName !== $lockName) {
                    return false;
                }
                // For EXACT we compare the lock's recorded content
                // against EITHER side of the diff — local or remote —
                // because the user clicks "lock this row" while looking
                // at one of them and we want the lock to keep matching
                // even after the other side mutates.
                $lockContent = (string) ($lock['content'] ?? '');
                $local       = is_array($entry['local']  ?? null) ? $entry['local'] : null;
                $remote      = is_array($entry['remote'] ?? null) ? $entry['remote'] : null;
                foreach ([$local, $remote] as $side) {
                    if (!is_array($side)) {
                        continue;
                    }
                    if (((string) ($side['content'] ?? '')) === $lockContent) {
                        // Optional priority discriminator for MX where two
                        // rows can share (type, name, content) but live
                        // at different preferences.
                        if ($lock['priority'] === null) {
                            return true;
                        }
                        if ((int) ($side['priority'] ?? -1) === (int) $lock['priority']) {
                            return true;
                        }
                    }
                }

                return false;

            default:
                return false;
        }
    }

    public static function lockIdFor(string $scope, string $type = '', string $name = '', ?string $content = null, ?int $priority = null): string
    {
        $type = strtoupper($type);
        $name = strtolower(rtrim($name, '.'));
        switch ($scope) {
            case self::SCOPE_ZONE:
                return 'zone:';

            case self::SCOPE_SUBTREE:
                return 'subtree:' . $name;

            case self::SCOPE_NAME:
                return 'name:' . $name;

            case self::SCOPE_TYPE_NAME:
                return 'type_name:' . $type . ':' . $name;

            case self::SCOPE_EXACT:
                $disc = ($priority === null ? '' : (string) $priority) . '|' . (string) $content;

                return 'exact:' . $type . ':' . $name . ':' . $disc;

            default:
                return '';
        }
    }

    /**
     * Convenience helper used by the UI's "lock this row" affordance:
     * given a diff entry, produce the lock that protects it. Defaults
     * to scope=type_name (the friendliest behaviour for a casual user
     * who just clicked the padlock once); the explicit add() method
     * exposes the full scope set for the Manage Locks panel.
     *
     * @param array<string, mixed> $entry
     */
    public static function lockIdForEntry(array $entry, string $scope = self::SCOPE_TYPE_NAME): string
    {
        $type = (string) ($entry['type'] ?? '');
        $name = (string) ($entry['name'] ?? '');
        $local  = is_array($entry['local']  ?? null) ? $entry['local'] : null;
        $remote = is_array($entry['remote'] ?? null) ? $entry['remote'] : null;
        $source = $local ?? $remote ?? [];
        $content = isset($source['content']) ? (string) $source['content'] : null;
        $priority = isset($source['priority']) ? (int) $source['priority'] : null;

        return self::lockIdFor($scope, $type, $name, $content, $priority);
    }

    /**
     * Add (or replace, idempotent) a lock. Returns the lock id so the
     * caller can echo it back to the front-end without re-deriving it.
     *
     * @throws \InvalidArgumentException on a scope that doesn't satisfy
     *                                   its required criteria (e.g. SUBTREE
     *                                   with no name).
     */
    public function add(
        string $user,
        string $zoneId,
        string $scope,
        string $type = '',
        string $name = '',
        ?string $content = null,
        ?int $priority = null,
        string $reason = '',
    ): string {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('unknown lock scope: ' . $scope);
        }
        $type = strtoupper($type);
        $name = strtolower(rtrim($name, '.'));

        switch ($scope) {
            case self::SCOPE_ZONE:
                // No criteria needed.
                break;
            case self::SCOPE_SUBTREE:
            case self::SCOPE_NAME:
                if ($name === '') {
                    throw new \InvalidArgumentException($scope . ' lock requires a name');
                }

                break;
            case self::SCOPE_TYPE_NAME:
                if ($type === '' || $name === '') {
                    throw new \InvalidArgumentException('type_name lock requires type and name');
                }

                break;
            case self::SCOPE_EXACT:
                if ($type === '' || $name === '' || $content === null || $content === '') {
                    throw new \InvalidArgumentException('exact lock requires type, name and content');
                }

                break;
        }

        $id = self::lockIdFor($scope, $type, $name, $content, $priority);
        $current = $this->loadRaw($user, $zoneId);
        $current['locks'][$id] = [
            'scope'      => $scope,
            'type'       => $type,
            'name'       => $name,
            'content'    => $content,
            'priority'   => $priority,
            'reason'     => $reason,
            'created_at' => time(),
        ];
        $this->saveRaw($user, $zoneId, $current);

        return $id;
    }

    public function remove(string $user, string $zoneId, string $lockId): bool
    {
        $current = $this->loadRaw($user, $zoneId);
        if (!isset($current['locks'][$lockId])) {
            return false;
        }
        unset($current['locks'][$lockId]);
        $this->saveRaw($user, $zoneId, $current);

        return true;
    }

    /**
     * @return array{version: int, locks: array<string, array{scope: string, type: string, name: string, content: ?string, priority: ?int, reason: string, created_at: int}>}
     */
    private function loadRaw(string $user, string $zoneId): array
    {
        $path = Paths::userLocksFile($user, $zoneId);
        if (!is_file($path)) {
            // Legacy fallback: the pre-v2 single-file path. Only useful
            // between the deploy and the migrator running; once that's
            // done, every read hits the zone-specific path.
            $legacy = Paths::userLocksFile($user);
            if (!is_file($legacy)) {
                return ['version' => self::VERSION, 'locks' => []];
            }
            $path = $legacy;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['version' => self::VERSION, 'locks' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['version' => self::VERSION, 'locks' => []];
        }
        $version = is_int($decoded['version'] ?? null) ? $decoded['version'] : 1;
        $rawLocks = is_array($decoded['locks'] ?? null) ? $decoded['locks'] : [];

        $locks = [];
        foreach ($rawLocks as $id => $row) {
            if (!is_string($id) || !is_array($row)) {
                continue;
            }
            $scope = isset($row['scope']) && is_string($row['scope']) ? $row['scope'] : '';
            $type  = (string) ($row['type'] ?? '');
            $name  = (string) ($row['name'] ?? '');

            // v1 migration: id was the literal "TYPE:NAME". If we
            // recognise that shape and there's no scope field, promote
            // it to a type_name lock. The id gets rewritten on the next
            // save() — until then, the v1 string keeps working as a key
            // because lockIdFor(SCOPE_TYPE_NAME, ...) produces
            // "type_name:TYPE:NAME" which is a different key — so
            // re-key inside this load to avoid duplicate matches.
            if ($scope === '' && $type !== '' && $name !== '') {
                $scope = self::SCOPE_TYPE_NAME;
                $newId = self::lockIdFor($scope, $type, $name);
                $id = $newId;
            }
            if (!in_array($scope, self::SCOPES, true)) {
                continue;
            }
            $locks[$id] = [
                'scope'      => $scope,
                'type'       => strtoupper($type),
                'name'       => strtolower(rtrim($name, '.')),
                'content'    => isset($row['content']) ? (string) $row['content'] : null,
                'priority'   => isset($row['priority']) ? (int) $row['priority'] : null,
                'reason'     => (string) ($row['reason'] ?? ''),
                'created_at' => (int) ($row['created_at'] ?? 0),
            ];
        }

        // Persist the migration so subsequent reads are cheap and the
        // file on disk reflects v2 even before anyone clicks anything.
        // The write always goes to the zone-specific path even when the
        // legacy single-file was the source — that promotes the v1 file
        // into the v2 zones/<zone_id>/ subdir in one step.
        if ($version < self::VERSION) {
            $this->saveRaw($user, $zoneId, ['version' => self::VERSION, 'locks' => $locks]);
        }

        return ['version' => self::VERSION, 'locks' => $locks];
    }

    /**
     * @param array{version: int, locks: array<string, mixed>} $data
     */
    private function saveRaw(string $user, string $zoneId, array $data): void
    {
        $path = Paths::userLocksFile($user, $zoneId);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }
}
