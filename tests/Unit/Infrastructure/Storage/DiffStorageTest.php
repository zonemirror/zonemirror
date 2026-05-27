<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Domain\DnsDiff;
use ZoneMirror\Infrastructure\Storage\DiffStorage;
use ZoneMirror\Infrastructure\Storage\Paths;

final class DiffStorageTest extends TestCase
{
    private string $tmpDir;
    private DiffStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-diff-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->tmpDir);
        $this->storage = new DiffStorage();
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testSaveAndLoadRoundTripsByZoneId(): void
    {
        $diff = new DnsDiff('example.com', 'zone-abc', time(), []);
        $this->storage->save('alice', 'zone-abc', $diff);

        // The on-disk path is the zone-specific subdir, not the legacy
        // flat file.
        self::assertFileExists($this->tmpDir . '/users/alice/zones/zone-abc/diff.json');
        self::assertFileDoesNotExist($this->tmpDir . '/users/alice/diff.json');

        $loaded = $this->storage->load('alice', 'zone-abc');
        self::assertIsArray($loaded);
        self::assertSame('example.com', $loaded['zone_name']);
    }

    public function testLoadFallsBackToLegacyPathWhenZoneSpecificMissing(): void
    {
        // Pre-v2 install: diff at users/<user>/diff.json (no zones/ subdir).
        $dir = $this->tmpDir . '/users/alice';
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/diff.json',
            (string) json_encode(['zone_name' => 'example.com', 'entries' => []])
        );

        $loaded = $this->storage->load('alice', 'zone-abc');
        self::assertIsArray($loaded);
        self::assertSame('example.com', $loaded['zone_name']);
    }

    public function testLoadPrefersZoneSpecificOverLegacyWhenBothExist(): void
    {
        mkdir($this->tmpDir . '/users/alice/zones/zone-abc', 0755, true);
        file_put_contents(
            $this->tmpDir . '/users/alice/diff.json',
            (string) json_encode(['zone_name' => 'legacy.example', 'entries' => []])
        );
        file_put_contents(
            $this->tmpDir . '/users/alice/zones/zone-abc/diff.json',
            (string) json_encode(['zone_name' => 'modern.example', 'entries' => []])
        );

        $loaded = $this->storage->load('alice', 'zone-abc');
        self::assertIsArray($loaded);
        self::assertSame('modern.example', $loaded['zone_name']);
    }

    public function testRemoveClearsBothZoneSpecificAndLegacyPaths(): void
    {
        mkdir($this->tmpDir . '/users/alice/zones/zone-abc', 0755, true);
        file_put_contents($this->tmpDir . '/users/alice/diff.json', '{}');
        file_put_contents($this->tmpDir . '/users/alice/zones/zone-abc/diff.json', '{}');

        $this->storage->remove('alice', 'zone-abc');

        self::assertFileDoesNotExist($this->tmpDir . '/users/alice/diff.json');
        self::assertFileDoesNotExist($this->tmpDir . '/users/alice/zones/zone-abc/diff.json');
    }

    public function testLoadReturnsNullWhenNothingOnDisk(): void
    {
        self::assertNull($this->storage->load('alice', 'zone-abc'));
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
