<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;
use ZoneMirror\Domain\AdminToken;

/**
 * Encrypted at-rest store for WHM-admin Cloudflare tokens.
 *
 * On disk this is a single JSON file (admin-tokens.json, 0600 root) whose
 * `tokens[].ciphertext` field carries the AEAD-encrypted token string and
 * whose other fields are the corresponding {@see AdminToken} metadata in
 * cleartext (id, name, status, counts). The plaintext token never lands
 * on disk and never crosses a process boundary.
 *
 * The store is meant to be used:
 *   - From the WHM admin UI (root via cpsrvd) for CRUD operations.
 *   - From the daemon (root) to load tokens it needs for outbound CF
 *     calls on behalf of enrolled cPanel users.
 *
 * It is NEVER opened from user-side PHP. The file mode (0600 root) makes
 * that explicit at the OS level too.
 */
final class AdminTokenStorage
{
    public function __construct(private readonly ConfigCrypto $crypto)
    {
    }

    /**
     * @return list<AdminToken>
     */
    public function all(): array
    {
        $json = $this->loadFile();

        $out = [];
        foreach ($json['tokens'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = AdminToken::fromArray($row);
        }

        return $out;
    }

    public function find(string $id): ?AdminToken
    {
        foreach ($this->all() as $token) {
            if ($token->id === $id) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Returns the plaintext token for a given id, or null if not found.
     * Callers are responsible for never logging the return value (the
     * FileLogger TokenRedactor scrubs common shapes, but assume the worst).
     */
    public function plaintextFor(string $id): ?string
    {
        $json = $this->loadFile();
        foreach ($json['tokens'] ?? [] as $row) {
            if (!is_array($row) || ($row['id'] ?? null) !== $id) {
                continue;
            }
            $ct = (string) ($row['ciphertext'] ?? '');
            if ($ct === '') {
                return null;
            }

            return $this->crypto->decrypt($ct);
        }

        return null;
    }

    /**
     * Add a new token. The plaintext is consumed here and not retained.
     * Returns the persisted AdminToken (id is server-assigned).
     */
    public function add(string $name, string $plaintextToken): AdminToken
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Admin token name must not be empty.');
        }
        if ($plaintextToken === '') {
            throw new RuntimeException('Admin token plaintext must not be empty.');
        }

        $json = $this->loadFile();
        $token = new AdminToken(
            id: $this->generateId(),
            name: $name,
            createdAt: time(),
            lastVerifiedAt: 0,
            status: AdminToken::STATUS_UNVERIFIED,
            zonesIndexed: 0,
        );

        $json['tokens'][] = $token->toArray() + [
            'ciphertext' => $this->crypto->encrypt($plaintextToken),
        ];

        $this->writeFile($json);

        return $token;
    }

    public function remove(string $id): void
    {
        $json = $this->loadFile();
        $kept = [];
        foreach ($json['tokens'] ?? [] as $row) {
            if (!is_array($row) || ($row['id'] ?? null) === $id) {
                continue;
            }
            $kept[] = $row;
        }
        $json['tokens'] = $kept;

        $this->writeFile($json);
    }

    /**
     * Update verification status / zones-indexed for an existing token.
     * Used by the zone indexer after each verify pass.
     */
    public function updateVerification(string $id, string $status, int $zonesIndexed): void
    {
        $json = $this->loadFile();
        $now = time();
        $changed = false;

        foreach ($json['tokens'] ?? [] as $i => $row) {
            if (!is_array($row) || ($row['id'] ?? null) !== $id) {
                continue;
            }
            $existing = AdminToken::fromArray($row);
            $updated = $existing->withVerification($status, $zonesIndexed, $now);
            $json['tokens'][$i] = $updated->toArray() + [
                'ciphertext' => (string) ($row['ciphertext'] ?? ''),
            ];
            $changed = true;
        }

        if ($changed) {
            $this->writeFile($json);
        }
    }

    /**
     * @return array{tokens: list<array<string, mixed>>}
     */
    private function loadFile(): array
    {
        $path = Paths::adminTokensFile();
        if (!is_file($path)) {
            return ['tokens' => []];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['tokens' => []];
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['tokens']) || !is_array($json['tokens'])) {
            return ['tokens' => []];
        }

        return ['tokens' => array_values($json['tokens'])];
    }

    /**
     * @param array{tokens: list<array<string, mixed>>} $json
     */
    private function writeFile(array $json): void
    {
        $dir = Paths::systemDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create system dir: ' . $dir);
        }
        $path = Paths::adminTokensFile();
        $tmp = $path . '.tmp';
        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to serialize admin tokens.');
        }
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write admin tokens file.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('Unable to install admin tokens file.');
        }
    }

    private function generateId(): string
    {
        // 16 hex chars is enough collision space for a per-server list that
        // grows by hand; nothing relies on this being a UUID.
        return bin2hex(random_bytes(8));
    }
}
