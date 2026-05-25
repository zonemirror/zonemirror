<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cpanel;

use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;

/**
 * Minimal BIND zone-file parser, scoped to what cPanel writes into
 * /var/named/<zone>.db. Used by the initial-seed flow so that connecting
 * a domain propagates the existing local DNS state to Cloudflare in one
 * pass instead of waiting for the user to edit each record afterwards.
 *
 * Scope on purpose:
 *  - Records we will sync to Cloudflare: A, AAAA, CNAME, MX, TXT, SRV, CAA.
 *  - NS and SOA are dropped — Cloudflare owns the authoritative ones and
 *    propagating them would either no-op or corrupt the zone delegation.
 *  - Other rrtypes (HINFO, NAPTR, …) are also ignored; cPanel's Zone
 *    Editor does not surface them.
 *
 * Format peculiarities cPanel relies on that we honour:
 *  - `;` introduces a comment, except inside quoted TXT strings.
 *  - Parenthesised continuations (SOA, multi-string TXT) span lines.
 *  - TXT rdata may be one or more "quoted strings" that the DNS protocol
 *    concatenates with NO separator.
 *  - A blank/whitespace-leading name on a record line inherits the
 *    previous owner name.
 *  - Trailing-dot on an owner name or rdata means absolute; otherwise
 *    relative to $ORIGIN (default = zone name).
 */
final class BindZoneParser
{
    private const KNOWN_TYPES = [
        'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA',
        'NS', 'SOA', 'PTR', 'DNAME', 'HINFO', 'NAPTR', 'TLSA',
    ];

    /**
     * @return list<DnsRecord>
     */
    public function parse(string $contents, string $originDomain): array
    {
        $origin = rtrim(strtolower(trim($originDomain)), '.');
        $contents = $this->unwrapMultiline($contents);

        $defaultTtl = 14400;
        $previousName = $origin;
        $records = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $rawLine) {
            $line = rtrim($this->stripComment($rawLine));
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s*\$TTL\s+(\d+)/i', $line, $m) === 1) {
                $defaultTtl = (int) $m[1];

                continue;
            }
            if (preg_match('/^\s*\$ORIGIN\s+(\S+)/i', $line, $m) === 1) {
                $origin = rtrim(strtolower($m[1]), '.');

                continue;
            }
            if (preg_match('/^\s*\$/', $line) === 1) {
                continue;
            }

            $leadingBlank = preg_match('/^[ \t]/', $line) === 1;
            $tokens = $this->tokenize(trim($line));
            if ($tokens === []) {
                continue;
            }

            $i = 0;
            $name = $previousName;
            if (!$leadingBlank) {
                if (
                    !$this->isTtlOrClass($tokens[0])
                    && !$this->isKnownType($tokens[0])
                ) {
                    $name = $tokens[$i];
                    $i++;
                }
            }

            $ttl = $defaultTtl;
            while ($i < count($tokens)) {
                $t = $tokens[$i];
                if (preg_match('/^\d+$/', $t) === 1) {
                    $ttl = (int) $t;
                    $i++;

                    continue;
                }
                if (strtoupper($t) === 'IN') {
                    $i++;

                    continue;
                }
                break;
            }
            if ($i >= count($tokens)) {
                continue;
            }
            $type = strtoupper($tokens[$i]);
            $i++;
            $rdata = array_slice($tokens, $i);
            if ($rdata === []) {
                continue;
            }

            $previousName = $name;
            $absoluteName = $this->absoluteName($name, $origin);

            $rec = $this->build($type, $absoluteName, max(60, $ttl), $rdata);
            if ($rec !== null) {
                $records[] = $rec;
            }
        }

        return $records;
    }

    /**
     * @param list<string> $rdata
     */
    private function build(string $type, string $name, int $ttl, array $rdata): ?DnsRecord
    {
        return match ($type) {
            'A' => new DnsRecord(RecordType::A, $name, $rdata[0], $ttl, null, false, []),
            'AAAA' => new DnsRecord(RecordType::AAAA, $name, $rdata[0], $ttl, null, false, []),
            'CNAME' => new DnsRecord(
                RecordType::CNAME,
                $name,
                rtrim(strtolower($rdata[0]), '.'),
                $ttl,
                null,
                false,
                [],
            ),
            'MX' => count($rdata) >= 2
                ? new DnsRecord(
                    RecordType::MX,
                    $name,
                    rtrim(strtolower($rdata[1]), '.'),
                    $ttl,
                    (int) $rdata[0],
                    null,
                    [],
                )
                : null,
            'TXT' => new DnsRecord(
                RecordType::TXT,
                $name,
                $this->joinTxtStrings($rdata),
                $ttl,
                null,
                null,
                [],
            ),
            'SRV' => count($rdata) >= 4
                ? $this->buildSrv($name, $ttl, $rdata)
                : null,
            'CAA' => count($rdata) >= 3
                ? new DnsRecord(
                    RecordType::CAA,
                    $name,
                    null,
                    $ttl,
                    null,
                    null,
                    [
                        'flags' => (int) $rdata[0],
                        'tag' => strtolower($rdata[1]),
                        'value' => $this->stripOuterQuotes($rdata[2]),
                    ],
                )
                : null,
            default => null,
        };
    }

    /**
     * @param list<string> $rdata
     */
    private function buildSrv(string $name, int $ttl, array $rdata): DnsRecord
    {
        $parts = explode('.', $name);
        $service = isset($parts[0]) && str_starts_with($parts[0], '_') ? $parts[0] : '_service';
        $proto = isset($parts[1]) && str_starts_with($parts[1], '_') ? $parts[1] : '_tcp';
        $domain = count($parts) >= 3 ? implode('.', array_slice($parts, 2)) : $name;

        return new DnsRecord(
            type: RecordType::SRV,
            name: $name,
            content: null,
            ttl: $ttl,
            priority: null,
            proxied: null,
            data: [
                'service' => $service,
                'proto' => $proto,
                'name' => $domain,
                'priority' => (int) $rdata[0],
                'weight' => (int) $rdata[1],
                'port' => (int) $rdata[2],
                'target' => rtrim($rdata[3], '.'),
            ],
        );
    }

    private function absoluteName(string $name, string $origin): string
    {
        $name = strtolower(trim($name));
        if ($name === '' || $name === '@') {
            return $origin;
        }
        if (str_ends_with($name, '.')) {
            return rtrim($name, '.');
        }

        return $name . '.' . $origin;
    }

    private function isKnownType(string $token): bool
    {
        return in_array(strtoupper($token), self::KNOWN_TYPES, true);
    }

    private function isTtlOrClass(string $token): bool
    {
        return preg_match('/^\d+$/', $token) === 1 || strtoupper($token) === 'IN';
    }

    /**
     * Splits a line on whitespace, but keeps "quoted strings" intact and
     * preserves backslash-escapes (notably `\;` inside TXT rdata, which
     * cPanel uses to embed semicolons that would otherwise look like
     * comment starts).
     *
     * @return list<string>
     */
    private function tokenize(string $line): array
    {
        $tokens = [];
        $current = '';
        $inQuote = false;
        $escape = false;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($escape) {
                $current .= $c;
                $escape = false;

                continue;
            }
            if ($c === '\\') {
                $escape = true;
                $current .= $c;

                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
                $current .= $c;

                continue;
            }
            if (!$inQuote && ($c === ' ' || $c === "\t")) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }

                continue;
            }
            $current .= $c;
        }
        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Strip the unquoted-`;` comment tail from a line. Inside a quoted
     * string a literal `;` is data, and `\;` anywhere is data too.
     */
    private function stripComment(string $line): string
    {
        $inQuote = false;
        $escape = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($escape) {
                $escape = false;

                continue;
            }
            if ($c === '\\') {
                $escape = true;

                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;

                continue;
            }
            if (!$inQuote && $c === ';') {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    /**
     * Join the contents of a paren-block onto a single logical line so the
     * line-by-line parser doesn't need to know about continuation.
     */
    private function unwrapMultiline(string $contents): string
    {
        $out = '';
        $depth = 0;
        $inQuote = false;
        $escape = false;
        $len = strlen($contents);

        for ($i = 0; $i < $len; $i++) {
            $c = $contents[$i];
            if ($escape) {
                $out .= $c;
                $escape = false;

                continue;
            }
            if ($c === '\\') {
                $escape = true;
                $out .= $c;

                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
                $out .= $c;

                continue;
            }
            if (!$inQuote) {
                if ($c === '(') {
                    $depth++;

                    continue;
                }
                if ($c === ')') {
                    $depth = max(0, $depth - 1);

                    continue;
                }
                if ($depth > 0 && ($c === "\n" || $c === "\r")) {
                    $out .= ' ';

                    continue;
                }
            }
            $out .= $c;
        }

        return $out;
    }

    /**
     * @param list<string> $rdata
     */
    private function joinTxtStrings(array $rdata): string
    {
        $out = '';
        foreach ($rdata as $part) {
            $part = $this->stripOuterQuotes($part);
            $part = preg_replace('/\\\\(.)/', '$1', $part) ?? $part;
            $out .= $part;
        }

        return $out;
    }

    private function stripOuterQuotes(string $s): string
    {
        if (strlen($s) >= 2 && $s[0] === '"' && $s[strlen($s) - 1] === '"') {
            return substr($s, 1, -1);
        }

        return $s;
    }
}
