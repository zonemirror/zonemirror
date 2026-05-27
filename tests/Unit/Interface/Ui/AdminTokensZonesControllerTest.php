<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Interface\Ui;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Storage\AdminTokenStorage;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\ZoneIndex;
use ZoneMirror\Interface\Ui\AdminTokensZonesController;

final class AdminTokensZonesControllerTest extends TestCase
{
    private string $tmpDir;
    private AdminTokenStorage $storage;
    private ZoneIndex $index;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-zones-ctl-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->tmpDir);

        $this->storage = new AdminTokenStorage(
            new ConfigCrypto(new KeyStore($this->tmpDir . '/master.key'))
        );
        $this->index = new ZoneIndex($this->tmpDir . '/zone-index.sqlite');
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        $files = glob($this->tmpDir . '/*');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
    }

    public function testListZonesGroupsByAccountAndUsesCachedPermissions(): void
    {
        $tok = $this->storage->add('reseller-A', 'cf_pat_abc');
        $this->index->replaceForToken($tok->id, [
            [
                'cf_zone_id' => 'z1',
                'name' => 'b-second.com',
                'cf_account_id' => 'acct-1',
                'cf_account_name' => 'Acme Corp',
                'permissions' => ['#dns_records:edit', '#dns_records:read', '#zone:read'],
            ],
            [
                'cf_zone_id' => 'z2',
                'name' => 'a-first.com',
                'cf_account_id' => 'acct-1',
                'cf_account_name' => 'Acme Corp',
                'permissions' => ['#dns_records:read', '#zone:read'],
            ],
            [
                'cf_zone_id' => 'z3',
                'name' => 'brand-b.io',
                'cf_account_id' => 'acct-2',
                'cf_account_name' => 'Brand B',
                'permissions' => [],
            ],
        ]);

        $ctl = new AdminTokensZonesController($this->storage, $this->index);
        $out = $ctl->listZones($tok->id);

        self::assertTrue($out['ok']);
        self::assertCount(2, $out['accounts']);

        // Accounts sorted by name (Acme Corp before Brand B).
        self::assertSame('Acme Corp', $out['accounts'][0]['cf_account_name']);
        self::assertSame('acct-1', $out['accounts'][0]['cf_account_id']);
        self::assertSame('Brand B', $out['accounts'][1]['cf_account_name']);

        // Zones inside the account sorted alphabetically.
        $acmeZones = $out['accounts'][0]['zones'];
        self::assertSame(['a-first.com', 'b-second.com'], array_map(
            static fn (array $z): string => $z['name'],
            $acmeZones,
        ));
        // a-first only has dns_records:read → can_read_dns true, can_edit_dns false.
        self::assertFalse($acmeZones[0]['can_edit_dns']);
        self::assertTrue($acmeZones[0]['can_read_dns']);
        // b-second has dns_records:edit → both true.
        self::assertTrue($acmeZones[1]['can_edit_dns']);
        self::assertTrue($acmeZones[1]['can_read_dns']);
        // probed_at populated during the replace call.
        self::assertGreaterThan(0, $acmeZones[0]['probed_at']);

        // Brand B's zone with no permissions cached → both false.
        $brandZones = $out['accounts'][1]['zones'];
        self::assertFalse($brandZones[0]['can_edit_dns']);
        self::assertFalse($brandZones[0]['can_read_dns']);
    }

    public function testListZonesUnknownTokenIsRejected(): void
    {
        $ctl = new AdminTokensZonesController($this->storage, $this->index);
        $out = $ctl->listZones('does-not-exist');

        self::assertFalse($out['ok']);
        self::assertSame('Token not found.', $out['error']);
    }

    public function testListZonesEmptyTokenIdIsRejected(): void
    {
        $ctl = new AdminTokensZonesController($this->storage, $this->index);
        $out = $ctl->listZones('');

        self::assertFalse($out['ok']);
        self::assertNotNull($out['error']);
    }

    public function testListZonesHandlesLegacyRowsWithoutAccountName(): void
    {
        // Mimic a slice indexed by a pre-cache build: empty cf_account_name
        // on every row. Controller should still group and just leave the
        // name blank for the UI to flag.
        $tok = $this->storage->add('reseller-A', 'cf_pat_abc');
        $this->index->replaceForToken($tok->id, [
            [
                'cf_zone_id' => 'z1',
                'name' => 'example.com',
                'cf_account_id' => 'acct-1',
                'cf_account_name' => '',
                'permissions' => [],
            ],
        ]);

        $ctl = new AdminTokensZonesController($this->storage, $this->index);
        $out = $ctl->listZones($tok->id);

        self::assertTrue($out['ok']);
        self::assertCount(1, $out['accounts']);
        self::assertSame('', $out['accounts'][0]['cf_account_name']);
        self::assertSame('acct-1', $out['accounts'][0]['cf_account_id']);
    }
}
