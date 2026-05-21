<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Queue;

/**
 * Exponential backoff with full jitter. Caps the delay so a stuck event
 * eventually retries within a useful operational window instead of drifting
 * out to hours.
 */
final class BackoffPolicy
{
    private const BASE_SECONDS = 2;
    private const MAX_SECONDS = 600;
    private const MAX_ATTEMPTS = 8;

    public function nextDelaySeconds(int $attempts): int
    {
        $expo = (int) min(self::MAX_SECONDS, self::BASE_SECONDS ** $attempts);

        return random_int(1, max(1, $expo));
    }

    public function shouldDeadLetter(int $attempts): bool
    {
        return $attempts >= self::MAX_ATTEMPTS;
    }

    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }
}
