<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cloudflare;

/**
 * Canonicalise a TXT record's content so cPanel-side and Cloudflare-side
 * values compare equal when they carry the same DNS payload.
 *
 * Why this exists: the two sides spell the same TXT differently.
 *
 *  - cPanel's /var/named zone file (and {@see \ZoneMirror\Infrastructure\Cpanel\BindZoneParser})
 *    yield the bare, concatenated rdata — `path=/`, the full DKIM key as one
 *    string.
 *  - Cloudflare's DNS API returns TXT `content` WITH the surrounding double
 *    quotes, and splits anything over 255 bytes into several quoted segments
 *    joined by a space: `"chunk-a" "chunk-b"`. The DNS protocol concatenates
 *    those segments with NO separator, so the canonical value is `chunk-achunk-b`.
 *
 * Without folding both into the same shape every TXT row in the review diff
 * reads as "different", and — worse — the greedy pairing in
 * {@see \ZoneMirror\Application\ComputeDiff} can marry two unrelated apex
 * TXTs (e.g. a Google site-verification on Cloudflare against the local SPF)
 * into a single destructive Update.
 *
 * SPF gets one extra fold: the implicit-positive `+` qualifier is dropped for
 * comparison. RFC 7208 §4.6.2 makes a missing qualifier mean `+`, so `+ip4:X`
 * and `ip4:X` are the same mechanism. cPanel emits the bare form and some
 * Cloudflare zones store the bare form too, so forcing one or the other (as
 * the email normaliser does for the push payload) must not leak into equality.
 */
final class TxtContentNormalizer
{
    /**
     * Strip the Cloudflare quoting/segmentation and return the bare rdata.
     * A value with no double quote is already bare and returned untouched.
     */
    public function canonical(string $content): string
    {
        if (!str_contains($content, '"')) {
            return $content;
        }

        $out = '';
        $inQuote = false;
        $escape = false;
        $len = strlen($content);
        for ($i = 0; $i < $len; $i++) {
            $c = $content[$i];
            if ($escape) {
                $out .= $c;
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
            // Whitespace BETWEEN quoted segments is structural and dropped;
            // anything inside a quoted segment is data and kept.
            if ($inQuote) {
                $out .= $c;
            }
        }

        return $out;
    }

    /**
     * The "logical identity" of a TXT value — what kind of record it is,
     * independent of its current payload. Two TXTs under the same owner name
     * are only the same record (an Update candidate) when their identity
     * matches; otherwise they are independent and must surface as a separate
     * Create + Delete rather than a destructive mutual Update.
     *
     *  - `v=spf1 …`  → `v=spf1`   (one SPF policy per name; a changed SPF is an update)
     *  - `v=DKIM1 …` → `v=dkim1`
     *  - `v=DMARC1 …`→ `v=dmarc1`
     *  - `google-site-verification=…` / any `token=…` → the token before `=`
     *    (a rotated verification value under the same token IS an update)
     *  - anything else → the whole canonical value (so only an exact match,
     *    which equality already caught, would ever pair).
     */
    public function identity(string $content): string
    {
        $c = $this->canonicalForCompare($content);
        if (preg_match('/^v=([a-z0-9]+)/i', $c, $m) === 1) {
            return 'v=' . strtolower($m[1]);
        }
        if (preg_match('/^([a-z0-9_.\-]+)=/i', $c, $m) === 1) {
            return strtolower($m[1]);
        }

        return $c;
    }

    /**
     * Canonical form suitable for equality checks: quote-folded, and for SPF
     * additionally qualifier-insensitive (drop the default `+`) and
     * lowercased (SPF mechanisms are case-insensitive).
     */
    public function canonicalForCompare(string $content): string
    {
        $bare = $this->canonical($content);

        if (stripos($bare, 'v=spf1') !== 0) {
            return $bare;
        }

        $tokens = preg_split('/\s+/', trim($bare));
        if ($tokens === false) {
            return $bare;
        }
        $tokens = array_map(
            static fn (string $t): string => ($t !== '' && $t[0] === '+') ? substr($t, 1) : $t,
            $tokens,
        );

        return strtolower(implode(' ', $tokens));
    }
}
