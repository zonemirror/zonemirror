<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Cloudflare;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Cloudflare\EmailAuthClassifier;

final class EmailAuthClassifierTest extends TestCase
{
    private EmailAuthClassifier $c;

    protected function setUp(): void
    {
        $this->c = new EmailAuthClassifier();
    }

    public function testDmarc(): void
    {
        self::assertSame('DMARC policy', $this->c->protectReason('TXT', '_dmarc.example.com', 'v=DMARC1; p=none'));
    }

    public function testDkimByName(): void
    {
        self::assertSame('DKIM key', $this->c->protectReason('CNAME', 'k1._domainkey.example.com', 'dkim.mcsv.net'));
        self::assertSame('DKIM key', $this->c->protectReason('TXT', 'mail._domainkey.example.com', 'k=rsa;p=AAAA'));
    }

    public function testSpf(): void
    {
        self::assertSame('SPF record', $this->c->protectReason('TXT', 'example.com', 'v=spf1 ip4:1.2.3.4 ~all'));
        // Cloudflare returns TXT content quoted; still recognised.
        self::assertSame('SPF record', $this->c->protectReason('TXT', 'example.com', '"v=spf1 -all"'));
    }

    public function testMx(): void
    {
        self::assertSame('mail routing (MX)', $this->c->protectReason('MX', 'example.com', 'eu.sparkpostmail.com'));
    }

    public function testVerificationTokens(): void
    {
        self::assertSame('domain verification', $this->c->protectReason('TXT', 'example.com', 'google-site-verification=abc'));
        self::assertSame('domain verification', $this->c->protectReason('TXT', 'example.com', 'facebook-domain-verification=xyz'));
        self::assertSame('domain verification', $this->c->protectReason('TXT', 'example.com', 'MS=ms12345678'));
    }

    public function testOrdinaryRecordsAreNotProtected(): void
    {
        self::assertNull($this->c->protectReason('A', 'www.example.com', '203.0.113.1'));
        self::assertNull($this->c->protectReason('CNAME', 'cdn.example.com', 'example.com'));
        self::assertNull($this->c->protectReason('TXT', 'example.com', 'path=/'));
    }
}
