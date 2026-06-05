<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\DnsEvent;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Domain\RecordType;

final class DnsEventTest extends TestCase
{
    public function testConstructorStoresAllProvidedValues(): void
    {
        $record = $this->makeRecord();
        $event = new DnsEvent(
            domain: 'example.com',
            action: EventAction::Upsert,
            record: $record,
            idempotencyKey: 'idem-key-1',
            createdAt: 1700000000,
            targetCloudflareId: 'cf-rec-abc123',
            zoneId: 'zone-id-xyz',
        );

        self::assertSame('example.com', $event->domain);
        self::assertSame(EventAction::Upsert, $event->action);
        self::assertSame($record, $event->record);
        self::assertSame('idem-key-1', $event->idempotencyKey);
        self::assertSame(1700000000, $event->createdAt);
        self::assertSame('cf-rec-abc123', $event->targetCloudflareId);
        self::assertSame('zone-id-xyz', $event->zoneId);
    }

    public function testOptionalParametersDefaultToNullAndEmptyString(): void
    {
        $event = new DnsEvent(
            domain: 'example.org',
            action: EventAction::Delete,
            record: $this->makeRecord(),
            idempotencyKey: 'idem-default',
            createdAt: 42,
        );

        self::assertNull($event->targetCloudflareId);
        self::assertSame('', $event->zoneId);
    }

    public function testDeleteActionIsPreserved(): void
    {
        $event = new DnsEvent(
            domain: 'delete.test',
            action: EventAction::Delete,
            record: $this->makeRecord(),
            idempotencyKey: 'k',
            createdAt: 1,
        );

        self::assertSame(EventAction::Delete, $event->action);
        self::assertSame('DELETE', $event->action->value);
    }

    public function testAcceptsEmptyDomainAndEmptyIdempotencyKey(): void
    {
        // Domain object boundary: constructor does not validate (caller does).
        // This guards against accidental added validation that would break
        // backfill paths.
        $event = new DnsEvent(
            domain: '',
            action: EventAction::Upsert,
            record: $this->makeRecord(),
            idempotencyKey: '',
            createdAt: 0,
        );

        self::assertSame('', $event->domain);
        self::assertSame('', $event->idempotencyKey);
        self::assertSame(0, $event->createdAt);
    }

    public function testAcceptsNegativeCreatedAt(): void
    {
        $event = new DnsEvent(
            domain: 'neg.test',
            action: EventAction::Upsert,
            record: $this->makeRecord(),
            idempotencyKey: 'k',
            createdAt: -1,
        );

        self::assertSame(-1, $event->createdAt);
    }

    public function testTargetCloudflareIdCanBeEmptyString(): void
    {
        // An explicit empty string is distinct from null — covers callers
        // that pass through whatever the UI provided without normalising.
        $event = new DnsEvent(
            domain: 'empty.test',
            action: EventAction::Upsert,
            record: $this->makeRecord(),
            idempotencyKey: 'k',
            createdAt: 1,
            targetCloudflareId: '',
            zoneId: 'z',
        );

        self::assertSame('', $event->targetCloudflareId);
        self::assertNotNull($event->targetCloudflareId);
    }

    public function testRecordReferenceIsHeldByIdentity(): void
    {
        // DnsEvent is an immutable wrapper — the held DnsRecord must be the
        // exact same instance passed in (no defensive copy / cloning).
        $record = $this->makeRecord();
        $event = new DnsEvent(
            domain: 'ref.test',
            action: EventAction::Upsert,
            record: $record,
            idempotencyKey: 'k',
            createdAt: 1,
        );

        self::assertSame($record, $event->record);
    }

    private function makeRecord(): DnsRecord
    {
        return new DnsRecord(
            type: RecordType::A,
            name: 'www.example.com',
            content: '198.51.100.10',
            ttl: 300,
            priority: null,
            proxied: false,
            data: [],
        );
    }
}
