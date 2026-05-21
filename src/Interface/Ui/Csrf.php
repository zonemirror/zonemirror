<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Ui;

/**
 * Per-session CSRF token using PHP's native session. Tokens are validated
 * with hash_equals to avoid timing-based oracle attacks.
 */
final class Csrf
{
    private const SESSION_KEY = 'zonemirror_csrf';

    public static function token(): string
    {
        self::ensureSession();
        $current = isset($_SESSION[self::SESSION_KEY]) ? (string) $_SESSION[self::SESSION_KEY] : '';
        if ($current === '') {
            $current = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $current;
        }

        return $current;
    }

    public static function verify(?string $candidate): bool
    {
        self::ensureSession();
        $expected = (string) ($_SESSION[self::SESSION_KEY] ?? '');
        if ($expected === '' || !is_string($candidate) || $candidate === '') {
            return false;
        }
        $ok = hash_equals($expected, $candidate);
        if ($ok) {
            // Rotate on successful verify so the same token cannot be replayed.
            unset($_SESSION[self::SESSION_KEY]);
        }

        return $ok;
    }

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}
