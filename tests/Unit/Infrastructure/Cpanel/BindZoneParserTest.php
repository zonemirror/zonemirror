<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Cpanel;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Cpanel\BindZoneParser;

final class BindZoneParserTest extends TestCase
{
    private BindZoneParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BindZoneParser();
    }

    public function testParsesAandAaaaWithApex(): void
    {
        $zone = <<<ZONE
        \$TTL 14400
        example.com.	86400	IN	SOA	ns1.example.com. admin.example.com. (
                            2024010101 3600 1800 1209600 86400 )
        example.com.	86400	IN	NS	ns1.example.com.
        example.com.	14400	IN	A	203.0.113.10
        example.com.	14400	IN	AAAA	2001:db8::1
        ZONE;

        $records = $this->parser->parse($zone, 'example.com');

        // SOA and NS are intentionally dropped.
        self::assertCount(2, $records);
        self::assertSame(RecordType::A, $records[0]->type);
        self::assertSame('example.com', $records[0]->name);
        self::assertSame('203.0.113.10', $records[0]->content);
        self::assertSame(14400, $records[0]->ttl);
        self::assertSame(RecordType::AAAA, $records[1]->type);
        self::assertSame('2001:db8::1', $records[1]->content);
    }

    public function testRelativeNamesAreAbsolutised(): void
    {
        $zone = "www\t14400\tIN\tA\t203.0.113.20\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(1, $records);
        self::assertSame('www.example.com', $records[0]->name);
    }

    public function testCnameLowercasedAndDotStripped(): void
    {
        $zone = "mail\t14400\tIN\tCNAME\tExample.COM.\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(1, $records);
        self::assertSame(RecordType::CNAME, $records[0]->type);
        self::assertSame('mail.example.com', $records[0]->name);
        self::assertSame('example.com', $records[0]->content);
    }

    public function testMxParsesPriorityAndExchange(): void
    {
        $zone = "example.com.\t14400\tIN\tMX\t10\tmail.example.com.\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(1, $records);
        self::assertSame(RecordType::MX, $records[0]->type);
        self::assertSame(10, $records[0]->priority);
        self::assertSame('mail.example.com', $records[0]->content);
    }

    public function testTxtSingleQuotedValueWithEscapedSemicolons(): void
    {
        // DKIM-style: the \; would otherwise look like a comment marker.
        $zone = "_dmarc\t14400\tIN\tTXT\t\"v=DMARC1\\; p=none\\;\"\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(1, $records);
        self::assertSame(RecordType::TXT, $records[0]->type);
        self::assertSame('_dmarc.example.com', $records[0]->name);
        self::assertSame('v=DMARC1; p=none;', $records[0]->content);
    }

    public function testTxtMultiQuotedConcatenates(): void
    {
        // 255+ char TXTs cPanel writes as two adjacent quoted strings.
        $zone = "default._domainkey\t14400\tIN\tTXT\t\"v=DKIM1\\; k=rsa\\; p=AAAA\" \"BBBB\"\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(1, $records);
        self::assertSame('v=DKIM1; k=rsa; p=AAAABBBB', $records[0]->content);
    }

    public function testCommentsAfterValuesAreStripped(): void
    {
        $zone = "example.com.\t14400\tIN\tA\t203.0.113.10\t; previous value\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(1, $records);
        self::assertSame('203.0.113.10', $records[0]->content);
    }

    public function testFullCommentLinesIgnored(): void
    {
        $zone = "; banner\n; another\nexample.com.\t14400\tIN\tA\t203.0.113.10\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(1, $records);
    }

    public function testSrvRecord(): void
    {
        $zone = "_sip._tcp\t14400\tIN\tSRV\t10\t5\t5060\tsipserver.example.com.\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(1, $records);
        self::assertSame(RecordType::SRV, $records[0]->type);
        self::assertSame('_sip._tcp.example.com', $records[0]->name);
        self::assertSame(10, $records[0]->data['priority']);
        self::assertSame(5, $records[0]->data['weight']);
        self::assertSame(5060, $records[0]->data['port']);
        self::assertSame('sipserver.example.com', $records[0]->data['target']);
    }

    public function testCaaRecord(): void
    {
        $zone = "example.com.\t14400\tIN\tCAA\t0\tissue\t\"letsencrypt.org\"\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(1, $records);
        self::assertSame(RecordType::CAA, $records[0]->type);
        self::assertSame(0, $records[0]->data['flags']);
        self::assertSame('issue', $records[0]->data['tag']);
        self::assertSame('letsencrypt.org', $records[0]->data['value']);
    }

    public function testInheritsPreviousNameOnBlankLeading(): void
    {
        $zone = "example.com.\t14400\tIN\tA\t203.0.113.10\n"
            . "\t14400\tIN\tAAAA\t2001:db8::1\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertCount(2, $records);
        self::assertSame('example.com', $records[1]->name);
        self::assertSame(RecordType::AAAA, $records[1]->type);
    }

    public function testRejectsUnknownAndAuthoritativeTypes(): void
    {
        $zone = "example.com.\t14400\tIN\tNS\tns1.example.com.\n"
            . "example.com.\t14400\tIN\tSOA\tns1.example.com. a.example.com. ( 1 3600 1800 1209600 86400 )\n"
            . "example.com.\t14400\tIN\tHINFO\tlinux\tphp\n";
        $records = $this->parser->parse($zone, 'example.com');

        self::assertSame([], $records);
    }

    public function testParsesRealCpanelLikeZone(): void
    {
        // Mirrors the structure of /var/named/<zone>.db that cPanel emits.
        $zone = <<<'ZONE'
; cPanel first:124.0.26 ZoneFile:1.3
; Zone file for example.com
$TTL 14400
example.com.	86400	IN	SOA	ns1.nubenode.com. admin.example.com. (
					2026043001 3600 1800 1209600 86400 )
example.com.	86400	IN	NS	ns1.nubenode.com.
example.com.	86400	IN	NS	ns2.nubenode.com.
example.com.	14400	IN	A	203.0.113.10
example.com.	14400	IN	MX	0	example.com.
mail	14400	IN	CNAME	example.com.
www	14400	IN	CNAME	example.com.
ftp	14400	IN	A	203.0.113.10
example.com.	14400	IN	TXT	"v=spf1 ip4:203.0.113.10 +a +mx ~all"
_dmarc	14400	IN	TXT	"v=DMARC1\; p=none\;"
ZONE;
        $records = $this->parser->parse($zone, 'example.com');

        $byType = [];
        foreach ($records as $r) {
            $byType[$r->type->value][] = $r;
        }

        self::assertCount(2, $byType['A']);
        self::assertCount(2, $byType['CNAME']);
        self::assertCount(1, $byType['MX']);
        self::assertCount(2, $byType['TXT']);
        self::assertArrayNotHasKey('NS', $byType);
        self::assertArrayNotHasKey('SOA', $byType);
    }
}
