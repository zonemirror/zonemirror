<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Mapping;

use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;

/**
 * Apply the WHM-admin "email DNS policy" to a record before it crosses
 * into Cloudflare. Two transformations are supported:
 *
 *  - DMARC override. cPanel emits a placeholder `_dmarc` TXT
 *    (`v=DMARC1; p=none;`) that has no rua/ruf, so reports never reach
 *    anyone. The admin sets a template once (typically pointing at the
 *    sysadmin mailbox) and we replace cPanel's record with it for every
 *    domain that goes through the sync — turning the operator's mailbox
 *    into the central inbox for DMARC reports across the server.
 *
 *  - SPF extras. cPanel's SPF mentions the server's IPv4 and the local
 *    A/MX of the domain, but it leaves out the IPv6 the server actually
 *    sends mail from, and any extra outbound hosts the operator runs
 *    (mail.<domain>, secondary IPs, …). The admin lists the mechanisms
 *    to inject; we splice them in just before the final `all` token, in
 *    declaration order, and skip duplicates so applying twice is a no-op.
 *
 * The transform is applied at diff-compute time so the cPanel column in
 * the review table shows what the user is actually going to push — the
 * "Will do" pill stays honest. The local zone file under /var/named is
 * never modified.
 */
final class EmailDnsNormalizer
{
    /**
     * @param array{dmarc_template?: string, spf_extras?: list<string>} $policy
     */
    public function normalize(DnsRecord $record, string $zoneName, array $policy): DnsRecord
    {
        if ($record->type !== RecordType::TXT) {
            return $record;
        }
        $name = strtolower($record->name);
        $zone = strtolower(rtrim($zoneName, '.'));

        if ($name === '_dmarc.' . $zone || $name === '_dmarc') {
            $template = trim((string) ($policy['dmarc_template'] ?? ''));
            if ($template !== '') {
                return $this->withContent($record, $this->renderTemplate($template, $zone));
            }
        }

        $content = (string) ($record->content ?? '');
        if ($this->looksLikeSpf($content)) {
            $extras = $this->validExtras($policy['spf_extras'] ?? []);
            if ($extras !== []) {
                $rewritten = $this->mergeSpf($content, $extras, $zone);
                if ($rewritten !== $content) {
                    return $this->withContent($record, $rewritten);
                }
            }
        }

        return $record;
    }

    private function withContent(DnsRecord $record, string $content): DnsRecord
    {
        return new DnsRecord(
            type: $record->type,
            name: $record->name,
            content: $content,
            ttl: $record->ttl,
            priority: $record->priority,
            proxied: $record->proxied,
            data: $record->data,
        );
    }

    private function renderTemplate(string $template, string $zone): string
    {
        return str_replace(['{domain}', '{zone}'], $zone, $template);
    }

    private function looksLikeSpf(string $content): bool
    {
        return stripos($content, 'v=spf1') === 0;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function validExtras(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $e) {
            if (is_string($e)) {
                $e = trim($e);
                if ($e !== '') {
                    $out[] = $e;
                }
            }
        }

        return $out;
    }

    /**
     * Splice the configured extras into an SPF string just before its
     * terminal `all` mechanism. Extras that are already literally present
     * are left out so the transform is idempotent. Tokens are compared
     * case-insensitively because SPF parsers do, and so the user can write
     * `IP6:` and not break parity with cPanel's lowercase.
     *
     * @param list<string> $extras
     */
    private function mergeSpf(string $spf, array $extras, string $zone): string
    {
        $tokens = preg_split('/\s+/', trim($spf)) ?: [];
        if ($tokens === [] || strcasecmp($tokens[0], 'v=spf1') !== 0) {
            return $spf;
        }

        // Pull the final `all` mechanism off the end so we can re-attach it
        // last. cPanel emits ~all; some setups use -all or ?all.
        $allToken = null;
        $last = end($tokens);
        if (is_string($last) && preg_match('/^[+\-?~]?all$/i', $last) === 1) {
            $allToken = $last;
            array_pop($tokens);
        }

        $renderedExtras = array_map(
            fn (string $e): string => $this->renderTemplate($e, $zone),
            $extras,
        );

        $present = array_map('strtolower', $tokens);
        foreach ($renderedExtras as $e) {
            if (!in_array(strtolower($e), $present, true)) {
                $tokens[] = $e;
                $present[] = strtolower($e);
            }
        }

        if ($allToken !== null) {
            $tokens[] = $allToken;
        }

        return implode(' ', $tokens);
    }
}
