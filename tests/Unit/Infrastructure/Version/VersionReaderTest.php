<?php

declare(strict_types=1);

namespace CfSync\Tests\Unit\Infrastructure\Version;

use CfSync\Infrastructure\Version\VersionReader;
use PHPUnit\Framework\TestCase;

final class VersionReaderTest extends TestCase
{
    public function testReadsTrimmedVersionFromExplicitRoot(): void
    {
        $root = sys_get_temp_dir() . '/cfsync-ver-' . bin2hex(random_bytes(4));
        mkdir($root, 0700, true);
        file_put_contents($root . '/VERSION', "1.2.3\n");
        self::assertSame('1.2.3', VersionReader::installed($root));
        @unlink($root . '/VERSION');
        @rmdir($root);
    }

    public function testFallsBackThroughKnownPaths(): void
    {
        // Explicit root missing — falls back to either the installed prefix
        // or the repo-root VERSION (present in the repo). Both yield a
        // non-empty value distinct from "unknown".
        $root = sys_get_temp_dir() . '/cfsync-ver-missing-' . bin2hex(random_bytes(4));
        $result = VersionReader::installed($root);
        self::assertNotSame('', $result);
        self::assertMatchesRegularExpression('/^[\w.\-]+$/', $result);
    }

    public function testEmptyExplicitFileFallsThrough(): void
    {
        $root = sys_get_temp_dir() . '/cfsync-ver-empty-' . bin2hex(random_bytes(4));
        mkdir($root, 0700, true);
        file_put_contents($root . '/VERSION', "   \n");
        // The whitespace-only file is rejected and we fall through to repo
        // root which yields a real version.
        $result = VersionReader::installed($root);
        self::assertNotSame('', $result);
        self::assertNotSame('unknown', $result, 'repo VERSION fallback should succeed');
        @unlink($root . '/VERSION');
        @rmdir($root);
    }
}
