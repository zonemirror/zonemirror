<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZoneMirror\Infrastructure\Storage\ZoneIndex;

final class ZoneIndexTest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-zi-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        $this->dbPath = $this->tmpDir . '/zone-index.sqlite';
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    public function testReplaceForTokenInsertsZonesAndCountsThem(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            [
                'cf_zone_id' => 'z1',
                'name' => 'example.com',
                'cf_account_id' => 'acct-A',
                'cf_account_name' => 'Acme Corp',
                'permissions' => ['#dns_records:edit', '#dns_records:read'],
            ],
            [
                'cf_zone_id' => 'z2',
                'name' => 'other.test',
                'cf_account_id' => 'acct-A',
                'cf_account_name' => 'Acme Corp',
                'permissions' => ['#dns_records:read'],
            ],
        ]);

        self::assertSame(2, $index->countForToken('tok-1'));
        self::assertSame(2, $index->count());
    }

    public function testReplaceForTokenLowercasesNameForLookups(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [[
            'cf_zone_id' => 'z1',
            'name' => 'Example.COM',
            'cf_account_id' => 'acct-A',
        ]]);

        $row = $index->findByDomain('example.com');
        self::assertNotNull($row);
        self::assertSame('z1', $row['cf_zone_id']);
        self::assertSame('example.com', $row['name']);
    }

    public function testReplaceForTokenWipesPreviousSliceForSameToken(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
            ['cf_zone_id' => 'z2', 'name' => 'b.example', 'cf_account_id' => 'acct-A'],
        ]);
        self::assertSame(2, $index->countForToken('tok-1'));

        // Replace with a smaller set — old z2 must disappear.
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
        ]);

        self::assertSame(1, $index->countForToken('tok-1'));
        self::assertNull($index->findByDomain('b.example'));
        self::assertNotNull($index->findByDomain('a.example'));
    }

    public function testReplaceForTokenWithEmptyArrayClearsThatTokenOnly(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
        ]);
        $index->replaceForToken('tok-2', [
            ['cf_zone_id' => 'z2', 'name' => 'b.example', 'cf_account_id' => 'acct-B'],
        ]);

        $index->replaceForToken('tok-1', []);

        self::assertSame(0, $index->countForToken('tok-1'));
        self::assertSame(1, $index->countForToken('tok-2'));
        self::assertSame(1, $index->count());
    }

    public function testReplaceForTokenIsolatesDifferentTokens(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
        ]);
        $index->replaceForToken('tok-2', [
            ['cf_zone_id' => 'z2', 'name' => 'b.example', 'cf_account_id' => 'acct-B'],
        ]);

        // Replacing tok-2 must not touch tok-1's slice.
        $index->replaceForToken('tok-2', [
            ['cf_zone_id' => 'z3', 'name' => 'c.example', 'cf_account_id' => 'acct-B'],
        ]);

        self::assertSame(1, $index->countForToken('tok-1'));
        self::assertSame(1, $index->countForToken('tok-2'));
        self::assertNotNull($index->findByDomain('a.example'));
        self::assertNotNull($index->findByDomain('c.example'));
        self::assertNull($index->findByDomain('b.example'));
    }

    public function testRemoveForTokenDeletesOnlyThatTokensRows(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
        ]);
        $index->replaceForToken('tok-2', [
            ['cf_zone_id' => 'z2', 'name' => 'b.example', 'cf_account_id' => 'acct-B'],
        ]);

        $index->removeForToken('tok-1');

        self::assertSame(0, $index->countForToken('tok-1'));
        self::assertSame(1, $index->countForToken('tok-2'));
    }

    public function testRemoveForTokenIsNoopForUnknownToken(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
        ]);

        $index->removeForToken('does-not-exist');

        self::assertSame(1, $index->countForToken('tok-1'));
    }

    public function testFindByDomainReturnsNullForUnknown(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
        ]);

        self::assertNull($index->findByDomain('nowhere.test'));
    }

    public function testFindByDomainReturnsNullForEmptyInput(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
        ]);

        self::assertNull($index->findByDomain(''));
        self::assertNull($index->findByDomain('   '));
    }

    public function testFindByDomainTrimsAndLowercasesInput(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'example.com', 'cf_account_id' => 'acct-A'],
        ]);

        $row = $index->findByDomain('  EXAMPLE.com  ');
        self::assertNotNull($row);
        self::assertSame('z1', $row['cf_zone_id']);
        self::assertSame('example.com', $row['name']);
        self::assertSame('acct-A', $row['cf_account_id']);
        self::assertSame('tok-1', $row['admin_token_id']);
    }

    public function testCountForTokenIsZeroForUnknownToken(): void
    {
        $index = new ZoneIndex($this->dbPath);
        self::assertSame(0, $index->countForToken('nobody'));
    }

    public function testCountReturnsTotalAcrossTokens(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
            ['cf_zone_id' => 'z2', 'name' => 'b.example', 'cf_account_id' => 'acct-A'],
        ]);
        $index->replaceForToken('tok-2', [
            ['cf_zone_id' => 'z3', 'name' => 'c.example', 'cf_account_id' => 'acct-B'],
        ]);

        self::assertSame(3, $index->count());
    }

    public function testCountAccountsForTokenCountsDistinctAccounts(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
            ['cf_zone_id' => 'z2', 'name' => 'b.example', 'cf_account_id' => 'acct-A'],
            ['cf_zone_id' => 'z3', 'name' => 'c.example', 'cf_account_id' => 'acct-B'],
        ]);

        self::assertSame(2, $index->countAccountsForToken('tok-1'));
    }

    public function testCountAccountsForTokenIgnoresEmptyAccountId(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => ''],
            ['cf_zone_id' => 'z2', 'name' => 'b.example', 'cf_account_id' => 'acct-A'],
        ]);

        self::assertSame(1, $index->countAccountsForToken('tok-1'));
    }

    public function testCountAccountsForTokenIsZeroForUnknownToken(): void
    {
        $index = new ZoneIndex($this->dbPath);
        self::assertSame(0, $index->countAccountsForToken('nobody'));
    }

    public function testAllForTokenReturnsRowsOrderedByName(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            [
                'cf_zone_id' => 'z2',
                'name' => 'beta.example',
                'cf_account_id' => 'acct-A',
                'cf_account_name' => 'Acme',
                'permissions' => ['#dns_records:edit'],
            ],
            [
                'cf_zone_id' => 'z1',
                'name' => 'alpha.example',
                'cf_account_id' => 'acct-A',
                'cf_account_name' => 'Acme',
                'permissions' => ['#dns_records:read'],
            ],
        ]);

        $rows = $index->allForToken('tok-1');
        self::assertCount(2, $rows);
        self::assertSame('alpha.example', $rows[0]['name']);
        self::assertSame('beta.example', $rows[1]['name']);
        self::assertSame('z1', $rows[0]['cf_zone_id']);
        self::assertSame('z2', $rows[1]['cf_zone_id']);
        self::assertSame('Acme', $rows[0]['cf_account_name']);
        self::assertSame(['#dns_records:read'], $rows[0]['permissions']);
        self::assertSame(['#dns_records:edit'], $rows[1]['permissions']);
        self::assertSame('tok-1', $rows[0]['admin_token_id']);
        self::assertGreaterThan(0, $rows[0]['probed_at']);
    }

    public function testAllForTokenReturnsEmptyArrayForUnknownToken(): void
    {
        $index = new ZoneIndex($this->dbPath);
        $index->replaceForToken('tok-1', [
            ['cf_zone_id' => 'z1', 'name' => 'a.example', 'cf_account_id' => 'acct-A'],
        ]);

        self::assertSame([], $index->allForToken('nobody'));
    }

    public function testAllForTokenDefaultsMissingPermissionsAndAccountName(): void
    {
        $index = new ZoneIndex($this->dbPath);
        // No permissions, no cf_account_name.
        $index->replaceForToken('tok-1', [[
            'cf_zone_id' => 'z1',
            'name' => 'a.example',
            'cf_account_id' => 'acct-A',
        ]]);

        $rows = $index->allForToken('tok-1');
        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]['cf_account_name']);
        self::assertSame([], $rows[0]['permissions']);
    }

    public function testReplaceForTokenIsIdempotentOnSameZoneId(): void
    {
        $index = new ZoneIndex($this->dbPath);
        // Same cf_zone_id appearing twice in the same payload — INSERT OR
        // REPLACE collapses to the last write.
        $index->replaceForToken('tok-1', [
            [
                'cf_zone_id' => 'z1',
                'name' => 'first.example',
                'cf_account_id' => 'acct-A',
            ],
            [
                'cf_zone_id' => 'z1',
                'name' => 'second.example',
                'cf_account_id' => 'acct-A',
            ],
        ]);

        self::assertSame(1, $index->countForToken('tok-1'));
        self::assertNotNull($index->findByDomain('second.example'));
        self::assertNull($index->findByDomain('first.example'));
    }

    public function testConstructorCreatesMissingParentDirectory(): void
    {
        $nested = $this->tmpDir . '/nested/deeper/zone-index.sqlite';
        $index = new ZoneIndex($nested);

        // Touch any method that triggers pdo() to materialise the dir.
        self::assertSame(0, $index->count());
        self::assertDirectoryExists(dirname($nested));
        self::assertFileExists($nested);
    }

    public function testPdoThrowsWhenDirectoryCannotBeCreated(): void
    {
        // Pointing at a path under an existing file makes mkdir() fail.
        $blocker = $this->tmpDir . '/blocker';
        file_put_contents($blocker, 'x');
        $badPath = $blocker . '/sub/zone-index.sqlite';

        $index = new ZoneIndex($badPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create zone-index dir');
        $index->count();
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
