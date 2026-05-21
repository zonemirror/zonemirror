<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Cloudflare;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Cloudflare\RecordMatcher;

final class RecordMatcherTest extends TestCase
{
    public function testFindsExactARecord(): void
    {
        $matcher = new RecordMatcher();
        $remote = [
            ['id' => 'r1', 'type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10'],
            ['id' => 'r2', 'type' => 'A', 'name' => 'api.example.com', 'content' => '203.0.113.11'],
        ];
        $record = new DnsRecord(RecordType::A, 'www.example.com', '203.0.113.10', 300, null, false, []);
        $match = $matcher->findEquivalent($remote, $record);
        self::assertNotNull($match);
        self::assertSame('r1', $match['id']);
    }

    public function testCaseInsensitiveName(): void
    {
        $matcher = new RecordMatcher();
        $remote = [['id' => 'r1', 'type' => 'A', 'name' => 'WWW.Example.com', 'content' => '203.0.113.10']];
        $record = new DnsRecord(RecordType::A, 'www.example.com', '203.0.113.10', 300, null, false, []);
        self::assertNotNull($matcher->findEquivalent($remote, $record));
    }

    public function testTxtIgnoresQuoteWrapping(): void
    {
        $matcher = new RecordMatcher();
        $remote = [['id' => 'r1', 'type' => 'TXT', 'name' => 'example.com', 'content' => '"v=spf1 -all"']];
        $record = new DnsRecord(RecordType::TXT, 'example.com', 'v=spf1 -all', 300, null, null, []);
        self::assertNotNull($matcher->findEquivalent($remote, $record));
    }

    public function testFallsBackToTypeNameWhenContentDiffers(): void
    {
        $matcher = new RecordMatcher();
        $remote = [['id' => 'r1', 'type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.99']];
        $record = new DnsRecord(RecordType::A, 'www.example.com', '203.0.113.10', 300, null, false, []);
        $match = $matcher->findEquivalent($remote, $record);
        self::assertNotNull($match);
        self::assertSame('r1', $match['id']);
    }

    public function testReturnsNullWhenNoCandidate(): void
    {
        $matcher = new RecordMatcher();
        $record = new DnsRecord(RecordType::A, 'absent.example.com', '203.0.113.10', 300, null, false, []);
        self::assertNull($matcher->findEquivalent([], $record));
    }

    public function testSrvMatchesByData(): void
    {
        $matcher = new RecordMatcher();
        $remote = [[
            'id' => 'r1',
            'type' => 'SRV',
            'name' => '_sip._tcp.example.com',
            'data' => ['service' => '_sip', 'proto' => '_tcp', 'port' => 5060, 'target' => 'voip.example.com'],
        ]];
        $record = new DnsRecord(RecordType::SRV, '_sip._tcp.example.com', null, 300, null, null, [
            'service' => '_sip',
            'proto' => '_tcp',
            'port' => 5060,
            'target' => 'voip.example.com',
        ]);
        self::assertNotNull($matcher->findEquivalent($remote, $record));
    }
}
