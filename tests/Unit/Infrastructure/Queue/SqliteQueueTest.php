<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Queue;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\DnsEvent;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Queue\SqliteQueue;
use ZoneMirror\Infrastructure\Storage\Paths;

final class SqliteQueueTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/zonemirror-test-' . bin2hex(random_bytes(8)) . '-' . uniqid('', true);
        mkdir($this->home, 0700, true);
        putenv(Paths::ENV_USER_HOME . '=' . $this->home);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->home);
        putenv(Paths::ENV_USER_HOME);
    }

    public function testRoundTripEnqueueClaimAck(): void
    {
        $queue = new SqliteQueue('alice');
        $queue->enqueue($this->makeEvent('rt-' . uniqid('', true)));

        self::assertSame(1, $queue->depth());

        $claim = $queue->claim();
        self::assertNotNull($claim);
        self::assertSame('example.com', $claim['domain']);
        self::assertSame(EventAction::Upsert, $claim['action']);

        $queue->ack($claim['id']);
        self::assertSame(0, $queue->depth());
    }

    public function testIdempotencyKeyDeduplicates(): void
    {
        $queue = new SqliteQueue('alice');
        $key = 'idem-' . uniqid('', true);
        $queue->enqueue($this->makeEvent($key));
        $queue->enqueue($this->makeEvent($key));
        $queue->enqueue($this->makeEvent($key));

        self::assertSame(1, $queue->depth());
    }

    public function testFailDeadLettersAfterMaxAttempts(): void
    {
        $queue = new SqliteQueue('alice');
        $queue->enqueue($this->makeEvent('fl-' . uniqid('', true)));

        $claim = $queue->claim(120);
        self::assertNotNull($claim);

        // Drive attempts directly past BackoffPolicy::MAX_ATTEMPTS without
        // racing the real-clock backoff. fail() increments by 1 each call.
        for ($i = $claim['attempts']; $i < 12; $i++) {
            $queue->fail($claim['id'], $i, 'boom');
        }

        self::assertSame(0, $queue->depth(), 'queue should drain to dead-letter');
        self::assertGreaterThanOrEqual(1, $queue->deadLetterCount());
    }

    public function testClaimRespectsVisibilityTimeout(): void
    {
        $queue = new SqliteQueue('alice');
        $queue->enqueue($this->makeEvent('vis-' . uniqid('', true)));

        $first = $queue->claim(120);
        $second = $queue->claim(120);

        self::assertNotNull($first);
        self::assertNull($second, 'second claim must not see the still-leased event');
    }

    public function testEnqueuedZoneIdRoundTrips(): void
    {
        $queue = new SqliteQueue('alice');
        $record = new DnsRecord(RecordType::A, 'www.example.com', '203.0.113.1', 300, null, false, []);
        $queue->enqueue(new DnsEvent(
            'example.com',
            EventAction::Upsert,
            $record,
            'rt-zone-1',
            time(),
            null,
            'zone-abc',
        ));
        $claim = $queue->claim();
        self::assertNotNull($claim);
        self::assertSame('zone-abc', $claim['zone_id']);
    }

    public function testDepthAndDeadLetterCountFilterByZoneId(): void
    {
        $queue = new SqliteQueue('alice');
        $record = new DnsRecord(RecordType::A, 'www.example.com', '203.0.113.1', 300, null, false, []);
        $queue->enqueue(new DnsEvent('example.com', EventAction::Upsert, $record, 'k1', time(), null, 'zone-A'));
        $queue->enqueue(new DnsEvent('example.com', EventAction::Upsert, $record, 'k2', time(), null, 'zone-A'));
        $queue->enqueue(new DnsEvent('example.com', EventAction::Upsert, $record, 'k3', time(), null, 'zone-B'));

        self::assertSame(3, $queue->depth());
        self::assertSame(2, $queue->depth('zone-A'));
        self::assertSame(1, $queue->depth('zone-B'));
        self::assertSame(0, $queue->depth('zone-MISSING'));
    }

    public function testBackfillEmptyZoneIdUpdatesLegacyRowsOnly(): void
    {
        $queue = new SqliteQueue('alice');
        $record = new DnsRecord(RecordType::A, 'www.example.com', '203.0.113.1', 300, null, false, []);
        // Two legacy rows (no zone_id) + one already-tagged row.
        $queue->enqueue(new DnsEvent('example.com', EventAction::Upsert, $record, 'bk-1', time()));
        $queue->enqueue(new DnsEvent('example.com', EventAction::Upsert, $record, 'bk-2', time()));
        $queue->enqueue(new DnsEvent('example.com', EventAction::Upsert, $record, 'bk-3', time(), null, 'zone-existing'));

        $touched = $queue->backfillEmptyZoneId('zone-X');
        self::assertSame(2, $touched);

        // Re-running is a no-op because nothing has zone_id = '' anymore.
        self::assertSame(0, $queue->backfillEmptyZoneId('zone-X'));

        self::assertSame(2, $queue->depth('zone-X'));
        self::assertSame(1, $queue->depth('zone-existing'));
    }

    private function makeEvent(string $key): DnsEvent
    {
        $record = new DnsRecord(RecordType::A, 'www.example.com', '203.0.113.1', 300, null, false, []);

        return new DnsEvent('example.com', EventAction::Upsert, $record, $key, time());
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $path . '/' . $i;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($path);
    }
}
