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
 *     "token_encrypted": "...base64..."
 *   }
 *
 * The plaintext token is only ever held in memory; it never round-trips to
 * disk in cleartext.
 */
final class UserConfigStorage
{
    public function __construct(private readonly ConfigCrypto $crypto)
    {
    }

    /**
     * @return array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token: string}
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

        return [
            'enabled' => (bool) ($json['enabled'] ?? false),
            'zone_id' => is_string($json['zone_id'] ?? null) ? $json['zone_id'] : '',
            'zone_name' => is_string($json['zone_name'] ?? null) ? $json['zone_name'] : '',
            'defaults' => [
                'proxied' => (bool) ($defaults['proxied'] ?? false),
            ],
            'token' => $token,
        ];
    }

    /**
     * @param array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token?: string} $data
     */
    public function save(string $user, array $data): void
    {
        $dir = Paths::userDir($user);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create user dir: ' . $dir);
        }

        $payload = [
            'version' => 1,
            'enabled' => (bool) $data['enabled'],
            'zone_id' => (string) $data['zone_id'],
            'zone_name' => (string) $data['zone_name'],
            'defaults' => [
                'proxied' => (bool) ($data['defaults']['proxied'] ?? false),
            ],
        ];

        $providedToken = isset($data['token']) ? (string) $data['token'] : '';
        if ($providedToken !== '') {
            $payload['token_encrypted'] = $this->crypto->encrypt($providedToken);
        } else {
            $existing = $this->load($user);
            if ($existing['token'] !== '') {
                $payload['token_encrypted'] = $this->crypto->encrypt($existing['token']);
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
     * @return array{enabled: bool, zone_id: string, zone_name: string, defaults: array{proxied: bool}, token: string}
     */
    private function empty(): array
    {
        return [
            'enabled' => false,
            'zone_id' => '',
            'zone_name' => '',
            'defaults' => ['proxied' => false],
            'token' => '',
        ];
    }
}
