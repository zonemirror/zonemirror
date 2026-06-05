<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Cloudflare;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareException;

/**
 * The real send() pipeline calls cURL against the Cloudflare API and cannot be
 * exercised deterministically from a unit test. The class docblock documents
 * that it is intentionally non-`final` so callers (and tests) can subclass and
 * stub the public verbs — see ProcessEventTest.php for the canonical pattern.
 * These tests pin that contract: the class stays open for extension, its
 * public methods can be overridden, and the destructor is safe when no cURL
 * handle was ever opened.
 */
final class CloudflareApiClientTest extends TestCase
{
    public function testCanBeInstantiatedWithToken(): void
    {
        $client = new CloudflareApiClient('test-token');

        self::assertInstanceOf(CloudflareApiClient::class, $client);
    }

    public function testCanBeInstantiatedWithEmptyToken(): void
    {
        // The constructor does not validate the token — Cloudflare rejects
        // empty tokens at the wire, and verifyTokenStatus() then returns ''.
        $client = new CloudflareApiClient('');

        self::assertInstanceOf(CloudflareApiClient::class, $client);
    }

    public function testClassIsNotFinalSoTestsCanSubclass(): void
    {
        // The docblock explicitly promises this. ProcessEventTest depends on
        // it. If somebody makes the class `final`, every downstream test that
        // stubs createRecord/updateRecord/deleteRecord breaks — catch it here.
        $reflection = new ReflectionClass(CloudflareApiClient::class);

        self::assertFalse($reflection->isFinal(), 'CloudflareApiClient must remain non-final for test subclassing.');
    }

    public function testDestructorIsSafeWhenNoHandleWasOpened(): void
    {
        // Regression guard: __destruct() checks $handle !== null before
        // calling curl_close(). If somebody preinitializes $handle (or removes
        // the null-check) the destructor on an unused client crashes. Assert
        // directly on the state the guard depends on: a fresh client must
        // start with a null cURL handle.
        $client = new CloudflareApiClient('test-token');
        $handleProp = (new ReflectionClass(CloudflareApiClient::class))->getProperty('handle');

        self::assertNull($handleProp->getValue($client), 'Fresh client must start with a null cURL handle.');

        unset($client);
    }

    public function testSubclassCanStubCreateRecord(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function createRecord(string $zoneId, array $record): array
            {
                return ['id' => 'stubbed-id', 'zone' => $zoneId, 'echo' => $record];
            }
        };

        $result = $client->createRecord('zone-1', ['type' => 'A', 'name' => 'www', 'content' => '1.2.3.4']);

        self::assertSame('stubbed-id', $result['id']);
        self::assertSame('zone-1', $result['zone']);
        self::assertSame(['type' => 'A', 'name' => 'www', 'content' => '1.2.3.4'], $result['echo']);
    }

    public function testSubclassCanStubUpdateRecord(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function updateRecord(string $zoneId, string $recordId, array $record): array
            {
                return ['id' => $recordId, 'zone' => $zoneId, 'echo' => $record];
            }
        };

        $result = $client->updateRecord('zone-1', 'rec-9', ['type' => 'A', 'content' => '5.6.7.8']);

        self::assertSame('rec-9', $result['id']);
        self::assertSame('zone-1', $result['zone']);
    }

    public function testSubclassCanStubDeleteRecord(): void
    {
        $calls = [];
        $client = new class ('test-token', $calls) extends CloudflareApiClient {
            /** @param list<array{zone: string, record: string}> $calls */
            public function __construct(string $token, public array &$calls)
            {
                parent::__construct($token);
            }

            public function deleteRecord(string $zoneId, string $recordId): void
            {
                $this->calls[] = ['zone' => $zoneId, 'record' => $recordId];
            }
        };

        $client->deleteRecord('zone-1', 'rec-1');
        $client->deleteRecord('zone-2', 'rec-2');

        self::assertCount(2, $calls);
        self::assertSame(['zone' => 'zone-1', 'record' => 'rec-1'], $calls[0]);
        self::assertSame(['zone' => 'zone-2', 'record' => 'rec-2'], $calls[1]);
    }

    public function testSubclassCanStubCreateRecordToThrow(): void
    {
        // Mirrors the ProcessEventTest pattern that drives the 81058
        // duplicate-record path. The subclass must be able to raise
        // CloudflareException with all the metadata fields the worker reads.
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

        try {
            $client->createRecord('zone-1', ['type' => 'A']);
            self::fail('Expected CloudflareException to be thrown.');
        } catch (CloudflareException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertFalse($e->retryable);
            self::assertSame(CloudflareException::CODE_DUPLICATE_RECORD, $e->cloudflareCode);
        }
    }

    public function testSubclassCanStubVerifyToken(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function verifyToken(): bool
            {
                return true;
            }

            public function verifyTokenStatus(): string
            {
                return 'active';
            }
        };

        self::assertTrue($client->verifyToken());
        self::assertSame('active', $client->verifyTokenStatus());
    }

    public function testSubclassCanStubFindZoneId(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function findZoneId(string $zoneName): ?string
            {
                return $zoneName === 'example.com' ? 'zone-abc' : null;
            }
        };

        self::assertSame('zone-abc', $client->findZoneId('example.com'));
        self::assertNull($client->findZoneId('missing.test'));
    }

    public function testSubclassCanStubListZones(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function listZones(): array
            {
                return [
                    ['id' => 'z1', 'name' => 'a.test'],
                    ['id' => 'z2', 'name' => 'b.test'],
                ];
            }
        };

        $zones = $client->listZones();

        self::assertCount(2, $zones);
        self::assertSame('z1', $zones[0]['id']);
    }

    public function testSubclassCanStubListZonesEmpty(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function listZones(): array
            {
                return [];
            }
        };

        self::assertSame([], $client->listZones());
    }

    public function testSubclassCanStubListRecordsWithFilter(): void
    {
        $captured = ['zone' => '', 'filter' => []];
        $client = new class ('test-token', $captured) extends CloudflareApiClient {
            /** @param array{zone: string, filter: array<string, mixed>} $captured */
            public function __construct(string $token, public array &$captured)
            {
                parent::__construct($token);
            }

            public function listRecords(string $zoneId, array $filter = []): array
            {
                $this->captured = ['zone' => $zoneId, 'filter' => $filter];

                return [['id' => 'r1', 'type' => 'A', 'name' => 'www', 'content' => '1.2.3.4']];
            }
        };

        $result = $client->listRecords('zone-1', ['type' => 'A', 'name' => 'www']);

        self::assertCount(1, $result);
        self::assertSame(
            ['zone' => 'zone-1', 'filter' => ['type' => 'A', 'name' => 'www']],
            $captured,
        );
    }

    public function testSubclassCanStubListRecordsWithDefaultFilter(): void
    {
        $client = new class ('test-token') extends CloudflareApiClient {
            public function listRecords(string $zoneId, array $filter = []): array
            {
                // Default filter is the empty array — assert we got it.
                if ($filter !== []) {
                    return [['err' => 'filter not empty']];
                }

                return [['id' => 'r1']];
            }
        };

        $result = $client->listRecords('zone-1');

        self::assertCount(1, $result);
        self::assertSame('r1', $result[0]['id']);
    }

    public function testCloudflareExceptionDuplicateCodeIsExposed(): void
    {
        // The client documents that callers translate 81058 into a no-op.
        // Pin the constant so a rename/typo here is caught at unit-test time
        // rather than as a production regression.
        self::assertSame(81058, CloudflareException::CODE_DUPLICATE_RECORD);
    }
}
