<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Application\ApplyLocalDmarc;
use ZoneMirror\Infrastructure\Storage\LocalRewriteState;
use ZoneMirror\Infrastructure\Storage\LockStorage;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;

final class ApplyLocalDmarcTest extends TestCase
{
    private string $tmpRoot;
    private string $bindDir;
    private string $systemDir;
    private string $userDomainsFile;
    private string $hasCustomDmarcDir;
    private string $userHome;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/zm-apply-' . bin2hex(random_bytes(4));
        $this->bindDir = $this->tmpRoot . '/var-named';
        $this->systemDir = $this->tmpRoot . '/var-cpanel-zonemirror';
        $this->hasCustomDmarcDir = $this->tmpRoot . '/has_custom_dmarc';
        $this->userHome = $this->tmpRoot . '/home';
        mkdir($this->bindDir, 0755, true);
        mkdir($this->systemDir, 0755, true);
        mkdir($this->hasCustomDmarcDir, 0755, true);
        mkdir($this->userHome, 0755, true);
        $this->userDomainsFile = $this->tmpRoot . '/userdomains';
        file_put_contents($this->userDomainsFile, "example.com: alice\nother.com: bob\n");
        putenv(Paths::ENV_BIND_DIR . '=' . $this->bindDir);
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);
        putenv('ZONEMIRROR_USERDOMAINS_FILE=' . $this->userDomainsFile);
        putenv('ZONEMIRROR_HAS_CUSTOM_DMARC_DIR=' . $this->hasCustomDmarcDir);
        putenv(Paths::ENV_USER_HOME . '=' . $this->userHome);
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_BIND_DIR . '=');
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        putenv('ZONEMIRROR_USERDOMAINS_FILE=');
        putenv('ZONEMIRROR_HAS_CUSTOM_DMARC_DIR=');
        putenv(Paths::ENV_USER_HOME . '=');
        $this->rmrf($this->tmpRoot);
    }

    public function testPreviewReportsFeatureDisabledWhenOff(): void
    {
        $this->seedConfig(enabled: false);
        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $plan = $apply->preview();
        self::assertSame(ApplyLocalDmarc::REASON_FEATURE_DISABLED, $plan['zones'][0]['reason']);
    }

    public function testPreviewReportsNoTemplateWhenTemplateMissing(): void
    {
        $this->seedConfig(enabled: true, template: '');
        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $plan = $apply->preview();
        self::assertSame(ApplyLocalDmarc::REASON_NO_TEMPLATE, $plan['zones'][0]['reason']);
    }

    public function testWouldApplyForPlaceholderRecord(): void
    {
        $this->seedConfig(enabled: true);
        $this->seedZone('example.com', "_dmarc\t14400\tIN\tTXT\t\"v=DMARC1\\; p=none\\;\"\n");
        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $plan = $apply->preview();
        $row = $this->row($plan['zones'], 'example.com');
        self::assertSame(ApplyLocalDmarc::REASON_WOULD_APPLY, $row['reason']);
        self::assertSame('_dmarc', $row['record_owner']);
        self::assertSame('alice', $row['owner']);
    }

    public function testSkipsWhenHasCustomDmarcFlagPresent(): void
    {
        $this->seedConfig(enabled: true);
        $this->seedZone('example.com', "_dmarc\t14400\tIN\tTXT\t\"v=DMARC1\\; p=none\\;\"\n");
        touch($this->hasCustomDmarcDir . '/example.com');
        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $plan = $apply->preview();
        $row = $this->row($plan['zones'], 'example.com');
        self::assertSame(ApplyLocalDmarc::REASON_HAS_CUSTOM_DMARC, $row['reason']);
    }

    public function testSkipsWhenZoneExcluded(): void
    {
        $this->seedConfig(enabled: true, excludeZones: ['example.com']);
        $this->seedZone('example.com', "_dmarc\t14400\tIN\tTXT\t\"v=DMARC1\\; p=none\\;\"\n");
        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $plan = $apply->preview();
        $row = $this->row($plan['zones'], 'example.com');
        self::assertSame(ApplyLocalDmarc::REASON_EXCLUDED_ZONE, $row['reason']);
    }

    public function testSkipsWhenRecordHasCustomRua(): void
    {
        $this->seedConfig(enabled: true);
        $this->seedZone(
            'example.com',
            "_dmarc\t14400\tIN\tTXT\t\"v=DMARC1\\; p=none\\; rua=mailto:owner\\@example.com\"\n",
        );
        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $plan = $apply->preview();
        $row = $this->row($plan['zones'], 'example.com');
        self::assertSame(ApplyLocalDmarc::REASON_CUSTOM_RUA, $row['reason']);
    }

    public function testOverwriteCustomRuaFlagFlipsItToWouldApply(): void
    {
        $this->seedConfig(enabled: true, overwriteCustomRua: true);
        $this->seedZone(
            'example.com',
            "_dmarc\t14400\tIN\tTXT\t\"v=DMARC1\\; p=none\\; rua=mailto:owner\\@example.com\"\n",
        );
        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $plan = $apply->preview();
        $row = $this->row($plan['zones'], 'example.com');
        self::assertSame(ApplyLocalDmarc::REASON_WOULD_APPLY, $row['reason']);
    }

    public function testSkipsWhenUserHasMatchingLock(): void
    {
        $this->seedConfig(enabled: true);
        $this->seedZone('example.com', "_dmarc\t14400\tIN\tTXT\t\"v=DMARC1\\; p=none\\;\"\n");
        // Lock _dmarc.example.com for alice
        $locks = new LockStorage();
        mkdir($this->userHome . '/.zonemirror', 0700, true);
        $locks->add('alice', LockStorage::SCOPE_TYPE_NAME, 'TXT', '_dmarc.example.com', null, null, 'managed by Postmaster');

        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $plan = $apply->preview();
        $row = $this->row($plan['zones'], 'example.com');
        self::assertSame(ApplyLocalDmarc::REASON_LOCKED, $row['reason']);
    }

    public function testApplyRewritesFileAndRecordsState(): void
    {
        $this->seedConfig(enabled: true);
        $today = gmdate('Ymd');
        $this->seedZone(
            'example.com',
            "_dmarc\t14400\tIN\tTXT\t\"v=DMARC1\\; p=none\\;\"\n",
            $today . '01',
        );
        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $result = $apply->apply('test');
        self::assertSame(1, $result['summary']['applied']);

        $contents = (string) file_get_contents($this->bindDir . '/example.com.db');
        self::assertStringContainsString('rua=mailto:s\\@example.org', $contents);
        self::assertStringContainsString($today . '02', $contents);

        $state = new LocalRewriteState();
        $stored = $state->forZone('example.com');
        self::assertSame('v=DMARC1; p=none;', $stored['_dmarc']['previous_content']);
        self::assertSame('test', $stored['_dmarc']['applied_by']);
    }

    public function testRevertRestoresPreviousContent(): void
    {
        $this->seedConfig(enabled: true);
        $today = gmdate('Ymd');
        $this->seedZone(
            'example.com',
            "_dmarc\t14400\tIN\tTXT\t\"v=DMARC1\\; p=quarantine\\; rua=mailto:owner\\@example.com\"\n",
            $today . '01',
        );
        $this->seedConfig(enabled: true, overwriteCustomRua: true);
        $apply = new ApplyLocalDmarc(new SystemConfigStorage());
        $apply->apply('test');

        $applied = (string) file_get_contents($this->bindDir . '/example.com.db');
        self::assertStringContainsString('rua=mailto:s\\@example.org', $applied);

        $revertResult = $apply->revert();
        self::assertSame(1, $revertResult['summary']['reverted']);

        $reverted = (string) file_get_contents($this->bindDir . '/example.com.db');
        self::assertStringContainsString(
            'v=DMARC1\\; p=quarantine\\; rua=mailto:owner\\@example.com',
            $reverted,
        );

        // State table is wiped on successful revert.
        self::assertTrue((new LocalRewriteState())->isEmpty());
    }

    /**
     * @param list<array<string, mixed>> $zones
     * @return array<string, mixed>
     */
    private function row(array $zones, string $zone): array
    {
        foreach ($zones as $r) {
            if (($r['zone'] ?? null) === $zone) {
                return $r;
            }
        }
        self::fail('no row for zone ' . $zone);
    }

    /**
     * @param list<string> $excludeZones
     */
    private function seedConfig(
        bool $enabled,
        string $template = 'v=DMARC1; p=none; rua=mailto:s@example.org',
        array $excludeZones = [],
        bool $overwriteCustomRua = false,
    ): void {
        $cfg = [
            'defaults' => ['proxied' => false, 'ttl' => 300, 'auto_ttl' => true],
            'allowed_users' => 'all',
            'rate_limit_rps' => 5,
            'dry_run' => false,
            'email_normalization' => [
                'dmarc_template' => $template,
                'spf_extras' => [],
                'dmarc' => ['enabled' => true, 'policy' => 'none', 'email' => '', 'rua' => true, 'ruf' => true, 'sp' => '', 'pct' => null, 'custom' => ''],
                'spf_presets' => [],
                'spf_custom' => '',
            ],
            'local_rewrite' => [
                'enabled' => $enabled,
                'exclude_zones' => $excludeZones,
                'overwrite_custom_rua' => $overwriteCustomRua,
                'respect_has_custom_dmarc' => true,
                'respect_user_locks' => true,
            ],
        ];
        file_put_contents($this->systemDir . '/system.json', (string) json_encode($cfg, JSON_PRETTY_PRINT));
    }

    private function seedZone(string $zone, string $dmarcLine, string $serial = '2024010101'): void
    {
        $body = <<<HEAD
        \$TTL 14400
        {$zone}.	86400	IN	SOA	ns1.{$zone}.	admin.{$zone}.	(	{$serial}	3600	1800	1209600	86400	)
        {$zone}.	86400	IN	NS	ns1.{$zone}.

        HEAD;
        $body .= $dmarcLine;
        file_put_contents($this->bindDir . '/' . $zone . '.db', $body);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }
}
