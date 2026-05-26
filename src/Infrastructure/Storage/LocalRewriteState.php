<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;

/**
 * Tracks every `_dmarc*` record the plugin has rewritten in the local
 * BIND zone file, alongside the previous content. This is what makes
 * `zonemirror local-dmarc revert` (and the interactive uninstall flow)
 * possible: without the previous content we'd have to guess what to
 * restore, and any guess would be wrong for clients who had a custom
 * rua/ruf before the plugin touched the zone.
 *
 * Storage: /var/cpanel/zonemirror/local-rewrites.json — root-owned,
 * 0644. Not a secret, but writes are atomic (tmp + rename) so a daemon
 * apply racing a CLI revert never sees a half-flushed file.
 *
 * Shape:
 *
 *   {
 *     "version": 1,
 *     "rewrites": {
 *       "<zone>": {
 *         "<owner-name>": {
 *           "previous_content": "v=DMARC1; p=none;",
 *           "applied_content":  "v=DMARC1; p=none; rua=mailto:sysadmin@…",
 *           "applied_at": 1779800000,
 *           "applied_by": "cli|admin-ui|daemon|hook"
 *         },
 *         ...
 *       },
 *       ...
 *     }
 *   }
 *
 * The (zone, owner-name) pair is the canonical key. `owner-name` is the
 * raw label from /var/named (e.g. "_dmarc" or "_dmarc.agent"), not the
 * absolute FQDN — that way a re-key after a zone rename is trivial.
 */
final class LocalRewriteState
{
    private const VERSION = 1;

    /**
     * @return array<string, array<string, array{previous_content: string, applied_content: string, applied_at: int, applied_by: string}>>
     */
    public function all(): array
    {
        return $this->loadRaw()['rewrites'];
    }

    /**
     * @return array<string, array{previous_content: string, applied_content: string, applied_at: int, applied_by: string}>
     */
    public function forZone(string $zone): array
    {
        $zone = strtolower(rtrim($zone, '.'));
        $all = $this->all();

        return $all[$zone] ?? [];
    }

    public function record(
        string $zone,
        string $ownerName,
        string $previousContent,
        string $appliedContent,
        string $appliedBy,
    ): void {
        $zone = strtolower(rtrim($zone, '.'));
        $ownerName = strtolower($ownerName);

        $state = $this->loadRaw();
        if (!isset($state['rewrites'][$zone]) || !is_array($state['rewrites'][$zone])) {
            $state['rewrites'][$zone] = [];
        }
        // First-time record for this owner: preserve the original previous
        // content even on subsequent re-applies, otherwise a re-apply after
        // a drift would record "previous = our last applied" and lose the
        // real pre-plugin value (the one we need to revert to on uninstall).
        $prev = $previousContent;
        if (isset($state['rewrites'][$zone][$ownerName]['previous_content'])) {
            $prev = (string) $state['rewrites'][$zone][$ownerName]['previous_content'];
        }
        $state['rewrites'][$zone][$ownerName] = [
            'previous_content' => $prev,
            'applied_content'  => $appliedContent,
            'applied_at'       => time(),
            'applied_by'       => $appliedBy,
        ];
        $this->saveRaw($state);
    }

    public function forget(string $zone, string $ownerName): void
    {
        $zone = strtolower(rtrim($zone, '.'));
        $ownerName = strtolower($ownerName);
        $state = $this->loadRaw();
        if (isset($state['rewrites'][$zone][$ownerName])) {
            unset($state['rewrites'][$zone][$ownerName]);
            if ($state['rewrites'][$zone] === []) {
                unset($state['rewrites'][$zone]);
            }
            $this->saveRaw($state);
        }
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    public function countZones(): int
    {
        return count($this->all());
    }

    public function countRecords(): int
    {
        $n = 0;
        foreach ($this->all() as $records) {
            $n += count($records);
        }

        return $n;
    }

    /**
     * @return array{version: int, rewrites: array<string, array<string, array{previous_content: string, applied_content: string, applied_at: int, applied_by: string}>>}
     */
    private function loadRaw(): array
    {
        $path = Paths::localRewritesFile();
        if (!is_file($path)) {
            return ['version' => self::VERSION, 'rewrites' => []];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['version' => self::VERSION, 'rewrites' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['rewrites']) || !is_array($decoded['rewrites'])) {
            return ['version' => self::VERSION, 'rewrites' => []];
        }
        $rewrites = [];
        foreach ($decoded['rewrites'] as $zone => $records) {
            if (!is_string($zone) || !is_array($records)) {
                continue;
            }
            $clean = [];
            foreach ($records as $owner => $row) {
                if (!is_string($owner) || !is_array($row)) {
                    continue;
                }
                $clean[strtolower($owner)] = [
                    'previous_content' => (string) ($row['previous_content'] ?? ''),
                    'applied_content'  => (string) ($row['applied_content'] ?? ''),
                    'applied_at'       => (int) ($row['applied_at'] ?? 0),
                    'applied_by'       => (string) ($row['applied_by'] ?? ''),
                ];
            }
            if ($clean !== []) {
                $rewrites[strtolower(rtrim($zone, '.'))] = $clean;
            }
        }

        return ['version' => self::VERSION, 'rewrites' => $rewrites];
    }

    /**
     * @param array{version: int, rewrites: array<string, array<string, array{previous_content: string, applied_content: string, applied_at: int, applied_by: string}>>} $data
     */
    private function saveRaw(array $data): void
    {
        $dir = Paths::systemDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create system dir: ' . $dir);
        }
        $path = Paths::localRewritesFile();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to serialize local-rewrites state.');
        }
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write local-rewrites state.');
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('Unable to install local-rewrites state.');
        }
    }
}
