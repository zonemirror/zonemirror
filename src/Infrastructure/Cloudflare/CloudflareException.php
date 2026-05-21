<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cloudflare;

use RuntimeException;

final class CloudflareException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
