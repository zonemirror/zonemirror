<?php

declare(strict_types=1);

namespace CfSync\Infrastructure\Cloudflare;

/**
 * Plain immutable container for a parsed Cloudflare HTTP response, including
 * the bits we need to honour rate-limit hints from the server.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public int $status,
        public array $body,
        public ?int $retryAfterSeconds,
        public ?int $rateLimitRemaining,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
