<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Logging;

use PHPUnit\Framework\TestCase;
use ValueError;
use ZoneMirror\Infrastructure\Logging\LogLevel;

final class LogLevelTest extends TestCase
{
    public function testDebugCaseHasExpectedValue(): void
    {
        self::assertSame('debug', LogLevel::Debug->value);
    }

    public function testInfoCaseHasExpectedValue(): void
    {
        self::assertSame('info', LogLevel::Info->value);
    }

    public function testWarningCaseHasExpectedValue(): void
    {
        self::assertSame('warning', LogLevel::Warning->value);
    }

    public function testErrorCaseHasExpectedValue(): void
    {
        self::assertSame('error', LogLevel::Error->value);
    }

    public function testCasesReturnsAllFourLevels(): void
    {
        $cases = LogLevel::cases();
        self::assertCount(4, $cases);
        self::assertSame(
            [LogLevel::Debug, LogLevel::Info, LogLevel::Warning, LogLevel::Error],
            $cases,
        );
    }

    public function testFromReturnsMatchingCase(): void
    {
        self::assertSame(LogLevel::Debug, LogLevel::from('debug'));
        self::assertSame(LogLevel::Info, LogLevel::from('info'));
        self::assertSame(LogLevel::Warning, LogLevel::from('warning'));
        self::assertSame(LogLevel::Error, LogLevel::from('error'));
    }

    public function testFromThrowsValueErrorOnUnknownValue(): void
    {
        $this->expectException(ValueError::class);
        LogLevel::from('verbose');
    }

    public function testFromThrowsValueErrorOnEmptyString(): void
    {
        $this->expectException(ValueError::class);
        LogLevel::from('');
    }

    public function testFromIsCaseSensitiveAndThrowsOnUppercase(): void
    {
        $this->expectException(ValueError::class);
        LogLevel::from('DEBUG');
    }

    public function testTryFromReturnsMatchingCase(): void
    {
        self::assertSame(LogLevel::Debug, LogLevel::tryFrom('debug'));
        self::assertSame(LogLevel::Info, LogLevel::tryFrom('info'));
        self::assertSame(LogLevel::Warning, LogLevel::tryFrom('warning'));
        self::assertSame(LogLevel::Error, LogLevel::tryFrom('error'));
    }

    public function testTryFromReturnsNullOnUnknownValue(): void
    {
        self::assertNull(LogLevel::tryFrom('verbose'));
    }

    public function testTryFromReturnsNullOnEmptyString(): void
    {
        self::assertNull(LogLevel::tryFrom(''));
    }

    public function testTryFromIsCaseSensitive(): void
    {
        self::assertNull(LogLevel::tryFrom('Debug'));
        self::assertNull(LogLevel::tryFrom('INFO'));
    }

    public function testCaseNamesAreExpected(): void
    {
        self::assertSame('Debug', LogLevel::Debug->name);
        self::assertSame('Info', LogLevel::Info->name);
        self::assertSame('Warning', LogLevel::Warning->name);
        self::assertSame('Error', LogLevel::Error->name);
    }

    public function testCasesAreSingletonsByIdentity(): void
    {
        self::assertSame(LogLevel::Debug, LogLevel::from('debug'));
        self::assertSame(LogLevel::Info, LogLevel::tryFrom('info'));
    }
}
