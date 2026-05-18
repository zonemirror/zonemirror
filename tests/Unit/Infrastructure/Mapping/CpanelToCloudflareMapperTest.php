<?php

declare(strict_types=1);

namespace CfSync\Tests\Unit\Infrastructure\Mapping;

use CfSync\Domain\RecordType;
use CfSync\Infrastructure\Mapping\CpanelToCloudflareMapper;
use PHPUnit\Framework\TestCase;

final class CpanelToCloudflareMapperTest extends TestCase
{
    private CpanelToCloudflareMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CpanelToCloudflareMapper();
    }

    public function testMapsARecord(): void
    {
        $rec = $this->mapper->map(
            ['type' => 'A', 'name' => 'www.example.com.', 'address' => '203.0.113.10', 'ttl' => 600],
            ['proxied' => true],
        );
        self::assertNotNull($rec);
        self::assertSame(RecordType::A, $rec->type);
        self::assertSame('www.example.com', $rec->name); // trailing dot stripped
        self::assertSame('203.0.113.10', $rec->content);
        self::assertSame(600, $rec->ttl);
        self::assertTrue($rec->proxied);
    }

    public function testNeverProxiesAcmeChallenge(): void
    {
        $rec = $this->mapper->map(
            ['type' => 'A', 'name' => '_acme-challenge.example.com.', 'address' => '203.0.113.10'],
            ['proxied' => true],
        );
        self::assertNotNull($rec);
        self::assertFalse($rec->proxied);
    }

    public function testNeverProxiesDmarc(): void
    {
        $rec = $this->mapper->map(
            ['type' => 'TXT', 'name' => '_dmarc.example.com.', 'txtdata' => 'v=DMARC1; p=none'],
            ['proxied' => true],
        );
        self::assertNotNull($rec);
        self::assertSame(RecordType::TXT, $rec->type);
        self::assertNull($rec->proxied);
    }

    public function testStripsTxtQuotes(): void
    {
        $rec = $this->mapper->map(
            ['type' => 'TXT', 'name' => 'example.com.', 'txtdata' => '"v=spf1 -all"'],
            ['proxied' => false],
        );
        self::assertNotNull($rec);
        self::assertSame('v=spf1 -all', $rec->content);
    }

    public function testMapsMxWithPriority(): void
    {
        $rec = $this->mapper->map(
            ['type' => 'MX', 'name' => 'example.com.', 'exchange' => 'mail.example.com.', 'preference' => 5, 'ttl' => 3600],
            ['proxied' => false],
        );
        self::assertNotNull($rec);
        self::assertSame(RecordType::MX, $rec->type);
        self::assertSame('mail.example.com', $rec->content);
        self::assertSame(5, $rec->priority);
    }

    public function testMapsSrvIntoStructuredData(): void
    {
        $rec = $this->mapper->map(
            [
                'type' => 'SRV',
                'name' => '_sip._tcp.example.com.',
                'target' => 'voip.example.com.',
                'port' => 5060,
                'weight' => 10,
                'priority' => 1,
                'ttl' => 300,
            ],
            ['proxied' => false],
        );
        self::assertNotNull($rec);
        self::assertSame(RecordType::SRV, $rec->type);
        self::assertSame('_sip', $rec->data['service']);
        self::assertSame('_tcp', $rec->data['proto']);
        self::assertSame('example.com', $rec->data['name']);
        self::assertSame('voip.example.com', $rec->data['target']);
        self::assertSame(5060, $rec->data['port']);
    }

    public function testMapsCaa(): void
    {
        $rec = $this->mapper->map(
            ['type' => 'CAA', 'name' => 'example.com.', 'flag' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
            ['proxied' => false],
        );
        self::assertNotNull($rec);
        self::assertSame(RecordType::CAA, $rec->type);
        self::assertSame(['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'], $rec->data);
    }

    public function testSkipsNs(): void
    {
        $rec = $this->mapper->map(
            ['type' => 'NS', 'name' => 'example.com.', 'nsdname' => 'ns1.example.com.'],
            ['proxied' => false],
        );
        self::assertNull($rec);
    }

    public function testSkipsUnknownType(): void
    {
        $rec = $this->mapper->map(['type' => 'PTR', 'name' => 'example.com.'], ['proxied' => false]);
        self::assertNull($rec);
    }

    public function testEnforcesMinimumTtl(): void
    {
        $rec = $this->mapper->map(
            ['type' => 'A', 'name' => 'example.com.', 'address' => '203.0.113.1', 'ttl' => 10],
            ['proxied' => false],
        );
        self::assertNotNull($rec);
        self::assertSame(60, $rec->ttl);
    }
}
