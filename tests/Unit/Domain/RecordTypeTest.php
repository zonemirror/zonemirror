<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\RecordType;

final class RecordTypeTest extends TestCase
{
    public function testEnumExposesAllExpectedCases(): void
    {
        $values = array_map(static fn (RecordType $t): string => $t->value, RecordType::cases());

        self::assertSame(
            ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'],
            $values,
        );
    }

    public function testTryFromStringReturnsNullForNull(): void
    {
        self::assertNull(RecordType::tryFromString(null));
    }

    public function testTryFromStringReturnsNullForEmptyString(): void
    {
        self::assertNull(RecordType::tryFromString(''));
    }

    public function testTryFromStringReturnsNullForUnknownValue(): void
    {
        self::assertNull(RecordType::tryFromString('PTR'));
    }

    public function testTryFromStringReturnsNullForWhitespaceOnly(): void
    {
        // Whitespace is not empty string, so it goes through tryFrom which won't match.
        self::assertNull(RecordType::tryFromString(' '));
    }

    public function testTryFromStringAcceptsExactUppercase(): void
    {
        self::assertSame(RecordType::A, RecordType::tryFromString('A'));
        self::assertSame(RecordType::AAAA, RecordType::tryFromString('AAAA'));
        self::assertSame(RecordType::CNAME, RecordType::tryFromString('CNAME'));
        self::assertSame(RecordType::MX, RecordType::tryFromString('MX'));
        self::assertSame(RecordType::TXT, RecordType::tryFromString('TXT'));
        self::assertSame(RecordType::SRV, RecordType::tryFromString('SRV'));
        self::assertSame(RecordType::CAA, RecordType::tryFromString('CAA'));
        self::assertSame(RecordType::NS, RecordType::tryFromString('NS'));
    }

    public function testTryFromStringNormalisesLowercaseToUppercase(): void
    {
        self::assertSame(RecordType::A, RecordType::tryFromString('a'));
        self::assertSame(RecordType::CNAME, RecordType::tryFromString('cname'));
        self::assertSame(RecordType::TXT, RecordType::tryFromString('txt'));
    }

    public function testTryFromStringNormalisesMixedCase(): void
    {
        self::assertSame(RecordType::AAAA, RecordType::tryFromString('AaAa'));
        self::assertSame(RecordType::MX, RecordType::tryFromString('Mx'));
    }

    public function testSupportsProxyIsTrueForAAndAAAAAndCNAME(): void
    {
        self::assertTrue(RecordType::A->supportsProxy());
        self::assertTrue(RecordType::AAAA->supportsProxy());
        self::assertTrue(RecordType::CNAME->supportsProxy());
    }

    public function testSupportsProxyIsFalseForOtherRecordTypes(): void
    {
        self::assertFalse(RecordType::MX->supportsProxy());
        self::assertFalse(RecordType::TXT->supportsProxy());
        self::assertFalse(RecordType::SRV->supportsProxy());
        self::assertFalse(RecordType::CAA->supportsProxy());
        self::assertFalse(RecordType::NS->supportsProxy());
    }
}
