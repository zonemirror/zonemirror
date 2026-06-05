<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Interface\Worker;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Logging\LogLevel;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Interface\Worker\WorkerLoop;

/**
 * WorkerLoop is a long-running daemon entry point that wires up a
 * collection of concrete collaborators (SystemConfigStorage,
 * EnrolledUsers, AdminTokenStorage, ZoneIndex, ...). The collaborators
 * are instantiated inside run() rather than injected, so a unit test
 * here exercises the run loop end-to-end against an empty on-disk
 * footprint (no enrolled users, no admin tokens, no zone index) and
 * verifies the supervisor-friendly contract:
 *
 *   - Construction is side-effect free.
 *   - run() honours the cooperative stop flag and returns 0 cleanly
 *     when no work is pending.
 *
 * The stop flag is pre-tripped via reflection so the while-loop body
 * never executes; that keeps the test deterministic without needing
 * to race signal delivery against an inner sleep().
 */
final class WorkerLoopTest extends TestCase
{
    private string $tmpDir;
    private string $systemDir;
    private string $userHome;
    private string $logPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-wl-' . bin2hex(random_bytes(4));
        $this->systemDir = $this->tmpDir . '/system';
        $this->userHome = $this->tmpDir . '/home';
        $this->logPath = $this->tmpDir . '/worker.log';

        mkdir($this->systemDir, 0755, true);
        mkdir($this->userHome, 0755, true);

        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);
        putenv(Paths::ENV_USER_HOME . '=' . $this->userHome);
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        putenv(Paths::ENV_USER_HOME . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testConstructorWithDefaultsDoesNotTouchDisk(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Error);

        $worker = new WorkerLoop($logger);

        self::assertInstanceOf(WorkerLoop::class, $worker);
        // Construction must not eagerly create the log file (it's only
        // touched when something is actually logged).
        self::assertFileDoesNotExist($this->logPath);
    }

    public function testConstructorAcceptsCustomSleepAndBatchOverrides(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Error);

        $worker = new WorkerLoop($logger, sleepSeconds: 5, batchPerUser: 100);

        self::assertInstanceOf(WorkerLoop::class, $worker);
    }

    public function testRunReturnsZeroWhenStopFlagAlreadySet(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Info);
        $worker = new WorkerLoop($logger, sleepSeconds: 0, batchPerUser: 1);

        $this->preTripStop($worker);

        $exit = $worker->run();

        self::assertSame(0, $exit);
        // The supervisor contract: a clean stop must produce a log line
        // ("worker started" + "worker stopped"). With Info level the
        // file must exist after run() returns.
        self::assertFileExists($this->logPath);
    }

    public function testRunLogsStartAndStopMarkers(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Info);
        $worker = new WorkerLoop($logger, sleepSeconds: 0, batchPerUser: 1);

        $this->preTripStop($worker);
        $worker->run();

        $contents = (string) file_get_contents($this->logPath);
        self::assertStringContainsString('worker started', $contents);
        self::assertStringContainsString('worker stopped', $contents);
    }

    public function testRunIsIdempotentAcrossInvocations(): void
    {
        $logger = new FileLogger($this->logPath, LogLevel::Info);
        $worker = new WorkerLoop($logger, sleepSeconds: 0, batchPerUser: 1);

        // First run: stopped immediately.
        $this->preTripStop($worker);
        $first = $worker->run();
        self::assertSame(0, $first);

        // After a run, the flag stays tripped (no reset path on the
        // class), so a second run() returns 0 just as quickly without
        // needing to re-trip the flag.
        $second = $worker->run();
        self::assertSame(0, $second);
    }

    public function testRunWithEmptyEnrolledUsersDoesNotErrorOnMissingSystemDir(): void
    {
        // Drop the system dir we created in setUp so the worker hits
        // the "no system.json, no enrolled-users file" cold-start path.
        $this->rmrf($this->systemDir);
        self::assertDirectoryDoesNotExist($this->systemDir);

        $logger = new FileLogger($this->logPath, LogLevel::Error);
        $worker = new WorkerLoop($logger, sleepSeconds: 0, batchPerUser: 1);

        $this->preTripStop($worker);

        self::assertSame(0, $worker->run());
    }

    /**
     * Pre-flip WorkerLoop::$stop to true via reflection so the inner
     * `while (!$this->isStopRequested())` exits before its first body
     * iteration. installSignalHandlers() then rebinds SIGTERM/SIGINT —
     * it does NOT reset the stop flag, so a value set here survives
     * into the loop check.
     */
    private function preTripStop(WorkerLoop $worker): void
    {
        $ref = new ReflectionClass($worker);
        $prop = $ref->getProperty('stop');
        $prop->setAccessible(true);
        $prop->setValue($worker, true);
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
