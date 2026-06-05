<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\UserConfigMetadataReader;

final class UserConfigMetadataReaderTest extends TestCase
{
    private string $tmpDir;
    private string $userHome;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-ucmr-' . bin2hex(random_bytes(4));
        $this->userHome = $this->tmpDir . '/home';
        mkdir($this->userHome, 0700, true);
        putenv(Paths::ENV_USER_HOME . '=' . $this->userHome);
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_USER_HOME . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testReadReturnsEmptyWhenConfigFileMissing(): void
    {
        $meta = UserConfigMetadataReader::read('alice');
        self::assertFalse($meta['has_token']);
        self::assertSame([], $meta['zones']);
    }

    public function testReadReturnsEmptyWhenJsonInvalid(): void
    {
        $this->seedRawConfig('not-valid-json{');
        $meta = UserConfigMetadataReader::read('alice');
        self::assertFalse($meta['has_token']);
        self::assertSame([], $meta['zones']);
    }

    public function testReadReturnsEmptyWhenJsonIsNotArray(): void
    {
        // JSON literal scalar — json_decode returns int, not array.
        $this->seedRawConfig('42');
        $meta = UserConfigMetadataReader::read('alice');
        self::assertFalse($meta['has_token']);
        self::assertSame([], $meta['zones']);
    }

    public function testReadDetectsTokenEncryptedAsHasToken(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'token_encrypted' => 'ciphertext-base64',
            'zones' => [],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertTrue($meta['has_token']);
        self::assertSame([], $meta['zones']);
    }

    public function testReadHasTokenFalseWhenTokenEncryptedIsEmptyString(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'token_encrypted' => '',
            'zones' => [],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertFalse($meta['has_token']);
    }

    public function testReadHasTokenFalseWhenTokenEncryptedIsNotString(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'token_encrypted' => ['unexpected' => 'shape'],
            'zones' => [],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertFalse($meta['has_token']);
    }

    public function testReadV1LegacyPromotesToSingleZoneArray(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 1,
            'enabled' => true,
            'zone_id' => 'zone-abc',
            'zone_name' => 'example.com',
            'defaults' => ['proxied' => true],
            'source' => 'admin',
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertFalse($meta['has_token']);
        self::assertCount(1, $meta['zones']);
        $zone = $meta['zones'][0];
        self::assertSame('zone-abc', $zone['zone_id']);
        self::assertSame('example.com', $zone['zone_name']);
        self::assertTrue($zone['enabled']);
        self::assertSame('admin', $zone['source']);
        self::assertTrue($zone['defaults']['proxied']);
    }

    public function testReadV1AssumesVersion1WhenAbsent(): void
    {
        // No 'version' key → defaults to 1; legacy extractor runs.
        $this->seedRawConfig((string) json_encode([
            'enabled' => false,
            'zone_id' => 'zone-xyz',
            'zone_name' => 'foo.example',
            'defaults' => ['proxied' => false],
            'source' => 'user',
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertCount(1, $meta['zones']);
        self::assertSame('zone-xyz', $meta['zones'][0]['zone_id']);
        self::assertFalse($meta['zones'][0]['enabled']);
        self::assertSame('user', $meta['zones'][0]['source']);
    }

    public function testReadV1ReturnsEmptyZonesWhenLegacyHasNoIdOrName(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 1,
            'enabled' => true,
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertSame([], $meta['zones']);
    }

    public function testReadV1DefaultsSourceToUserWhenInvalid(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 1,
            'zone_id' => 'zid',
            'zone_name' => 'a.example',
            'source' => 'bogus',
            'defaults' => ['proxied' => false],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertSame('user', $meta['zones'][0]['source']);
    }

    public function testReadV1DefaultsProxiedFalseWhenDefaultsMissing(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 1,
            'zone_id' => 'zid',
            'zone_name' => 'a.example',
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertFalse($meta['zones'][0]['defaults']['proxied']);
    }

    public function testReadV2ReturnsAllNormalizedZones(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'token_encrypted' => 'ct',
            'zones' => [
                [
                    'zone_id' => 'z1',
                    'zone_name' => 'a.example',
                    'enabled' => true,
                    'source' => 'user',
                    'defaults' => ['proxied' => false],
                ],
                [
                    'zone_id' => 'z2',
                    'zone_name' => 'b.example',
                    'enabled' => false,
                    'source' => 'admin',
                    'defaults' => ['proxied' => true],
                ],
            ],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertTrue($meta['has_token']);
        self::assertCount(2, $meta['zones']);
        self::assertSame('z1', $meta['zones'][0]['zone_id']);
        self::assertTrue($meta['zones'][0]['enabled']);
        self::assertSame('user', $meta['zones'][0]['source']);
        self::assertFalse($meta['zones'][0]['defaults']['proxied']);
        self::assertSame('z2', $meta['zones'][1]['zone_id']);
        self::assertFalse($meta['zones'][1]['enabled']);
        self::assertSame('admin', $meta['zones'][1]['source']);
        self::assertTrue($meta['zones'][1]['defaults']['proxied']);
    }

    public function testReadV2SkipsNonArrayEntriesAndZonesWithoutIdOrName(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [
                'not-an-array',
                ['enabled' => true], // no zone_id/zone_name → dropped
                [
                    'zone_id' => 'keep',
                    'zone_name' => 'ok.example',
                    'enabled' => true,
                ],
            ],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertCount(1, $meta['zones']);
        self::assertSame('keep', $meta['zones'][0]['zone_id']);
    }

    public function testReadV2KeepsZoneWithOnlyZoneId(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [
                ['zone_id' => 'idonly'],
            ],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertCount(1, $meta['zones']);
        self::assertSame('idonly', $meta['zones'][0]['zone_id']);
        self::assertSame('', $meta['zones'][0]['zone_name']);
    }

    public function testReadV2KeepsZoneWithOnlyZoneName(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [
                ['zone_name' => 'name.example'],
            ],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertCount(1, $meta['zones']);
        self::assertSame('', $meta['zones'][0]['zone_id']);
        self::assertSame('name.example', $meta['zones'][0]['zone_name']);
    }

    public function testReadV2DefaultsSourceToUserWhenInvalid(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'a.example',
                'source' => 'whatever',
            ]],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertSame('user', $meta['zones'][0]['source']);
    }

    public function testReadV2DefaultsProxiedFalseWhenDefaultsMissing(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'a.example',
            ]],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertFalse($meta['zones'][0]['defaults']['proxied']);
        // 'enabled' defaults to false too.
        self::assertFalse($meta['zones'][0]['enabled']);
    }

    public function testReadV2EmptyZonesWhenZonesKeyIsNotArray(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => 'not-an-array',
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertSame([], $meta['zones']);
    }

    public function testReadV2EmptyZonesWhenZonesKeyAbsent(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertSame([], $meta['zones']);
    }

    public function testReadVersionAboveTwoStillUsesMultiZonePath(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 5,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'a.example',
                'enabled' => true,
            ]],
        ]));
        $meta = UserConfigMetadataReader::read('alice');
        self::assertCount(1, $meta['zones']);
        self::assertSame('z1', $meta['zones'][0]['zone_id']);
    }

    public function testZoneForDomainReturnsMatchingEnabledZone(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [
                [
                    'zone_id' => 'z1',
                    'zone_name' => 'example.com',
                    'enabled' => true,
                ],
            ],
        ]));
        $zone = UserConfigMetadataReader::zoneForDomain('alice', 'example.com');
        self::assertNotNull($zone);
        self::assertSame('z1', $zone['zone_id']);
    }

    public function testZoneForDomainIsCaseInsensitive(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'Example.COM',
                'enabled' => true,
            ]],
        ]));
        $zone = UserConfigMetadataReader::zoneForDomain('alice', 'EXAMPLE.com');
        self::assertNotNull($zone);
        self::assertSame('z1', $zone['zone_id']);
    }

    public function testZoneForDomainStripsTrailingDotOnBothSides(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'example.com.',
                'enabled' => true,
            ]],
        ]));
        $zone = UserConfigMetadataReader::zoneForDomain('alice', 'example.com.');
        self::assertNotNull($zone);
        self::assertSame('z1', $zone['zone_id']);
    }

    public function testZoneForDomainSkipsDisabledMatch(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'example.com',
                'enabled' => false,
            ]],
        ]));
        self::assertNull(UserConfigMetadataReader::zoneForDomain('alice', 'example.com'));
    }

    public function testZoneForDomainReturnsNullWhenNoMatch(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'other.example',
                'enabled' => true,
            ]],
        ]));
        self::assertNull(UserConfigMetadataReader::zoneForDomain('alice', 'example.com'));
    }

    public function testZoneForDomainReturnsNullWhenConfigMissing(): void
    {
        self::assertNull(UserConfigMetadataReader::zoneForDomain('ghost', 'example.com'));
    }

    public function testZoneForDomainPicksFirstEnabledAmongMany(): void
    {
        $this->seedRawConfig((string) json_encode([
            'version' => 2,
            'zones' => [
                [
                    'zone_id' => 'z-disabled',
                    'zone_name' => 'example.com',
                    'enabled' => false,
                ],
                [
                    'zone_id' => 'z-enabled',
                    'zone_name' => 'example.com',
                    'enabled' => true,
                ],
            ],
        ]));
        $zone = UserConfigMetadataReader::zoneForDomain('alice', 'example.com');
        self::assertNotNull($zone);
        self::assertSame('z-enabled', $zone['zone_id']);
    }

    private function seedRawConfig(string $raw): void
    {
        // Paths::userHome($user) honours ZONEMIRROR_USER_HOME for ANY
        // user, so the config lives directly at $userHome/.zonemirror/.
        $dir = $this->userHome . '/.zonemirror';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/config.json', $raw);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
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
