<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use PDO;
use RuntimeException;

/**
 * Lookup table from a domain name to the Cloudflare zone (and the admin
 * token that owns it) so the cPanel user-side UI can answer "is this
 * domain in any of my admin's CF accounts?" in a single SQLite query.
 *
 * The index is owned by the daemon (root). User-side code only reads
 * via {@see findByDomain()} — the file is 0644 so the read path works
 * from inside cagefs/LSPHP without leaking any secret material (the
 * row only carries zone id, zone name, CF account id, and the *id* of
 * the admin token, never its plaintext).
 *
 * The schema is intentionally flat (no JOINs): the WHM admin already
 * knows its own token list in /var/cpanel/zonemirror/admin-tokens.json,
 * and the daemon rebuilds the index in full on every refresh, so
 * referential pruning happens at write time.
 */
final class ZoneIndex
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Replace every row owned by $tokenId with $zones in a single
     * transaction. This is the only write path; the daemon never appends
     * to the index, it always rewrites a token's slice in full so a
     * deleted CF zone disappears immediately on the next refresh.
     *
     * @param list<array{cf_zone_id: string, name: string, cf_account_id: string}> $zones
     */
    public function replaceForToken(string $tokenId, array $zones): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM zones WHERE admin_token_id = ?');
            $del->execute([$tokenId]);

            if ($zones !== []) {
                $ins = $pdo->prepare(
                    'INSERT OR REPLACE INTO zones
                     (cf_zone_id, name, cf_account_id, admin_token_id, last_seen_at)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $now = time();
                foreach ($zones as $z) {
                    $ins->execute([
                        (string) ($z['cf_zone_id'] ?? ''),
                        strtolower((string) ($z['name'] ?? '')),
                        (string) ($z['cf_account_id'] ?? ''),
                        $tokenId,
                        $now,
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    public function removeForToken(string $tokenId): void
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare('DELETE FROM zones WHERE admin_token_id = ?');
        $stmt->execute([$tokenId]);
    }

    /**
     * Find the zone that hosts $domain, or null if no admin token sees it.
     *
     * @return array{cf_zone_id: string, name: string, cf_account_id: string, admin_token_id: string}|null
     */
    public function findByDomain(string $domain): ?array
    {
        $name = strtolower(trim($domain));
        if ($name === '') {
            return null;
        }
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'SELECT cf_zone_id, name, cf_account_id, admin_token_id
             FROM zones WHERE name = ? LIMIT 1'
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'cf_zone_id' => (string) $row['cf_zone_id'],
            'name' => (string) $row['name'],
            'cf_account_id' => (string) $row['cf_account_id'],
            'admin_token_id' => (string) $row['admin_token_id'],
        ];
    }

    public function countForToken(string $tokenId): int
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM zones WHERE admin_token_id = ?');
        $stmt->execute([$tokenId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many distinct Cloudflare accounts the given token can see.
     * A single token can be scoped to "all zones in account X" (count
     * 1) or "all zones across all the user's accounts" (count > 1).
     * Used by the WHM UI to surface multi-account coverage.
     */
    public function countAccountsForToken(string $tokenId): int
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT cf_account_id)
             FROM zones
             WHERE admin_token_id = ? AND cf_account_id != ""'
        );
        $stmt->execute([$tokenId]);

        return (int) $stmt->fetchColumn();
    }

    public function count(): int
    {
        $pdo = $this->pdo();

        return (int) $pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn();
    }

    /**
     * @return list<array{cf_zone_id: string, name: string, cf_account_id: string, admin_token_id: string}>
     */
    public function allForToken(string $tokenId): array
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'SELECT cf_zone_id, name, cf_account_id, admin_token_id
             FROM zones WHERE admin_token_id = ? ORDER BY name'
        );
        $stmt->execute([$tokenId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create zone-index dir: ' . $dir);
        }

        $newFile = !is_file($this->path);

        $pdo = new PDO('sqlite:' . $this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS zones (
                cf_zone_id      TEXT PRIMARY KEY,
                name            TEXT NOT NULL,
                cf_account_id   TEXT NOT NULL DEFAULT "",
                admin_token_id  TEXT NOT NULL,
                last_seen_at    INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS zones_by_name ON zones(name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS zones_by_token ON zones(admin_token_id)');

        if ($newFile) {
            // 0644 so the cPanel user-side PHP can read the index. The
            // row content is non-sensitive (zone id + name + admin token
            // *id*, no plaintext token material).
            @chmod($this->path, 0644);
        }

        $this->pdo = $pdo;

        return $this->pdo;
    }
}
