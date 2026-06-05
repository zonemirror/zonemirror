<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Cloudflare;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Cloudflare\TxtContentNormalizer;

final class TxtContentNormalizerTest extends TestCase
{
    private TxtContentNormalizer $txt;

    protected function setUp(): void
    {
        $this->txt = new TxtContentNormalizer();
    }

    public function testBareValueIsUntouched(): void
    {
        self::assertSame('path=/', $this->txt->canonical('path=/'));
    }

    public function testSingleQuotedSegmentIsUnwrapped(): void
    {
        self::assertSame('path=/', $this->txt->canonical('"path=/"'));
    }

    public function testMultiSegmentTxtConcatenatesWithNoSeparator(): void
    {
        // Cloudflare returns long TXT (e.g. DKIM keys) split into quoted
        // 255-byte segments joined by a space; the wire format concatenates
        // them with nothing in between.
        self::assertSame(
            'v=DKIM1; k=rsa; p=AAAABBBB',
            $this->txt->canonical('"v=DKIM1; k=rsa; p=AAAA" "BBBB"'),
        );
    }

    public function testEscapedQuoteInsideSegmentIsKept(): void
    {
        self::assertSame('a"b', $this->txt->canonical('"a\"b"'));
    }

    public function testCloudflareQuotedSpfEqualsBarecPanelSpf(): void
    {
        $remote = $this->txt->canonicalForCompare('"v=spf1 ip4:1.2.3.4 a mx ~all"');
        $local = $this->txt->canonicalForCompare('v=spf1 ip4:1.2.3.4 a mx ~all');

        self::assertSame($remote, $local);
    }

    public function testSpfPlusQualifierIsIgnoredForEquality(): void
    {
        // The email normaliser rewrites the cPanel side to the explicit `+`
        // form; some Cloudflare zones store the bare form. RFC 7208 makes
        // them identical, so equality must not see a difference.
        $local = $this->txt->canonicalForCompare('v=spf1 +ip4:1.2.3.4 +a +mx ~all');
        $remote = $this->txt->canonicalForCompare('"v=spf1 ip4:1.2.3.4 a mx ~all"');

        self::assertSame($local, $remote);
    }

    public function testSpfMeaningfulQualifiersArePreserved(): void
    {
        // Only the default `+` is folded away; `-`, `~`, `?` change meaning
        // and must survive so a real policy change still reads as different.
        self::assertNotSame(
            $this->txt->canonicalForCompare('v=spf1 -all'),
            $this->txt->canonicalForCompare('v=spf1 ~all'),
        );
    }

    public function testDifferentApexTxtsDoNotCanonicaliseEqual(): void
    {
        // The dangerous case: a Google site-verification must never fold to
        // the same value as an SPF, or the diff would pair them as an Update.
        self::assertNotSame(
            $this->txt->canonicalForCompare('"google-site-verification=abc123"'),
            $this->txt->canonicalForCompare('v=spf1 +ip4:1.2.3.4 ~all'),
        );
    }

    public function testIdentityGroupsSpfRegardlessOfPayload(): void
    {
        self::assertSame(
            $this->txt->identity('v=spf1 +ip4:1.2.3.4 ~all'),
            $this->txt->identity('"v=spf1 a:mail.example.com include:x.net -all"'),
        );
    }

    public function testIdentitySeparatesSpfFromVerificationToken(): void
    {
        // The apex case that produced the destructive Update: SPF and a
        // Google site-verification share an owner name but are NOT the same
        // record, so their identities must differ.
        self::assertNotSame(
            $this->txt->identity('v=spf1 +ip4:1.2.3.4 ~all'),
            $this->txt->identity('"google-site-verification=abc123"'),
        );
    }

    public function testIdentityGroupsSameVerificationTokenAcrossValues(): void
    {
        // A rotated verification value under the same token IS an update.
        self::assertSame(
            $this->txt->identity('"google-site-verification=OLD"'),
            $this->txt->identity('google-site-verification=NEW'),
        );
    }

    public function testIdentitySeparatesDkimFromSpf(): void
    {
        self::assertNotSame(
            $this->txt->identity('v=DKIM1; k=rsa; p=AAAA'),
            $this->txt->identity('v=spf1 ~all'),
        );
    }
}
