<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Logging;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Logging\LogLevel;

final class FileLoggerTest extends TestCase
{
    private string $tmpDir;
    private string $logPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-fl-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        $this->logPath = $this->tmpDir . '/zonemirror.log';
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    public function testInfoWritesJsonLineWithExpectedFields(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Debug);
        $logger->info('hello world', ['user' => 'alice']);

        $lines = $this->readLines($this->logPath);
        self::assertCount(1, $lines);

        $entry = $this->decodeLine($lines[0]);
        self::assertSame('info', $entry['level']);
        self::assertSame('hello world', $entry['msg']);
        self::assertSame(['user' => 'alice'], $entry['ctx']);
        self::assertArrayHasKey('ts', $entry);
        self::assertIsString($entry['ts']);
        self::assertNotSame('', $entry['ts']);
    }

    public function testDebugIsSuppressedWhenMinLevelIsInfo(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Info);
        $logger->debug('noisy');

        self::assertFileDoesNotExist($this->logPath);
    }

    public function testDebugIsEmittedWhenMinLevelIsDebug(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Debug);
        $logger->debug('trace');

        $lines = $this->readLines($this->logPath);
        self::assertCount(1, $lines);
        self::assertSame('debug', $this->decodeLine($lines[0])['level']);
    }

    public function testWarningPassesThroughAtDefaultMinLevel(): void
    {
        $logger = new FileLogger($this->logPath);
        $logger->warning('careful');

        $lines = $this->readLines($this->logPath);
        self::assertCount(1, $lines);
        self::assertSame('warning', $this->decodeLine($lines[0])['level']);
    }

    public function testErrorPassesThroughAtDefaultMinLevel(): void
    {
        $logger = new FileLogger($this->logPath);
        $logger->error('boom');

        $lines = $this->readLines($this->logPath);
        self::assertCount(1, $lines);
        self::assertSame('error', $this->decodeLine($lines[0])['level']);
    }

    public function testInfoSuppressedWhenMinLevelIsWarning(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Warning);
        $logger->info('chatty');

        self::assertFileDoesNotExist($this->logPath);
    }

    public function testWarningSuppressedWhenMinLevelIsError(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Error);
        $logger->warning('not loud enough');

        self::assertFileDoesNotExist($this->logPath);
    }

    public function testEmptyContextSerializesAsEmptyObjectOrArray(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Debug);
        $logger->info('no ctx');

        $entry = $this->decodeLine($this->readLines($this->logPath)[0]);
        self::assertSame([], $entry['ctx']);
        self::assertSame('no ctx', $entry['msg']);
    }

    public function testEmptyMessageIsStillLogged(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Debug);
        $logger->info('');

        $entry = $this->decodeLine($this->readLines($this->logPath)[0]);
        self::assertSame('', $entry['msg']);
        self::assertSame('info', $entry['level']);
    }

    public function testCreatesParentDirectoryWhenMissing(): void
    {
        $deepPath = $this->tmpDir . '/nested/deeper/zm.log';
        $logger = new FileLogger($deepPath);
        $logger->info('reach');

        self::assertFileExists($deepPath);
        $entry = $this->decodeLine($this->readLines($deepPath)[0]);
        self::assertSame('reach', $entry['msg']);
    }

    public function testAppendsMultipleLines(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Debug);
        $logger->info('one');
        $logger->warning('two');
        $logger->error('three');

        $lines = $this->readLines($this->logPath);
        self::assertCount(3, $lines);
        self::assertSame('one', $this->decodeLine($lines[0])['msg']);
        self::assertSame('two', $this->decodeLine($lines[1])['msg']);
        self::assertSame('three', $this->decodeLine($lines[2])['msg']);
    }

    public function testRedactsBearerTokenInMessage(): void
    {
        $logger = new FileLogger($this->logPath);
        $logger->error('Authorization: Bearer abcdefghijklmnopqrstuvwxyz0123456789abcd failed');

        $raw = (string) file_get_contents($this->logPath);
        self::assertStringContainsString('[REDACTED]', $raw);
        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz0123456789abcd', $raw);
    }

    public function testRedactsTokenFieldInContext(): void
    {
        $logger = new FileLogger($this->logPath);
        $logger->info('auth attempt', ['token' => 'supersecrettoken1234567890abcdef1234']);

        $raw = (string) file_get_contents($this->logPath);
        self::assertStringContainsString('[REDACTED]', $raw);
        self::assertStringNotContainsString('supersecrettoken1234567890abcdef1234', $raw);
    }

    public function testTimestampIsIso8601(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Debug);
        $logger->info('time check');

        $entry = $this->decodeLine($this->readLines($this->logPath)[0]);
        self::assertIsString($entry['ts']);
        // gmdate('c') emits e.g. 2026-06-02T10:15:30+00:00
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $entry['ts'],
        );
    }

    public function testLineEndsWithNewline(): void
    {
        $logger = new FileLogger($this->logPath);
        $logger->error('eol');

        $raw = (string) file_get_contents($this->logPath);
        self::assertSame("\n", substr($raw, -1));
    }

    public function testNestedContextRoundTripsThroughJson(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Debug);
        $logger->info('nested', [
            'zone' => 'example.com',
            'meta' => ['count' => 3, 'flag' => true],
        ]);

        $entry = $this->decodeLine($this->readLines($this->logPath)[0]);
        self::assertSame('example.com', $entry['ctx']['zone']);
        self::assertSame(3, $entry['ctx']['meta']['count']);
        self::assertTrue($entry['ctx']['meta']['flag']);
    }

    /**
     * @return list<string>
     */
    private function readLines(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $lines = explode("\n", rtrim($raw, "\n"));

        return $lines === [''] ? [] : $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeLine(string $line): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = (array) json_decode($line, true);

        return $decoded;
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
