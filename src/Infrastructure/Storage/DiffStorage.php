<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;
use ZoneMirror\Domain\DnsDiff;

/**
 * Persist a {@see DnsDiff} so the cPanel-user UI can render it without
 * holding any admin secret. One file per (user, zone):
 *
 *   /var/cpanel/zonemirror/users/<user>/zones/<zone_id>/diff.json
 *   (0644 root:root)
 *
 * One file per zone — not one per user — because each connected zone
 * has its own compute/review lifecycle; one zone reaching
 * `awaiting_review` must not block another from refreshing.
 *
 * Lives in the system tree, not the user's home:
 *  - The daemon (root) writes; the user can only read. Putting it under
 *    ~/.zonemirror would tempt the hook code to try to "fix" it.
 *  - The content is non-sensitive: zone name, zone id, per-record local
 *    and remote payloads. No token material is referenced anywhere.
 *
 * Race-free writes use the standard "write tmp + rename" dance. Stale
 * reads from a half-written file would otherwise show the user a
 * misleading partial diff.
 *
 * Legacy fallback: load() honours the pre-v2 path
 * (`users/<user>/diff.json`) when the zone-specific path is missing.
 * That keeps a daemon read working between the v2 deploy and the
 * migrator running, so a stale "Computing diff" panel from a single-
 * zone user can still resolve. The migrator moves the file into place
 * exactly once.
 */
final class DiffStorage
{
    public function save(string $user, string $zoneId, DnsDiff $diff): void
    {
        $path = Paths::userDiffFile($user, $zoneId);
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
     * diff has been computed yet (typical for a freshly-connected zone
     * whose first daemon cycle hasn't run yet).
     *
     * Falls back to the legacy single-file path
     * (`users/<user>/diff.json`) when the zone-specific path is
     * missing — only useful between the v2 deploy and the migrator
     * completing.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $user, string $zoneId): ?array
    {
        $path = Paths::userDiffFile($user, $zoneId);
        if (!is_file($path)) {
            $legacy = Paths::userDiffFile($user);
            if (!is_file($legacy)) {
                return null;
            }
            $path = $legacy;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function remove(string $user, string $zoneId): void
    {
        $path = Paths::userDiffFile($user, $zoneId);
        if (is_file($path)) {
            @unlink($path);
        }
        // Legacy fallback: clear the v1 path too so a future load()
        // doesn't resurrect a stale diff.
        $legacy = Paths::userDiffFile($user);
        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }
}
