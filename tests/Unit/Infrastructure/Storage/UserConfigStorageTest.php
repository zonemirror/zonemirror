<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\UserConfigStorage;

final class UserConfigStorageTest extends TestCase
{
    private string $tmpDir;
    private string $userHome;
    private UserConfigStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-ucs-' . bin2hex(random_bytes(4));
        $this->userHome = $this->tmpDir . '/home';
        mkdir($this->userHome, 0700, true);
        putenv(Paths::ENV_USER_HOME . '=' . $this->userHome);

        $keyFile = $this->userHome . '/master.key';
        $this->storage = new UserConfigStorage(new ConfigCrypto(new KeyStore($keyFile)));
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_USER_HOME . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testEmptyReturnsNoZonesAndNoToken(): void
    {
        $cfg = $this->storage->load('alice');
        self::assertSame('', $cfg['token']);
        self::assertSame([], $cfg['zones']);
    }

    public function testLoadPromotesV1IntoSingleZoneArray(): void
    {
        // Write a v1 single-zone config — the shape M3.b/M4 produced.
        $this->seedRawConfig([
            'version' => 1,
            'enabled' => true,
            'zone_id' => 'zone-abc',
            'zone_name' => 'example.com',
            'defaults' => ['proxied' => true],
            'source' => 'admin',
            'sync_state' => 'awaiting_review',
        ]);

        $cfg = $this->storage->load('alice');
        self::assertCount(1, $cfg['zones']);
        $zone = $cfg['zones'][0];
        self::assertSame('zone-abc', $zone['zone_id']);
        self::assertSame('example.com', $zone['zone_name']);
        self::assertTrue($zone['enabled']);
        self::assertSame('admin', $zone['source']);
        self::assertSame('awaiting_review', $zone['sync_state']);
        self::assertTrue($zone['defaults']['proxied']);
    }

    public function testLoadPromotesLegacyInitialSeedState(): void
    {
        // M3.b's initial_seed_state must map onto the new sync_state.
        $this->seedRawConfig([
            'version' => 1,
            'enabled' => true,
            'zone_id' => 'zone-abc',
            'zone_name' => 'example.com',
            'defaults' => ['proxied' => false],
            'source' => 'admin',
            'initial_seed_state' => 'in_progress',
        ]);
        $cfg = $this->storage->load('alice');
        self::assertSame('computing_diff', $cfg['zones'][0]['sync_state']);
    }

    public function testSaveAndLoadRoundTripsMultipleZones(): void
    {
        $this->storage->save('alice', [
            'token' => '',
            'zones' => [
                [
                    'zone_id' => 'z1',
                    'zone_name' => 'a.example',
                    'enabled' => true,
                    'defaults' => ['proxied' => false],
                    'source' => 'admin',
                    'sync_state' => 'awaiting_review',
                    'last_error' => '',
                ],
                [
                    'zone_id' => 'z2',
                    'zone_name' => 'b.example',
                    'enabled' => false,
                    'defaults' => ['proxied' => true],
                    'source' => 'admin',
                    'sync_state' => 'idle',
                    'last_error' => '',
                ],
            ],
        ]);

        $cfg = $this->storage->load('alice');
        self::assertCount(2, $cfg['zones']);
        self::assertSame('z1', $cfg['zones'][0]['zone_id']);
        self::assertTrue($cfg['zones'][0]['enabled']);
        self::assertSame('z2', $cfg['zones'][1]['zone_id']);
        self::assertFalse($cfg['zones'][1]['enabled']);
    }

    public function testSavePersistsTokenEncryptedAtUserLevel(): void
    {
        $this->storage->save('alice', [
            'token' => 'cf_pat_abc',
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'a.example',
                'enabled' => true,
                'defaults' => ['proxied' => false],
                'source' => 'user',
                'sync_state' => 'idle',
                'last_error' => '',
            ]],
        ]);

        // Plaintext must not appear in the on-disk JSON.
        $raw = (string) file_get_contents($this->userHome . '/.zonemirror/config.json');
        self::assertStringNotContainsString('cf_pat_abc', $raw);
        // But load() decrypts it back.
        self::assertSame('cf_pat_abc', $this->storage->load('alice')['token']);
    }

    public function testUpsertZoneReplacesExistingMatchById(): void
    {
        $cfg = ['token' => '', 'zones' => [[
            'zone_id' => 'z1',
            'zone_name' => 'a.example',
            'enabled' => true,
            'defaults' => ['proxied' => false],
            'source' => 'admin',
            'sync_state' => 'idle',
            'last_error' => '',
        ]]];
        $updated = $cfg['zones'][0];
        $updated['sync_state'] = 'awaiting_review';
        $out = UserConfigStorage::upsertZone($cfg, $updated);
        self::assertCount(1, $out['zones']);
        self::assertSame('awaiting_review', $out['zones'][0]['sync_state']);
    }

    public function testUpsertZoneAppendsWhenIdIsNew(): void
    {
        $cfg = ['token' => '', 'zones' => [[
            'zone_id' => 'z1',
            'zone_name' => 'a.example',
            'enabled' => true,
            'defaults' => ['proxied' => false],
            'source' => 'admin',
            'sync_state' => 'idle',
            'last_error' => '',
        ]]];
        $newZone = [
            'zone_id' => 'z2',
            'zone_name' => 'b.example',
            'enabled' => true,
            'defaults' => ['proxied' => false],
            'source' => 'admin',
            'sync_state' => 'pending_diff',
            'last_error' => '',
        ];
        $out = UserConfigStorage::upsertZone($cfg, $newZone);
        self::assertCount(2, $out['zones']);
        self::assertSame('z2', $out['zones'][1]['zone_id']);
    }

    public function testFindZoneByNameIsCaseInsensitive(): void
    {
        $cfg = ['token' => '', 'zones' => [[
            'zone_id' => 'z1',
            'zone_name' => 'Example.COM',
            'enabled' => true,
            'defaults' => ['proxied' => false],
            'source' => 'admin',
            'sync_state' => 'idle',
            'last_error' => '',
        ]]];
        self::assertNotNull(UserConfigStorage::findZoneByName($cfg, 'EXAMPLE.com'));
        self::assertNull(UserConfigStorage::findZoneByName($cfg, 'other.example'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedRawConfig(array $payload): void
    {
        // Paths::userHome($user) honours ZONEMIRROR_USER_HOME for ANY
        // user, so the config lives at $userHome/.zonemirror/ (no
        // per-user subdir). Match that layout here.
        $dir = $this->userHome . '/.zonemirror';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents(
            $dir . '/config.json',
            (string) json_encode($payload)
        );
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
