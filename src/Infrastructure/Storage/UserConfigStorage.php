<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;

/**
 * Reads and writes per-cPanel-user configuration. Tokens are encrypted via
 * ConfigCrypto. The on-disk shape is:
 *
 *   {
 *     "version": 1,
 *     "enabled": true,
 *     "zone_id": "...",
 *     "zone_name": "example.com",
 *     "defaults": { "proxied": false },
 *     "source": "admin" | "user",
 *     "sync_state": "idle|pending_diff|computing_diff|awaiting_review|failed",
 *     "last_error": "..."                         // present only when sync_state=failed
 *     "token_encrypted": "...base64..."           // only when source = user
 *   }
 *
 * The `source` field distinguishes the two onboarding paths:
 *   - "user": the cPanel user pasted their own Cloudflare token. The
 *     daemon decrypts that ciphertext to authenticate against CF.
 *   - "admin": the WHM admin has a token that covers this user's zone.
 *     No per-user token is stored; the daemon resolves which admin
 *     token to use via the zone index at sync time.
 *
 * The `sync_state` field drives the diff-review wizard:
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
 * On-disk back-compat: configs written by M3.b use `initial_seed_state`
 * with values none/pending/in_progress/done/failed. They are mapped
 * one-to-one on load (none→idle, pending→pending_diff, etc.) so an
 * upgraded install does not lose connections.
 *
 * Configs written before the source field existed default to "user"
 * on load (they came from the v0.1 paste-token flow). The plaintext
 * token is only ever held in memory; it never round-trips to disk in
 * cleartext.
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
     * @return array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token: string, source: string, sync_state: string, last_error: string}
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

        $defaults = is_array($json['defaults'] ?? null) ? $json['defaults'] : [];
        $rawSource = is_string($json['source'] ?? null) ? $json['source'] : '';
        $source = in_array($rawSource, [self::SOURCE_USER, self::SOURCE_ADMIN], true)
            ? $rawSource
            : self::SOURCE_USER; // v0.1 configs predate the field

        $state = self::STATE_IDLE;
        if (is_string($json['sync_state'] ?? null) && in_array($json['sync_state'], self::STATES, true)) {
            $state = $json['sync_state'];
        } elseif (is_string($json['initial_seed_state'] ?? null)) {
            // M3.b → M4 migration: re-interpret the legacy field.
            $state = self::LEGACY_STATE_MAP[$json['initial_seed_state']] ?? self::STATE_IDLE;
        }

        return [
            'enabled' => (bool) ($json['enabled'] ?? false),
            'zone_id' => is_string($json['zone_id'] ?? null) ? $json['zone_id'] : '',
            'zone_name' => is_string($json['zone_name'] ?? null) ? $json['zone_name'] : '',
            'defaults' => [
                'proxied' => (bool) ($defaults['proxied'] ?? false),
            ],
            'token' => $token,
            'source' => $source,
            'sync_state' => $state,
            'last_error' => is_string($json['last_error'] ?? null) ? $json['last_error'] : '',
        ];
    }

    /**
     * @param array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token?: string, source?: string, sync_state?: string, last_error?: string} $data
     */
    public function save(string $user, array $data): void
    {
        $dir = Paths::userDir($user);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create user dir: ' . $dir);
        }

        $rawSource = isset($data['source']) ? (string) $data['source'] : self::SOURCE_USER;
        $source = in_array($rawSource, [self::SOURCE_USER, self::SOURCE_ADMIN], true)
            ? $rawSource
            : self::SOURCE_USER;

        $rawState = isset($data['sync_state']) ? (string) $data['sync_state'] : self::STATE_IDLE;
        $state = in_array($rawState, self::STATES, true) ? $rawState : self::STATE_IDLE;

        $payload = [
            'version' => 1,
            'enabled' => (bool) $data['enabled'],
            'zone_id' => (string) $data['zone_id'],
            'zone_name' => (string) $data['zone_name'],
            'defaults' => [
                'proxied' => (bool) ($data['defaults']['proxied'] ?? false),
            ],
            'source' => $source,
            'sync_state' => $state,
        ];
        if ($state === self::STATE_FAILED) {
            $payload['last_error'] = isset($data['last_error']) ? (string) $data['last_error'] : '';
        }

        // Token ciphertext is only relevant for the user-pasted path. For
        // admin-covered domains the daemon resolves the token via the zone
        // index, and we deliberately do not retain anything decryptable
        // in the user's home.
        if ($source === self::SOURCE_USER) {
            $providedToken = isset($data['token']) ? (string) $data['token'] : '';
            if ($providedToken !== '') {
                $payload['token_encrypted'] = $this->crypto->encrypt($providedToken);
            } else {
                $existing = $this->load($user);
                if ($existing['token'] !== '') {
                    $payload['token_encrypted'] = $this->crypto->encrypt($existing['token']);
                }
            }
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
    }

    /**
     * @return array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token: string, source: string, sync_state: string, last_error: string}
     */
    private function empty(): array
    {
        return [
            'enabled' => false,
            'zone_id' => '',
            'zone_name' => '',
            'defaults' => ['proxied' => false],
            'token' => '',
            'source' => self::SOURCE_USER,
            'sync_state' => self::STATE_IDLE,
            'last_error' => '',
        ];
    }
}
