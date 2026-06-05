<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Application;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZoneMirror\Application\ComputeDiff;
use ZoneMirror\Infrastructure\Cpanel\BindZoneParser;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Mapping\EmailDnsNormalizer;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;

/**
 * ComputeDiff::compute() instantiates a real CloudflareApiClient internally
 * (no DI seam for it), so the parts of the method that depend on the
 * Cloudflare API cannot be exercised deterministically from a unit test
 * without modifying src/. The behaviour that IS deterministic — and
 * therefore the contract we pin here — is the readability gate that runs
 * before any Cloudflare interaction happens: when the local BIND zone file
 * is missing or unreadable the method must throw a RuntimeException whose
 * message names the offending path, so the caller (WorkerLoop) can surface
 * a useful error in the per-user log without having paid for a wasted
 * Cloudflare round-trip.
 */
final class ComputeDiffTest extends TestCase
{
    private string $tmpRoot;
    private string $logPath;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/zm-compute-diff-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0755, true);
        $this->logPath = $this->tmpRoot . '/compute.log';
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);
    }

    public function testThrowsRuntimeExceptionWhenExplicitZoneFilePathDoesNotExist(): void
    {
        $missing = $this->tmpRoot . '/does-not-exist.db';

        $diff = new ComputeDiff();

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Zone file not readable: ' . $missing);

        $diff->compute(
            zoneName: 'example.com',
            zoneId: 'zone-id',
            cloudflareToken: 'fake-token',
            log: new FileLogger($this->logPath),
            zoneFilePath: $missing,
        );
    }

    public function testThrowsRuntimeExceptionWhenDefaultZoneFilePathIsUnreadable(): void
    {
        // When zoneFilePath is null the implementation derives the path as
        // /var/named/<zone>.db. Using a randomised .invalid zoneName makes
        // the derived path guaranteed-absent on any host the suite runs
        // on, so the gate fires and the exception path is hit without
        // depending on the developer machine's filesystem state.
        $zoneName = 'zonemirror-unit-test-' . bin2hex(random_bytes(8)) . '.invalid';

        $diff = new ComputeDiff();

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Zone file not readable: /var/named/' . $zoneName . '.db');

        $diff->compute(
            zoneName: $zoneName,
            zoneId: 'zone-id',
            cloudflareToken: 'fake-token',
            log: new FileLogger($this->logPath),
        );
    }

    public function testThrowsRuntimeExceptionWhenExplicitPathIsEmptyString(): void
    {
        // An empty zoneFilePath is a degenerate caller bug (e.g. an
        // upstream config field rendered uninitialised). The gate must
        // still refuse it cleanly rather than silently fall through to
        // file_get_contents('') and produce a confusing parse error.
        $diff = new ComputeDiff();

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage('Zone file not readable: ');

        $diff->compute(
            zoneName: 'example.com',
            zoneId: 'zone-id',
            cloudflareToken: 'fake-token',
            log: new FileLogger($this->logPath),
            zoneFilePath: '',
        );
    }

    public function testConstructorAcceptsBothDefaultAndExplicitCollaborators(): void
    {
        // ComputeDiff exposes three optional collaborators on the
        // constructor; production code (WorkerLoop) calls `new ComputeDiff()`
        // with no arguments, while consumers that want a custom parser or
        // normaliser pass them explicitly. Both forms must instantiate
        // cleanly so neither call-site regresses. We don't invoke compute()
        // here because that path requires a Cloudflare round-trip — the
        // assertion is structural.
        $default = new ComputeDiff();
        self::assertInstanceOf(ComputeDiff::class, $default);

        $explicit = new ComputeDiff(
            new BindZoneParser(),
            new EmailDnsNormalizer(),
            new SystemConfigStorage(),
        );
        self::assertInstanceOf(ComputeDiff::class, $explicit);
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
            $pathname = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($pathname);
            } else {
                @unlink($pathname);
            }
        }
        @rmdir($path);
    }
}
