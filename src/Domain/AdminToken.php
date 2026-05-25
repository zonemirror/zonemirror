<?php

declare(strict_types=1);

namespace ZoneMirror\Domain;

/**
 * A Cloudflare API token configured by the WHM admin to cover one or more
 * Cloudflare accounts/zones on behalf of cPanel users on this server.
 *
 * The plaintext token only ever exists in memory; storage holds an
 * AEAD ciphertext (see AdminTokenStorage) and uses this value object for
 * everything else.
 *
 * Verification state is tracked on the token itself so the WHM UI can
 * render a status pill without re-hitting Cloudflare on every page load.
 */
final class AdminToken
{
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_OK = 'ok';
    public const STATUS_UNAUTHORIZED = 'unauthorized';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PARTIAL_SCOPE = 'partial-scope';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $createdAt,
        public readonly int $lastVerifiedAt,
        public readonly string $status,
        public readonly int $zonesIndexed,
    ) {
    }

    /**
     * @return array{id: string, name: string, created_at: int, last_verified_at: int, status: string, zones_indexed: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->createdAt,
            'last_verified_at' => $this->lastVerifiedAt,
            'status' => $this->status,
            'zones_indexed' => $this->zonesIndexed,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            createdAt: (int) ($row['created_at'] ?? 0),
            lastVerifiedAt: (int) ($row['last_verified_at'] ?? 0),
            status: self::normalizeStatus((string) ($row['status'] ?? self::STATUS_UNVERIFIED)),
            zonesIndexed: max(0, (int) ($row['zones_indexed'] ?? 0)),
        );
    }

    public function withVerification(string $status, int $zonesIndexed, int $now): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            createdAt: $this->createdAt,
            lastVerifiedAt: $now,
            status: self::normalizeStatus($status),
            zonesIndexed: max(0, $zonesIndexed),
        );
    }

    private static function normalizeStatus(string $candidate): string
    {
        $known = [
            self::STATUS_UNVERIFIED,
            self::STATUS_OK,
            self::STATUS_UNAUTHORIZED,
            self::STATUS_EXPIRED,
            self::STATUS_PARTIAL_SCOPE,
        ];

        return in_array($candidate, $known, true) ? $candidate : self::STATUS_UNVERIFIED;
    }
}
