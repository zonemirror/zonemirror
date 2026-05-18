<?php

declare(strict_types=1);

namespace CfSync\Interface\Hook;

/**
 * Pulls the (domain, action, raw record) tuple out of a cPanel standardized
 * hook payload. cPanel feeds the payload on stdin as JSON; the shape varies
 * by hook event but always nests the API arguments under data.args and the
 * function result under data.result.data.
 */
final class HookPayloadParser
{
    /**
     * @param array<string, mixed> $payload
     * @return array{domain: string, raw: array<string, mixed>}|null
     */
    public static function extract(array $payload): ?array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $args = is_array($data['args'] ?? null) ? $data['args'] : [];
        $resultData = is_array($data['result']['data'] ?? null) ? $data['result']['data'] : [];

        $domain = (string) ($args['domain'] ?? $args['zone'] ?? '');
        if ($domain === '') {
            return null;
        }

        // Some hookpoints (mass_edit_zone) carry a list under data.result.data.
        if (isset($resultData[0]) && is_array($resultData[0])) {
            // Caller should iterate; expose only the first entry to keep the
            // single-record contract. mass_edit handler walks the array itself.
            return ['domain' => $domain, 'raw' => $resultData[0]];
        }

        return ['domain' => $domain, 'raw' => $resultData + $args];
    }

    /**
     * Build a stable idempotency key from action + domain + record shape so
     * duplicate cPanel hook fires (e.g. cPanel retries on transient errors)
     * collapse into a single Cloudflare call.
     *
     * @param array<string, mixed> $raw
     */
    public static function idempotencyKey(string $action, string $domain, array $raw): string
    {
        $material = [
            $action,
            strtolower($domain),
            strtoupper((string) ($raw['type'] ?? '')),
            strtolower(rtrim((string) ($raw['name'] ?? $raw['dname'] ?? ''), '.')),
            (string) ($raw['address'] ?? $raw['cname'] ?? $raw['exchange'] ?? $raw['txtdata'] ?? $raw['target'] ?? $raw['content'] ?? ''),
            (string) ($raw['preference'] ?? $raw['priority'] ?? ''),
            (string) ($raw['port'] ?? ''),
        ];

        return hash('sha256', implode('|', $material));
    }
}
