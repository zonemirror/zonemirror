<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

/**
 * Reads the unencrypted half of a user's config file. Used from the
 * cPanel hook path, which runs as the cPanel user and therefore cannot
 * read the root-owned master encryption key.
 *
 * The hook only needs enough information to decide whether to enqueue
 * a Cloudflare event: does the user have a connection for the domain
 * the hook fired on, is it enabled, and is there a credentialled path
 * to Cloudflare for it? The credentialled path comes from two sources:
 *   - "user": a per-user token is on file (has_token = true). The
 *     daemon decrypts it later.
 *   - "admin": an admin token covers this user's zone. No token in
 *     the user's home; the daemon resolves it via the zone index at
 *     sync time. has_token stays false in this case.
 *
 * Either source is enough for the hook to enqueue work.
 *
 * Multi-zone: the file holds a list of zone connections, each with
 * its own enabled/source/defaults. v1 single-zone configs are
 * transparently mapped into a one-item list so the hook code can be
 * uniformly multi-zone without caring whether the underlying file
 * has been migrated yet.
 *
 * @phpstan-type ZoneMeta array{
 *     zone_id: string,
 *     zone_name: string,
 *     enabled: bool,
 *     source: string,
 *     defaults: array{proxied: bool}
 * }
 * @phpstan-type Metadata array{
 *     has_token: bool,
 *     zones: list<ZoneMeta>
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
        /** @var array<string, mixed>|null $json */
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return self::empty();
        }

        $hasToken = isset($json['token_encrypted'])
            && is_string($json['token_encrypted'])
            && $json['token_encrypted'] !== '';

        $version = (int) ($json['version'] ?? 1);
        if ($version < 2) {
            $legacy = self::extractLegacyZone($json);

            return [
                'has_token' => $hasToken,
                'zones' => $legacy === null ? [] : [$legacy],
            ];
        }

        $zones = [];
        $rawZones = $json['zones'] ?? [];
        if (is_array($rawZones)) {
            foreach ($rawZones as $z) {
                if (!is_array($z)) {
                    continue;
                }
                $normalized = self::normalizeZone($z);
                if ($normalized !== null) {
                    $zones[] = $normalized;
                }
            }
        }

        return [
            'has_token' => $hasToken,
            'zones' => $zones,
        ];
    }

    /**
     * Find the enabled zone in $user's config that matches $domain
     * (case-insensitive), or null. Used by the hook scripts to decide
     * whether the edit they're processing concerns a synced zone.
     *
     * @return ZoneMeta|null
     */
    public static function zoneForDomain(string $user, string $domain): ?array
    {
        $needle = strtolower(rtrim($domain, '.'));
        foreach (self::read($user)['zones'] as $z) {
            if ($z['enabled'] && strtolower(rtrim($z['zone_name'], '.')) === $needle) {
                return $z;
            }
        }

        return null;
    }

    /**
     * @return Metadata
     */
    private static function empty(): array
    {
        return [
            'has_token' => false,
            'zones' => [],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return ZoneMeta|null
     */
    private static function normalizeZone(array $raw): ?array
    {
        $zoneId = is_string($raw['zone_id'] ?? null) ? $raw['zone_id'] : '';
        $zoneName = is_string($raw['zone_name'] ?? null) ? $raw['zone_name'] : '';
        if ($zoneId === '' && $zoneName === '') {
            return null;
        }
        $rawSource = is_string($raw['source'] ?? null) ? $raw['source'] : '';
        $source = in_array($rawSource, [UserConfigStorage::SOURCE_USER, UserConfigStorage::SOURCE_ADMIN], true)
            ? $rawSource
            : UserConfigStorage::SOURCE_USER;
        $defaults = is_array($raw['defaults'] ?? null) ? $raw['defaults'] : [];

        return [
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'source' => $source,
            'defaults' => ['proxied' => (bool) ($defaults['proxied'] ?? false)],
        ];
    }

    /**
     * @param array<string, mixed> $json
     * @return ZoneMeta|null
     */
    private static function extractLegacyZone(array $json): ?array
    {
        $zoneId = is_string($json['zone_id'] ?? null) ? $json['zone_id'] : '';
        $zoneName = is_string($json['zone_name'] ?? null) ? $json['zone_name'] : '';
        if ($zoneId === '' && $zoneName === '') {
            return null;
        }
        $rawSource = is_string($json['source'] ?? null) ? $json['source'] : '';
        $source = in_array($rawSource, [UserConfigStorage::SOURCE_USER, UserConfigStorage::SOURCE_ADMIN], true)
            ? $rawSource
            : UserConfigStorage::SOURCE_USER;
        $defaults = is_array($json['defaults'] ?? null) ? $json['defaults'] : [];

        return [
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'enabled' => (bool) ($json['enabled'] ?? false),
            'source' => $source,
            'defaults' => ['proxied' => (bool) ($defaults['proxied'] ?? false)],
        ];
    }
}
