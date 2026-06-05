<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\DnsDiffEntry;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;

final class DnsDiffEntryTest extends TestCase
{
    public function testConstructorExposesAllReadonlyProperties(): void
    {
        $local = new DnsRecord(
            type: RecordType::A,
            name: 'example.com',
            content: '192.0.2.1',
            ttl: 300,
            priority: null,
            proxied: false,
            data: [],
        );
        $remote = [
            'id' => 'cf-record-id',
            'type' => 'A',
            'name' => 'example.com',
            'content' => '192.0.2.2',
            'ttl' => 1,
            'priority' => null,
            'proxied' => true,
            'data' => null,
        ];

        $entry = new DnsDiffEntry(
            key: 'A:example.com',
            status: 'different',
            type: 'A',
            name: 'example.com',
            local: $local,
            remote: $remote,
        );

        self::assertSame('A:example.com', $entry->key);
        self::assertSame('different', $entry->status);
        self::assertSame('A', $entry->type);
        self::assertSame('example.com', $entry->name);
        self::assertSame($local, $entry->local);
        self::assertSame($remote, $entry->remote);
    }

    public function testToArrayWithBothLocalAndRemote(): void
    {
        $local = new DnsRecord(
            type: RecordType::A,
            name: 'example.com',
            content: '192.0.2.1',
            ttl: 300,
            priority: null,
            proxied: false,
            data: [],
        );
        $remote = [
            'id' => 'cf-id-1',
            'type' => 'A',
            'name' => 'example.com',
            'content' => '192.0.2.2',
            'ttl' => 1,
            'priority' => null,
            'proxied' => true,
            'data' => null,
        ];

        $entry = new DnsDiffEntry(
            key: 'A:example.com',
            status: 'different',
            type: 'A',
            name: 'example.com',
            local: $local,
            remote: $remote,
        );

        $expected = [
            'key' => 'A:example.com',
            'status' => 'different',
            'type' => 'A',
            'name' => 'example.com',
            'protected' => false,
            'protect_reason' => '',
            'local' => $local->toCloudflarePayload(),
            'remote' => [
                'id' => 'cf-id-1',
                'type' => 'A',
                'name' => 'example.com',
                'content' => '192.0.2.2',
                'ttl' => 1,
                'priority' => null,
                'proxied' => true,
                'data' => null,
            ],
        ];

        self::assertSame($expected, $entry->toArray());
    }

    public function testToArrayWhenCpanelOnlyHasNullRemote(): void
    {
        $local = new DnsRecord(
            type: RecordType::TXT,
            name: 'example.com',
            content: '"v=spf1 -all"',
            ttl: 14400,
            priority: null,
            proxied: null,
            data: [],
        );

        $entry = new DnsDiffEntry(
            key: 'TXT:example.com',
            status: 'cpanel_only',
            type: 'TXT',
            name: 'example.com',
            local: $local,
            remote: null,
        );

        $array = $entry->toArray();

        self::assertSame('cpanel_only', $array['status']);
        self::assertNull($array['remote']);
        self::assertSame($local->toCloudflarePayload(), $array['local']);
    }

    public function testToArrayWhenCloudflareOnlyHasNullLocal(): void
    {
        $remote = [
            'id' => 'cf-id-2',
            'type' => 'CNAME',
            'name' => 'www.example.com',
            'content' => 'example.com',
            'ttl' => 3600,
            'priority' => null,
            'proxied' => false,
            'data' => null,
        ];

        $entry = new DnsDiffEntry(
            key: 'CNAME:www.example.com',
            status: 'cloudflare_only',
            type: 'CNAME',
            name: 'www.example.com',
            local: null,
            remote: $remote,
        );

        $array = $entry->toArray();

        self::assertSame('cloudflare_only', $array['status']);
        self::assertNull($array['local']);
        self::assertIsArray($array['remote']);
        self::assertSame('cf-id-2', $array['remote']['id']);
        self::assertSame('CNAME', $array['remote']['type']);
        self::assertSame('www.example.com', $array['remote']['name']);
        self::assertSame('example.com', $array['remote']['content']);
        self::assertSame(3600, $array['remote']['ttl']);
        self::assertNull($array['remote']['priority']);
        self::assertFalse($array['remote']['proxied']);
        self::assertNull($array['remote']['data']);
    }

    public function testToArrayCoercesRemoteScalarsAndDefaultsMissingKeys(): void
    {
        // id/type/name missing -> '' ; ttl missing -> 0 ;
        // content/priority/proxied/data missing -> null
        $entry = new DnsDiffEntry(
            key: 'A:foo.example.com',
            status: 'different',
            type: 'A',
            name: 'foo.example.com',
            local: null,
            remote: [],
        );

        $array = $entry->toArray();

        self::assertIsArray($array['remote']);
        self::assertSame('', $array['remote']['id']);
        self::assertSame('', $array['remote']['type']);
        self::assertSame('', $array['remote']['name']);
        self::assertNull($array['remote']['content']);
        self::assertSame(0, $array['remote']['ttl']);
        self::assertNull($array['remote']['priority']);
        self::assertNull($array['remote']['proxied']);
        self::assertNull($array['remote']['data']);
    }

    public function testToArrayCastsNonStringIdTypeNameToString(): void
    {
        $entry = new DnsDiffEntry(
            key: 'MX:example.com:mail.example.com',
            status: 'different',
            type: 'MX',
            name: 'example.com',
            local: null,
            remote: [
                'id' => 12345,
                'type' => 'mx',
                'name' => 'example.com',
                'content' => 'mail.example.com',
                'ttl' => '900',
                'priority' => 10,
                'proxied' => null,
                'data' => null,
            ],
        );

        $array = $entry->toArray();

        self::assertIsArray($array['remote']);
        self::assertSame('12345', $array['remote']['id']);
        self::assertSame('mx', $array['remote']['type']);
        self::assertSame('example.com', $array['remote']['name']);
        self::assertSame('mail.example.com', $array['remote']['content']);
        self::assertSame(900, $array['remote']['ttl']);
        self::assertSame(10, $array['remote']['priority']);
        self::assertNull($array['remote']['proxied']);
    }

    public function testToArrayPreservesStructuredDataPayload(): void
    {
        $structured = [
            'flags' => 0,
            'tag' => 'issue',
            'value' => 'letsencrypt.org',
        ];
        $entry = new DnsDiffEntry(
            key: 'CAA:example.com',
            status: 'different',
            type: 'CAA',
            name: 'example.com',
            local: null,
            remote: [
                'id' => 'cf-caa-1',
                'type' => 'CAA',
                'name' => 'example.com',
                'content' => null,
                'ttl' => 1,
                'priority' => null,
                'proxied' => null,
                'data' => $structured,
            ],
        );

        $array = $entry->toArray();

        self::assertIsArray($array['remote']);
        self::assertSame($structured, $array['remote']['data']);
        self::assertNull($array['remote']['content']);
    }

    public function testToArrayKeysAreInExpectedOrder(): void
    {
        $entry = new DnsDiffEntry(
            key: 'A:identical.example.com',
            status: 'identical',
            type: 'A',
            name: 'identical.example.com',
            local: null,
            remote: null,
        );

        self::assertSame(
            ['key', 'status', 'type', 'name', 'protected', 'protect_reason', 'local', 'remote'],
            array_keys($entry->toArray()),
        );
    }

    public function testIdenticalStatusWithBothNullStillSerializesCleanly(): void
    {
        // Edge: the docblock says at most one of local/remote is null,
        // but the type signature allows both null. Verify it doesn't blow up.
        $entry = new DnsDiffEntry(
            key: 'A:ghost.example.com',
            status: 'identical',
            type: 'A',
            name: 'ghost.example.com',
            local: null,
            remote: null,
        );

        $array = $entry->toArray();

        self::assertNull($array['local']);
        self::assertNull($array['remote']);
        self::assertSame('identical', $array['status']);
    }
}
