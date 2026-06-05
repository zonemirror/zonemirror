<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Interface\Ui;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Interface\Ui\Csrf;

final class CsrfTest extends TestCase
{
    private const SESSION_KEY = 'zonemirror_csrf';

    protected function setUp(): void
    {
        // Ensure a clean session state for each test. We start a session here
        // so Csrf's ensureSession() short-circuits and we control $_SESSION.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTokenReturnsHexStringOf64Chars(): void
    {
        $token = Csrf::token();

        self::assertNotSame('', $token);
        self::assertSame(64, strlen($token));
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $token));
    }

    public function testTokenStoresValueInSessionUnderExpectedKey(): void
    {
        $token = Csrf::token();

        self::assertArrayHasKey(self::SESSION_KEY, $_SESSION);
        self::assertSame($token, $_SESSION[self::SESSION_KEY]);
    }

    public function testTokenReturnsSameValueOnRepeatedCallsUntilRotated(): void
    {
        $first = Csrf::token();
        $second = Csrf::token();
        $third = Csrf::token();

        self::assertSame($first, $second);
        self::assertSame($first, $third);
    }

    public function testTokenRegeneratesWhenSessionEntryIsEmptyString(): void
    {
        $_SESSION[self::SESSION_KEY] = '';

        $token = Csrf::token();

        self::assertNotSame('', $token);
        self::assertSame(64, strlen($token));
        self::assertSame($token, $_SESSION[self::SESSION_KEY]);
    }

    public function testTokenCoercesNonStringSessionValueToString(): void
    {
        // The implementation casts the session value to string; a non-empty
        // coerced value should be returned as-is rather than regenerated.
        $_SESSION[self::SESSION_KEY] = 12345;

        $token = Csrf::token();

        self::assertSame('12345', $token);
    }

    public function testVerifyReturnsTrueForMatchingToken(): void
    {
        $token = Csrf::token();

        self::assertTrue(Csrf::verify($token));
    }

    public function testVerifyRotatesTokenOnSuccessSoReplayFails(): void
    {
        $token = Csrf::token();

        self::assertTrue(Csrf::verify($token));
        // Session entry must be cleared so the same candidate cannot be reused.
        self::assertArrayNotHasKey(self::SESSION_KEY, $_SESSION);
        self::assertFalse(Csrf::verify($token));
    }

    public function testVerifyReturnsFalseForMismatchedCandidate(): void
    {
        $token = Csrf::token();
        $wrong = str_repeat('0', strlen($token));

        self::assertNotSame($token, $wrong);
        self::assertFalse(Csrf::verify($wrong));
        // On failure, the stored token must remain intact.
        self::assertSame($token, $_SESSION[self::SESSION_KEY]);
    }

    public function testVerifyReturnsFalseForNullCandidate(): void
    {
        Csrf::token();

        self::assertFalse(Csrf::verify(null));
    }

    public function testVerifyReturnsFalseForEmptyCandidate(): void
    {
        Csrf::token();

        self::assertFalse(Csrf::verify(''));
    }

    public function testVerifyReturnsFalseWhenNoTokenStored(): void
    {
        // No previous Csrf::token() call -> session has no entry.
        self::assertArrayNotHasKey(self::SESSION_KEY, $_SESSION);
        self::assertFalse(Csrf::verify('whatever'));
    }

    public function testVerifyReturnsFalseWhenStoredTokenIsEmptyString(): void
    {
        $_SESSION[self::SESSION_KEY] = '';

        self::assertFalse(Csrf::verify('whatever'));
    }

    public function testTokenAfterSuccessfulVerifyProducesNewValue(): void
    {
        $first = Csrf::token();
        self::assertTrue(Csrf::verify($first));

        $second = Csrf::token();

        self::assertNotSame($first, $second);
        self::assertSame(64, strlen($second));
    }

    public function testVerifyDoesNotRotateOnFailedAttempt(): void
    {
        $token = Csrf::token();

        self::assertFalse(Csrf::verify('not-the-token'));
        self::assertFalse(Csrf::verify(null));
        self::assertFalse(Csrf::verify(''));

        // The original token must still verify after failed attempts.
        self::assertTrue(Csrf::verify($token));
    }

    public function testTokenIsLengthOf32BytesHexEncoded(): void
    {
        // Boundary: random_bytes(32) -> 64 hex chars, never less.
        $samples = [];
        for ($i = 0; $i < 5; $i++) {
            $_SESSION = [];
            $samples[] = Csrf::token();
        }

        foreach ($samples as $sample) {
            self::assertSame(64, strlen($sample));
            self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $sample));
        }
        // All five regenerations must yield distinct tokens.
        self::assertCount(5, array_unique($samples));
    }
}
