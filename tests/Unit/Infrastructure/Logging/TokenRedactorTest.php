<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Logging;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Logging\TokenRedactor;

final class TokenRedactorTest extends TestCase
{
    public function testRedactsBearerHeader(): void
    {
        $in = 'Authorization: Bearer abcdefghijklmnopqrstuvwxyz0123456789abcd';
        $out = TokenRedactor::redact($in);
        self::assertStringContainsString('[REDACTED]', $out);
        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $out);
    }

    public function testRedactsJsonTokenField(): void
    {
        $in = '{"token":"abc123longenoughtohittheregex"}';
        $out = TokenRedactor::redact($in);
        self::assertStringContainsString('[REDACTED]', $out);
    }

    public function testLeavesShortIdentifiersAlone(): void
    {
        $in = 'user=alice id=42';
        self::assertSame($in, TokenRedactor::redact($in));
    }
}
