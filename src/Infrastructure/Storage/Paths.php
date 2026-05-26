<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

/**
 * Canonical filesystem layout for the plugin.
 *
 * Under /var/cpanel/zonemirror/ (root-owned, dir 0755):
 *   - system.json       0644  dry-run flag, allowlist, rate limit. No secrets.
 *   - enrolled-users    0644  list of cPanel users that opted in.
 *   - logs/             0700  daemon log; user-side processes write per-user.
 *   - admin-tokens.json 0600  WHM-admin Cloudflare tokens (AEAD-ciphertext).
 *   - master.key        0600  AEAD key for admin secrets only. Root-only.
 *   - zone-index.sqlite 0644  CF zones reachable via admin tokens, indexed
 *                             by domain. World-readable so the cPanel UI can
 *                             show "your domain is in zone X (admin)"; only
 *                             the zone id and name are stored here, never
 *                             tokens or any other secret.
 *   - users/<user>/diff.json
 *                       0644  per-user computed diff between cPanel-local
 *                             and Cloudflare. Root writes (daemon), the user
 *                             reads. Non-sensitive: only zone records.
 *
 * Per cPanel user under <user-home>/.zonemirror/ (user-owned, 0700):
 *   - master.key   0600 user  AEAD key for THIS user's token only (case 2).
 *   - config.json  0600 user  connection list + optional user-owned CF token.
 *   - queue.sqlite 0600 user  hook → daemon event queue.
 *   - log.txt      0600 user  user-facing log.
 *
 * The two AEAD keys are independent. The admin master.key (root-only)
 * encrypts the admin tokens that the daemon uses on behalf of any
 * enrolled cPanel user. Each user has their own master.key that encrypts
 * the user's own CF token (case 2). No single key compromises more than
 * its scope.
 *
 * All paths can be overridden via environment variables to support tests
 * and unusual installations.
 */
final class Paths
{
    public const ENV_SYSTEM_DIR = 'ZONEMIRROR_SYSTEM_DIR';
    public const ENV_USER_HOME = 'ZONEMIRROR_USER_HOME';

    private const DEFAULT_SYSTEM_DIR = '/var/cpanel/zonemirror';
    private const USER_SUBDIR = '.zonemirror';

    public static function systemDir(): string
    {
        $override = getenv(self::ENV_SYSTEM_DIR);

        return is_string($override) && $override !== '' ? $override : self::DEFAULT_SYSTEM_DIR;
    }

    public static function systemConfigFile(): string
    {
        return self::systemDir() . '/system.json';
    }

    public static function enrolledUsersFile(): string
    {
        return self::systemDir() . '/enrolled-users';
    }

    /**
     * Per-zone record of `_dmarc*` rewrites the plugin has applied to
     * the local BIND zone file, with the pre-rewrite content. Consumed
     * by the revert path (`zonemirror local-dmarc revert` and the
     * interactive uninstall flow). Root-owned, 0644 — not a secret, but
     * touch only through LocalRewriteState so writes stay atomic.
     */
    public static function localRewritesFile(): string
    {
        return self::systemDir() . '/local-rewrites.json';
    }

    /**
     * Override for the BIND zone files root. Defaults to /var/named on
     * cPanel; tests point it at a tmp dir to exercise the writer end to
     * end without touching the production server's DNS.
     */
    public const ENV_BIND_DIR = 'ZONEMIRROR_BIND_DIR';

    public static function bindDir(): string
    {
        $override = getenv(self::ENV_BIND_DIR);

        return is_string($override) && $override !== '' ? $override : '/var/named';
    }

    public static function bindZoneFile(string $zone): string
    {
        return self::bindDir() . '/' . strtolower(rtrim($zone, '.')) . '.db';
    }

    public static function logFile(): string
    {
        return self::systemDir() . '/logs/zonemirror.log';
    }

    /**
     * AEAD key for admin-scoped secrets (admin Cloudflare tokens). Created
     * lazily by KeyStore the first time the daemon or the WHM admin UI
     * encrypts something here. Distinct from each user's master.key.
     */
    public static function adminKeyFile(): string
    {
        return self::systemDir() . '/master.key';
    }

    /**
     * Encrypted list of admin Cloudflare tokens managed in WHM.
     */
    public static function adminTokensFile(): string
    {
        return self::systemDir() . '/admin-tokens.json';
    }

    /**
     * SQLite index of CF zones reachable through admin tokens, indexed by
     * domain name so the cPanel UI can resolve "is this domain covered?"
     * without exposing token material.
     */
    public static function zoneIndexFile(): string
    {
        return self::systemDir() . '/zone-index.sqlite';
    }

    /**
     * Per-user diff file the daemon computes and the cPanel UI consumes.
     * Lives under the system tree (not the user's home) so the daemon
     * doesn't need to follow user-owned symlinks to write it.
     */
    public static function userDiffFile(string $user): string
    {
        return self::systemDir() . '/users/' . $user . '/diff.json';
    }

    public static function userDir(string $user): string
    {
        return self::userHome($user) . '/' . self::USER_SUBDIR;
    }

    public static function userConfigFile(string $user): string
    {
        return self::userDir($user) . '/config.json';
    }

    public static function userKeyFile(string $user): string
    {
        return self::userDir($user) . '/master.key';
    }

    public static function userQueueFile(string $user): string
    {
        return self::userDir($user) . '/queue.sqlite';
    }

    public static function userLogFile(string $user): string
    {
        return self::userDir($user) . '/log.txt';
    }

    public static function userLocksFile(string $user): string
    {
        return self::userDir($user) . '/locks.json';
    }

    private static function userHome(string $user): string
    {
        $override = getenv(self::ENV_USER_HOME);
        if (is_string($override) && $override !== '') {
            return $override;
        }
        if ($user === '' || $user === 'root') {
            return '/root';
        }

        // cPanel/CloudLinux hosts often place users outside /home (e.g.
        // /home2 when the original partition fills). Trust the OS over
        // a hardcoded prefix.
        if (function_exists('posix_getpwnam')) {
            $pw = posix_getpwnam($user);
            if (is_array($pw) && $pw['dir'] !== '') {
                return $pw['dir'];
            }
        }

        return '/home/' . $user;
    }
}
