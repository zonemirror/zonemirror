<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cloudflare;

/**
 * Plain immutable container for a parsed Cloudflare HTTP response, including
 * the bits we need to honour rate-limit hints from the server.
 */
final class HttpResponse
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly ?int $retryAfterSeconds,
        public readonly ?int $rateLimitRemaining,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
