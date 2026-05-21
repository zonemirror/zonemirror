<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

/**
 * Canonical filesystem layout for the plugin.
 *
 * - System defaults and the master encryption key live under
 *   /var/cpanel/zonemirror/ (root-owned, 0700).
 * - Per-user state (encrypted token, queue) lives under
 *   <user-home>/.zonemirror/ (user-owned, 0700) so hooks running as
 *   the cPanel user can write to their own queue without escalating to root.
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

    public static function systemKeyFile(): string
    {
        return self::systemDir() . '/master.key';
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

    public static function userQueueFile(string $user): string
    {
        return self::userDir($user) . '/queue.sqlite';
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

        return '/home/' . $user;
    }
}
