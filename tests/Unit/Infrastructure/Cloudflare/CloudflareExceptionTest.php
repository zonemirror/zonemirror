<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Cloudflare;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareException;

final class CloudflareExceptionTest extends TestCase
{
    public function testIsRuntimeException(): void
    {
        $exception = new CloudflareException('boom');
        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testDefaultsForOptionalConstructorArgs(): void
    {
        $exception = new CloudflareException('boom');
        self::assertSame('boom', $exception->getMessage());
        self::assertSame(0, $exception->httpStatus);
        self::assertFalse($exception->retryable);
        self::assertNull($exception->retryAfterSeconds);
        self::assertNull($exception->cloudflareCode);
    }

    public function testParentCodeMirrorsHttpStatus(): void
    {
        $exception = new CloudflareException('rate limited', 429, true, 30, 10013);
        self::assertSame(429, $exception->getCode());
        self::assertSame(429, $exception->httpStatus);
    }

    public function testRetryableExceptionRetainsAllFields(): void
    {
        $exception = new CloudflareException(
            'rate limited',
            429,
            true,
            30,
            10013,
        );
        self::assertSame('rate limited', $exception->getMessage());
        self::assertSame(429, $exception->httpStatus);
        self::assertTrue($exception->retryable);
        self::assertSame(30, $exception->retryAfterSeconds);
        self::assertSame(10013, $exception->cloudflareCode);
    }

    public function testNonRetryableExceptionWithoutRetryAfter(): void
    {
        $exception = new CloudflareException('bad request', 400, false, null, 1004);
        self::assertSame('bad request', $exception->getMessage());
        self::assertSame(400, $exception->httpStatus);
        self::assertFalse($exception->retryable);
        self::assertNull($exception->retryAfterSeconds);
        self::assertSame(1004, $exception->cloudflareCode);
    }

    public function testEmptyMessageIsPreserved(): void
    {
        $exception = new CloudflareException('');
        self::assertSame('', $exception->getMessage());
        self::assertSame(0, $exception->httpStatus);
    }

    public function testZeroRetryAfterSecondsIsNotNull(): void
    {
        $exception = new CloudflareException('retry now', 503, true, 0);
        self::assertSame(0, $exception->retryAfterSeconds);
        self::assertNotNull($exception->retryAfterSeconds);
    }

    public function testDuplicateRecordCodeConstantHasExpectedValue(): void
    {
        self::assertSame(81058, CloudflareException::CODE_DUPLICATE_RECORD);
    }

    public function testDuplicateRecordCodeUsableAsCloudflareCode(): void
    {
        $exception = new CloudflareException(
            'An identical record already exists',
            400,
            false,
            null,
            CloudflareException::CODE_DUPLICATE_RECORD,
        );
        self::assertSame(CloudflareException::CODE_DUPLICATE_RECORD, $exception->cloudflareCode);
        self::assertSame(81058, $exception->cloudflareCode);
    }

    public function testCanBeThrownAndCaught(): void
    {
        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage('thrown');
        $this->expectExceptionCode(502);

        throw new CloudflareException('thrown', 502, true, 5, 10000);
    }

    public function testCanBeCaughtAsRuntimeException(): void
    {
        try {
            throw new CloudflareException('boom', 500);
        } catch (RuntimeException $e) {
            self::assertInstanceOf(CloudflareException::class, $e);
            self::assertSame('boom', $e->getMessage());
            self::assertSame(500, $e->getCode());
        }
    }

    public function testPropertiesAreReadonly(): void
    {
        $exception = new CloudflareException('boom', 429, true, 30, 81058);
        $reflection = new \ReflectionClass($exception);
        self::assertTrue($reflection->getProperty('httpStatus')->isReadOnly());
        self::assertTrue($reflection->getProperty('retryable')->isReadOnly());
        self::assertTrue($reflection->getProperty('retryAfterSeconds')->isReadOnly());
        self::assertTrue($reflection->getProperty('cloudflareCode')->isReadOnly());
    }
}
