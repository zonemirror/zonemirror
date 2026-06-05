<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Interface\Ui;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Logging\LogLevel;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\EnrolledUsers;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\LockStorage;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;
use ZoneMirror\Infrastructure\Storage\UserConfigStorage;
use ZoneMirror\Infrastructure\Storage\ZoneIndex;
use ZoneMirror\Interface\Ui\Csrf;
use ZoneMirror\Interface\Ui\UserController;

final class UserControllerTest extends TestCase
{
    private const SESSION_KEY = 'zonemirror_csrf';
    private const USER = 'alice';

    private string $tmpDir;
    private string $systemDir;
    private string $userHome;
    private UserConfigStorage $storage;
    private FileLogger $log;
    private ZoneIndex $zoneIndex;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-uc-' . bin2hex(random_bytes(4));
        $this->systemDir = $this->tmpDir . '/system';
        $this->userHome = $this->tmpDir . '/home';
        mkdir($this->systemDir, 0755, true);
        mkdir($this->userHome, 0700, true);

        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);
        putenv(Paths::ENV_USER_HOME . '=' . $this->userHome);

        // Allow the test user so POSTs aren't no-ops.
        (new SystemConfigStorage())->save([
            'defaults' => ['proxied' => false, 'ttl' => 300, 'auto_ttl' => true],
            'allowed_users' => 'all',
            'rate_limit_rps' => 5,
            'dry_run' => true,
            'email_normalization' => [
                'dmarc_template' => '',
                'spf_extras' => [],
                'dmarc' => [
                    'enabled' => false,
                    'policy' => 'none',
                    'email' => '',
                    'rua' => true,
                    'ruf' => false,
                    'sp' => '',
                    'pct' => null,
                    'custom' => '',
                ],
                'spf_presets' => [],
                'spf_custom' => '',
            ],
            'local_rewrite' => [
                'enabled' => false,
                'exclude_zones' => [],
                'overwrite_custom_rua' => false,
                'respect_has_custom_dmarc' => true,
                'respect_user_locks' => true,
            ],
        ]);

        $keyFile = $this->userHome . '/master.key';
        $this->storage = new UserConfigStorage(new ConfigCrypto(new KeyStore($keyFile)));
        $this->log = new FileLogger($this->tmpDir . '/log.txt', LogLevel::Info);
        $this->zoneIndex = new ZoneIndex($this->systemDir . '/zone-index.sqlite');

        // Start the session so Csrf's ensureSession() is a no-op and we
        // can pre-seed $_SESSION with a known token.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        putenv(Paths::ENV_USER_HOME . '=');
        $this->rmrf($this->tmpDir);
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $e);
        }
        @rmdir($path);
    }

    /**
     * Build a fresh controller with all collaborators injected so the
     * tests stay deterministic and don't touch production paths.
     *
     * @param (callable(string): void)|null $enrollmentBackend
     */
    private function makeController(?callable $enrollmentBackend = null): UserController
    {
        return new UserController(
            $this->storage,
            $this->log,
            $this->zoneIndex,
            $enrollmentBackend,
        );
    }

    /**
     * Seed a fresh CSRF token for the next POST and return it so the
     * caller can drop it into the POST body verbatim.
     */
    private function seedCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    /**
     * Persist a zone in the user's config so the action tests have
     * something to refresh / disconnect / lock against.
     *
     * @return array{zone_id: string, zone_name: string}
     */
    private function seedConnectedZone(
        string $zoneId = 'zone-1',
        string $zoneName = 'example.com',
        bool $enabled = true,
        string $source = UserConfigStorage::SOURCE_ADMIN,
        string $syncState = UserConfigStorage::STATE_AWAITING_REVIEW,
    ): array {
        $cfg = $this->storage->load(self::USER);
        $cfg = UserConfigStorage::upsertZone($cfg, [
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'enabled' => $enabled,
            'defaults' => ['proxied' => false],
            'source' => $source,
            'sync_state' => $syncState,
            'last_error' => '',
        ]);
        $this->storage->save(self::USER, $cfg);

        return ['zone_id' => $zoneId, 'zone_name' => $zoneName];
    }

    public function testHandleGetReturnsEmptyViewModelForFreshUser(): void
    {
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'GET', []);

        self::assertSame(self::USER, $vm['user']);
        self::assertTrue($vm['allowed']);
        self::assertFalse($vm['saved']);
        self::assertSame([], $vm['errors']);
        self::assertSame('', $vm['message']);
        self::assertFalse($vm['token_set']);
        self::assertNotSame('', $vm['csrf']);
        self::assertNull($vm['test_result']);
        self::assertSame([], $vm['zones']);
        self::assertSame([], $vm['domains']);
    }

    public function testHandleGetWithDomainsClassifiesNotInZone(): void
    {
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'GET', [], ['example.com', 'unknown.test']);

        self::assertCount(2, $vm['domains']);
        $names = array_column($vm['domains'], 'name');
        self::assertSame(['example.com', 'unknown.test'], $names);
        foreach ($vm['domains'] as $d) {
            self::assertSame(UserController::DOMAIN_NOT_IN_ZONE, $d['status']);
            self::assertSame('', $d['zone_id']);
        }
    }

    public function testHandleGetClassifiesAvailableDomainViaZoneIndex(): void
    {
        $this->zoneIndex->replaceForToken('admin-tok', [[
            'cf_zone_id' => 'cf-zone-1',
            'name' => 'example.com',
            'cf_account_id' => 'acct-1',
            'cf_account_name' => 'Acme',
            'permissions' => [],
        ]]);

        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'GET', [], ['example.com']);

        self::assertCount(1, $vm['domains']);
        self::assertSame(UserController::DOMAIN_AVAILABLE, $vm['domains'][0]['status']);
        self::assertSame('cf-zone-1', $vm['domains'][0]['zone_id']);
        self::assertSame(UserConfigStorage::SOURCE_ADMIN, $vm['domains'][0]['source']);
    }

    public function testHandleGetClassifiesConnectedAdminAndDisabledZones(): void
    {
        $this->seedConnectedZone('zone-a', 'connected.example', true, UserConfigStorage::SOURCE_ADMIN);
        $this->seedConnectedZone('zone-b', 'disabled.example', false, UserConfigStorage::SOURCE_USER);

        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'GET', [], ['connected.example', 'disabled.example']);

        $byName = [];
        foreach ($vm['domains'] as $d) {
            $byName[$d['name']] = $d;
        }
        self::assertSame(UserController::DOMAIN_CONNECTED_ADMIN, $byName['connected.example']['status']);
        self::assertSame('zone-a', $byName['connected.example']['zone_id']);
        self::assertSame(UserController::DOMAIN_DISABLED, $byName['disabled.example']['status']);
        self::assertSame('zone-b', $byName['disabled.example']['zone_id']);
    }

    public function testHandleGetDeduplicatesAndNormalisesDomainList(): void
    {
        $ctl = $this->makeController();
        // Mix in trailing dots, uppercase, whitespace, and duplicates;
        // the controller should collapse them to a single normalised
        // entry per domain.
        $vm = $ctl->handle(self::USER, 'GET', [], [
            'Example.COM',
            ' example.com. ',
            '',
            'other.test',
        ]);

        self::assertCount(2, $vm['domains']);
        self::assertSame(['example.com', 'other.test'], array_column($vm['domains'], 'name'));
    }

    public function testHandleGetIncludesZoneVmsForConnectedZones(): void
    {
        $this->seedConnectedZone('zone-a', 'one.example', true);
        $this->seedConnectedZone('zone-b', 'two.example', false);

        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'GET', []);

        self::assertCount(2, $vm['zones']);
        self::assertSame('zone-a', $vm['zones'][0]['zone_id']);
        self::assertTrue($vm['zones'][0]['enabled']);
        self::assertSame(0, $vm['zones'][0]['queue_depth']);
        self::assertNull($vm['zones'][0]['diff']);
        self::assertSame([], $vm['zones'][0]['locks']);
        self::assertSame(0, $vm['zones'][0]['locks_count']);
        self::assertNull($vm['zones'][0]['diff_summary']);

        self::assertSame('zone-b', $vm['zones'][1]['zone_id']);
        self::assertFalse($vm['zones'][1]['enabled']);
        // Disabled zones skip the queue/diff/lock reads.
        self::assertSame(0, $vm['zones'][1]['queue_depth']);
        self::assertNull($vm['zones'][1]['diff']);
    }

    public function testHandlePostWithoutCsrfReturnsError(): void
    {
        $ctl = $this->makeController();
        // Do NOT seed a CSRF token — verify() will fail.
        $vm = $ctl->handle(self::USER, 'POST', ['action' => 'refresh_diff', 'zone_id' => 'zone-1']);

        self::assertFalse($vm['saved']);
        self::assertCount(1, $vm['errors']);
        self::assertStringContainsString('CSRF', $vm['errors'][0]);
    }

    public function testHandlePostFromDisallowedUserIsIgnored(): void
    {
        // Restrict allowlist so this user is no longer allowed.
        (new SystemConfigStorage())->save([
            'defaults' => ['proxied' => false, 'ttl' => 300, 'auto_ttl' => true],
            'allowed_users' => ['someone-else'],
            'rate_limit_rps' => 5,
            'dry_run' => true,
            'email_normalization' => [
                'dmarc_template' => '',
                'spf_extras' => [],
                'dmarc' => [
                    'enabled' => false, 'policy' => 'none', 'email' => '',
                    'rua' => true, 'ruf' => false, 'sp' => '', 'pct' => null, 'custom' => '',
                ],
                'spf_presets' => [],
                'spf_custom' => '',
            ],
            'local_rewrite' => [
                'enabled' => false, 'exclude_zones' => [],
                'overwrite_custom_rua' => false, 'respect_has_custom_dmarc' => true,
                'respect_user_locks' => true,
            ],
        ]);

        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', ['action' => 'refresh_diff']);

        self::assertFalse($vm['allowed']);
        self::assertFalse($vm['saved']);
        self::assertSame([], $vm['errors']);
    }

    public function testConnectDomainRejectsEmptyDomain(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'connect_domain',
            'domain' => '',
        ], ['example.com']);

        self::assertFalse($vm['saved']);
        self::assertSame(['No domain provided.'], $vm['errors']);
    }

    public function testConnectDomainRejectsDomainNotOwnedByUser(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'connect_domain',
            'domain' => 'not-mine.example',
        ], ['example.com']);

        self::assertFalse($vm['saved']);
        self::assertSame(['That domain does not belong to this cPanel account.'], $vm['errors']);
    }

    public function testConnectDomainRejectsDomainNotInAdminIndex(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'connect_domain',
            'domain' => 'example.com',
        ], ['example.com']);

        self::assertFalse($vm['saved']);
        self::assertSame(
            ['That domain is not covered by any Cloudflare account on this server.'],
            $vm['errors'],
        );
    }

    public function testConnectDomainSucceedsAndEnrollsUser(): void
    {
        $this->zoneIndex->replaceForToken('admin-tok', [[
            'cf_zone_id' => 'cf-zone-1',
            'name' => 'example.com',
            'cf_account_id' => 'acct-1',
            'cf_account_name' => 'Acme',
            'permissions' => [],
        ]]);
        $csrf = $this->seedCsrfToken();
        $calls = [];
        $ctl = $this->makeController(function (string $op) use (&$calls): void {
            $calls[] = $op;
        });

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'connect_domain',
            'domain' => 'Example.COM ',
        ], ['example.com']);

        self::assertTrue($vm['saved']);
        self::assertSame([], $vm['errors']);
        self::assertStringContainsString('example.com connected', $vm['message']);
        self::assertSame(['enroll'], $calls);

        // Side-effect: zone is now in user config with sync_state=pending_diff.
        $cfg = $this->storage->load(self::USER);
        self::assertCount(1, $cfg['zones']);
        self::assertSame('cf-zone-1', $cfg['zones'][0]['zone_id']);
        self::assertSame('example.com', $cfg['zones'][0]['zone_name']);
        self::assertTrue($cfg['zones'][0]['enabled']);
        self::assertSame(UserConfigStorage::STATE_PENDING_DIFF, $cfg['zones'][0]['sync_state']);
        self::assertSame(UserConfigStorage::SOURCE_ADMIN, $cfg['zones'][0]['source']);
    }

    public function testConnectDomainPropagatesEnrollmentFailure(): void
    {
        $this->zoneIndex->replaceForToken('admin-tok', [[
            'cf_zone_id' => 'cf-zone-1',
            'name' => 'example.com',
            'cf_account_id' => 'acct-1',
            'cf_account_name' => 'Acme',
            'permissions' => [],
        ]]);
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController(static function (string $op): void {
            throw new \RuntimeException('adminbin denied');
        });

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'connect_domain',
            'domain' => 'example.com',
        ], ['example.com']);

        self::assertFalse($vm['saved']);
        self::assertCount(1, $vm['errors']);
        self::assertStringContainsString('adminbin denied', $vm['errors'][0]);

        // Half-state guard: config must not have been mutated.
        $cfg = $this->storage->load(self::USER);
        self::assertSame([], $cfg['zones']);
    }

    public function testRefreshDiffRequiresZoneId(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'refresh_diff',
            'zone_id' => '',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Missing zone id.'], $vm['errors']);
    }

    public function testRefreshDiffRejectsUnknownZone(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'refresh_diff',
            'zone_id' => 'missing',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Zone not connected.'], $vm['errors']);
    }

    public function testRefreshDiffFlipsZoneIntoPendingState(): void
    {
        $this->seedConnectedZone('zone-a', 'one.example', true, UserConfigStorage::SOURCE_ADMIN, UserConfigStorage::STATE_AWAITING_REVIEW);
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'refresh_diff',
            'zone_id' => 'zone-a',
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame([], $vm['errors']);
        $cfg = $this->storage->load(self::USER);
        self::assertSame(UserConfigStorage::STATE_PENDING_DIFF, $cfg['zones'][0]['sync_state']);
    }

    public function testDisconnectMissingZoneIdFails(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'disconnect',
            'zone_id' => '',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Missing zone id.'], $vm['errors']);
    }

    public function testDisconnectAlreadyDisabledIsIdempotent(): void
    {
        $this->seedConnectedZone('zone-a', 'one.example', false);
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'disconnect',
            'zone_id' => 'zone-a',
        ]);

        self::assertTrue($vm['saved']);
        self::assertStringContainsString('already disconnected', $vm['message']);
    }

    public function testDisconnectSoftDeletesAndUnenrollsWhenLastZone(): void
    {
        $this->seedConnectedZone('zone-a', 'one.example', true);
        $csrf = $this->seedCsrfToken();
        $calls = [];
        $ctl = $this->makeController(function (string $op) use (&$calls): void {
            $calls[] = $op;
        });

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'disconnect',
            'zone_id' => 'zone-a',
        ]);

        self::assertTrue($vm['saved']);
        self::assertStringContainsString('disconnected', $vm['message']);
        self::assertSame(['unenroll'], $calls);

        $cfg = $this->storage->load(self::USER);
        self::assertCount(1, $cfg['zones']);
        self::assertFalse($cfg['zones'][0]['enabled']);
        self::assertSame(UserConfigStorage::STATE_IDLE, $cfg['zones'][0]['sync_state']);
    }

    public function testDisconnectDoesNotUnenrollWhenOtherZonesActive(): void
    {
        $this->seedConnectedZone('zone-a', 'one.example', true);
        $this->seedConnectedZone('zone-b', 'two.example', true);
        $csrf = $this->seedCsrfToken();
        $calls = [];
        $ctl = $this->makeController(function (string $op) use (&$calls): void {
            $calls[] = $op;
        });

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'disconnect',
            'zone_id' => 'zone-a',
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame([], $calls, 'must not unenroll while another zone is still active');
    }

    public function testReenableRequiresKnownZone(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'reenable',
            'zone_id' => 'missing',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Zone not connected.'], $vm['errors']);
    }

    public function testReenableMissingZoneIdReturnsError(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'reenable',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Missing zone id.'], $vm['errors']);
    }

    public function testReenableFlipsDisabledZoneBackOn(): void
    {
        $this->seedConnectedZone('zone-a', 'one.example', false);
        $csrf = $this->seedCsrfToken();
        $calls = [];
        $ctl = $this->makeController(function (string $op) use (&$calls): void {
            $calls[] = $op;
        });

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'reenable',
            'zone_id' => 'zone-a',
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame(['enroll'], $calls);
        $cfg = $this->storage->load(self::USER);
        self::assertTrue($cfg['zones'][0]['enabled']);
        self::assertSame(UserConfigStorage::STATE_PENDING_DIFF, $cfg['zones'][0]['sync_state']);
    }

    public function testAddLockRequiresZoneId(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'add_lock',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Missing zone id.'], $vm['errors']);
    }

    public function testAddLockRejectsUnknownZone(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'add_lock',
            'zone_id' => 'missing',
            'scope' => LockStorage::SCOPE_TYPE_NAME,
            'type' => 'TXT',
            'name' => '_dmarc.example.com',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Zone not connected.'], $vm['errors']);
    }

    public function testAddLockRejectsInvalidScope(): void
    {
        $this->seedConnectedZone();
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'add_lock',
            'zone_id' => 'zone-1',
            'scope' => 'unknown-scope',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Invalid lock scope.'], $vm['errors']);
    }

    public function testAddLockSucceedsForTypeNameScope(): void
    {
        $this->seedConnectedZone();
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'add_lock',
            'zone_id' => 'zone-1',
            'scope' => LockStorage::SCOPE_TYPE_NAME,
            'type' => 'TXT',
            'name' => '_dmarc.example.com',
            'reason' => 'managed by postmaster',
        ]);

        self::assertTrue($vm['saved']);
        self::assertStringContainsString('Lock added', $vm['message']);

        $locks = (new LockStorage())->all(self::USER, 'zone-1');
        self::assertCount(1, $locks);
    }

    public function testAddLockSurfacesInvalidArgumentFromStorage(): void
    {
        $this->seedConnectedZone();
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        // SCOPE_SUBTREE without a name throws InvalidArgumentException
        // inside LockStorage; the controller catches it and returns the
        // message as a UI error.
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'add_lock',
            'zone_id' => 'zone-1',
            'scope' => LockStorage::SCOPE_SUBTREE,
            'name' => '',
        ]);

        self::assertFalse($vm['saved']);
        self::assertCount(1, $vm['errors']);
        self::assertStringContainsString('subtree', $vm['errors'][0]);
    }

    public function testRemoveLockRequiresZoneId(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'remove_lock',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Missing zone id.'], $vm['errors']);
    }

    public function testRemoveLockRequiresLockId(): void
    {
        $this->seedConnectedZone();
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'remove_lock',
            'zone_id' => 'zone-1',
            'lock_id' => '',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Missing lock id.'], $vm['errors']);
    }

    public function testRemoveLockFailsWhenLockMissing(): void
    {
        $this->seedConnectedZone();
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'remove_lock',
            'zone_id' => 'zone-1',
            'lock_id' => 'type_name:TXT:nope',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Lock not found.'], $vm['errors']);
    }

    public function testRemoveLockSucceedsForExistingLock(): void
    {
        $this->seedConnectedZone();
        $lockId = (new LockStorage())->add(
            user: self::USER,
            zoneId: 'zone-1',
            scope: LockStorage::SCOPE_TYPE_NAME,
            type: 'TXT',
            name: '_dmarc.example.com',
        );
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'remove_lock',
            'zone_id' => 'zone-1',
            'lock_id' => $lockId,
        ]);

        self::assertTrue($vm['saved']);
        self::assertSame('Lock removed.', $vm['message']);
        self::assertSame([], (new LockStorage())->all(self::USER, 'zone-1'));
    }

    public function testToggleLockRequiresZoneId(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'toggle_lock',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Missing zone id.'], $vm['errors']);
    }

    public function testToggleLockRequiresLockKey(): void
    {
        $this->seedConnectedZone();
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'toggle_lock',
            'zone_id' => 'zone-1',
            'lock_key' => '',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Missing record identifier.'], $vm['errors']);
    }

    public function testToggleLockFailsWhenNoDiffOnDisk(): void
    {
        $this->seedConnectedZone();
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'toggle_lock',
            'zone_id' => 'zone-1',
            'lock_key' => 'TXT|_dmarc.example.com',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['No diff available.'], $vm['errors']);
    }

    public function testApplyRequiresZoneId(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'apply',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Missing zone id.'], $vm['errors']);
    }

    public function testApplyRejectsUnknownZone(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'apply',
            'zone_id' => 'missing',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Zone not connected.'], $vm['errors']);
    }

    public function testApplyFailsWhenNoDiffAvailable(): void
    {
        $this->seedConnectedZone();
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();

        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'apply',
            'zone_id' => 'zone-1',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['No diff available. Press Refresh to recompute.'], $vm['errors']);
    }

    public function testTestActionReturnsHintWhenTokenMissing(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'test',
            'token' => '',
            'zone_name' => 'example.com',
        ]);

        self::assertSame('Provide a token to test.', $vm['test_result']);
        self::assertFalse($vm['saved']);
    }

    public function testSaveRequiresZoneName(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        // No `action` field → falls through to save().
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'zone_name' => '',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Zone name is required.'], $vm['errors']);
    }

    public function testSaveRejectsMalformedZoneName(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'zone_name' => 'not a domain!',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['Zone name is not a valid domain.'], $vm['errors']);
    }

    public function testSaveRequiresTokenWhenNoneStored(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'zone_name' => 'example.com',
            'token' => '',
        ]);

        self::assertFalse($vm['saved']);
        self::assertSame(['A Cloudflare API token is required.'], $vm['errors']);
    }

    public function testLastApplyMetaIsNullBeforeAnyApply(): void
    {
        $ctl = $this->makeController();
        self::assertNull($ctl->lastApplyMeta());
    }

    public function testQueueStatusForEmptyConfigReturnsNoZones(): void
    {
        $ctl = $this->makeController();
        $status = $ctl->queueStatus(self::USER);

        self::assertSame([], $status['zones']);
        self::assertGreaterThan(0, $status['ts']);
    }

    public function testQueueStatusIncludesOnlyEnabledZones(): void
    {
        $this->seedConnectedZone('zone-a', 'one.example', true);
        $this->seedConnectedZone('zone-b', 'two.example', false);
        $ctl = $this->makeController();

        $status = $ctl->queueStatus(self::USER);

        self::assertArrayHasKey('zone-a', $status['zones']);
        self::assertArrayNotHasKey('zone-b', $status['zones']);
        self::assertSame(0, $status['zones']['zone-a']['queue_depth']);
        self::assertSame(0, $status['zones']['zone-a']['dead_letters']);
        self::assertSame([], $status['zones']['zone-a']['pending_keys']);
    }

    public function testHandleReturnsRotatedCsrfTokenInViewModel(): void
    {
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'refresh_diff',
            'zone_id' => 'missing',
        ]);

        // Csrf::verify() rotates the token on success; Csrf::token()
        // (called at the end of handle()) generates a fresh one. The
        // returned csrf should therefore differ from the one we sent in.
        self::assertNotSame($csrf, $vm['csrf']);
        self::assertSame(64, strlen($vm['csrf']));
    }

    public function testEnrollmentSucceedsViaRealEnrolledUsersWhenNoBackend(): void
    {
        $this->zoneIndex->replaceForToken('admin-tok', [[
            'cf_zone_id' => 'cf-zone-1',
            'name' => 'example.com',
            'cf_account_id' => 'acct-1',
            'cf_account_name' => 'Acme',
            'permissions' => [],
        ]]);
        $csrf = $this->seedCsrfToken();
        $ctl = $this->makeController();
        $vm = $ctl->handle(self::USER, 'POST', [
            'csrf' => $csrf,
            'action' => 'connect_domain',
            'domain' => 'example.com',
        ], ['example.com']);

        self::assertTrue($vm['saved']);
        $enrolled = (new EnrolledUsers())->all();
        self::assertContains(self::USER, $enrolled);
    }
}
