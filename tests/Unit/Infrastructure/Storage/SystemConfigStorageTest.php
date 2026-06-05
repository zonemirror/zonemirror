<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;

final class SystemConfigStorageTest extends TestCase
{
    private string $tmpDir;
    private string $systemDir;
    private SystemConfigStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-scs-' . bin2hex(random_bytes(4));
        $this->systemDir = $this->tmpDir . '/system';
        mkdir($this->systemDir, 0755, true);
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);

        $this->storage = new SystemConfigStorage();
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testLoadReturnsFailSafeDefaultsWhenFileMissing(): void
    {
        $cfg = $this->storage->load();

        self::assertFalse($cfg['defaults']['proxied']);
        self::assertSame(300, $cfg['defaults']['ttl']);
        self::assertTrue($cfg['defaults']['auto_ttl']);
        self::assertSame([], $cfg['allowed_users']);
        self::assertSame(5, $cfg['rate_limit_rps']);
        self::assertTrue($cfg['dry_run']);
        self::assertSame('', $cfg['email_normalization']['dmarc_template']);
        self::assertSame([], $cfg['email_normalization']['spf_extras']);
        self::assertFalse($cfg['email_normalization']['dmarc']['enabled']);
        self::assertSame('none', $cfg['email_normalization']['dmarc']['policy']);
        self::assertSame('', $cfg['email_normalization']['dmarc']['sp']);
        self::assertNull($cfg['email_normalization']['dmarc']['pct']);
        self::assertSame([], $cfg['email_normalization']['spf_presets']);
        self::assertSame('', $cfg['email_normalization']['spf_custom']);
        self::assertFalse($cfg['local_rewrite']['enabled']);
        self::assertSame([], $cfg['local_rewrite']['exclude_zones']);
        self::assertFalse($cfg['local_rewrite']['overwrite_custom_rua']);
        self::assertTrue($cfg['local_rewrite']['respect_has_custom_dmarc']);
        self::assertTrue($cfg['local_rewrite']['respect_user_locks']);
    }

    public function testLoadReturnsDefaultsWhenJsonIsInvalid(): void
    {
        file_put_contents($this->systemDir . '/system.json', 'this is not json');

        $cfg = $this->storage->load();

        self::assertSame(5, $cfg['rate_limit_rps']);
        self::assertTrue($cfg['dry_run']);
        self::assertSame([], $cfg['allowed_users']);
    }

    public function testLoadReturnsDefaultsWhenJsonIsNotAnObject(): void
    {
        file_put_contents($this->systemDir . '/system.json', '[1,2,3]');

        $cfg = $this->storage->load();

        self::assertTrue($cfg['dry_run']);
        self::assertSame(300, $cfg['defaults']['ttl']);
    }

    public function testLoadClampsTtlToMinimumOfSixty(): void
    {
        $this->writeSystem(['defaults' => ['ttl' => 5, 'proxied' => true, 'auto_ttl' => false]]);

        $cfg = $this->storage->load();

        self::assertSame(60, $cfg['defaults']['ttl']);
        self::assertTrue($cfg['defaults']['proxied']);
        self::assertFalse($cfg['defaults']['auto_ttl']);
    }

    public function testLoadAcceptsAllowedUsersAll(): void
    {
        $this->writeSystem(['allowed_users' => 'all']);

        $cfg = $this->storage->load();

        self::assertSame('all', $cfg['allowed_users']);
    }

    public function testLoadFiltersEmptyAllowedUsernames(): void
    {
        $this->writeSystem(['allowed_users' => ['alice', '', 'bob', 0]]);

        $cfg = $this->storage->load();

        self::assertSame(['alice', 'bob', '0'], $cfg['allowed_users']);
    }

    public function testLoadIgnoresInvalidAllowedUsersScalar(): void
    {
        // A string that isn't "all" must be ignored, falling back to defaults.
        $this->writeSystem(['allowed_users' => 'some']);

        $cfg = $this->storage->load();

        self::assertSame([], $cfg['allowed_users']);
    }

    public function testLoadClampsRateLimitToBounds(): void
    {
        $this->writeSystem(['rate_limit_rps' => 0]);
        self::assertSame(1, $this->storage->load()['rate_limit_rps']);

        $this->writeSystem(['rate_limit_rps' => 9999]);
        self::assertSame(50, $this->storage->load()['rate_limit_rps']);

        $this->writeSystem(['rate_limit_rps' => 7]);
        self::assertSame(7, $this->storage->load()['rate_limit_rps']);
    }

    public function testLoadCoercesDryRunToBoolean(): void
    {
        $this->writeSystem(['dry_run' => 0]);
        self::assertFalse($this->storage->load()['dry_run']);

        $this->writeSystem(['dry_run' => 1]);
        self::assertTrue($this->storage->load()['dry_run']);
    }

    public function testLoadTrimsDmarcTemplateAndFiltersSpfExtras(): void
    {
        $this->writeSystem([
            'email_normalization' => [
                'dmarc_template' => '  v=DMARC1; p=none  ',
                'spf_extras' => [' include:_spf.example.com ', '', 0, 'ip6:fe80::1'],
            ],
        ]);

        $cfg = $this->storage->load();

        self::assertSame('v=DMARC1; p=none', $cfg['email_normalization']['dmarc_template']);
        self::assertSame(
            ['include:_spf.example.com', 'ip6:fe80::1'],
            $cfg['email_normalization']['spf_extras'],
        );
    }

    public function testLoadNormalisesDmarcBuilderAcceptingValidValues(): void
    {
        $this->writeSystem([
            'email_normalization' => [
                'dmarc' => [
                    'enabled' => true,
                    'policy' => 'reject',
                    'email' => '  dmarc@example.com  ',
                    'rua' => false,
                    'ruf' => true,
                    'sp' => 'quarantine',
                    'pct' => 50,
                    'custom' => '  fo=1  ',
                ],
            ],
        ]);

        $cfg = $this->storage->load();
        $dmarc = $cfg['email_normalization']['dmarc'];

        self::assertTrue($dmarc['enabled']);
        self::assertSame('reject', $dmarc['policy']);
        self::assertSame('dmarc@example.com', $dmarc['email']);
        self::assertFalse($dmarc['rua']);
        self::assertTrue($dmarc['ruf']);
        self::assertSame('quarantine', $dmarc['sp']);
        self::assertSame(50, $dmarc['pct']);
        self::assertSame('fo=1', $dmarc['custom']);
    }

    public function testLoadFallsBackToDmarcDefaultsForInvalidPolicyAndPct(): void
    {
        $this->writeSystem([
            'email_normalization' => [
                'dmarc' => [
                    'policy' => 'bogus',
                    'sp' => 'invalid',
                    'pct' => 250,
                ],
            ],
        ]);

        $dmarc = $this->storage->load()['email_normalization']['dmarc'];

        self::assertSame('none', $dmarc['policy']);
        self::assertSame('', $dmarc['sp']);
        self::assertNull($dmarc['pct']);
    }

    public function testLoadAcceptsEmptyStringAsValidSubPolicy(): void
    {
        $this->writeSystem([
            'email_normalization' => [
                'dmarc' => ['sp' => ''],
            ],
        ]);

        self::assertSame('', $this->storage->load()['email_normalization']['dmarc']['sp']);
    }

    public function testLoadFiltersNonStringSpfPresets(): void
    {
        $this->writeSystem([
            'email_normalization' => [
                'spf_presets' => ['google', 123, '', 'mailgun', false],
            ],
        ]);

        self::assertSame(
            ['google', 'mailgun'],
            $this->storage->load()['email_normalization']['spf_presets'],
        );
    }

    public function testLoadKeepsRawSpfCustomWhenString(): void
    {
        $this->writeSystem([
            'email_normalization' => [
                'spf_custom' => '  ip4:1.2.3.4  ',
            ],
        ]);

        // spf_custom is NOT trimmed by load().
        self::assertSame(
            '  ip4:1.2.3.4  ',
            $this->storage->load()['email_normalization']['spf_custom'],
        );
    }

    public function testLoadNormalisesLocalRewriteExcludeZones(): void
    {
        $this->writeSystem([
            'local_rewrite' => [
                'enabled' => true,
                'exclude_zones' => ['Example.COM.', ' Other.Example ', '', 'example.com'],
                'overwrite_custom_rua' => true,
                'respect_has_custom_dmarc' => false,
                'respect_user_locks' => false,
            ],
        ]);

        $lr = $this->storage->load()['local_rewrite'];

        self::assertTrue($lr['enabled']);
        // Lowercased, trimmed, trailing dot stripped, and de-duplicated.
        self::assertSame(['example.com', 'other.example'], $lr['exclude_zones']);
        self::assertTrue($lr['overwrite_custom_rua']);
        self::assertFalse($lr['respect_has_custom_dmarc']);
        self::assertFalse($lr['respect_user_locks']);
    }

    public function testLoadIgnoresNonStringExcludeZoneEntries(): void
    {
        $this->writeSystem([
            'local_rewrite' => [
                'exclude_zones' => [123, ['nested'], 'good.example'],
            ],
        ]);

        self::assertSame(
            ['good.example'],
            $this->storage->load()['local_rewrite']['exclude_zones'],
        );
    }

    public function testSaveCreatesSystemDirAndPersistsRoundTrip(): void
    {
        $this->rmrf($this->systemDir);
        self::assertFalse(is_dir($this->systemDir));

        $data = [
            'defaults' => ['proxied' => true, 'ttl' => 600, 'auto_ttl' => false],
            'allowed_users' => ['alice', 'bob'],
            'rate_limit_rps' => 10,
            'dry_run' => false,
            'email_normalization' => [
                'dmarc_template' => 'v=DMARC1; p=quarantine',
                'spf_extras' => ['include:_spf.example'],
                'dmarc' => [
                    'enabled' => true,
                    'policy' => 'quarantine',
                    'email' => 'dmarc@example.com',
                    'rua' => true,
                    'ruf' => false,
                    'sp' => 'reject',
                    'pct' => 75,
                    'custom' => 'fo=1',
                ],
                'spf_presets' => ['google'],
                'spf_custom' => 'ip4:1.2.3.4',
            ],
            'local_rewrite' => [
                'enabled' => true,
                'exclude_zones' => ['skip.example'],
                'overwrite_custom_rua' => true,
                'respect_has_custom_dmarc' => false,
                'respect_user_locks' => true,
            ],
        ];

        $this->storage->save($data);

        self::assertTrue(is_dir($this->systemDir));
        self::assertFileExists($this->systemDir . '/system.json');

        $loaded = $this->storage->load();
        self::assertSame($data, $loaded);
    }

    public function testSaveWritesPrettyPrintedUnescapedJson(): void
    {
        $data = $this->storage->load();
        $data['email_normalization']['spf_custom'] = 'include:/path/with/slashes';
        $this->storage->save($data);

        $raw = (string) file_get_contents($this->systemDir . '/system.json');

        // JSON_PRETTY_PRINT inserts newlines and 4-space indentation.
        self::assertStringContainsString("\n    ", $raw);
        // JSON_UNESCAPED_SLASHES keeps slashes raw.
        self::assertStringContainsString('include:/path/with/slashes', $raw);
        self::assertStringNotContainsString('include:\\/path', $raw);
    }

    public function testSaveCleansUpTmpFileOnRenameFailure(): void
    {
        $this->storage->save($this->storage->load());

        // Replace system.json with a directory at the same path so that the
        // atomic rename() inside save() fails — the implementation must then
        // unlink the .tmp staging file and bubble up a RuntimeException.
        unlink($this->systemDir . '/system.json');
        mkdir($this->systemDir . '/system.json', 0755);
        // Populate the directory so rename() cannot replace it.
        file_put_contents($this->systemDir . '/system.json/keep', 'x');

        try {
            $this->storage->save($this->storage->load());
            self::fail('Expected RuntimeException on rename failure.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Unable to install system config', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->systemDir . '/system.json.tmp');
    }

    public function testSaveThrowsWhenSystemDirCannotBeCreated(): void
    {
        // Point at a path whose parent is a regular file: mkdir() cannot
        // create a directory under it, and is_dir() stays false.
        $blocker = $this->tmpDir . '/blocker';
        file_put_contents($blocker, 'x');
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $blocker . '/nested');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create system dir');
        $this->storage->save($this->storage->load());
    }

    public function testIsUserAllowedReturnsTrueWhenAllowedUsersIsAll(): void
    {
        $this->writeSystem(['allowed_users' => 'all']);

        self::assertTrue($this->storage->isUserAllowed('anyone'));
        self::assertTrue($this->storage->isUserAllowed(''));
    }

    public function testIsUserAllowedMatchesExplicitListExactly(): void
    {
        $this->writeSystem(['allowed_users' => ['alice', 'bob']]);

        self::assertTrue($this->storage->isUserAllowed('alice'));
        self::assertTrue($this->storage->isUserAllowed('bob'));
        self::assertFalse($this->storage->isUserAllowed('carol'));
        self::assertFalse($this->storage->isUserAllowed('Alice'));
    }

    public function testIsUserAllowedReturnsFalseOnFreshInstall(): void
    {
        // Fail-safe default is an empty allowlist.
        self::assertFalse($this->storage->isUserAllowed('alice'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeSystem(array $payload): void
    {
        if (!is_dir($this->systemDir)) {
            mkdir($this->systemDir, 0755, true);
        }
        file_put_contents(
            $this->systemDir . '/system.json',
            (string) json_encode($payload),
        );
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($path);
    }
}
