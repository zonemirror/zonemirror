<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Cloudflare;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Cloudflare\HttpResponse;

final class HttpResponseTest extends TestCase
{
    public function testExposesConstructorArgumentsAsReadonlyProperties(): void
    {
        $response = new HttpResponse(200, ['success' => true, 'result' => ['id' => 'abc']], 30, 1199);

        self::assertSame(200, $response->status);
        self::assertSame(['success' => true, 'result' => ['id' => 'abc']], $response->body);
        self::assertSame(30, $response->retryAfterSeconds);
        self::assertSame(1199, $response->rateLimitRemaining);
    }

    public function testAllowsNullRateLimitFields(): void
    {
        $response = new HttpResponse(204, [], null, null);

        self::assertNull($response->retryAfterSeconds);
        self::assertNull($response->rateLimitRemaining);
        self::assertSame([], $response->body);
    }

    public function testIsSuccessReturnsTrueForLowerBoundary(): void
    {
        $response = new HttpResponse(200, [], null, null);

        self::assertTrue($response->isSuccess());
    }

    public function testIsSuccessReturnsTrueForUpperBoundary(): void
    {
        $response = new HttpResponse(299, [], null, null);

        self::assertTrue($response->isSuccess());
    }

    public function testIsSuccessReturnsTrueForCommonNoContent(): void
    {
        $response = new HttpResponse(204, [], null, null);

        self::assertTrue($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForRedirect(): void
    {
        $response = new HttpResponse(301, [], null, null);

        self::assertFalse($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForClientError(): void
    {
        $response = new HttpResponse(404, ['errors' => [['message' => 'not found']]], null, 0);

        self::assertFalse($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForRateLimited(): void
    {
        $response = new HttpResponse(429, [], 60, 0);

        self::assertFalse($response->isSuccess());
        self::assertSame(60, $response->retryAfterSeconds);
        self::assertSame(0, $response->rateLimitRemaining);
    }

    public function testIsSuccessReturnsFalseForServerError(): void
    {
        $response = new HttpResponse(500, [], null, null);

        self::assertFalse($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForBelowRange(): void
    {
        $response = new HttpResponse(199, [], null, null);

        self::assertFalse($response->isSuccess());
    }

    public function testIsSuccessReturnsFalseForZeroStatus(): void
    {
        $response = new HttpResponse(0, [], null, null);

        self::assertFalse($response->isSuccess());
    }

    public function testPreservesNestedBodyStructure(): void
    {
        $body = [
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => [
                ['id' => 'r1', 'type' => 'A', 'name' => 'www.example.com'],
                ['id' => 'r2', 'type' => 'AAAA', 'name' => 'www.example.com'],
            ],
        ];
        $response = new HttpResponse(200, $body, null, 1000);

        self::assertSame($body, $response->body);
        self::assertTrue($response->isSuccess());
    }
}
