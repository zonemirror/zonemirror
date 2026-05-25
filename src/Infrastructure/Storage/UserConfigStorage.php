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
 *     "initial_seed_state": "none|pending|in_progress|done|failed",
 *     "token_encrypted": "...base64..."          // only when source = user
 *   }
 *
 * The `source` field distinguishes the two onboarding paths:
 *   - "user": the cPanel user pasted their own Cloudflare token. The
 *     daemon decrypts that ciphertext to authenticate against CF.
 *   - "admin": the WHM admin has a token that covers this user's zone.
 *     No per-user token is stored; the daemon resolves which admin
 *     token to use via the zone index at sync time.
 *
 * The `initial_seed_state` field tracks the one-shot backfill from the
 * cPanel-local zone file. When a user connects a domain we set this to
 * `pending`; the daemon picks it up, reads /var/named/<zone>.db, enqueues
 * an Upsert per syncable record, and moves it to `done` (or `failed`).
 * On subsequent connects of the same domain it is set back to `pending`
 * so the user gets a fresh reconcile rather than relying on stale state.
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

    public const SEED_NONE = 'none';
    public const SEED_PENDING = 'pending';
    public const SEED_IN_PROGRESS = 'in_progress';
    public const SEED_DONE = 'done';
    public const SEED_FAILED = 'failed';

    private const SEED_STATES = [
        self::SEED_NONE,
        self::SEED_PENDING,
        self::SEED_IN_PROGRESS,
        self::SEED_DONE,
        self::SEED_FAILED,
    ];

    /**
     * @return array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token: string, source: string, initial_seed_state: string}
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

        $rawSeed = is_string($json['initial_seed_state'] ?? null) ? $json['initial_seed_state'] : '';
        $seed = in_array($rawSeed, self::SEED_STATES, true) ? $rawSeed : self::SEED_NONE;

        return [
            'enabled' => (bool) ($json['enabled'] ?? false),
            'zone_id' => is_string($json['zone_id'] ?? null) ? $json['zone_id'] : '',
            'zone_name' => is_string($json['zone_name'] ?? null) ? $json['zone_name'] : '',
            'defaults' => [
                'proxied' => (bool) ($defaults['proxied'] ?? false),
            ],
            'token' => $token,
            'source' => $source,
            'initial_seed_state' => $seed,
        ];
    }

    /**
     * @param array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token?: string, source?: string, initial_seed_state?: string} $data
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

        $rawSeed = isset($data['initial_seed_state']) ? (string) $data['initial_seed_state'] : self::SEED_NONE;
        $seed = in_array($rawSeed, self::SEED_STATES, true) ? $rawSeed : self::SEED_NONE;

        $payload = [
            'version' => 1,
            'enabled' => (bool) $data['enabled'],
            'zone_id' => (string) $data['zone_id'],
            'zone_name' => (string) $data['zone_name'],
            'defaults' => [
                'proxied' => (bool) ($data['defaults']['proxied'] ?? false),
            ],
            'source' => $source,
            'initial_seed_state' => $seed,
        ];

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
     * @return array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token: string, source: string, initial_seed_state: string}
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
            'initial_seed_state' => self::SEED_NONE,
        ];
    }
}
