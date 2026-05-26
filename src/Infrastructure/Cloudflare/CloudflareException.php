<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cloudflare;

use RuntimeException;

final class CloudflareException extends RuntimeException
{
    /**
     * 81058 = "An identical record already exists" — surfaced so the worker
     * can treat a duplicate-create race as a no-op instead of failing the
     * queue item. Keep as int (not enum) because Cloudflare publishes the
     * codes as documentation, not as a stable typed contract.
     */
    public const CODE_DUPLICATE_RECORD = 81058;

    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?int $cloudflareCode = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
