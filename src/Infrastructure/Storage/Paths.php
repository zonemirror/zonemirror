<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

/**
 * Canonical filesystem layout for the plugin.
 *
 * - Admin config under /var/cpanel/zonemirror/ (root-owned, dir 0755, files
 *   0644). It only carries the dry-run flag, the allowlist, and the list of
 *   enrolled users — no secrets — and is read in user-space by hooks and the
 *   cPanel UI, so it must be world-readable.
 * - Daemon log /var/cpanel/zonemirror/logs/zonemirror.log (root-only).
 * - Per-user state under <user-home>/.zonemirror/ (user-owned, 0700):
 *     - master.key   (the AEAD key that encrypts THIS user's token only)
 *     - config.json  (encrypted token + zone metadata)
 *     - queue.sqlite (hook → daemon event queue)
 *     - log.txt      (user-space log written by hooks and the cPanel UI)
 *   Keeping the AEAD key per-user means no shared secret exists that one
 *   cPanel user could read to decrypt another user's token.
 *
 * All paths can be overridden via environment variables to support tests and
 * unusual installations.
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

    public static function logFile(): string
    {
        return self::systemDir() . '/logs/zonemirror.log';
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
            if (is_array($pw) && isset($pw['dir']) && is_string($pw['dir']) && $pw['dir'] !== '') {
                return $pw['dir'];
            }
        }

        return '/home/' . $user;
    }
}
