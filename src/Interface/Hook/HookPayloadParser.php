<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Hook;

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
     * Normalise a record extracted from a UAPI DNS::mass_edit_zone payload
     * (the only DNS-mutating hookpoint that fires from modern cPanel/
     * Jupiter) into the historical legacy ZoneEdit shape — i.e. the keys
     * CpanelToCloudflareMapper already understands.
     *
     * The mass_edit shape is:
     *
     *   { "dname": "_dmarc", "ttl": 300, "record_type": "TXT",
     *     "data": ["dmFsdWUgYmFzZTY0"] }
     *
     * `data` carries the rrtype-specific fields in BIND order, as plain
     * strings (NOT base64 — the `parse_zone` output uses `data_b64` for
     * transport but `mass_edit_zone` input takes raw strings and writes
     * them verbatim to the zone file):
     *
     *   A / AAAA / NS / CNAME / PTR  -> [target]
     *   MX                           -> [preference, exchange]
     *   TXT                          -> [chunk1, chunk2, ...]  (concat)
     *   SRV                          -> [priority, weight, port, target]
     *   CAA                          -> [flags, tag, value]
     *   SOA                          -> we ignore (authoritative-side)
     *
     * @param array<string, mixed> $rec
     * @return array<string, mixed>|null
     */
    public static function normaliseDnsMassEditRecord(array $rec): ?array
    {
        $type = strtoupper((string) ($rec['record_type'] ?? ''));
        if ($type === '') {
            return null;
        }
        $name = (string) ($rec['dname'] ?? '');
        if ($name === '') {
            return null;
        }
        $ttl = (int) ($rec['ttl'] ?? 0);
        $rawData = is_array($rec['data'] ?? null) ? $rec['data'] : [];
        $data = array_map(static fn ($v): string => (string) $v, $rawData);

        $out = ['type' => $type, 'name' => $name, 'ttl' => $ttl];

        switch ($type) {
            case 'A':
            case 'AAAA':
                $out['address'] = $data[0] ?? '';

                return $out;
            case 'CNAME':
                $out['cname'] = $data[0] ?? '';

                return $out;
            case 'NS':
                // The mapper drops NS anyway (authoritative on Cloudflare).
                return null;
            case 'MX':
                $out['preference'] = (int) ($data[0] ?? 10);
                $out['exchange']   = $data[1] ?? '';

                return $out;
            case 'TXT':
                // BIND splits long TXT into 255-byte chunks. Cloudflare
                // wants the concatenation — TXT semantically is one
                // string regardless of the wire-level chunking.
                $out['txtdata'] = implode('', $data);

                return $out;
            case 'SRV':
                $out['priority'] = (int) ($data[0] ?? 0);
                $out['weight']   = (int) ($data[1] ?? 0);
                $out['port']     = (int) ($data[2] ?? 0);
                $out['target']   = $data[3] ?? '';

                return $out;
            case 'CAA':
                $out['flag']  = (int) ($data[0] ?? 0);
                $out['tag']   = $data[1] ?? 'issue';
                $out['value'] = $data[2] ?? '';

                return $out;
            default:
                return null;
        }
    }

    /**
     * The `add` / `edit` / `remove` fields in a DNS::mass_edit_zone payload
     * can arrive as a single JSON string OR as a list of JSON strings (or
     * a list of integers, for `remove`) depending on the caller. This
     * normalises every shape into a list and JSON-decodes string entries.
     *
     * @return list<array<string, mixed>|int>
     */
    public static function decodeMassEditField(mixed $field): array
    {
        if ($field === null || $field === '') {
            return [];
        }
        $items = is_array($field) ? array_values($field) : [$field];
        $out = [];
        foreach ($items as $it) {
            if (is_array($it)) {
                $out[] = $it;

                continue;
            }
            if (is_int($it)) {
                $out[] = $it;

                continue;
            }
            if (is_string($it)) {
                $trim = trim($it);
                if ($trim === '') {
                    continue;
                }
                // remove= is always a stringified line_index.
                if (ctype_digit($trim)) {
                    $out[] = (int) $trim;

                    continue;
                }
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }

        return $out;
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
