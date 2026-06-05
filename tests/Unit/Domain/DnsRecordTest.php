<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;

final class DnsRecordTest extends TestCase
{
    public function testConstructorPersistsAllFieldsAsReadonlyState(): void
    {
        $record = new DnsRecord(
            RecordType::A,
            'www.example.com',
            '203.0.113.10',
            300,
            null,
            true,
            [],
        );

        self::assertSame(RecordType::A, $record->type);
        self::assertSame('www.example.com', $record->name);
        self::assertSame('203.0.113.10', $record->content);
        self::assertSame(300, $record->ttl);
        self::assertNull($record->priority);
        self::assertTrue($record->proxied);
        self::assertSame([], $record->data);
    }

    public function testToCloudflarePayloadIncludesProxiedForARecord(): void
    {
        $record = new DnsRecord(
            RecordType::A,
            'www.example.com',
            '203.0.113.10',
            300,
            null,
            true,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertSame([
            'type' => 'A',
            'name' => 'www.example.com',
            'ttl' => 300,
            'content' => '203.0.113.10',
            'proxied' => true,
        ], $payload);
    }

    public function testToCloudflarePayloadIncludesProxiedForAaaaRecord(): void
    {
        $record = new DnsRecord(
            RecordType::AAAA,
            'www.example.com',
            '2001:db8::1',
            120,
            null,
            false,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertSame('AAAA', $payload['type']);
        self::assertArrayHasKey('proxied', $payload);
        self::assertFalse($payload['proxied']);
    }

    public function testToCloudflarePayloadIncludesProxiedForCnameRecord(): void
    {
        $record = new DnsRecord(
            RecordType::CNAME,
            'alias.example.com',
            'target.example.com',
            1,
            null,
            true,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayHasKey('proxied', $payload);
        self::assertTrue($payload['proxied']);
    }

    public function testToCloudflarePayloadOmitsProxiedForTypeThatDoesNotSupportProxy(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'v=spf1 -all',
            300,
            null,
            true,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayNotHasKey('proxied', $payload);
        self::assertSame('v=spf1 -all', $payload['content']);
    }

    public function testToCloudflarePayloadOmitsProxiedWhenNullEvenForProxyableType(): void
    {
        $record = new DnsRecord(
            RecordType::A,
            'www.example.com',
            '203.0.113.10',
            300,
            null,
            null,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayNotHasKey('proxied', $payload);
    }

    public function testToCloudflarePayloadOmitsContentWhenNull(): void
    {
        $record = new DnsRecord(
            RecordType::SRV,
            '_sip._tcp.example.com',
            null,
            3600,
            null,
            null,
            ['priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.example.com'],
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayNotHasKey('content', $payload);
        self::assertSame('SRV', $payload['type']);
        self::assertSame('_sip._tcp.example.com', $payload['name']);
        self::assertSame(3600, $payload['ttl']);
    }

    public function testToCloudflarePayloadIncludesPriorityWhenSet(): void
    {
        $record = new DnsRecord(
            RecordType::MX,
            'example.com',
            'mail.example.com',
            3600,
            10,
            null,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayHasKey('priority', $payload);
        self::assertSame(10, $payload['priority']);
        self::assertSame('mail.example.com', $payload['content']);
    }

    public function testToCloudflarePayloadOmitsPriorityWhenNull(): void
    {
        $record = new DnsRecord(
            RecordType::MX,
            'example.com',
            'mail.example.com',
            3600,
            null,
            null,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayNotHasKey('priority', $payload);
    }

    public function testToCloudflarePayloadIncludesPriorityZeroAsZero(): void
    {
        $record = new DnsRecord(
            RecordType::MX,
            'example.com',
            'mail.example.com',
            3600,
            0,
            null,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayHasKey('priority', $payload);
        self::assertSame(0, $payload['priority']);
    }

    public function testToCloudflarePayloadIncludesDataWhenNonEmpty(): void
    {
        $data = [
            'flags' => 0,
            'tag' => 'issue',
            'value' => 'letsencrypt.org',
        ];

        $record = new DnsRecord(
            RecordType::CAA,
            'example.com',
            null,
            3600,
            null,
            null,
            $data,
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayHasKey('data', $payload);
        self::assertSame($data, $payload['data']);
        self::assertArrayNotHasKey('content', $payload);
    }

    public function testToCloudflarePayloadOmitsDataWhenEmpty(): void
    {
        $record = new DnsRecord(
            RecordType::A,
            'www.example.com',
            '203.0.113.10',
            300,
            null,
            null,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayNotHasKey('data', $payload);
    }

    public function testToCloudflarePayloadAlwaysIncludesTypeNameAndTtl(): void
    {
        $record = new DnsRecord(
            RecordType::NS,
            'example.com',
            null,
            86400,
            null,
            null,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertSame('NS', $payload['type']);
        self::assertSame('example.com', $payload['name']);
        self::assertSame(86400, $payload['ttl']);
    }

    public function testToCloudflarePayloadHandlesAllOptionalFieldsOmittedYieldingMinimalPayload(): void
    {
        $record = new DnsRecord(
            RecordType::NS,
            'example.com',
            null,
            3600,
            null,
            null,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertSame([
            'type' => 'NS',
            'name' => 'example.com',
            'ttl' => 3600,
        ], $payload);
    }

    public function testToCloudflarePayloadProducesFullSrvPayloadWithDataAndPriority(): void
    {
        $data = ['priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.example.com'];

        $record = new DnsRecord(
            RecordType::SRV,
            '_sip._tcp.example.com',
            null,
            3600,
            10,
            null,
            $data,
        );

        $payload = $record->toCloudflarePayload();

        self::assertSame('SRV', $payload['type']);
        self::assertSame('_sip._tcp.example.com', $payload['name']);
        self::assertSame(3600, $payload['ttl']);
        self::assertSame(10, $payload['priority']);
        self::assertSame($data, $payload['data']);
        self::assertArrayNotHasKey('content', $payload);
        self::assertArrayNotHasKey('proxied', $payload);
    }

    public function testToCloudflarePayloadAcceptsEmptyStringContentAndIncludesIt(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            '',
            300,
            null,
            null,
            [],
        );

        $payload = $record->toCloudflarePayload();

        self::assertArrayHasKey('content', $payload);
        self::assertSame('', $payload['content']);
    }
}
