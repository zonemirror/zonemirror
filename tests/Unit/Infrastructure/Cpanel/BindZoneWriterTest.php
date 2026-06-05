<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Cpanel;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Cpanel\BindZoneWriter;
use ZoneMirror\Infrastructure\Storage\Paths;

final class BindZoneWriterTest extends TestCase
{
    private BindZoneWriter $writer;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->writer = new BindZoneWriter();
        $this->tmpDir = sys_get_temp_dir() . '/zm-bind-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        putenv(Paths::ENV_BIND_DIR . '=' . $this->tmpDir);
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_BIND_DIR . '=');
        if (is_dir($this->tmpDir)) {
            $entries = glob($this->tmpDir . '/*');
            foreach ($entries === false ? [] : $entries as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testFindsRootDmarcAndSubdomainDmarc(): void
    {
        $zone = $this->zoneWith([
            '_dmarc'	    => 'v=DMARC1\; p=none\;',
            '_dmarc.agent'  => 'v=DMARC1\; p=none\;',
        ]);
        $found = $this->writer->findDmarcRecords($zone);
        self::assertCount(2, $found);
        self::assertSame('_dmarc', $found[0]['owner']);
        self::assertSame('v=DMARC1; p=none;', $found[0]['previous']);
        self::assertSame('_dmarc.agent', $found[1]['owner']);
    }

    public function testIgnoresUnrelatedTxt(): void
    {
        $zone = $this->zoneWith([
            'example.com.'           => 'v=spf1 ~all',
            'default._domainkey'     => 'v=DKIM1\; k=rsa\; p=…',
            '_dmarcXXX'              => 'something',
        ]);
        $found = $this->writer->findDmarcRecords($zone);
        self::assertSame([], $found);
    }

    public function testRewriteIsIdempotent(): void
    {
        $zone = $this->zoneWith(['_dmarc' => 'v=DMARC1\; p=none\;']);
        $template = 'v=DMARC1; p=none; rua=mailto:sysadmin@example.org';

        $first = $this->writer->rewriteDmarc($zone, $template);
        self::assertCount(1, $first['changes']);
        self::assertSame('v=DMARC1; p=none;', $first['changes'][0]['previous']);
        self::assertSame($template, $first['changes'][0]['applied']);

        $second = $this->writer->rewriteDmarc($first['contents'], $template);
        self::assertSame([], $second['changes']);
        self::assertSame($first['contents'], $second['contents']);
    }

    public function testEscapesSemicolonAndAtInTxt(): void
    {
        $zone = $this->zoneWith(['_dmarc' => 'v=DMARC1\; p=none\;']);
        $template = 'v=DMARC1; p=none; rua=mailto:sysadmin@example.org';

        $result = $this->writer->rewriteDmarc($zone, $template);
        // The written form must escape `;` and `@` (cPanel parity).
        self::assertStringContainsString(
            '"v=DMARC1\\; p=none\\; rua=mailto:sysadmin\\@example.org"',
            $result['contents'],
        );
    }

    public function testBumpSoaSerialIncrementsTodayPrefix(): void
    {
        $today = gmdate('Ymd');
        $orig = <<<ZONE
        \$TTL 14400
        example.com.	86400	IN	SOA	ns1.example.com.	admin.example.com.	(
            {$today}01 3600 1800 1209600 86400 )
        _dmarc	14400	IN	TXT	"v=DMARC1\\; p=none\\;"
        ZONE;

        $bumped = $this->writer->bumpSoaSerial($orig);
        self::assertStringContainsString($today . '02', $bumped);
        self::assertStringNotContainsString($today . '01 3600', $bumped);
    }

    public function testBumpSoaSerialResetsOnNewDay(): void
    {
        $oldDay = gmdate('Ymd', time() - 7 * 86400);
        $today = gmdate('Ymd');
        $orig = "example.com. 86400 IN SOA ns1. admin. ( {$oldDay}99 3600 1800 1209600 86400 )";

        $bumped = $this->writer->bumpSoaSerial($orig);
        self::assertStringContainsString($today . '01', $bumped);
    }

    public function testBumpSoaSerialHandlesSingleLineSoa(): void
    {
        $today = gmdate('Ymd');
        $orig = "example.com.\t86400\tIN\tSOA\tns1.example.com.\tadmin.example.com.\t(\t\t\t\t\t\t{$today}05\t\t\t\t\t\t3600\t\t\t\t\t\t1800\t\t\t\t\t\t1209600\t\t\t\t\t\t86400\t)\n";

        $bumped = $this->writer->bumpSoaSerial($orig);
        self::assertStringContainsString($today . '06', $bumped);
    }

    public function testApplyToZoneWritesFileAndReturnsChanges(): void
    {
        $today = gmdate('Ymd');
        $original = $this->zoneWith(['_dmarc' => 'v=DMARC1\; p=none\;'], "{$today}01");
        $path = $this->tmpDir . '/example.com.db';
        file_put_contents($path, $original);

        $result = $this->writer->applyToZone('example.com', 'v=DMARC1; p=none; rua=mailto:s@example.org');
        self::assertCount(1, $result['changes']);
        self::assertSame('_dmarc', $result['changes'][0]['owner']);
        self::assertSame('v=DMARC1; p=none;', $result['changes'][0]['previous']);

        $disk = (string) file_get_contents($path);
        self::assertStringContainsString('rua=mailto:s\\@example.org', $disk);
        // SOA bumped:
        self::assertStringContainsString($today . '02', $disk);
    }

    public function testRevertSingleRestoresExactPreviousContent(): void
    {
        $today = gmdate('Ymd');
        $original = $this->zoneWith(['_dmarc' => 'v=DMARC1\; p=quarantine\; rua=mailto:owner\\@example.com'], "{$today}01");
        $path = $this->tmpDir . '/example.com.db';
        file_put_contents($path, $original);

        // First push our template…
        $this->writer->applyToZone('example.com', 'v=DMARC1; p=none; rua=mailto:s@example.org');
        $afterApply = (string) file_get_contents($path);
        self::assertStringContainsString('rua=mailto:s\\@example.org', $afterApply);

        // …then revert with the exact pre-plugin string.
        $result = $this->writer->revertSingle(
            'example.com',
            '_dmarc',
            'v=DMARC1; p=quarantine; rua=mailto:owner@example.com',
        );
        self::assertTrue($result['ok']);

        $afterRevert = (string) file_get_contents($path);
        self::assertStringContainsString(
            'v=DMARC1\\; p=quarantine\\; rua=mailto:owner\\@example.com',
            $afterRevert,
        );
    }

    /**
     * @param array<string, string> $dmarcRecords owner => raw rdata (already escaped, as cPanel writes it)
     */
    private function zoneWith(array $dmarcRecords, string $serial = '2024010101'): string
    {
        $body = <<<HEAD
        ; Zone file for example.com
        \$TTL 14400
        example.com.	86400	IN	SOA	ns1.example.com.	admin.example.com.	(	{$serial}	3600	1800	1209600	86400	)
        example.com.	86400	IN	NS	ns1.example.com.
        example.com.	14400	IN	A	203.0.113.10

        HEAD;
        foreach ($dmarcRecords as $owner => $rdata) {
            $body .= "{$owner}\t14400\tIN\tTXT\t\"{$rdata}\"\n";
        }

        return $body;
    }
}
