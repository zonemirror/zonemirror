<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Logging;

/**
 * Redacts anything that looks like a Cloudflare API token or bearer credential
 * before it reaches the log file. Tokens are user-supplied secrets; leaking
 * them to /var/log would compromise the whole zone.
 */
final class TokenRedactor
{
    public static function redact(string $text): string
    {
        // Bearer <token> headers
        $text = (string) preg_replace('/(Authorization:\s*Bearer\s+)[A-Za-z0-9_\-]{20,}/i', '$1[REDACTED]', $text);
        // Cloudflare API tokens (40-char alnum-with-dash blocks)
        $text = (string) preg_replace('/\b[A-Za-z0-9_\-]{40,}\b/', '[REDACTED]', $text);

        // JSON {"token":"..."} or {"token_encrypted":"..."}
        return (string) preg_replace('/("token(?:_encrypted)?"\s*:\s*")[^"]+(")/i', '$1[REDACTED]$2', $text);
    }
}
