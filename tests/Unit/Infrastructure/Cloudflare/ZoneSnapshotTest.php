<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Cloudflare;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Cloudflare\RecordMatcher;
use ZoneMirror\Infrastructure\Cloudflare\ZoneSnapshot;

final class ZoneSnapshotTest extends TestCase
{
    public function testAllReturnsRecordsPassedToConstructor(): void
    {
        $records = [
            ['id' => 'r1', 'type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10'],
            ['id' => 'r2', 'type' => 'A', 'name' => 'api.example.com', 'content' => '203.0.113.11'],
        ];
        $snapshot = new ZoneSnapshot($records);

        self::assertSame($records, $snapshot->all());
    }

    public function testAllReturnsEmptyArrayWhenConstructedEmpty(): void
    {
        $snapshot = new ZoneSnapshot([]);

        self::assertSame([], $snapshot->all());
    }

    public function testFindDelegatesToMatcherAndReturnsResult(): void
    {
        $records = [
            ['id' => 'r1', 'type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10'],
        ];
        $snapshot = new ZoneSnapshot($records, new RecordMatcher());
        $record = new DnsRecord(RecordType::A, 'www.example.com', '203.0.113.10', 300, null, false, []);

        $match = $snapshot->find($record);

        self::assertNotNull($match);
        self::assertSame('r1', $match['id']);
    }

    public function testFindReturnsNullWhenNoMatch(): void
    {
        $snapshot = new ZoneSnapshot([], new RecordMatcher());
        $record = new DnsRecord(RecordType::A, 'absent.example.com', '203.0.113.10', 300, null, false, []);

        self::assertNull($snapshot->find($record));
    }

    public function testFindReflectsMutationsAppliedAfterConstruction(): void
    {
        $snapshot = new ZoneSnapshot([], new RecordMatcher());
        $snapshot->applyCreate([
            'id' => 'rNew',
            'type' => 'A',
            'name' => 'fresh.example.com',
            'content' => '203.0.113.50',
        ]);
        $record = new DnsRecord(RecordType::A, 'fresh.example.com', '203.0.113.50', 300, null, false, []);

        $match = $snapshot->find($record);

        self::assertNotNull($match);
        self::assertSame('rNew', $match['id']);
    }

    public function testFindByIdReturnsMatchingRecord(): void
    {
        $records = [
            ['id' => 'a', 'type' => 'A', 'name' => 'a.example.com'],
            ['id' => 'b', 'type' => 'A', 'name' => 'b.example.com'],
        ];
        $snapshot = new ZoneSnapshot($records);

        $found = $snapshot->findById('b');

        self::assertNotNull($found);
        self::assertSame('b.example.com', $found['name']);
    }

    public function testFindByIdReturnsNullWhenIdIsEmpty(): void
    {
        $records = [
            ['id' => '', 'type' => 'A', 'name' => 'empty.example.com'],
            ['id' => 'r1', 'type' => 'A', 'name' => 'www.example.com'],
        ];
        $snapshot = new ZoneSnapshot($records);

        self::assertNull($snapshot->findById(''));
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $snapshot = new ZoneSnapshot([
            ['id' => 'a', 'type' => 'A', 'name' => 'a.example.com'],
        ]);

        self::assertNull($snapshot->findById('missing'));
    }

    public function testFindByIdReturnsNullOnEmptySnapshot(): void
    {
        $snapshot = new ZoneSnapshot([]);

        self::assertNull($snapshot->findById('anything'));
    }

    public function testFindByIdHandlesRecordsWithoutIdKey(): void
    {
        $snapshot = new ZoneSnapshot([
            ['type' => 'A', 'name' => 'noid.example.com'],
            ['id' => 'r1', 'type' => 'A', 'name' => 'www.example.com'],
        ]);

        $found = $snapshot->findById('r1');

        self::assertNotNull($found);
        self::assertSame('www.example.com', $found['name']);
    }

    public function testFindByIdCoercesNonStringIdsToString(): void
    {
        $snapshot = new ZoneSnapshot([
            ['id' => 42, 'type' => 'A', 'name' => 'num.example.com'],
        ]);

        $found = $snapshot->findById('42');

        self::assertNotNull($found);
        self::assertSame('num.example.com', $found['name']);
    }

    public function testApplyCreateAppendsRecord(): void
    {
        $snapshot = new ZoneSnapshot([
            ['id' => 'r1', 'type' => 'A', 'name' => 'a.example.com'],
        ]);

        $snapshot->applyCreate(['id' => 'r2', 'type' => 'A', 'name' => 'b.example.com']);

        $all = $snapshot->all();
        self::assertCount(2, $all);
        self::assertSame('r2', $all[1]['id']);
    }

    public function testApplyCreateOnEmptySnapshotProducesSingleRecord(): void
    {
        $snapshot = new ZoneSnapshot([]);

        $snapshot->applyCreate(['id' => 'r1', 'type' => 'A', 'name' => 'a.example.com']);

        $all = $snapshot->all();
        self::assertCount(1, $all);
        self::assertSame('r1', $all[0]['id']);
    }

    public function testApplyUpdateMergesFieldsAndPreservesId(): void
    {
        $snapshot = new ZoneSnapshot([
            [
                'id' => 'r1',
                'type' => 'A',
                'name' => 'www.example.com',
                'content' => '203.0.113.10',
                'ttl' => 300,
            ],
        ]);

        $snapshot->applyUpdate('r1', ['content' => '203.0.113.99', 'ttl' => 600]);

        $found = $snapshot->findById('r1');
        self::assertNotNull($found);
        self::assertSame('r1', $found['id']);
        self::assertSame('203.0.113.99', $found['content']);
        self::assertSame(600, $found['ttl']);
        self::assertSame('A', $found['type']);
        self::assertSame('www.example.com', $found['name']);
    }

    public function testApplyUpdateOverwritesAttemptedIdChangeWithProvidedId(): void
    {
        $snapshot = new ZoneSnapshot([
            ['id' => 'r1', 'type' => 'A', 'name' => 'www.example.com'],
        ]);

        $snapshot->applyUpdate('r1', ['id' => 'hacker', 'content' => '1.2.3.4']);

        $all = $snapshot->all();
        self::assertCount(1, $all);
        self::assertSame('r1', $all[0]['id']);
        self::assertSame('1.2.3.4', $all[0]['content']);
    }

    public function testApplyUpdateAppendsWhenIdNotPresent(): void
    {
        $snapshot = new ZoneSnapshot([
            ['id' => 'r1', 'type' => 'A', 'name' => 'a.example.com'],
        ]);

        $snapshot->applyUpdate('rNew', ['type' => 'A', 'name' => 'b.example.com', 'content' => '1.1.1.1']);

        $all = $snapshot->all();
        self::assertCount(2, $all);
        self::assertSame('rNew', $all[1]['id']);
        self::assertSame('b.example.com', $all[1]['name']);
    }

    public function testApplyDeleteRemovesMatchingRecord(): void
    {
        $snapshot = new ZoneSnapshot([
            ['id' => 'r1', 'type' => 'A', 'name' => 'a.example.com'],
            ['id' => 'r2', 'type' => 'A', 'name' => 'b.example.com'],
            ['id' => 'r3', 'type' => 'A', 'name' => 'c.example.com'],
        ]);

        $snapshot->applyDelete('r2');

        $all = $snapshot->all();
        self::assertCount(2, $all);
        self::assertSame('r1', $all[0]['id']);
        self::assertSame('r3', $all[1]['id']);
    }

    public function testApplyDeleteReindexesRemainingRecordsAsList(): void
    {
        $snapshot = new ZoneSnapshot([
            ['id' => 'r1', 'type' => 'A', 'name' => 'a.example.com'],
            ['id' => 'r2', 'type' => 'A', 'name' => 'b.example.com'],
        ]);

        $snapshot->applyDelete('r1');

        $all = $snapshot->all();
        self::assertSame([0], array_keys($all));
        self::assertSame('r2', $all[0]['id']);
    }

    public function testApplyDeleteIsNoopWhenIdMissing(): void
    {
        $records = [
            ['id' => 'r1', 'type' => 'A', 'name' => 'a.example.com'],
            ['id' => 'r2', 'type' => 'A', 'name' => 'b.example.com'],
        ];
        $snapshot = new ZoneSnapshot($records);

        $snapshot->applyDelete('missing');

        self::assertSame($records, $snapshot->all());
    }

    public function testApplyDeleteWithEmptyStringRemovesRecordsWithoutId(): void
    {
        $snapshot = new ZoneSnapshot([
            ['type' => 'A', 'name' => 'noid.example.com'],
            ['id' => 'r1', 'type' => 'A', 'name' => 'a.example.com'],
        ]);

        $snapshot->applyDelete('');

        $all = $snapshot->all();
        self::assertCount(1, $all);
        self::assertSame('r1', $all[0]['id']);
    }

    public function testFindByIdAfterApplyCreateLocatesNewRecord(): void
    {
        $snapshot = new ZoneSnapshot([]);
        $snapshot->applyCreate(['id' => 'fresh', 'type' => 'A', 'name' => 'fresh.example.com']);

        $found = $snapshot->findById('fresh');

        self::assertNotNull($found);
        self::assertSame('fresh.example.com', $found['name']);
    }

    public function testFindByIdAfterApplyDeleteReturnsNull(): void
    {
        $snapshot = new ZoneSnapshot([
            ['id' => 'r1', 'type' => 'A', 'name' => 'a.example.com'],
        ]);

        $snapshot->applyDelete('r1');

        self::assertNull($snapshot->findById('r1'));
    }
}
