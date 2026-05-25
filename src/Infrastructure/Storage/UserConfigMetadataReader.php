<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

/**
 * Reads the unencrypted half of a user's config file. Used from the cPanel
 * hook path, which runs as the cPanel user and therefore cannot read the
 * root-owned master encryption key.
 *
 * The hook only needs enough information to decide whether to enqueue a
 * Cloudflare event: is the user enabled, do they have a zone id, and is
 * there a credentialled path to Cloudflare for this domain? The latter
 * can come from two sources:
 *   - "user": a per-user token is on file (has_token = true). The
 *     daemon decrypts it later.
 *   - "admin": an admin token covers this user's zone. No token in the
 *     user's home; the daemon resolves it via the zone index at sync
 *     time. has_token stays false in this case.
 *
 * Either source is enough for the hook to enqueue work.
 *
 * @phpstan-type Metadata array{
 *     enabled: bool,
 *     zone_id: string,
 *     zone_name: string,
 *     has_token: bool,
 *     source: string,
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
        $rawSource = is_string($json['source'] ?? null) ? $json['source'] : '';
        $source = in_array($rawSource, [UserConfigStorage::SOURCE_USER, UserConfigStorage::SOURCE_ADMIN], true)
            ? $rawSource
            : UserConfigStorage::SOURCE_USER;

        return [
            'enabled' => (bool) ($json['enabled'] ?? false),
            'zone_id' => is_string($json['zone_id'] ?? null) ? $json['zone_id'] : '',
            'zone_name' => is_string($json['zone_name'] ?? null) ? $json['zone_name'] : '',
            'has_token' => isset($json['token_encrypted'])
                && is_string($json['token_encrypted'])
                && $json['token_encrypted'] !== '',
            'source' => $source,
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
            'source' => UserConfigStorage::SOURCE_USER,
            'defaults' => ['proxied' => false],
        ];
    }
}
