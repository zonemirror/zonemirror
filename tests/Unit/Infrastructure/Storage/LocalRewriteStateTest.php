<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Storage\LocalRewriteState;
use ZoneMirror\Infrastructure\Storage\Paths;

final class LocalRewriteStateTest extends TestCase
{
    private string $tmpDir;
    private LocalRewriteState $state;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-state-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->tmpDir);
        $this->state = new LocalRewriteState();
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        @unlink($this->tmpDir . '/local-rewrites.json');
        @rmdir($this->tmpDir);
    }

    public function testStartsEmpty(): void
    {
        self::assertTrue($this->state->isEmpty());
        self::assertSame(0, $this->state->countZones());
        self::assertSame(0, $this->state->countRecords());
    }

    public function testRecordPersistsAndPreservesOriginalPreviousAcrossReapply(): void
    {
        $this->state->record('example.com', '_dmarc', 'v=DMARC1; p=none;', 'v=DMARC1; …rua@a', 'cli');
        $this->state->record('example.com', '_dmarc', 'v=DMARC1; …rua@a', 'v=DMARC1; …rua@b', 'cli');

        $records = $this->state->forZone('example.com');
        // The first recorded previous wins — that's the value we need to
        // revert to on uninstall, not whatever transient we had between
        // re-applies.
        self::assertSame('v=DMARC1; p=none;', $records['_dmarc']['previous_content']);
        self::assertSame('v=DMARC1; …rua@b', $records['_dmarc']['applied_content']);
    }

    public function testForgetRemovesRecordAndEmptyZone(): void
    {
        $this->state->record('a.com', '_dmarc', 'prev', 'app', 'cli');
        $this->state->record('a.com', '_dmarc.sub', 'prev2', 'app2', 'cli');
        $this->state->record('b.com', '_dmarc', 'prev3', 'app3', 'cli');

        self::assertSame(2, $this->state->countZones());
        self::assertSame(3, $this->state->countRecords());

        $this->state->forget('a.com', '_dmarc');
        self::assertSame(2, $this->state->countRecords());
        self::assertArrayHasKey('_dmarc.sub', $this->state->forZone('a.com'));

        $this->state->forget('a.com', '_dmarc.sub');
        // Zone wiped entirely once its last record is forgotten.
        self::assertSame(1, $this->state->countZones());
        self::assertSame([], $this->state->forZone('a.com'));
    }

    public function testZoneAndOwnerKeysAreCaseAndDotInsensitive(): void
    {
        $this->state->record('Example.COM.', '_DMARC', 'prev', 'app', 'cli');
        self::assertSame(['_dmarc'], array_keys($this->state->forZone('example.com')));
        self::assertSame(['_dmarc'], array_keys($this->state->forZone('EXAMPLE.com.')));
    }

    public function testStateSurvivesRoundTripThroughDisk(): void
    {
        $this->state->record('example.com', '_dmarc', 'prev', 'app', 'admin-ui');
        $fresh = new LocalRewriteState();
        $records = $fresh->forZone('example.com');
        self::assertSame('prev', $records['_dmarc']['previous_content']);
        self::assertSame('admin-ui', $records['_dmarc']['applied_by']);
    }
}
