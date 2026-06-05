<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\DnsDiff;
use ZoneMirror\Domain\DnsDiffEntry;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;

final class DnsDiffTest extends TestCase
{
    public function testStatusConstantsHaveExpectedValues(): void
    {
        self::assertSame('identical', DnsDiff::STATUS_IDENTICAL);
        self::assertSame('different', DnsDiff::STATUS_DIFFERENT);
        self::assertSame('cpanel_only', DnsDiff::STATUS_CPANEL_ONLY);
        self::assertSame('cloudflare_only', DnsDiff::STATUS_CLOUDFLARE_ONLY);
    }

    public function testConstructorExposesPropertiesAsReadonly(): void
    {
        $entry = $this->makeEntry(DnsDiff::STATUS_IDENTICAL, 'A', 'example.com');
        $diff = new DnsDiff('example.com', 'zone-abc', 1700000000, [$entry]);

        self::assertSame('example.com', $diff->zoneName);
        self::assertSame('zone-abc', $diff->zoneId);
        self::assertSame(1700000000, $diff->computedAt);
        self::assertCount(1, $diff->entries);
        self::assertSame($entry, $diff->entries[0]);
    }

    public function testSummaryReturnsZeroCountsForEachStatusWhenEntriesEmpty(): void
    {
        $diff = new DnsDiff('example.com', 'zone-abc', 0, []);

        self::assertSame(
            [
                DnsDiff::STATUS_IDENTICAL => 0,
                DnsDiff::STATUS_DIFFERENT => 0,
                DnsDiff::STATUS_CPANEL_ONLY => 0,
                DnsDiff::STATUS_CLOUDFLARE_ONLY => 0,
            ],
            $diff->summary(),
        );
    }

    public function testSummaryCountsEachKnownStatus(): void
    {
        $entries = [
            $this->makeEntry(DnsDiff::STATUS_IDENTICAL, 'A', 'a1.example.com'),
            $this->makeEntry(DnsDiff::STATUS_IDENTICAL, 'A', 'a2.example.com'),
            $this->makeEntry(DnsDiff::STATUS_DIFFERENT, 'AAAA', 'a3.example.com'),
            $this->makeEntry(DnsDiff::STATUS_CPANEL_ONLY, 'TXT', 'a4.example.com'),
            $this->makeEntry(DnsDiff::STATUS_CPANEL_ONLY, 'TXT', 'a5.example.com'),
            $this->makeEntry(DnsDiff::STATUS_CPANEL_ONLY, 'TXT', 'a6.example.com'),
            $this->makeEntry(DnsDiff::STATUS_CLOUDFLARE_ONLY, 'MX', 'a7.example.com'),
        ];
        $diff = new DnsDiff('example.com', 'zone-abc', 0, $entries);

        self::assertSame(
            [
                DnsDiff::STATUS_IDENTICAL => 2,
                DnsDiff::STATUS_DIFFERENT => 1,
                DnsDiff::STATUS_CPANEL_ONLY => 3,
                DnsDiff::STATUS_CLOUDFLARE_ONLY => 1,
            ],
            $diff->summary(),
        );
    }

    public function testSummaryIncludesUnknownStatusWithoutLosingKnownKeys(): void
    {
        $entries = [
            $this->makeEntry(DnsDiff::STATUS_IDENTICAL, 'A', 'a1.example.com'),
            $this->makeEntry('weird_status', 'A', 'a2.example.com'),
            $this->makeEntry('weird_status', 'A', 'a3.example.com'),
        ];
        $diff = new DnsDiff('example.com', 'zone-abc', 0, $entries);

        $summary = $diff->summary();

        self::assertSame(1, $summary[DnsDiff::STATUS_IDENTICAL]);
        self::assertSame(0, $summary[DnsDiff::STATUS_DIFFERENT]);
        self::assertSame(0, $summary[DnsDiff::STATUS_CPANEL_ONLY]);
        self::assertSame(0, $summary[DnsDiff::STATUS_CLOUDFLARE_ONLY]);
        self::assertSame(2, $summary['weird_status']);
    }

    public function testToArrayContainsZoneMetadataAndComputedSummary(): void
    {
        $entries = [
            $this->makeEntry(DnsDiff::STATUS_IDENTICAL, 'A', 'a1.example.com'),
            $this->makeEntry(DnsDiff::STATUS_DIFFERENT, 'A', 'a2.example.com'),
        ];
        $diff = new DnsDiff('example.com', 'zone-abc', 1700000001, $entries);

        $arr = $diff->toArray();

        self::assertSame('example.com', $arr['zone_name']);
        self::assertSame('zone-abc', $arr['zone_id']);
        self::assertSame(1700000001, $arr['computed_at']);
        self::assertSame(
            [
                DnsDiff::STATUS_IDENTICAL => 1,
                DnsDiff::STATUS_DIFFERENT => 1,
                DnsDiff::STATUS_CPANEL_ONLY => 0,
                DnsDiff::STATUS_CLOUDFLARE_ONLY => 0,
            ],
            $arr['summary'],
        );
    }

    public function testToArrayDelegatesEntrySerialisationToEachEntry(): void
    {
        $entry1 = $this->makeEntry(DnsDiff::STATUS_IDENTICAL, 'A', 'a1.example.com');
        $entry2 = $this->makeEntry(DnsDiff::STATUS_CPANEL_ONLY, 'TXT', 'a2.example.com');
        $diff = new DnsDiff('example.com', 'zone-abc', 0, [$entry1, $entry2]);

        $arr = $diff->toArray();

        self::assertIsArray($arr['entries']);
        self::assertCount(2, $arr['entries']);
        self::assertSame($entry1->toArray(), $arr['entries'][0]);
        self::assertSame($entry2->toArray(), $arr['entries'][1]);
    }

    public function testToArrayWithNoEntriesYieldsEmptyEntriesList(): void
    {
        $diff = new DnsDiff('example.com', 'zone-abc', 0, []);
        $arr = $diff->toArray();

        self::assertSame([], $arr['entries']);
    }

    public function testToArrayIsJsonEncodable(): void
    {
        $entry = $this->makeEntry(DnsDiff::STATUS_IDENTICAL, 'A', 'a1.example.com');
        $diff = new DnsDiff('example.com', 'zone-abc', 1700000002, [$entry]);

        $json = json_encode($diff->toArray());

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('example.com', $decoded['zone_name']);
    }

    private function makeEntry(string $status, string $type, string $name): DnsDiffEntry
    {
        $local = new DnsRecord(
            RecordType::A,
            $name,
            '203.0.113.1',
            300,
            null,
            null,
            [],
        );

        return new DnsDiffEntry(
            $type . ':' . $name,
            $status,
            $type,
            $name,
            $local,
            null,
        );
    }
}
