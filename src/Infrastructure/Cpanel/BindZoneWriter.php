<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cpanel;

use RuntimeException;
use ZoneMirror\Infrastructure\Storage\Paths;

/**
 * Rewrites the `_dmarc*` TXT records of a cPanel-managed BIND zone file
 * (/var/named/<zone>.db), bumps the SOA serial and asks PowerDNS / BIND
 * to reload the zone.
 *
 * The writer is intentionally line-oriented: we don't reformat the file,
 * we don't reorder records, we don't touch anything that isn't a DMARC
 * TXT we already targeted. That keeps the diff against cPanel-emitted
 * zones minimal so `diff -u /var/named/x.db.bak /var/named/x.db` after
 * an apply shows only the line(s) we changed plus the SOA serial bump —
 * which is exactly what an operator wants to verify by eye.
 *
 * cPanel's escaping rules inside TXT strings:
 *   - `;` → `\;` (otherwise it starts a comment)
 *   - `@` → `\@` (BIND only needs this for owner names, not rdata, but
 *                 cPanel escapes it anyway; we follow suit so a fresh
 *                 `cPanel update` doesn't re-diff the line back)
 *
 * SOA bump strategy:
 *   - Parse the serial as either YYYYMMDDnn (the cPanel default) or a
 *     bare integer. If today's date prefix matches the existing one,
 *     increment nn; otherwise reset to today + 01. For a non-date
 *     serial, just `+1`.
 *
 * Reload:
 *   - Prefer whmapi1 reload_dns_zone (covers both PowerDNS and BIND on
 *     cPanel and triggers any downstream notify-out the operator has
 *     configured). Fall back to `pdns_control reload <zone>` and then
 *     `rndc reload <zone>`. A failed reload is non-fatal — the file is
 *     already on disk; the operator will see the warning in the CLI
 *     output and can reload manually.
 */
final class BindZoneWriter
{
    /**
     * @return list<array{owner: string, previous: string, lineno: int}> Records that ARE present and look like DMARC placeholders. Owner is the raw label (e.g. "_dmarc", "_dmarc.agent").
     */
    public function findDmarcRecords(string $zoneContents): array
    {
        $out = [];
        $lines = preg_split('/\r\n|\r|\n/', $zoneContents);
        if ($lines === false) {
            return [];
        }
        foreach ($lines as $idx => $line) {
            $parsed = $this->parseDmarcLine($line);
            if ($parsed !== null) {
                $out[] = ['owner' => $parsed['owner'], 'previous' => $parsed['content'], 'lineno' => $idx + 1];
            }
        }

        return $out;
    }

    /**
     * Rewrite every `_dmarc*` TXT record in $zoneContents to $newContent,
     * preserving the line's TTL / class / type / quoting style. Returns
     * the new file contents plus a list of changes (so callers can record
     * them in LocalRewriteState).
     *
     * @return array{contents: string, changes: list<array{owner: string, previous: string, applied: string}>}
     */
    public function rewriteDmarc(string $zoneContents, string $newContent): array
    {
        $changes = [];
        $applied = $this->escapeTxtContent($newContent);

        $lines = preg_split('/\r\n|\r|\n/', $zoneContents);
        if ($lines === false) {
            return ['contents' => $zoneContents, 'changes' => []];
        }
        $hasCRLF = str_contains($zoneContents, "\r\n");
        $newlineEnding = str_ends_with($zoneContents, "\n");

        foreach ($lines as $i => $line) {
            $parsed = $this->parseDmarcLine($line);
            if ($parsed === null) {
                continue;
            }
            if ($parsed['content'] === $newContent) {
                // Already what we want — leave alone. The change list
                // does NOT record idempotent passes so the SOA bump is
                // suppressed too (see applyToZone()).
                continue;
            }
            $rebuilt = $parsed['prefix'] . '"' . $applied . '"' . $parsed['suffix'];
            $lines[$i] = $rebuilt;
            $changes[] = [
                'owner'    => $parsed['owner'],
                'previous' => $parsed['content'],
                'applied'  => $newContent,
            ];
        }

        $joined = implode($hasCRLF ? "\r\n" : "\n", $lines);
        if ($newlineEnding && !str_ends_with($joined, "\n") && !str_ends_with($joined, "\r\n")) {
            $joined .= $hasCRLF ? "\r\n" : "\n";
        }

        return ['contents' => $joined, 'changes' => $changes];
    }

    /**
     * Bump the SOA serial in-place. Idempotent within the same call but
     * NOT idempotent across calls: every invocation produces a strictly
     * greater serial, which is what slave nameservers want to see.
     */
    public function bumpSoaSerial(string $zoneContents): string
    {
        // SOA can be either single-line (cPanel default with many tabs)
        // or multi-line in parens. Either way the serial is the FIRST
        // bare integer that appears AFTER the SOA token and BEFORE the
        // closing paren / EOL.
        $pos = $this->findSoaSerialOffset($zoneContents);
        if ($pos === null) {
            return $zoneContents;
        }
        [$start, $end] = $pos;
        $current = substr($zoneContents, $start, $end - $start);
        $next = $this->nextSerial($current);

        return substr($zoneContents, 0, $start) . $next . substr($zoneContents, $end);
    }

    /**
     * Apply a rewrite to a zone on disk, atomic write + SOA bump + reload.
     *
     * @return array{changes: list<array{owner: string, previous: string, applied: string}>, reloaded: bool, reload_method: string, error: ?string}
     */
    public function applyToZone(string $zone, string $newContent): array
    {
        $path = Paths::bindZoneFile($zone);
        if (!is_file($path) || !is_readable($path)) {
            return ['changes' => [], 'reloaded' => false, 'reload_method' => '', 'error' => 'zone file missing: ' . $path];
        }
        $orig = @file_get_contents($path);
        if ($orig === false) {
            return ['changes' => [], 'reloaded' => false, 'reload_method' => '', 'error' => 'cannot read zone file'];
        }
        $rw = $this->rewriteDmarc($orig, $newContent);
        if ($rw['changes'] === []) {
            return ['changes' => [], 'reloaded' => false, 'reload_method' => '', 'error' => null];
        }
        $bumped = $this->bumpSoaSerial($rw['contents']);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bumped, LOCK_EX) === false) {
            return ['changes' => [], 'reloaded' => false, 'reload_method' => '', 'error' => 'cannot write tmp file'];
        }
        // Match cPanel's mode/ownership on the original to avoid surprising
        // a future zone regeneration with a different file owner.
        @chmod($tmp, fileperms($path) & 0o7777);
        $stat = @stat($path);
        if (is_array($stat)) {
            @chown($tmp, $stat['uid']);
            @chgrp($tmp, $stat['gid']);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return ['changes' => [], 'reloaded' => false, 'reload_method' => '', 'error' => 'cannot install zone file'];
        }
        $reload = $this->reloadZone($zone);

        return [
            'changes'       => $rw['changes'],
            'reloaded'      => $reload['ok'],
            'reload_method' => $reload['method'],
            'error'         => $reload['ok'] ? null : ($reload['error'] ?? 'reload failed'),
        ];
    }

    /**
     * Replace a single owner's DMARC record back to a specific value.
     * Used by the revert path so we can restore EXACTLY what was there
     * before the plugin touched the zone, not the current template.
     *
     * @return array{ok: bool, error: ?string, reloaded: bool, reload_method: string}
     */
    public function revertSingle(string $zone, string $ownerName, string $previousContent): array
    {
        $path = Paths::bindZoneFile($zone);
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'error' => 'zone file missing', 'reloaded' => false, 'reload_method' => ''];
        }
        $orig = @file_get_contents($path);
        if ($orig === false) {
            return ['ok' => false, 'error' => 'cannot read zone file', 'reloaded' => false, 'reload_method' => ''];
        }
        $applied = $this->escapeTxtContent($previousContent);
        $lines = preg_split('/\r\n|\r|\n/', $orig);
        if ($lines === false) {
            return ['ok' => false, 'error' => 'cannot split lines', 'reloaded' => false, 'reload_method' => ''];
        }
        $hasCRLF = str_contains($orig, "\r\n");
        $newlineEnding = str_ends_with($orig, "\n");
        $found = false;
        $needle = strtolower($ownerName);
        foreach ($lines as $i => $line) {
            $parsed = $this->parseDmarcLine($line);
            if ($parsed === null) {
                continue;
            }
            if (strtolower($parsed['owner']) !== $needle) {
                continue;
            }
            $lines[$i] = $parsed['prefix'] . '"' . $applied . '"' . $parsed['suffix'];
            $found = true;

            break;
        }
        if (!$found) {
            return ['ok' => false, 'error' => 'record not found in zone', 'reloaded' => false, 'reload_method' => ''];
        }
        $joined = implode($hasCRLF ? "\r\n" : "\n", $lines);
        if ($newlineEnding && !str_ends_with($joined, "\n") && !str_ends_with($joined, "\r\n")) {
            $joined .= $hasCRLF ? "\r\n" : "\n";
        }
        $bumped = $this->bumpSoaSerial($joined);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bumped, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'cannot write tmp file', 'reloaded' => false, 'reload_method' => ''];
        }
        @chmod($tmp, fileperms($path) & 0o7777);
        $stat = @stat($path);
        if (is_array($stat)) {
            @chown($tmp, $stat['uid']);
            @chgrp($tmp, $stat['gid']);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'cannot install zone file', 'reloaded' => false, 'reload_method' => ''];
        }
        $reload = $this->reloadZone($zone);

        return [
            'ok'            => true,
            'error'         => $reload['ok'] ? null : ($reload['error'] ?? 'reload failed'),
            'reloaded'      => $reload['ok'],
            'reload_method' => $reload['method'],
        ];
    }

    /**
     * @return array{owner: string, content: string, prefix: string, suffix: string}|null Returns null for non-DMARC lines (comments, other records, blank lines).
     */
    private function parseDmarcLine(string $line): ?array
    {
        // Match: <owner>\s+<ttl>?\s+(IN\s+)?TXT\s+"<content>"<trailing>
        // owner: starts at column 0 (no leading whitespace), is "_dmarc" or "_dmarc.<sub>"
        if (preg_match('/^([_a-z0-9][_a-z0-9.\-]*)(\s+\d+)?(\s+IN)?\s+TXT\s+"((?:[^"\\\\]|\\\\.)*)"([^\n]*)$/i', $line, $m) !== 1) {
            return null;
        }
        $owner = $m[1];
        // Only care about `_dmarc` exact label or anything that starts
        // with `_dmarc.`. A label like `_dmarcsomething` is not DMARC.
        $lower = strtolower($owner);
        if ($lower !== '_dmarc' && !str_starts_with($lower, '_dmarc.')) {
            return null;
        }
        $contentEscaped = $m[4];
        $content = $this->unescapeTxtContent($contentEscaped);
        if (stripos($content, 'v=DMARC1') !== 0) {
            return null;
        }
        $quotePos = strpos($line, '"');
        if ($quotePos === false) {
            return null;
        }
        $prefix = substr($line, 0, $quotePos);
        $suffix = $m[5];

        return [
            'owner'   => $owner,
            'content' => $content,
            'prefix'  => $prefix,
            'suffix'  => $suffix,
        ];
    }

    private function escapeTxtContent(string $s): string
    {
        // Order matters: escape backslash first so we don't double-escape
        // the backslashes we add for ; and @.
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace(';', '\\;', $s);
        $s = str_replace('@', '\\@', $s);
        $s = str_replace('"', '\\"', $s);

        return $s;
    }

    private function unescapeTxtContent(string $s): string
    {
        // Reverse of escapeTxtContent for round-trip comparison. The
        // BindZoneParser already does something similar for read-path
        // records; here we keep a local copy so the writer is self-contained.
        return preg_replace('/\\\\(.)/', '$1', $s) ?? $s;
    }

    /**
     * Find the byte-offset window of the SOA serial in $contents. Returns
     * [start, end] in bytes (substr-style) or null if no SOA line found.
     *
     * @return array{0: int, 1: int}|null
     */
    private function findSoaSerialOffset(string $contents): ?array
    {
        // Locate the SOA line (`IN  SOA  …`) and scan forward for the
        // first standalone integer. The integer is the serial: per
        // RFC 1035 §3.3.13 it always precedes refresh/retry/expire/min.
        if (preg_match('/\bIN\s+SOA\b/i', $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $hit = $m[0];
        $hitText = $hit[0];
        $hitOffset = $hit[1];
        if ($hitOffset < 0) {
            return null;
        }
        $cursor = $hitOffset + strlen($hitText);
        $len = strlen($contents);
        $closingParen = strpos($contents, ')', $cursor);
        $boundary = $closingParen === false ? $len : $closingParen;

        // Skip the two domain-name fields (primary NS + responsible
        // mailbox), then the optional `(`, then look for the first
        // digit-run.
        $state = 'awaiting_name1';
        $i = $cursor;
        while ($i < $boundary) {
            $c = $contents[$i];
            if ($c === ';') {
                // Comment to end of line — skip.
                $eol = strpos($contents, "\n", $i);
                $i = $eol === false ? $boundary : $eol;

                continue;
            }
            if (ctype_space($c) || $c === '(' || $c === ')') {
                $i++;

                continue;
            }
            if ($state === 'awaiting_name1' || $state === 'awaiting_name2') {
                // Consume the token.
                $tokenEnd = $i;
                while ($tokenEnd < $boundary) {
                    $c2 = $contents[$tokenEnd];
                    if (ctype_space($c2) || $c2 === ';') {
                        break;
                    }
                    $tokenEnd++;
                }
                $state = $state === 'awaiting_name1' ? 'awaiting_name2' : 'awaiting_serial';
                $i = $tokenEnd;

                continue;
            }
            // State: awaiting_serial. Expect a digit.
            if (!ctype_digit($c)) {
                return null;
            }
            $serialEnd = $i;
            while ($serialEnd < $boundary && ctype_digit($contents[$serialEnd])) {
                $serialEnd++;
            }

            return [$i, $serialEnd];
        }

        return null;
    }

    private function nextSerial(string $current): string
    {
        $today = gmdate('Ymd');
        if (preg_match('/^(\d{8})(\d{2})$/', $current, $m) === 1) {
            if ($m[1] === $today) {
                $nn = (int) $m[2] + 1;
                if ($nn > 99) {
                    // The day overflows after 99 bumps. RFC 1982 wraparound
                    // is safe; fall back to today + 99 -> +1 = (today+1)01.
                    return gmdate('Ymd', time() + 86400) . '01';
                }

                return $today . sprintf('%02d', $nn);
            }

            return $today . '01';
        }

        // Non-date serial — just increment. Unsigned 32-bit wraparound
        // is implicit in DNS, but PHP ints are 64-bit so plain +1 works.
        return (string) (((int) $current) + 1);
    }

    /**
     * @return array{ok: bool, method: string, error: ?string}
     */
    private function reloadZone(string $zone): array
    {
        $zone = strtolower(rtrim($zone, '.'));
        // Try whmapi1 first (works for both PowerDNS and BIND on cPanel
        // and is the documented public surface).
        if (is_executable('/usr/local/cpanel/bin/whmapi1')) {
            $cmd = sprintf(
                '/usr/local/cpanel/bin/whmapi1 reload_dns_zone zone=%s 2>&1',
                escapeshellarg($zone),
            );
            exec($cmd, $out, $rc);
            if ($rc === 0) {
                return ['ok' => true, 'method' => 'whmapi1', 'error' => null];
            }
        }
        if (is_executable('/usr/bin/pdns_control')) {
            exec('/usr/bin/pdns_control reload ' . escapeshellarg($zone) . ' 2>&1', $out, $rc);
            if ($rc === 0) {
                return ['ok' => true, 'method' => 'pdns_control', 'error' => null];
            }
        }
        if (is_executable('/usr/sbin/rndc')) {
            exec('/usr/sbin/rndc reload ' . escapeshellarg($zone) . ' 2>&1', $out, $rc);
            if ($rc === 0) {
                return ['ok' => true, 'method' => 'rndc', 'error' => null];
            }
        }

        return ['ok' => false, 'method' => '', 'error' => 'no reload command succeeded'];
    }
}
