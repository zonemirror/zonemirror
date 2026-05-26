<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Application\ProcessEvent;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Domain\SyncResult;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareException;
use ZoneMirror\Infrastructure\Cloudflare\ZoneSnapshot;
use ZoneMirror\Infrastructure\Logging\FileLogger;

final class ProcessEventTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/zonemirror-test-' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            @unlink($this->logPath);
        }
    }

    /**
     * Regression: Cloudflare 81058 ("identical record already exists") used
     * to crash the queue item when our snapshot was stale (race against a
     * manual dashboard edit, or two pushes for the same row). It should now
     * resolve as NoChange — desired state already matches.
     */
    public function testCreateRaceResolvesAsNoChange(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function createRecord(string $zoneId, array $record): array
            {
                throw new CloudflareException(
                    'Cloudflare create record failed: An identical record already exists.',
                    httpStatus: 400,
                    retryable: false,
                    cloudflareCode: CloudflareException::CODE_DUPLICATE_RECORD,
                );
            }
        };

        $result = $this->processor($client)->handle(
            zoneId: 'zone-id',
            action: EventAction::Upsert,
            record: $this->aRecord(),
            snapshot: new ZoneSnapshot([]),
        );

        self::assertSame(SyncResult::NoChange, $result);
    }

    /**
     * Guard: only 81058 is swallowed. Any other Cloudflare error must keep
     * propagating so the WorkerLoop can decide retryable vs dead-letter.
     */
    public function testCreateFailureForOtherReasonsStillThrows(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function createRecord(string $zoneId, array $record): array
            {
                throw new CloudflareException(
                    'Cloudflare create record failed: Invalid TTL.',
                    httpStatus: 400,
                    retryable: false,
                    cloudflareCode: 1004,
                );
            }
        };

        self::expectException(CloudflareException::class);
        $this->processor($client)->handle(
            zoneId: 'zone-id',
            action: EventAction::Upsert,
            record: $this->aRecord(),
            snapshot: new ZoneSnapshot([]),
        );
    }

    private function processor(CloudflareApiClient $client): ProcessEvent
    {
        return new ProcessEvent($client, new FileLogger($this->logPath));
    }

    private function aRecord(): DnsRecord
    {
        return new DnsRecord(
            type: RecordType::A,
            name: 'www.example.com',
            content: '203.0.113.10',
            ttl: 1,
            priority: null,
            proxied: false,
            data: [],
        );
    }
}
