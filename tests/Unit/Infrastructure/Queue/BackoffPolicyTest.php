<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Queue;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Queue\BackoffPolicy;

final class BackoffPolicyTest extends TestCase
{
    private BackoffPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new BackoffPolicy();
    }

    public function testMaxAttemptsReturnsConfiguredCeiling(): void
    {
        self::assertSame(8, $this->policy->maxAttempts());
    }

    public function testShouldDeadLetterFalseBelowCeiling(): void
    {
        self::assertFalse($this->policy->shouldDeadLetter(0));
        self::assertFalse($this->policy->shouldDeadLetter(1));
        self::assertFalse($this->policy->shouldDeadLetter(7));
    }

    public function testShouldDeadLetterTrueAtCeiling(): void
    {
        self::assertTrue($this->policy->shouldDeadLetter(8));
    }

    public function testShouldDeadLetterTrueAboveCeiling(): void
    {
        self::assertTrue($this->policy->shouldDeadLetter(9));
        self::assertTrue($this->policy->shouldDeadLetter(100));
    }

    public function testNextDelayForZeroAttemptsReturnsOne(): void
    {
        // 2^0 = 1, so random_int(1, max(1, 1)) is always 1.
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(1, $this->policy->nextDelaySeconds(0));
        }
    }

    public function testNextDelayForFirstAttemptIsWithinJitterWindow(): void
    {
        // 2^1 = 2 → random_int(1, 2) ∈ {1, 2}.
        for ($i = 0; $i < 30; $i++) {
            $delay = $this->policy->nextDelaySeconds(1);
            self::assertGreaterThanOrEqual(1, $delay);
            self::assertLessThanOrEqual(2, $delay);
        }
    }

    public function testNextDelayForSmallAttemptStaysUnderExponentialCap(): void
    {
        // 2^4 = 16 → random_int(1, 16).
        for ($i = 0; $i < 50; $i++) {
            $delay = $this->policy->nextDelaySeconds(4);
            self::assertGreaterThanOrEqual(1, $delay);
            self::assertLessThanOrEqual(16, $delay);
        }
    }

    public function testNextDelayCapsAtMaxSecondsForLargeAttemptCounts(): void
    {
        // 2^15 = 32768, well above the 600s cap. Delay must stay in [1, 600].
        for ($i = 0; $i < 50; $i++) {
            $delay = $this->policy->nextDelaySeconds(15);
            self::assertGreaterThanOrEqual(1, $delay);
            self::assertLessThanOrEqual(600, $delay);
        }
    }

    public function testNextDelayProducesValuesAcrossJitterRange(): void
    {
        // With 50 samples in [1, 16], we should see at least 2 distinct values
        // unless random_int is broken. Validates the jitter is actually applied.
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[$this->policy->nextDelaySeconds(4)] = true;
        }
        self::assertGreaterThan(1, count($seen), 'jitter should produce a spread of values');
    }

    public function testNextDelayNeverReturnsZeroOrNegative(): void
    {
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 20] as $attempts) {
            for ($i = 0; $i < 10; $i++) {
                $delay = $this->policy->nextDelaySeconds($attempts);
                self::assertGreaterThanOrEqual(1, $delay, "attempts={$attempts} produced sub-1 delay");
            }
        }
    }
}
