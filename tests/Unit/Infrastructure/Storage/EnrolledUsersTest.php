<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZoneMirror\Infrastructure\Storage\EnrolledUsers;
use ZoneMirror\Infrastructure\Storage\Paths;

final class EnrolledUsersTest extends TestCase
{
    private string $tmpDir;
    private string $systemDir;
    private EnrolledUsers $enrolled;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-eu-' . bin2hex(random_bytes(4));
        $this->systemDir = $this->tmpDir . '/system';
        mkdir($this->systemDir, 0755, true);
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);

        $this->enrolled = new EnrolledUsers();
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testAllReturnsEmptyListWhenFileDoesNotExist(): void
    {
        self::assertSame([], $this->enrolled->all());
    }

    public function testAllReturnsEmptyListWhenFileIsEmpty(): void
    {
        file_put_contents($this->systemDir . '/enrolled-users', '');
        self::assertSame([], $this->enrolled->all());
    }

    public function testAllReturnsEmptyListWhenFileContainsOnlyWhitespace(): void
    {
        file_put_contents($this->systemDir . '/enrolled-users', "   \n  \n\n");
        self::assertSame([], $this->enrolled->all());
    }

    public function testAllReadsUsersOnePerLineAndTrimsThem(): void
    {
        file_put_contents(
            $this->systemDir . '/enrolled-users',
            "alice\nbob\n carol \n",
        );
        self::assertSame(['alice', 'bob', 'carol'], $this->enrolled->all());
    }

    public function testAllFiltersBlankLinesAndHandlesMixedLineEndings(): void
    {
        file_put_contents(
            $this->systemDir . '/enrolled-users',
            "alice\r\n\nbob\r\n\r\ncarol\n",
        );
        self::assertSame(['alice', 'bob', 'carol'], $this->enrolled->all());
    }

    public function testEnrollCreatesFileWithUserWhenNoneExisted(): void
    {
        $this->enrolled->enroll('alice');

        $path = $this->systemDir . '/enrolled-users';
        self::assertFileExists($path);
        self::assertSame(['alice'], $this->enrolled->all());
        self::assertSame("alice\n", (string) file_get_contents($path));
    }

    public function testEnrollAppendsNewUserSorted(): void
    {
        $this->enrolled->enroll('charlie');
        $this->enrolled->enroll('alice');
        $this->enrolled->enroll('bob');

        // Stored sorted on disk.
        self::assertSame(['alice', 'bob', 'charlie'], $this->enrolled->all());
        self::assertSame(
            "alice\nbob\ncharlie\n",
            (string) file_get_contents($this->systemDir . '/enrolled-users'),
        );
    }

    public function testEnrollIsIdempotentForExistingUser(): void
    {
        $this->enrolled->enroll('alice');
        $this->enrolled->enroll('alice');
        $this->enrolled->enroll('alice');

        self::assertSame(['alice'], $this->enrolled->all());
    }

    public function testEnrollCreatesSystemDirIfMissing(): void
    {
        // Point at a deeper nested system dir that doesn't yet exist
        // to confirm write() creates the parent directory.
        $nested = $this->tmpDir . '/nested/another/system';
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $nested);

        $this->enrolled->enroll('alice');

        self::assertDirectoryExists($nested);
        self::assertSame(['alice'], $this->enrolled->all());
    }

    public function testRemoveDeletesUserFromList(): void
    {
        $this->enrolled->enroll('alice');
        $this->enrolled->enroll('bob');
        $this->enrolled->enroll('carol');

        $this->enrolled->remove('bob');

        self::assertSame(['alice', 'carol'], $this->enrolled->all());
    }

    public function testRemoveIsSafeWhenUserNotEnrolled(): void
    {
        $this->enrolled->enroll('alice');

        $this->enrolled->remove('bob');

        // Still has alice, and the file is well-formed.
        self::assertSame(['alice'], $this->enrolled->all());
    }

    public function testRemoveOnEmptyFileWritesEmptyFileWithoutThrowing(): void
    {
        $this->enrolled->remove('ghost');

        $path = $this->systemDir . '/enrolled-users';
        self::assertFileExists($path);
        self::assertSame([], $this->enrolled->all());
    }

    public function testRemoveDoesNotDeletePartialMatches(): void
    {
        $this->enrolled->enroll('alice');
        $this->enrolled->enroll('alicia');

        $this->enrolled->remove('alice');

        // Exact-match semantics: 'alicia' must remain even though
        // 'alice' is a prefix of it.
        self::assertSame(['alicia'], $this->enrolled->all());
    }

    public function testWriteThrowsWhenSystemDirCannotBeCreated(): void
    {
        // Use an unreachable path: place a file where a directory is
        // expected, so mkdir() and is_dir() both fail.
        $blocker = $this->tmpDir . '/blocker';
        file_put_contents($blocker, 'not a dir');
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $blocker . '/system');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create system dir');
        $this->enrolled->enroll('alice');
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
