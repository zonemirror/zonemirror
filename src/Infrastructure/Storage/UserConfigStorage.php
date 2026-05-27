<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;

/**
 * Reads and writes per-cPanel-user configuration. A single cPanel user
 * holds N independent zone connections (one per cPanel domain they
 * want to sync to Cloudflare); each connection carries its own state
 * machine. The token they may have pasted (case 2 — "I want to use my
 * own CF account, not the admin's") is encrypted via ConfigCrypto and
 * applies to every zone whose `source` is `user`.
 *
 * On-disk shape (v2):
 *
 *   {
 *     "version": 2,
 *     "token_encrypted": "...base64...",            // optional
 *     "zones": [
 *       {
 *         "zone_id":   "...",
 *         "zone_name": "example.com",
 *         "enabled":   true,
 *         "defaults":  { "proxied": false },
 *         "source":    "admin" | "user",
 *         "sync_state":"idle|pending_diff|computing_diff|awaiting_review|failed",
 *         "last_error":"..."                        // only when sync_state=failed
 *       },
 *       ...
 *     ]
 *   }
 *
 * Disconnecting a zone is a *soft delete* — the entry stays in
 * `zones[]` with `enabled: false` so its diff history and locks live
 * on for a re-enable. A user is considered "active" (daemon iterates
 * them) iff at least one of their zones has `enabled: true`.
 *
 * v1 back-compat: existing single-zone configs (with top-level
 * `enabled`/`zone_id`/`zone_name`) are transparently promoted into a
 * one-item `zones[]` array on load and written back as v2 on the next
 * save. The promotion happens in memory only; the file is rewritten
 * lazily so a daemon read that doesn't subsequently save doesn't
 * spuriously rewrite the on-disk inode.
 *
 * The `sync_state` field on each zone drives the diff-review wizard:
 *   - idle: nothing pending. Either freshly disconnected or the diff
 *     has already been applied. Hooks still fire for future edits.
 *   - pending_diff: the user just connected or asked to refresh; the
 *     daemon needs to recompute the diff.
 *   - computing_diff: daemon is mid-computation. Visible-state guard
 *     against an interrupted cycle.
 *   - awaiting_review: diff.json is on disk; the cPanel UI shows the
 *     table and the user picks per-row what to apply.
 *   - failed: the most recent diff attempt threw. `last_error` carries
 *     the message for the UI to display.
 *
 * On-disk back-compat for M3.b's `initial_seed_state` field on v1
 * configs is preserved during promotion: none→idle, pending→
 * pending_diff, in_progress→computing_diff, done→idle, failed→failed.
 *
 * @phpstan-type ZoneEntry array{
 *     zone_id: string,
 *     zone_name: string,
 *     enabled: bool,
 *     defaults: array{proxied: bool},
 *     source: string,
 *     sync_state: string,
 *     last_error: string
 * }
 * @phpstan-type UserConfig array{
 *     token: string,
 *     zones: list<ZoneEntry>
 * }
 */
final class UserConfigStorage
{
    public function __construct(private readonly ConfigCrypto $crypto)
    {
    }

    public const SOURCE_USER = 'user';
    public const SOURCE_ADMIN = 'admin';

    public const STATE_IDLE = 'idle';
    public const STATE_PENDING_DIFF = 'pending_diff';
    public const STATE_COMPUTING_DIFF = 'computing_diff';
    public const STATE_AWAITING_REVIEW = 'awaiting_review';
    public const STATE_FAILED = 'failed';

    public const CURRENT_VERSION = 2;

    private const STATES = [
        self::STATE_IDLE,
        self::STATE_PENDING_DIFF,
        self::STATE_COMPUTING_DIFF,
        self::STATE_AWAITING_REVIEW,
        self::STATE_FAILED,
    ];

    /** @var array<string, string> */
    private const LEGACY_STATE_MAP = [
        'none' => self::STATE_IDLE,
        'pending' => self::STATE_PENDING_DIFF,
        'in_progress' => self::STATE_COMPUTING_DIFF,
        'done' => self::STATE_IDLE,
        'failed' => self::STATE_FAILED,
    ];

    /**
     * @return UserConfig
     */
    public function load(string $user): array
    {
        $path = Paths::userConfigFile($user);
        if (!is_file($path)) {
            return $this->empty();
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $this->empty();
        }
        /** @var array<string, mixed>|null $json */
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $this->empty();
        }

        $token = '';
        if (isset($json['token_encrypted']) && is_string($json['token_encrypted']) && $json['token_encrypted'] !== '') {
            try {
                $token = $this->crypto->decrypt($json['token_encrypted']);
            } catch (RuntimeException) {
                $token = '';
            }
        }

        $version = (int) ($json['version'] ?? 1);
        if ($version < 2) {
            $legacyZone = $this->extractLegacyZone($json);

            return [
                'token' => $token,
                'zones' => $legacyZone === null ? [] : [$legacyZone],
            ];
        }

        $zones = [];
        $rawZones = $json['zones'] ?? [];
        if (is_array($rawZones)) {
            foreach ($rawZones as $z) {
                if (!is_array($z)) {
                    continue;
                }
                $normalized = $this->normalizeZone($z);
                if ($normalized !== null) {
                    $zones[] = $normalized;
                }
            }
        }

        return [
            'token' => $token,
            'zones' => $zones,
        ];
    }

    /**
     * @param UserConfig $cfg
     */
    public function save(string $user, array $cfg): void
    {
        $dir = Paths::userDir($user);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create user dir: ' . $dir);
        }

        $payload = [
            'version' => self::CURRENT_VERSION,
            'zones' => array_map(
                fn (array $z): array => $this->encodeZone($z),
                array_values($cfg['zones'] ?? []),
            ),
        ];

        // Token ciphertext is a user-level field (a single CF token can
        // back any number of `source: user` zones). The plaintext is
        // never persisted; only the AEAD ciphertext lives on disk.
        $token = (string) ($cfg['token'] ?? '');
        if ($token !== '') {
            $payload['token_encrypted'] = $this->crypto->encrypt($token);
        }

        $path = Paths::userConfigFile($user);
        $tmp = $path . '.tmp';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to serialize user config.');
        }
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write user config.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('Unable to install user config.');
        }

        // The daemon writes this file too (e.g. when flipping a zone's
        // sync_state from pending_diff to awaiting_review). Its
        // tmp+rename leaves the inode owned by root, which the cPanel-
        // user LSPHP then can't read — the config silently falls back
        // to empty() and the UI shows "disconnected" while the user is
        // in fact connected. Chown explicitly when running as root.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0
            && function_exists('posix_getpwnam')
        ) {
            $pw = @posix_getpwnam($user);
            if (is_array($pw)) {
                @chown($path, $pw['uid']);
                @chgrp($path, $pw['gid']);
            }
        }
    }

    /**
     * Convenience accessor — return the matching zone entry by id, or
     * null if not present. Callers prefer this over hand-rolling the
     * lookup so the array shape stays in one place.
     *
     * @param UserConfig $cfg
     * @return ZoneEntry|null
     */
    public static function findZone(array $cfg, string $zoneId): ?array
    {
        foreach ($cfg['zones'] as $z) {
            if ($z['zone_id'] === $zoneId) {
                return $z;
            }
        }

        return null;
    }

    /**
     * @param UserConfig $cfg
     * @return ZoneEntry|null
     */
    public static function findZoneByName(array $cfg, string $zoneName): ?array
    {
        $needle = strtolower($zoneName);
        foreach ($cfg['zones'] as $z) {
            if (strtolower($z['zone_name']) === $needle) {
                return $z;
            }
        }

        return null;
    }

    /**
     * Replace one zone in $cfg, matched by zone_id. Adds the zone if
     * no entry with that id exists yet. Returns the new config without
     * mutating $cfg in place.
     *
     * @param UserConfig $cfg
     * @param ZoneEntry  $zone
     * @return UserConfig
     */
    public static function upsertZone(array $cfg, array $zone): array
    {
        $found = false;
        $out = $cfg;
        foreach ($out['zones'] as $i => $existing) {
            if ($existing['zone_id'] === $zone['zone_id']) {
                $out['zones'][$i] = $zone;
                $found = true;

                break;
            }
        }
        if (!$found) {
            $out['zones'][] = $zone;
        }

        return $out;
    }

    /**
     * Build the canonical empty config — no token, no zones. Used when
     * the file is missing or unreadable.
     *
     * @return UserConfig
     */
    public function empty(): array
    {
        return [
            'token' => '',
            'zones' => [],
        ];
    }

    /**
     * Map an arbitrary array (untrusted JSON shape) into a strict
     * ZoneEntry, or return null if the entry is unusable (missing the
     * id+name pair).
     *
     * @param array<string, mixed> $raw
     * @return ZoneEntry|null
     */
    private function normalizeZone(array $raw): ?array
    {
        $zoneId = is_string($raw['zone_id'] ?? null) ? $raw['zone_id'] : '';
        $zoneName = is_string($raw['zone_name'] ?? null) ? $raw['zone_name'] : '';
        if ($zoneId === '' && $zoneName === '') {
            return null;
        }

        $defaults = is_array($raw['defaults'] ?? null) ? $raw['defaults'] : [];

        $rawSource = is_string($raw['source'] ?? null) ? $raw['source'] : '';
        $source = in_array($rawSource, [self::SOURCE_USER, self::SOURCE_ADMIN], true)
            ? $rawSource
            : self::SOURCE_USER;

        $rawState = is_string($raw['sync_state'] ?? null) ? $raw['sync_state'] : '';
        $state = in_array($rawState, self::STATES, true) ? $rawState : self::STATE_IDLE;

        return [
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'defaults' => ['proxied' => (bool) ($defaults['proxied'] ?? false)],
            'source' => $source,
            'sync_state' => $state,
            'last_error' => is_string($raw['last_error'] ?? null) ? $raw['last_error'] : '',
        ];
    }

    /**
     * @param ZoneEntry $z
     * @return array<string, mixed>
     */
    private function encodeZone(array $z): array
    {
        $encoded = [
            'zone_id' => (string) $z['zone_id'],
            'zone_name' => (string) $z['zone_name'],
            'enabled' => (bool) $z['enabled'],
            'defaults' => ['proxied' => (bool) ($z['defaults']['proxied'] ?? false)],
            'source' => in_array($z['source'], [self::SOURCE_USER, self::SOURCE_ADMIN], true)
                ? $z['source']
                : self::SOURCE_USER,
            'sync_state' => in_array($z['sync_state'], self::STATES, true)
                ? $z['sync_state']
                : self::STATE_IDLE,
        ];
        if ($encoded['sync_state'] === self::STATE_FAILED && ($z['last_error'] ?? '') !== '') {
            $encoded['last_error'] = (string) $z['last_error'];
        }

        return $encoded;
    }

    /**
     * Lift a v1 (single-zone) JSON payload into the one-zone v2 shape.
     * Returns null when the v1 config has no zone_id at all — that's
     * just an empty config in old clothes.
     *
     * @param array<string, mixed> $json
     * @return ZoneEntry|null
     */
    private function extractLegacyZone(array $json): ?array
    {
        $zoneId = is_string($json['zone_id'] ?? null) ? $json['zone_id'] : '';
        $zoneName = is_string($json['zone_name'] ?? null) ? $json['zone_name'] : '';
        if ($zoneId === '' && $zoneName === '') {
            return null;
        }

        $defaults = is_array($json['defaults'] ?? null) ? $json['defaults'] : [];

        $rawSource = is_string($json['source'] ?? null) ? $json['source'] : '';
        $source = in_array($rawSource, [self::SOURCE_USER, self::SOURCE_ADMIN], true)
            ? $rawSource
            : self::SOURCE_USER;

        $state = self::STATE_IDLE;
        if (is_string($json['sync_state'] ?? null) && in_array($json['sync_state'], self::STATES, true)) {
            $state = $json['sync_state'];
        } elseif (is_string($json['initial_seed_state'] ?? null)) {
            $state = self::LEGACY_STATE_MAP[$json['initial_seed_state']] ?? self::STATE_IDLE;
        }

        return [
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'enabled' => (bool) ($json['enabled'] ?? false),
            'defaults' => ['proxied' => (bool) ($defaults['proxied'] ?? false)],
            'source' => $source,
            'sync_state' => $state,
            'last_error' => is_string($json['last_error'] ?? null) ? $json['last_error'] : '',
        ];
    }
}
