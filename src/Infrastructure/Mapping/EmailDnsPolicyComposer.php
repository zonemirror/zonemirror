<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Mapping;

/**
 * Compose the canonical `email_normalization` strings (DMARC template,
 * SPF extras list) from the structured form-state the WHM admin form
 * collects, and round-trip back so the form can pre-fill itself on
 * subsequent loads.
 *
 * The canonical fields stored in system.json stay the same — a single
 * `dmarc_template` string and a flat `spf_extras` list — so the runtime
 * normaliser (EmailDnsNormalizer) doesn't need to know about presets,
 * builder state, or the form at all. Everything UI-shaped lives here.
 */
final class EmailDnsPolicyComposer
{
    /**
     * Common third-party senders presented as checkboxes in the WHM form.
     * Keys are stable slugs persisted in system.json under
     * `email_normalization.spf_presets`; values are the SPF mechanism the
     * preset expands to. The labels live alongside in PRESET_LABELS so
     * the form template can stay a thin renderer.
     */
    public const SPF_PRESETS = [
        'a_mail'        => '+a:mail.{domain}',
        'server_ipv6'   => '+ip6:{server_ipv6}',
        'google'        => '+include:_spf.google.com',
        'outlook'       => '+include:spf.protection.outlook.com',
        'mailgun'       => '+include:mailgun.org',
        'sendgrid'      => '+include:sendgrid.net',
        'mailjet'       => '+include:_spf.mailjet.com',
        'amazon_ses'    => '+include:amazonses.com',
        'salesforce'    => '+include:_spf.salesforce.com',
        'mailchimp'     => '+include:servers.mcsv.net',
        'zoho'          => '+include:zoho.com',
    ];

    public const PRESET_LABELS = [
        'a_mail'      => "Server's mail subdomain (mail.<domain>)",
        'server_ipv6' => "Server's IPv6 outbound address",
        'google'      => 'Google Workspace / Gmail',
        'outlook'     => 'Microsoft 365 / Outlook',
        'mailgun'     => 'Mailgun',
        'sendgrid'    => 'SendGrid',
        'mailjet'     => 'Mailjet',
        'amazon_ses'  => 'Amazon SES',
        'salesforce'  => 'Salesforce',
        'mailchimp'   => 'Mailchimp Transactional / Mandrill',
        'zoho'        => 'Zoho Mail',
    ];

    /**
     * Compose a DMARC TXT body from the WHM builder fields. A non-empty
     * `custom` short-circuits the builder so power users can express
     * tags the form does not offer (adkim, aspf, fo, rf, ri, …).
     *
     * @param array{
     *     enabled?: bool,
     *     policy?: string,
     *     email?: string,
     *     rua?: bool,
     *     ruf?: bool,
     *     sp?: string,
     *     pct?: int|null,
     *     custom?: string
     * } $cfg
     */
    public function composeDmarc(array $cfg): string
    {
        if (!($cfg['enabled'] ?? false)) {
            return '';
        }
        $custom = trim((string) ($cfg['custom'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        $policy = (string) ($cfg['policy'] ?? 'none');
        if (!in_array($policy, ['none', 'quarantine', 'reject'], true)) {
            $policy = 'none';
        }
        $parts = ['v=DMARC1', 'p=' . $policy];

        $email = trim((string) ($cfg['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            if (($cfg['rua'] ?? true)) {
                $parts[] = 'rua=mailto:' . $email;
            }
            if (($cfg['ruf'] ?? false)) {
                $parts[] = 'ruf=mailto:' . $email;
            }
        }

        $sp = (string) ($cfg['sp'] ?? '');
        if (in_array($sp, ['none', 'quarantine', 'reject'], true)) {
            $parts[] = 'sp=' . $sp;
        }

        $pct = $cfg['pct'] ?? null;
        if (is_int($pct) && $pct >= 1 && $pct <= 99) {
            // 100 is the spec default; emitting `pct=100` is noise and would
            // diff against any record that omits it.
            $parts[] = 'pct=' . $pct;
        }

        return implode('; ', $parts);
    }

    /**
     * Expand a set of checkbox-selected preset slugs and a free-text list
     * of extra mechanisms into the flat `spf_extras` list the normaliser
     * understands. `{server_ipv6}` is substituted from $serverIpv6; if no
     * IPv6 is available the matching preset is silently dropped (the form
     * already hides its checkbox in that case).
     *
     * @param list<string> $presetSlugs Slugs from SPF_PRESETS the admin ticked.
     * @param string $customRaw Raw textarea contents, one mechanism per line.
     * @return list<string>
     */
    public function composeSpfExtras(array $presetSlugs, string $customRaw, ?string $serverIpv6): array
    {
        $out = [];
        foreach ($presetSlugs as $slug) {
            if (!is_string($slug) || !isset(self::SPF_PRESETS[$slug])) {
                continue;
            }
            $token = self::SPF_PRESETS[$slug];
            if (str_contains($token, '{server_ipv6}')) {
                if ($serverIpv6 === null || $serverIpv6 === '') {
                    continue;
                }
                $token = str_replace('{server_ipv6}', $serverIpv6, $token);
            }
            $out[] = $token;
        }
        $lines = preg_split('/\R+/', $customRaw);
        if ($lines === false) {
            $lines = [];
        }
        foreach ($lines as $line) {
            // A custom line may hold several space-separated mechanisms;
            // split so each is stored as its own token rather than one opaque
            // string the normaliser would splice verbatim. Drop reserved terms
            // (`v=spf1`, any `all`) so a whole SPF record pasted here can't be
            // merged mid-body — the corruption that once broke every zone.
            $tokens = preg_split('/\s+/', trim($line));
            foreach ($tokens === false ? [] : $tokens as $token) {
                if ($token !== '' && preg_match('/^[+\-?~]?(v=spf1|all)$/i', $token) !== 1) {
                    $out[] = $token;
                }
            }
        }
        // De-dupe case-insensitively, preserving first occurrence (so a
        // preset isn't shadowed by a manually-typed duplicate).
        $seen = [];
        $deduped = [];
        foreach ($out as $t) {
            $k = strtolower($t);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $deduped[] = $t;
            }
        }

        return $deduped;
    }

    /**
     * Best-effort detection of the server's primary IPv6 outbound address.
     * Reads /proc/net/if_inet6 (always present on Linux) and returns the
     * first global-scope address that isn't link-local or unique-local.
     * Returns the empty string if nothing usable is found — callers MUST
     * treat that as "no IPv6 preset available".
     */
    public static function detectServerIpv6(): string
    {
        $raw = @file_get_contents('/proc/net/if_inet6');
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $lines = preg_split('/\R/', $raw);
        if ($lines === false) {
            return '';
        }
        foreach ($lines as $line) {
            // Format: <hex-addr> <ifindex> <prefixlen> <scope> <flags> <iface>
            //         32 chars   hex      hex         hex    hex     name
            $cols = preg_split('/\s+/', trim($line));
            if ($cols === false || count($cols) < 6) {
                continue;
            }
            [$hex, , , $scope] = $cols;
            if (strlen($hex) !== 32 || strtolower($scope) !== '00') {
                // scope 00 = global; anything else (10/20/40) is link/
                // site/compat which we never want to advertise as SPF.
                continue;
            }
            $colonized = preg_replace('/(.{4})/', '$1:', $hex);
            if (!is_string($colonized)) {
                continue;
            }
            $colonized = rtrim($colonized, ':');
            // Skip ULA fc00::/7 (RFC 4193) — those are private-network
            // addresses, not real outbound.
            $first = (int) base_convert(substr($hex, 0, 2), 16, 10);
            if (($first & 0xfe) === 0xfc) {
                continue;
            }
            $packed = @inet_pton($colonized);
            if ($packed === false) {
                continue;
            }
            $compact = @inet_ntop($packed);
            if (is_string($compact) && $compact !== '') {
                return $compact;
            }
        }

        return '';
    }
}
