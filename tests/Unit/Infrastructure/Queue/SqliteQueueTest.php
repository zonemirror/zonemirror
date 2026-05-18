<?php

declare(strict_types=1);

namespace CfSync\Tests\Unit\Infrastructure\Queue;

use CfSync\Domain\DnsEvent;
use CfSync\Domain\DnsRecord;
use CfSync\Domain\EventAction;
use CfSync\Domain\RecordType;
use CfSync\Infrastructure\Queue\SqliteQueue;
use CfSync\Infrastructure\Storage\Paths;
use PHPUnit\Framework\TestCase;

final class SqliteQueueTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/cfsync-test-' . bin2hex(random_bytes(8)) . '-' . uniqid('', true);
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
