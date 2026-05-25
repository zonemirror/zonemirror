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

        self::assertSame(
            'v=spf1 ip4:1.2.3.4 +a +mx +ip6:2001:db8::1 +a:mail.example.com ~all',
            $out->content,
        );
    }

    public function testSpfMergeIsIdempotent(): void
    {
        $record = new DnsRecord(
            RecordType::TXT,
            'example.com',
            'v=spf1 ip4:1.2.3.4 +ip6:2001:db8::1 ~all',
            14400,
            null,
            null,
            [],
        );

        $out = $this->normalizer->normalize($record, 'example.com', [
            'spf_extras' => ['+ip6:2001:db8::1'],
        ]);

        // Already present → no duplicate token.
        self::assertSame('v=spf1 ip4:1.2.3.4 +ip6:2001:db8::1 ~all', $out->content);
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

        self::assertSame('v=spf1 ip4:1.2.3.4 +ip6:2001:db8::1', $out->content);
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
}
