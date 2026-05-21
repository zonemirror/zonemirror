<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

/**
 * Reads the unencrypted half of a user's config file. Used from the cPanel
 * hook path, which runs as the cPanel user and therefore cannot read the
 * root-owned master encryption key.
 *
 * The hook only needs to answer: is the user enabled, do they have a zone
 * id, do they have a (currently encrypted) token on file? The token itself
 * is decrypted later, in the daemon, which runs as root.
 *
 * @phpstan-type Metadata array{
 *     enabled: bool,
 *     zone_id: string,
 *     zone_name: string,
 *     has_token: bool,
 *     defaults: array{proxied: bool}
 * }
 */
final class UserConfigMetadataReader
{
    /**
     * @return Metadata
     */
    public static function read(string $user): array
    {
        $path = Paths::userConfigFile($user);
        if (!is_file($path)) {
            return self::empty();
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return self::empty();
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return self::empty();
        }

        $defaults = is_array($json['defaults'] ?? null) ? $json['defaults'] : [];

        return [
            'enabled' => (bool) ($json['enabled'] ?? false),
            'zone_id' => is_string($json['zone_id'] ?? null) ? $json['zone_id'] : '',
            'zone_name' => is_string($json['zone_name'] ?? null) ? $json['zone_name'] : '',
            'has_token' => isset($json['token_encrypted'])
                && is_string($json['token_encrypted'])
                && $json['token_encrypted'] !== '',
            'defaults' => [
                'proxied' => (bool) ($defaults['proxied'] ?? false),
            ],
        ];
    }

    /**
     * @return Metadata
     */
    private static function empty(): array
    {
        return [
            'enabled' => false,
            'zone_id' => '',
            'zone_name' => '',
            'has_token' => false,
            'defaults' => ['proxied' => false],
        ];
    }
}
