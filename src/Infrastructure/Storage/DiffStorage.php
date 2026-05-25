<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;
use ZoneMirror\Domain\DnsDiff;

/**
 * Persist a {@see DnsDiff} so the cPanel-user UI can render it without
 * holding any admin secret. The file lives at:
 *
 *   /var/cpanel/zonemirror/users/<user>/diff.json   (0644 root:root)
 *
 * It is intentionally in the system tree, not under the user's home:
 *  - The daemon (root) writes; the user can only read. Putting it under
 *    ~/.zonemirror would tempt the hook code to try to "fix" it.
 *  - The content is non-sensitive: zone name, zone id, per-record local
 *    and remote payloads. No token material is referenced anywhere.
 *
 * Race-free writes use the standard "write tmp + rename" dance. Stale
 * reads from a half-written file would otherwise show the user a
 * misleading partial diff.
 */
final class DiffStorage
{
    public function save(string $user, DnsDiff $diff): void
    {
        $path = Paths::userDiffFile($user);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create user diff dir: ' . $dir);
        }

        $encoded = json_encode(
            $diff->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($encoded === false) {
            throw new RuntimeException('Unable to serialise diff.');
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write diff tmp: ' . $tmp);
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('Unable to install diff: ' . $path);
        }
    }

    /**
     * Read the diff as a plain associative array. Returns null when no
     * diff has been computed yet (typical for a freshly-connected
     * domain whose first daemon cycle hasn't run yet).
     *
     * @return array<string, mixed>|null
     */
    public function load(string $user): ?array
    {
        $path = Paths::userDiffFile($user);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function remove(string $user): void
    {
        $path = Paths::userDiffFile($user);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
