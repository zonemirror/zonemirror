<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Mapping;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Mapping\EmailDnsNormalizer;

final class EmailDnsNormalizerTest extends TestCase
{
    private EmailDnsNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new EmailDnsNormalizer();
    }

    public function testDmarcReplacedByTemplate(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            '_dmarc.example.com',
            'v=DMARC1; p=none;',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'dmarc_template' => 'v=DMARC1; p=none; rua=mailto:sysadmin@host.tld',
        ]);

        self::assertSame('v=DMARC1; p=none; rua=mailto:sysadmin@host.tld', $out->content);
    }

    public function testDmarcUntouchedWhenTemplateEmpty(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            '_dmarc.example.com',
            'v=DMARC1; p=none;',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', ['dmarc_template' => '']);
        self::assertSame('v=DMARC1; p=none;', $out->content);
    }

    public function testDmarcDoesNotMatchOtherTxt(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            'something.example.com',
            'v=DMARC1; p=none;',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'dmarc_template' => 'v=DMARC1; p=reject',
        ]);
        // Name doesn't match _dmarc.<zone>, leave alone even if content looks DMARC-shaped.
        self::assertSame('v=DMARC1; p=none;', $out->content);
    }

    public function testSpfGetsExtrasAddedBeforeAll(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'v=spf1 ip4:1.2.3.4 +a +mx ~all',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'spf_extras' => ['+ip6:2001:db8::1', '+a:mail.{domain}'],
        ]);

        // Bare `ip4:` is canonicalised to `+ip4:` so it matches the form
        // Cloudflare stores; otherwise the diff would forever show the
        // record as "different" just because of the qualifier.
        self::assertSame(
            'v=spf1 +ip4:1.2.3.4 +a +mx +ip6:2001:db8::1 +a:mail.example.com ~all',
            $out->content,
        );
    }

    public function testSpfExtrasCannotSpliceVersionTagOrAllIntoBody(): void
    {
        // Reproduces the incident where a full SPF record pasted into the
        // custom-extras textarea landed in stored `spf_extras` as `+v=spf1`
        // and `+a`. Splicing the version tag mid-record is a permerror. The
        // normaliser must drop reserved terms and keep only the real
        // mechanism, so the output stays a single valid policy.
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'v=spf1 ip4:1.2.3.4 +a +mx ~all',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'spf_extras' => ['+v=spf1', '+a:mail.{domain}'],
        ]);

        self::assertSame(
            'v=spf1 +ip4:1.2.3.4 +a +mx +a:mail.example.com ~all',
            $out->content,
        );
    }

    public function testSpfExtraHoldingAWholePastedRecordIsFlattenedAndCleaned(): void
    {
        // A legacy stored extra may be one opaque multi-token string. It must
        // be split, its `v=spf1`/`all` dropped, and only genuine mechanisms
        // merged — never spliced verbatim into the body.
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'v=spf1 ip4:1.2.3.4 ~all',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'spf_extras' => ['v=spf1 +include:_spf.google.com ~all'],
        ]);

        self::assertSame(
            'v=spf1 +ip4:1.2.3.4 +include:_spf.google.com ~all',
            $out->content,
        );
    }

    public function testSpfMergeIsIdempotent(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'v=spf1 +ip4:1.2.3.4 +ip6:2001:db8::1 ~all',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'spf_extras' => ['+ip6:2001:db8::1'],
        ]);

        // Already present → no duplicate token.
        self::assertSame('v=spf1 +ip4:1.2.3.4 +ip6:2001:db8::1 ~all', $out->content);
    }

    public function testSpfWithoutTerminalAllStillGetsExtras(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'v=spf1 ip4:1.2.3.4',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'spf_extras' => ['+ip6:2001:db8::1'],
        ]);

        self::assertSame('v=spf1 +ip4:1.2.3.4 +ip6:2001:db8::1', $out->content);
    }

    public function testSpfQualifierIsCanonicalisedEvenWithoutExtras(): void
    {
        // No admin-configured extras at all — the pass must still run so
        // the bare cPanel form ("ip4:X +a +mx") becomes the Cloudflare-
        // canonical form ("+ip4:X +a +mx"); otherwise the same SPF would
        // forever show as "different" in the review.
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'v=spf1 ip4:1.2.3.4 a mx include:_spf.google.com ~all',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', []);

        self::assertSame(
            'v=spf1 +ip4:1.2.3.4 +a +mx +include:_spf.google.com ~all',
            $out->content,
        );
    }

    public function testSpfWithMinusAllPreservesQualifier(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'v=spf1 +a -all',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'spf_extras' => ['+ip6:::1'],
        ]);

        self::assertSame('v=spf1 +a +ip6:::1 -all', $out->content);
    }

    public function testNonTxtRecordUntouched(): void
    {
        $record = new DnsRecord(
            RecordType::A,
            'example.com',
            '1.2.3.4',
            14400,
            null,
            false,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'dmarc_template' => 'v=DMARC1; p=reject',
            'spf_extras' => ['+ip6:::1'],
        ]);

        self::assertSame($record, $out);
    }

    public function testTxtThatIsntSpfOrDmarcUntouched(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'arbitrary verification token',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'spf_extras' => ['+ip6:::1'],
        ]);

        self::assertSame('arbitrary verification token', $out->content);
    }

    public function testEnsureDmarcSynthesizesWhenZoneHasNone(): void
    {
        $records = [
            new DnsRecord(RecordType::TXT, 'example.com', 'v=spf1 ~all', 14400, null, null, []),
        ];

        $out = $this->normalizer->ensureDmarc($records, 'example.com', [
            'dmarc_template' => 'v=DMARC1; p=none; rua=mailto:ops@host.tld',
        ]);

        self::assertCount(2, $out);
        $dmarc = $out[1];
        self::assertSame('_dmarc.example.com', $dmarc->name);
        self::assertSame('v=DMARC1; p=none; rua=mailto:ops@host.tld', $dmarc->content);
    }

    public function testEnsureDmarcDoesNotDuplicateExistingDmarc(): void
    {
        $records = [
            new DnsRecord(RecordType::TXT, '_dmarc.example.com', 'v=DMARC1; p=reject', 14400, null, null, []),
        ];

        $out = $this->normalizer->ensureDmarc($records, 'example.com', [
            'dmarc_template' => 'v=DMARC1; p=none; rua=mailto:ops@host.tld',
        ]);

        self::assertSame($records, $out);
    }

    public function testEnsureDmarcNoopWithoutTemplate(): void
    {
        $records = [
            new DnsRecord(RecordType::TXT, 'example.com', 'v=spf1 ~all', 14400, null, null, []),
        ];

        self::assertSame($records, $this->normalizer->ensureDmarc($records, 'example.com', []));
    }
}
