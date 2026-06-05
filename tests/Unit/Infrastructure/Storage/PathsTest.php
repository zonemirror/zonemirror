<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Storage\Paths;

final class PathsTest extends TestCase
{
    private string $tmpDir;
    private string $systemDir;
    private string $userHome;
    private string $bindDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-paths-' . bin2hex(random_bytes(4));
        $this->systemDir = $this->tmpDir . '/system';
        $this->userHome = $this->tmpDir . '/home';
        $this->bindDir = $this->tmpDir . '/bind';
        mkdir($this->systemDir, 0700, true);
        mkdir($this->userHome, 0700, true);
        mkdir($this->bindDir, 0700, true);

        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);
        putenv(Paths::ENV_USER_HOME . '=' . $this->userHome);
        putenv(Paths::ENV_BIND_DIR . '=' . $this->bindDir);
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        putenv(Paths::ENV_USER_HOME . '=');
        putenv(Paths::ENV_BIND_DIR . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testSystemDirHonoursEnvironmentOverride(): void
    {
        self::assertSame($this->systemDir, Paths::systemDir());
    }

    public function testSystemDirFallsBackToDefaultWhenEnvIsEmpty(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        self::assertSame('/var/cpanel/zonemirror', Paths::systemDir());
    }

    public function testSystemConfigFileSitsAtSystemRoot(): void
    {
        self::assertSame($this->systemDir . '/system.json', Paths::systemConfigFile());
    }

    public function testEnrolledUsersFileSitsAtSystemRoot(): void
    {
        self::assertSame($this->systemDir . '/enrolled-users', Paths::enrolledUsersFile());
    }

    public function testLocalRewritesFileSitsAtSystemRoot(): void
    {
        self::assertSame($this->systemDir . '/local-rewrites.json', Paths::localRewritesFile());
    }

    public function testBindDirHonoursEnvironmentOverride(): void
    {
        self::assertSame($this->bindDir, Paths::bindDir());
    }

    public function testBindDirFallsBackToVarNamedWhenEnvIsEmpty(): void
    {
        putenv(Paths::ENV_BIND_DIR . '=');
        self::assertSame('/var/named', Paths::bindDir());
    }

    public function testBindZoneFileLowercasesAndStripsTrailingDot(): void
    {
        self::assertSame(
            $this->bindDir . '/example.com.db',
            Paths::bindZoneFile('Example.COM.'),
        );
    }

    public function testBindZoneFileWithoutTrailingDot(): void
    {
        self::assertSame(
            $this->bindDir . '/sub.example.com.db',
            Paths::bindZoneFile('sub.example.com'),
        );
    }

    public function testBindZoneFileWithMultipleTrailingDotsStripsAll(): void
    {
        self::assertSame(
            $this->bindDir . '/example.com.db',
            Paths::bindZoneFile('example.com...'),
        );
    }

    public function testLogFileLivesUnderLogsSubdir(): void
    {
        self::assertSame($this->systemDir . '/logs/zonemirror.log', Paths::logFile());
    }

    public function testAdminKeyFileSitsAtSystemRoot(): void
    {
        self::assertSame($this->systemDir . '/master.key', Paths::adminKeyFile());
    }

    public function testAdminTokensFileSitsAtSystemRoot(): void
    {
        self::assertSame($this->systemDir . '/admin-tokens.json', Paths::adminTokensFile());
    }

    public function testZoneIndexFileSitsAtSystemRoot(): void
    {
        self::assertSame($this->systemDir . '/zone-index.sqlite', Paths::zoneIndexFile());
    }

    public function testUserDiffFileWithoutZoneIdReturnsLegacyV1Path(): void
    {
        self::assertSame(
            $this->systemDir . '/users/alice/diff.json',
            Paths::userDiffFile('alice'),
        );
    }

    public function testUserDiffFileWithEmptyZoneIdReturnsLegacyV1Path(): void
    {
        self::assertSame(
            $this->systemDir . '/users/alice/diff.json',
            Paths::userDiffFile('alice', ''),
        );
    }

    public function testUserDiffFileWithZoneIdReturnsPerZonePath(): void
    {
        self::assertSame(
            $this->systemDir . '/users/alice/zones/zone-abc/diff.json',
            Paths::userDiffFile('alice', 'zone-abc'),
        );
    }

    public function testUserDirHonoursUserHomeOverride(): void
    {
        self::assertSame(
            $this->userHome . '/.zonemirror',
            Paths::userDir('alice'),
        );
    }

    public function testUserConfigFileSitsUnderUserDir(): void
    {
        self::assertSame(
            $this->userHome . '/.zonemirror/config.json',
            Paths::userConfigFile('alice'),
        );
    }

    public function testUserKeyFileSitsUnderUserDir(): void
    {
        self::assertSame(
            $this->userHome . '/.zonemirror/master.key',
            Paths::userKeyFile('alice'),
        );
    }

    public function testUserQueueFileSitsUnderUserDir(): void
    {
        self::assertSame(
            $this->userHome . '/.zonemirror/queue.sqlite',
            Paths::userQueueFile('alice'),
        );
    }

    public function testUserLogFileSitsUnderUserDir(): void
    {
        self::assertSame(
            $this->userHome . '/.zonemirror/log.txt',
            Paths::userLogFile('alice'),
        );
    }

    public function testUserLocksFileWithoutZoneIdReturnsLegacyPath(): void
    {
        self::assertSame(
            $this->userHome . '/.zonemirror/locks.json',
            Paths::userLocksFile('alice'),
        );
    }

    public function testUserLocksFileWithEmptyZoneIdReturnsLegacyPath(): void
    {
        self::assertSame(
            $this->userHome . '/.zonemirror/locks.json',
            Paths::userLocksFile('alice', ''),
        );
    }

    public function testUserLocksFileWithZoneIdReturnsPerZonePath(): void
    {
        self::assertSame(
            $this->userHome . '/.zonemirror/zones/zone-xyz/locks.json',
            Paths::userLocksFile('alice', 'zone-xyz'),
        );
    }

    public function testUserHomeOverrideAppliesToAnyUserIncludingRoot(): void
    {
        // The override is intentionally global — userHome($user) returns
        // the override regardless of $user. This is what the existing
        // UserConfigStorageTest relies on.
        self::assertSame(
            $this->userHome . '/.zonemirror',
            Paths::userDir('root'),
        );
        self::assertSame(
            $this->userHome . '/.zonemirror',
            Paths::userDir(''),
        );
    }

    public function testUserHomeFallsBackToRootForRootUserWhenNoOverride(): void
    {
        putenv(Paths::ENV_USER_HOME . '=');
        self::assertSame('/root/.zonemirror', Paths::userDir('root'));
    }

    public function testUserHomeFallsBackToRootForEmptyUserWhenNoOverride(): void
    {
        putenv(Paths::ENV_USER_HOME . '=');
        self::assertSame('/root/.zonemirror', Paths::userDir(''));
    }

    public function testUserHomeFallsBackToHomePrefixForUnknownUserWhenNoOverride(): void
    {
        putenv(Paths::ENV_USER_HOME . '=');
        // A guaranteed-nonexistent user so posix_getpwnam() returns false
        // and Paths falls through to the /home/<user> default.
        $bogus = 'zm_no_such_user_' . bin2hex(random_bytes(4));
        self::assertSame('/home/' . $bogus . '/.zonemirror', Paths::userDir($bogus));
    }

    public function testEnvConstantsExposeExpectedNames(): void
    {
        self::assertSame('ZONEMIRROR_SYSTEM_DIR', Paths::ENV_SYSTEM_DIR);
        self::assertSame('ZONEMIRROR_USER_HOME', Paths::ENV_USER_HOME);
        self::assertSame('ZONEMIRROR_BIND_DIR', Paths::ENV_BIND_DIR);
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
