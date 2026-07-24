<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Mapping;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Mapping\EmailDnsPolicyComposer;

final class EmailDnsPolicyComposerTest extends TestCase
{
    private EmailDnsPolicyComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new EmailDnsPolicyComposer();
    }

    public function testDisabledComposesEmpty(): void
    {
        self::assertSame('', $this->composer->composeDmarc([
            'enabled' => false,
            'policy' => 'reject',
            'email' => 'a@b.com',
        ]));
    }

    public function testComposesBasicDmarc(): void
    {
        $out = $this->composer->composeDmarc([
            'enabled' => true,
            'policy' => 'none',
            'email' => 'sysadmin@host.tld',
            'rua' => true,
            'ruf' => false,
        ]);
        self::assertSame(
            'v=DMARC1; p=none; rua=mailto:sysadmin@host.tld',
            $out,
        );
    }

    public function testComposesWithRuaAndRuf(): void
    {
        $out = $this->composer->composeDmarc([
            'enabled' => true,
            'policy' => 'quarantine',
            'email' => 'reports@host.tld',
            'rua' => true,
            'ruf' => true,
            'sp' => 'reject',
            'pct' => 50,
        ]);
        self::assertSame(
            'v=DMARC1; p=quarantine; rua=mailto:reports@host.tld; ruf=mailto:reports@host.tld; sp=reject; pct=50',
            $out,
        );
    }

    public function testPctOf100IsOmittedBecauseItIsTheSpecDefault(): void
    {
        $out = $this->composer->composeDmarc([
            'enabled' => true,
            'policy' => 'reject',
            'email' => 'a@b.com',
            'rua' => true,
            'pct' => 100,
        ]);
        self::assertStringNotContainsString('pct', $out);
    }

    public function testInvalidEmailIsSkipped(): void
    {
        // No rua/ruf is added when the address fails validation. The rest
        // of the record still composes — better to ship a half-empty DMARC
        // than to swallow the policy entirely.
        $out = $this->composer->composeDmarc([
            'enabled' => true,
            'policy' => 'none',
            'email' => 'not-an-email',
            'rua' => true,
        ]);
        self::assertSame('v=DMARC1; p=none', $out);
    }

    public function testCustomOverridesBuilder(): void
    {
        $out = $this->composer->composeDmarc([
            'enabled' => true,
            'policy' => 'none',
            'email' => 'a@b.com',
            'rua' => true,
            'custom' => 'v=DMARC1; p=reject; adkim=s; aspf=s',
        ]);
        self::assertSame('v=DMARC1; p=reject; adkim=s; aspf=s', $out);
    }

    public function testInvalidPolicyFallsBackToNone(): void
    {
        $out = $this->composer->composeDmarc([
            'enabled' => true,
            'policy' => 'haxor',
            'email' => '',
        ]);
        self::assertStringContainsString('p=none', $out);
    }

    public function testSpfExtrasExpandsPresets(): void
    {
        $out = $this->composer->composeSpfExtras(
            ['a_mail', 'google'],
            '',
            '2a01:4f8::1',
        );
        self::assertSame(
            ['+a:mail.{domain}', '+include:_spf.google.com'],
            $out,
        );
    }

    public function testServerIpv6PresetIsSubstituted(): void
    {
        $out = $this->composer->composeSpfExtras(
            ['server_ipv6'],
            '',
            '2a01:4f8:2210:1792::2',
        );
        self::assertSame(['+ip6:2a01:4f8:2210:1792::2'], $out);
    }

    public function testServerIpv6PresetDroppedWhenNoIpv6(): void
    {
        $out = $this->composer->composeSpfExtras(['server_ipv6', 'google'], '', null);
        // Server IPv6 silently disappears; Google preset still applies.
        self::assertSame(['+include:_spf.google.com'], $out);
    }

    public function testCustomLinesAppendedAndDeduped(): void
    {
        $out = $this->composer->composeSpfExtras(
            ['google'],
            "+include:_spf.google.com\n+ip4:198.51.100.7\n",
            null,
        );
        self::assertSame(
            ['+include:_spf.google.com', '+ip4:198.51.100.7'],
            $out,
        );
    }

    public function testUnknownPresetSlugIsIgnored(): void
    {
        $out = $this->composer->composeSpfExtras(['google', 'bogus_provider'], '', null);
        self::assertSame(['+include:_spf.google.com'], $out);
    }

    public function testCustomLineWithSeveralMechanismsIsSplitIntoTokens(): void
    {
        $out = $this->composer->composeSpfExtras(
            [],
            '+ip4:198.51.100.7 +include:mailgun.org',
            null,
        );
        self::assertSame(['+ip4:198.51.100.7', '+include:mailgun.org'], $out);
    }

    public function testPastedWholeSpfRecordDropsVersionTagAndAll(): void
    {
        // The exact operator mistake behind the incident: a full SPF policy
        // pasted into the custom-extras box. The version tag and terminal
        // `all` must be stripped so only real mechanisms survive; otherwise
        // they get spliced mid-record and permerror every managed zone.
        $out = $this->composer->composeSpfExtras(
            [],
            'v=spf1 +a +include:_spf.google.com ~all',
            null,
        );
        self::assertSame(['+a', '+include:_spf.google.com'], $out);
    }
}
