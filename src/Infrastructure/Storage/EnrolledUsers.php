<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;

/**
 * Source of truth for which cPanel users currently have the plugin enabled,
 * so the worker can scan only those queues. Updated by the cPanel UI
 * controller when a user toggles the enable switch.
 */
final class EnrolledUsers
{
    /**
     * @return list<string>
     */
    public function all(): array
    {
        $path = Paths::enrolledUsersFile();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $lines = preg_split('/\R/', trim($raw));
        if ($lines === false) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }

    public function enroll(string $user): void
    {
        $users = $this->all();
        if (!in_array($user, $users, true)) {
            $users[] = $user;
            $this->write($users);
        }
    }

    public function remove(string $user): void
    {
        $users = array_values(array_filter($this->all(), static fn (string $u): bool => $u !== $user));
        $this->write($users);
    }

    /**
     * @param list<string> $users
     */
    private function write(array $users): void
    {
        $dir = Paths::systemDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create system dir: ' . $dir);
        }
        sort($users);
        $path = Paths::enrolledUsersFile();
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, implode("\n", $users) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to write enrolled-users.');
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('Unable to install enrolled-users.');
        }
    }
}
