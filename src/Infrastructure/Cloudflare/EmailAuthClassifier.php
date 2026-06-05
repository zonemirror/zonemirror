<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cloudflare;

/**
 * Recognises the records whose accidental deletion (or downgrade) silently
 * breaks mail or third-party domain ownership: SPF, DKIM, DMARC, MX, and the
 * `*-site-verification` / `*-domain-verification` tokens services plant at
 * the apex.
 *
 * For a zone whose authoritative NS point at Cloudflare, these are routinely
 * managed straight in the Cloudflare dashboard and never written back to the
 * cPanel /var/named copy ZoneMirror reads. The diff then surfaces them as
 * "exists on Cloudflare only → delete" or, for SPF, as a downgrade Update.
 * Marking them lets the UI keep them out of every bulk action (a single tick
 * can still delete one deliberately) so a careless "Delete all CF-only" or
 * "Update all" can't take out the domain's mail.
 */
final class EmailAuthClassifier
{
    /**
     * A short human reason this record is protected, or null if it is not an
     * email-authentication / verification record.
     */
    public function protectReason(string $type, string $name, ?string $content): ?string
    {
        if (strtoupper($type) === 'MX') {
            return 'mail routing (MX)';
        }

        $n = strtolower(rtrim(trim($name), '.'));
        if (preg_match('/^_dmarc(\.|$)/', $n) === 1) {
            return 'DMARC policy';
        }
        if (preg_match('/(^|\.)_domainkey(\.|$)/', $n) === 1) {
            return 'DKIM key';
        }

        $c = trim((string) $content);
        if (strlen($c) >= 2 && $c[0] === '"') {
            $c = trim($c, '"');
        }
        if (stripos($c, 'v=spf1') === 0) {
            return 'SPF record';
        }
        if (stripos($c, 'v=dkim1') === 0) {
            return 'DKIM key';
        }
        if (
            preg_match('/^[a-z0-9_.\-]*(site|domain)-verification=/i', $c) === 1
            || preg_match('/^ms=ms[0-9a-f]/i', $c) === 1
            || preg_match('/-verification=/i', $c) === 1
        ) {
            return 'domain verification';
        }

        return null;
    }
}
