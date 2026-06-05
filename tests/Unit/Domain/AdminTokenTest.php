<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\AdminToken;

final class AdminTokenTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $token = new AdminToken(
            id: 'tok_123',
            name: 'Primary',
            createdAt: 1_700_000_000,
            lastVerifiedAt: 1_700_000_500,
            status: AdminToken::STATUS_OK,
            zonesIndexed: 7,
        );

        self::assertSame('tok_123', $token->id);
        self::assertSame('Primary', $token->name);
        self::assertSame(1_700_000_000, $token->createdAt);
        self::assertSame(1_700_000_500, $token->lastVerifiedAt);
        self::assertSame(AdminToken::STATUS_OK, $token->status);
        self::assertSame(7, $token->zonesIndexed);
    }

    public function testStatusConstantsHaveExpectedValues(): void
    {
        self::assertSame('unverified', AdminToken::STATUS_UNVERIFIED);
        self::assertSame('ok', AdminToken::STATUS_OK);
        self::assertSame('unauthorized', AdminToken::STATUS_UNAUTHORIZED);
        self::assertSame('expired', AdminToken::STATUS_EXPIRED);
        self::assertSame('partial-scope', AdminToken::STATUS_PARTIAL_SCOPE);
    }

    public function testToArrayReturnsCanonicalShape(): void
    {
        $token = new AdminToken(
            id: 'tok_abc',
            name: 'Cloudflare main',
            createdAt: 1_700_001_000,
            lastVerifiedAt: 1_700_002_000,
            status: AdminToken::STATUS_OK,
            zonesIndexed: 42,
        );

        self::assertSame(
            [
                'id' => 'tok_abc',
                'name' => 'Cloudflare main',
                'created_at' => 1_700_001_000,
                'last_verified_at' => 1_700_002_000,
                'status' => AdminToken::STATUS_OK,
                'zones_indexed' => 42,
            ],
            $token->toArray(),
        );
    }

    public function testFromArrayRoundTripsFullPayload(): void
    {
        $row = [
            'id' => 'tok_xyz',
            'name' => 'Backup',
            'created_at' => 1_700_100_000,
            'last_verified_at' => 1_700_200_000,
            'status' => AdminToken::STATUS_PARTIAL_SCOPE,
            'zones_indexed' => 3,
        ];

        $token = AdminToken::fromArray($row);

        self::assertSame('tok_xyz', $token->id);
        self::assertSame('Backup', $token->name);
        self::assertSame(1_700_100_000, $token->createdAt);
        self::assertSame(1_700_200_000, $token->lastVerifiedAt);
        self::assertSame(AdminToken::STATUS_PARTIAL_SCOPE, $token->status);
        self::assertSame(3, $token->zonesIndexed);
        self::assertSame($row, $token->toArray());
    }

    public function testFromArrayUsesDefaultsForMissingKeys(): void
    {
        $token = AdminToken::fromArray([]);

        self::assertSame('', $token->id);
        self::assertSame('', $token->name);
        self::assertSame(0, $token->createdAt);
        self::assertSame(0, $token->lastVerifiedAt);
        self::assertSame(AdminToken::STATUS_UNVERIFIED, $token->status);
        self::assertSame(0, $token->zonesIndexed);
    }

    public function testFromArrayCoercesScalarTypes(): void
    {
        $token = AdminToken::fromArray([
            'id' => 999,
            'name' => 12345,
            'created_at' => '1700000000',
            'last_verified_at' => '1700000500',
            'status' => AdminToken::STATUS_EXPIRED,
            'zones_indexed' => '11',
        ]);

        self::assertSame('999', $token->id);
        self::assertSame('12345', $token->name);
        self::assertSame(1_700_000_000, $token->createdAt);
        self::assertSame(1_700_000_500, $token->lastVerifiedAt);
        self::assertSame(AdminToken::STATUS_EXPIRED, $token->status);
        self::assertSame(11, $token->zonesIndexed);
    }

    public function testFromArrayNormalizesUnknownStatusToUnverified(): void
    {
        $token = AdminToken::fromArray([
            'id' => 'tok_1',
            'name' => 'n',
            'created_at' => 1,
            'last_verified_at' => 2,
            'status' => 'totally-bogus-status',
            'zones_indexed' => 5,
        ]);

        self::assertSame(AdminToken::STATUS_UNVERIFIED, $token->status);
    }

    public function testFromArrayNormalizesEmptyStatusToUnverified(): void
    {
        $token = AdminToken::fromArray([
            'id' => 'tok_1',
            'status' => '',
        ]);

        self::assertSame(AdminToken::STATUS_UNVERIFIED, $token->status);
    }

    public function testFromArrayClampsNegativeZonesIndexedToZero(): void
    {
        $token = AdminToken::fromArray([
            'id' => 'tok_1',
            'zones_indexed' => -50,
        ]);

        self::assertSame(0, $token->zonesIndexed);
    }

    public function testFromArrayAcceptsAllKnownStatuses(): void
    {
        $known = [
            AdminToken::STATUS_UNVERIFIED,
            AdminToken::STATUS_OK,
            AdminToken::STATUS_UNAUTHORIZED,
            AdminToken::STATUS_EXPIRED,
            AdminToken::STATUS_PARTIAL_SCOPE,
        ];

        foreach ($known as $status) {
            $token = AdminToken::fromArray(['id' => 'tok', 'status' => $status]);
            self::assertSame($status, $token->status);
        }
    }

    public function testWithVerificationUpdatesStatusAndZonesAndTimestamp(): void
    {
        $original = new AdminToken(
            id: 'tok_keep',
            name: 'Stable',
            createdAt: 1_700_000_000,
            lastVerifiedAt: 1_700_000_100,
            status: AdminToken::STATUS_UNVERIFIED,
            zonesIndexed: 0,
        );

        $updated = $original->withVerification(AdminToken::STATUS_OK, 12, 1_700_999_999);

        self::assertSame('tok_keep', $updated->id);
        self::assertSame('Stable', $updated->name);
        self::assertSame(1_700_000_000, $updated->createdAt);
        self::assertSame(1_700_999_999, $updated->lastVerifiedAt);
        self::assertSame(AdminToken::STATUS_OK, $updated->status);
        self::assertSame(12, $updated->zonesIndexed);
    }

    public function testWithVerificationIsImmutableAndReturnsNewInstance(): void
    {
        $original = new AdminToken(
            id: 'tok_keep',
            name: 'Stable',
            createdAt: 1_700_000_000,
            lastVerifiedAt: 1_700_000_100,
            status: AdminToken::STATUS_UNVERIFIED,
            zonesIndexed: 0,
        );

        $updated = $original->withVerification(AdminToken::STATUS_OK, 4, 1_700_500_000);

        self::assertNotSame($original, $updated);
        self::assertSame(AdminToken::STATUS_UNVERIFIED, $original->status);
        self::assertSame(0, $original->zonesIndexed);
        self::assertSame(1_700_000_100, $original->lastVerifiedAt);
    }

    public function testWithVerificationNormalizesUnknownStatus(): void
    {
        $original = new AdminToken(
            id: 'tok_x',
            name: 'n',
            createdAt: 0,
            lastVerifiedAt: 0,
            status: AdminToken::STATUS_OK,
            zonesIndexed: 1,
        );

        $updated = $original->withVerification('not-a-real-status', 5, 123);

        self::assertSame(AdminToken::STATUS_UNVERIFIED, $updated->status);
        self::assertSame(5, $updated->zonesIndexed);
        self::assertSame(123, $updated->lastVerifiedAt);
    }

    public function testWithVerificationClampsNegativeZonesToZero(): void
    {
        $original = new AdminToken(
            id: 'tok_x',
            name: 'n',
            createdAt: 0,
            lastVerifiedAt: 0,
            status: AdminToken::STATUS_OK,
            zonesIndexed: 10,
        );

        $updated = $original->withVerification(AdminToken::STATUS_UNAUTHORIZED, -1, 999);

        self::assertSame(0, $updated->zonesIndexed);
        self::assertSame(AdminToken::STATUS_UNAUTHORIZED, $updated->status);
    }

    public function testWithVerificationAcceptsZeroValues(): void
    {
        $original = new AdminToken(
            id: 'tok_x',
            name: 'n',
            createdAt: 50,
            lastVerifiedAt: 60,
            status: AdminToken::STATUS_OK,
            zonesIndexed: 8,
        );

        $updated = $original->withVerification(AdminToken::STATUS_EXPIRED, 0, 0);

        self::assertSame(0, $updated->zonesIndexed);
        self::assertSame(0, $updated->lastVerifiedAt);
        self::assertSame(AdminToken::STATUS_EXPIRED, $updated->status);
        self::assertSame(50, $updated->createdAt);
    }
}
