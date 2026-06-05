<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ValueError;
use ZoneMirror\Domain\EventAction;

final class EventActionTest extends TestCase
{
    public function testUpsertCaseHasExpectedValue(): void
    {
        self::assertSame('UPSERT', EventAction::Upsert->value);
    }

    public function testDeleteCaseHasExpectedValue(): void
    {
        self::assertSame('DELETE', EventAction::Delete->value);
    }

    public function testFromUpsertReturnsUpsertCase(): void
    {
        self::assertSame(EventAction::Upsert, EventAction::from('UPSERT'));
    }

    public function testFromDeleteReturnsDeleteCase(): void
    {
        self::assertSame(EventAction::Delete, EventAction::from('DELETE'));
    }

    public function testFromInvalidValueThrowsValueError(): void
    {
        $this->expectException(ValueError::class);
        EventAction::from('INVALID');
    }

    public function testFromEmptyStringThrowsValueError(): void
    {
        $this->expectException(ValueError::class);
        EventAction::from('');
    }

    public function testFromIsCaseSensitive(): void
    {
        $this->expectException(ValueError::class);
        EventAction::from('upsert');
    }

    public function testTryFromUpsertReturnsUpsertCase(): void
    {
        self::assertSame(EventAction::Upsert, EventAction::tryFrom('UPSERT'));
    }

    public function testTryFromDeleteReturnsDeleteCase(): void
    {
        self::assertSame(EventAction::Delete, EventAction::tryFrom('DELETE'));
    }

    public function testTryFromInvalidValueReturnsNull(): void
    {
        self::assertNull(EventAction::tryFrom('INVALID'));
    }

    public function testTryFromEmptyStringReturnsNull(): void
    {
        self::assertNull(EventAction::tryFrom(''));
    }

    public function testTryFromIsCaseSensitive(): void
    {
        self::assertNull(EventAction::tryFrom('delete'));
    }

    public function testCasesReturnsAllEnumCases(): void
    {
        $cases = EventAction::cases();
        self::assertCount(2, $cases);
        self::assertSame(EventAction::Upsert, $cases[0]);
        self::assertSame(EventAction::Delete, $cases[1]);
    }

    public function testCaseNameUpsert(): void
    {
        self::assertSame('Upsert', EventAction::Upsert->name);
    }

    public function testCaseNameDelete(): void
    {
        self::assertSame('Delete', EventAction::Delete->name);
    }

    public function testCasesAreSingletons(): void
    {
        self::assertSame(EventAction::Upsert, EventAction::Upsert);
        self::assertSame(EventAction::Delete, EventAction::Delete);
    }

    public function testDifferentCasesAreNotEqual(): void
    {
        self::assertNotSame(EventAction::Upsert, EventAction::Delete);
    }
}
