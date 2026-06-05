<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ValueError;
use ZoneMirror\Domain\SyncResult;

final class SyncResultTest extends TestCase
{
    public function testAppliedCaseHasExpectedValue(): void
    {
        self::assertSame('applied', SyncResult::Applied->value);
    }

    public function testNoChangeCaseHasExpectedValue(): void
    {
        self::assertSame('no_change', SyncResult::NoChange->value);
    }

    public function testSkippedCaseHasExpectedValue(): void
    {
        self::assertSame('skipped', SyncResult::Skipped->value);
    }

    public function testFailedCaseHasExpectedValue(): void
    {
        self::assertSame('failed', SyncResult::Failed->value);
    }

    public function testCasesReturnsAllFourEnumMembersInDeclarationOrder(): void
    {
        $cases = SyncResult::cases();

        self::assertCount(4, $cases);
        self::assertSame(SyncResult::Applied, $cases[0]);
        self::assertSame(SyncResult::NoChange, $cases[1]);
        self::assertSame(SyncResult::Skipped, $cases[2]);
        self::assertSame(SyncResult::Failed, $cases[3]);
    }

    public function testFromReturnsEnumForValidValue(): void
    {
        self::assertSame(SyncResult::Applied, SyncResult::from('applied'));
        self::assertSame(SyncResult::NoChange, SyncResult::from('no_change'));
        self::assertSame(SyncResult::Skipped, SyncResult::from('skipped'));
        self::assertSame(SyncResult::Failed, SyncResult::from('failed'));
    }

    public function testFromThrowsForUnknownValue(): void
    {
        $this->expectException(ValueError::class);
        SyncResult::from('unknown');
    }

    public function testFromThrowsForEmptyString(): void
    {
        $this->expectException(ValueError::class);
        SyncResult::from('');
    }

    public function testTryFromReturnsEnumForValidValue(): void
    {
        self::assertSame(SyncResult::Applied, SyncResult::tryFrom('applied'));
        self::assertSame(SyncResult::Failed, SyncResult::tryFrom('failed'));
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(SyncResult::tryFrom('bogus'));
    }

    public function testTryFromReturnsNullForEmptyString(): void
    {
        self::assertNull(SyncResult::tryFrom(''));
    }

    public function testTryFromIsCaseSensitive(): void
    {
        self::assertNull(SyncResult::tryFrom('APPLIED'));
        self::assertNull(SyncResult::tryFrom('Applied'));
    }

    public function testCaseNamesAreExposed(): void
    {
        self::assertSame('Applied', SyncResult::Applied->name);
        self::assertSame('NoChange', SyncResult::NoChange->name);
        self::assertSame('Skipped', SyncResult::Skipped->name);
        self::assertSame('Failed', SyncResult::Failed->name);
    }

    public function testCasesAreIdenticalSingletons(): void
    {
        self::assertSame(SyncResult::Applied, SyncResult::Applied);
        self::assertNotSame(SyncResult::Applied, SyncResult::Failed);
    }
}
