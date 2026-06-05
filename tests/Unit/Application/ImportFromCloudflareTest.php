<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Application\ImportFromCloudflare;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareException;

final class ImportFromCloudflareTest extends TestCase
{
    public function testListRemoteReturnsRecordsFromClient(): void
    {
        $records = [
            ['id' => 'rec-1', 'type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10'],
            ['id' => 'rec-2', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 -all'],
        ];
        $client = $this->clientReturning($records);

        $import = new ImportFromCloudflare($client);
        $result = $import->listRemote('zone-abc');

        self::assertSame($records, $result);
        self::assertSame(['zone-abc'], $client->capturedZoneIds);
    }

    public function testListRemoteReturnsEmptyListWhenClientHasNoRecords(): void
    {
        $client = $this->clientReturning([]);

        $import = new ImportFromCloudflare($client);
        $result = $import->listRemote('zone-empty');

        self::assertSame([], $result);
        self::assertCount(1, $client->capturedZoneIds);
        self::assertSame('zone-empty', $client->capturedZoneIds[0]);
    }

    public function testListRemoteForwardsZoneIdVerbatim(): void
    {
        // Empty string is a valid PHP string and must reach the client
        // unaltered — the Application layer must not "sanitize" inputs.
        $client = $this->clientReturning([]);

        $import = new ImportFromCloudflare($client);
        $import->listRemote('');

        self::assertSame([''], $client->capturedZoneIds);
    }

    public function testListRemotePropagatesCloudflareException(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function listRecords(string $zoneId, array $filter = []): array
            {
                throw new CloudflareException(
                    'Cloudflare list records failed: zone not found.',
                    httpStatus: 404,
                    retryable: false,
                    cloudflareCode: 1001,
                );
            }
        };

        $import = new ImportFromCloudflare($client);

        self::expectException(CloudflareException::class);
        $import->listRemote('missing-zone');
    }

    public function testListRemoteIsRepeatable(): void
    {
        // ImportFromCloudflare must not cache: each call hits the client
        // so the UI sees fresh data when the user clicks "refresh".
        $client = $this->clientReturning([['id' => 'rec-1']]);

        $import = new ImportFromCloudflare($client);
        $import->listRemote('zone-1');
        $import->listRemote('zone-2');
        $import->listRemote('zone-1');

        self::assertSame(['zone-1', 'zone-2', 'zone-1'], $client->capturedZoneIds);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function clientReturning(array $records): RecordingCloudflareApiClient
    {
        return new RecordingCloudflareApiClient('test-token', $records);
    }
}

final class RecordingCloudflareApiClient extends CloudflareApiClient
{
    /** @var list<string> */
    public array $capturedZoneIds = [];

    /**
     * @param list<array<string, mixed>> $records
     */
    public function __construct(string $token, private readonly array $records)
    {
        parent::__construct($token);
    }

    /**
     * @param array{type?: string, name?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function listRecords(string $zoneId, array $filter = []): array
    {
        $this->capturedZoneIds[] = $zoneId;

        return $this->records;
    }
}
