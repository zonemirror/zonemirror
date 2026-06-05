<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZoneMirror\Infrastructure\Storage\KeyStore;

final class KeyStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-ks-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    public function testLoadProvisionsThirtyTwoByteKeyWhenAbsent(): void
    {
        $keyPath = $this->tmpDir . '/master.key';
        self::assertFileDoesNotExist($keyPath);

        $store = new KeyStore($keyPath);
        $key = $store->load();

        self::assertSame(32, strlen($key));
        self::assertFileExists($keyPath);
        self::assertSame(32, strlen((string) file_get_contents($keyPath)));
    }

    public function testProvisionedKeyFileHasZeroSixHundredPermissions(): void
    {
        $keyPath = $this->tmpDir . '/master.key';
        (new KeyStore($keyPath))->load();

        $perms = fileperms($keyPath) & 0777;
        self::assertSame(0600, $perms);
    }

    public function testLoadCreatesParentDirectoryWhenMissing(): void
    {
        $keyPath = $this->tmpDir . '/nested/deeper/master.key';
        self::assertDirectoryDoesNotExist(dirname($keyPath));

        $key = (new KeyStore($keyPath))->load();

        self::assertSame(32, strlen($key));
        self::assertDirectoryExists(dirname($keyPath));
        self::assertFileExists($keyPath);
    }

    public function testLoadReturnsSameKeyAcrossCalls(): void
    {
        $keyPath = $this->tmpDir . '/master.key';
        $store = new KeyStore($keyPath);

        $first = $store->load();
        $second = $store->load();

        self::assertSame($first, $second);
    }

    public function testLoadReturnsSameKeyAcrossInstancesForSamePath(): void
    {
        $keyPath = $this->tmpDir . '/master.key';
        $first = (new KeyStore($keyPath))->load();
        $second = (new KeyStore($keyPath))->load();

        self::assertSame($first, $second);
    }

    public function testProvisionedKeysAreDistinctAcrossPaths(): void
    {
        $a = (new KeyStore($this->tmpDir . '/a.key'))->load();
        $b = (new KeyStore($this->tmpDir . '/b.key'))->load();

        self::assertNotSame($a, $b);
    }

    public function testLoadThrowsWhenKeyFileHasInvalidLength(): void
    {
        $keyPath = $this->tmpDir . '/master.key';
        file_put_contents($keyPath, str_repeat('x', 16));

        $store = new KeyStore($keyPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or unreadable master key.');
        $store->load();
    }

    public function testLoadThrowsWhenKeyFileIsEmpty(): void
    {
        $keyPath = $this->tmpDir . '/master.key';
        file_put_contents($keyPath, '');

        $store = new KeyStore($keyPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or unreadable master key.');
        $store->load();
    }

    public function testLoadThrowsWhenKeyFileIsTooLarge(): void
    {
        $keyPath = $this->tmpDir . '/master.key';
        file_put_contents($keyPath, str_repeat("\x00", 64));

        $store = new KeyStore($keyPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or unreadable master key.');
        $store->load();
    }

    public function testLoadThrowsWhenParentDirectoryCannotBeCreated(): void
    {
        // Use a path under a regular file — mkdir cannot create a directory
        // whose parent is itself a file, so provision() must fail.
        $blocker = $this->tmpDir . '/blocker';
        file_put_contents($blocker, 'not a directory');

        $keyPath = $blocker . '/sub/master.key';
        $store = new KeyStore($keyPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to');
        $store->load();
    }

    public function testLoadReadsExistingValidKeyWithoutOverwritingIt(): void
    {
        $keyPath = $this->tmpDir . '/master.key';
        $seed = random_bytes(32);
        file_put_contents($keyPath, $seed);
        chmod($keyPath, 0600);
        $mtimeBefore = filemtime($keyPath);

        // Sleep avoided; we compare contents and mtime stability via clearstatcache.
        clearstatcache(true, $keyPath);

        $loaded = (new KeyStore($keyPath))->load();

        self::assertSame($seed, $loaded);
        clearstatcache(true, $keyPath);
        self::assertSame($mtimeBefore, filemtime($keyPath));
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
