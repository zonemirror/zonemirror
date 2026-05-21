<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Interface\Hook;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Interface\Hook\HookPayloadParser;

final class HookPayloadParserTest extends TestCase
{
    public function testExtractsSingleRecord(): void
    {
        $payload = [
            'data' => [
                'args' => ['domain' => 'example.com'],
                'result' => ['data' => ['type' => 'A', 'name' => 'www.example.com.', 'address' => '203.0.113.10']],
            ],
        ];
        $extracted = HookPayloadParser::extract($payload);
        self::assertNotNull($extracted);
        self::assertSame('example.com', $extracted['domain']);
        self::assertSame('A', $extracted['raw']['type']);
    }

    public function testReturnsNullWithoutDomain(): void
    {
        self::assertNull(HookPayloadParser::extract(['data' => ['args' => [], 'result' => ['data' => []]]]));
    }

    public function testIdempotencyKeyIsStable(): void
    {
        $raw = ['type' => 'A', 'name' => 'www.example.com', 'address' => '203.0.113.10'];
        $a = HookPayloadParser::idempotencyKey('UPSERT', 'example.com', $raw);
        $b = HookPayloadParser::idempotencyKey('UPSERT', 'example.com', $raw);
        self::assertSame($a, $b);
        self::assertSame(64, strlen($a), 'sha256 hex');
    }

    public function testIdempotencyKeyDiffersOnContent(): void
    {
        $a = HookPayloadParser::idempotencyKey('UPSERT', 'example.com', ['type' => 'A', 'name' => 'a', 'address' => '1.1.1.1']);
        $b = HookPayloadParser::idempotencyKey('UPSERT', 'example.com', ['type' => 'A', 'name' => 'a', 'address' => '2.2.2.2']);
        self::assertNotSame($a, $b);
    }
}
