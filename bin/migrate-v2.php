#!/usr/local/cpanel/3rdparty/bin/php
<?php

declare(strict_types=1);

/**
 * `zonemirror migrate-v2` worker. Walks every user listed in
 * /var/cpanel/zonemirror/enrolled-users and rewrites their on-disk
 * state from the pre-v2 single-zone shape into the multi-zone shape:
 *
 *   1. Promote ~user/.zonemirror/config.json from v1 (flat fields) to
 *      v2 (zones[]). Existing tokens are preserved; the single zone
 *      becomes the first (and only) item of zones[].
 *   2. Move /var/cpanel/zonemirror/users/<user>/diff.json into
 *      users/<user>/zones/<zone_id>/diff.json.
 *   3. Move ~user/.zonemirror/locks.json into
 *      ~user/.zonemirror/zones/<zone_id>/locks.json.
 *   4. Add the `zone_id` column to events table in
 *      ~user/.zonemirror/queue.sqlite (idempotent), then back-fill
 *      every legacy event row's zone_id with the user's single zone
 *      so the daemon can route them.
 *
 * Idempotent: re-running it after a v2 install is a no-op because
 * the v1 → v2 detection looks at on-disk shape, not at a marker file.
 *
 * Operates as root only — touches per-user homes, /var/cpanel/zonemirror,
 * and chowns files back to the user after writes.
 */

use ZoneMirror\Infrastructure\Queue\SqliteQueue;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\EnrolledUsers;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\UserConfigStorage;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — plugin not properly installed\n");
    exit(2);
}
require $autoload;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "migrate-v2 must run as root.\n");
    exit(2);
}

$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // script name
$verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);

$enrolled = new EnrolledUsers();
$users = $enrolled->all();

$migrated = 0;
$alreadyV2 = 0;
$skipped = 0;
$errors = [];

foreach ($users as $user) {
    try {
        $result = migrate_user($user, $verbose);
        if ($result === 'migrated') {
            $migrated++;
        } elseif ($result === 'already-v2') {
            $alreadyV2++;
        } else {
            $skipped++;
        }
    } catch (Throwable $e) {
        $errors[] = ['user' => $user, 'msg' => $e->getMessage()];
        fwrite(STDERR, sprintf("[error] %s: %s\n", $user, $e->getMessage()));
    }
}

printf(
    "migrate-v2 done: %d users seen, %d migrated, %d already on v2, %d skipped, %d errors.\n",
    count($users),
    $migrated,
    $alreadyV2,
    $skipped,
    count($errors),
);
exit($errors === [] ? 0 : 1);

/**
 * Migrate one user's state in place. Returns:
 *   - 'migrated'   : at least one of (config, diff, locks, queue) was
 *                    transformed.
 *   - 'already-v2' : everything is already in v2 layout.
 *   - 'skipped'    : the user has no config to migrate (deleted user
 *                    that lingered in enrolled-users, or a brand-new
 *                    enrollee with no zone yet).
 */
function migrate_user(string $user, bool $verbose): string
{
    $cfgPath = Paths::userConfigFile($user);
    if (!is_file($cfgPath)) {
        return 'skipped';
    }

    $raw = (string) @file_get_contents($cfgPath);
    /** @var array<string, mixed>|null $json */
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return 'skipped';
    }

    $version = (int) ($json['version'] ?? 1);
    $isV1 = $version < 2;
    $legacyZoneId = $isV1
        ? (string) ($json['zone_id'] ?? '')
        : (function () use ($json): string {
            // Already v2: figure out the user's first zone id so we
            // can still move stragglers (a legacy diff.json that
            // didn't get cleaned up by an earlier partial migration).
            $zones = is_array($json['zones'] ?? null) ? $json['zones'] : [];
            foreach ($zones as $z) {
                if (is_array($z) && is_string($z['zone_id'] ?? null) && $z['zone_id'] !== '') {
                    return $z['zone_id'];
                }
            }

            return '';
        })();

    $changes = [];

    if ($isV1) {
        $cryptoKey = Paths::userKeyFile($user);
        $crypto = new ConfigCrypto(new KeyStore($cryptoKey));
        $storage = new UserConfigStorage($crypto);
        // load() promotes the v1 shape in-memory; save() rewrites the
        // file as v2 and chowns it back to the cPanel user.
        $cfg = $storage->load($user);
        if ($cfg['zones'] === []) {
            // v1 config with no zone_id — nothing to carry forward.
            return 'skipped';
        }
        $storage->save($user, $cfg);
        $changes[] = 'config';
    }

    if ($legacyZoneId !== '') {
        // diff.json: move from users/<user>/diff.json to
        // users/<user>/zones/<zone_id>/diff.json.
        $legacyDiff = Paths::userDiffFile($user);
        $newDiff = Paths::userDiffFile($user, $legacyZoneId);
        if (is_file($legacyDiff) && !is_file($newDiff)) {
            $newDir = dirname($newDiff);
            if (!is_dir($newDir) && !@mkdir($newDir, 0755, true) && !is_dir($newDir)) {
                throw new RuntimeException('cannot create ' . $newDir);
            }
            if (!@rename($legacyDiff, $newDiff)) {
                throw new RuntimeException('cannot move diff.json into ' . $newDiff);
            }
            @chmod($newDiff, 0644);
            $changes[] = 'diff';
        }

        // locks.json: move from ~user/.zonemirror/locks.json to
        // ~user/.zonemirror/zones/<zone_id>/locks.json. Owned by the
        // user; chown back after the move.
        $legacyLocks = Paths::userLocksFile($user);
        $newLocks = Paths::userLocksFile($user, $legacyZoneId);
        if (is_file($legacyLocks) && !is_file($newLocks)) {
            $newDir = dirname($newLocks);
            if (!is_dir($newDir) && !@mkdir($newDir, 0700, true) && !is_dir($newDir)) {
                throw new RuntimeException('cannot create ' . $newDir);
            }
            if (!@rename($legacyLocks, $newLocks)) {
                throw new RuntimeException('cannot move locks.json into ' . $newLocks);
            }
            @chmod($newLocks, 0600);
            chown_back($newLocks, $user);
            chown_back($newDir, $user);
            chown_back(dirname($newDir), $user);
            $changes[] = 'locks';
        }

        // queue.sqlite: idempotent ADD COLUMN happens on first
        // SqliteQueue construction; then back-fill rows. Wrap in
        // try/catch in case the queue file doesn't exist yet (the
        // user is enrolled but no hook has fired).
        try {
            $queue = new SqliteQueue($user);
            $backfilled = $queue->backfillEmptyZoneId($legacyZoneId);
            if ($backfilled > 0) {
                $changes[] = "queue (+{$backfilled})";
            }
        } catch (Throwable $e) {
            if ($verbose) {
                fwrite(STDERR, "[warn] {$user}: queue migration skipped: " . $e->getMessage() . "\n");
            }
        }
    }

    if ($changes === []) {
        return 'already-v2';
    }

    if ($verbose || count($changes) > 0) {
        printf("[ok]    %s: migrated %s\n", $user, implode(', ', $changes));
    }

    return 'migrated';
}

/**
 * chown one path back to the cPanel user. Best-effort: failures are
 * logged but don't abort the rest of the migration for this user.
 */
function chown_back(string $path, string $user): void
{
    if (!function_exists('posix_getpwnam')) {
        return;
    }
    $pw = @posix_getpwnam($user);
    if (!is_array($pw)) {
        return;
    }
    @chown($path, $pw['uid']);
    @chgrp($path, $pw['gid']);
}
