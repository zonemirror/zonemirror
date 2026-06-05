<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Interface\Hook;

use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Interface\Hook\HookHandler;

final class HookHandlerTest extends TestCase
{
    private string $tmpDir;
    private string $userHome;
    private string $systemDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-hh-' . bin2hex(random_bytes(4));
        $this->userHome = $this->tmpDir . '/home';
        $this->systemDir = $this->tmpDir . '/system';
        mkdir($this->userHome, 0700, true);
        mkdir($this->systemDir, 0755, true);
        putenv(Paths::ENV_USER_HOME . '=' . $this->userHome);
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_USER_HOME . '=');
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testHandleDoesNothingWhenPayloadHasNoDomain(): void
    {
        // No user config, no system config, no domain — must not enqueue
        // and must not throw. The hook is best-effort.
        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        $handler->handle(['data' => ['args' => [], 'result' => ['data' => []]]], 'alice');

        self::assertFalse($this->queueExists('alice'));
    }

    public function testHandleIgnoresEditOnNonSyncedZone(): void
    {
        // The user has 'example.com' synced; the hook fires for a
        // domain they have no connection for. Must not enqueue.
        $this->seedUserConfig('alice', [
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'example.com',
                'enabled' => true,
                'defaults' => ['proxied' => false],
                'source' => 'admin',
            ]],
        ]);
        $this->seedSystemConfig(['allowed_users' => 'all']);

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        $handler->handle(
            $this->payload('other.example', ['type' => 'A', 'name' => 'www.other.example', 'address' => '203.0.113.10']),
            'alice',
        );

        self::assertSame(0, $this->queueDepth('alice'));
    }

    public function testHandleSkipsZoneThatIsDisabled(): void
    {
        // zone is in the config but enabled=false. Hook must skip;
        // zoneForDomain only returns enabled zones.
        $this->seedUserConfig('alice', [
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'example.com',
                'enabled' => false,
                'defaults' => ['proxied' => false],
                'source' => 'admin',
            ]],
        ]);
        $this->seedSystemConfig(['allowed_users' => 'all']);

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        $handler->handle(
            $this->payload('example.com', ['type' => 'A', 'name' => 'www.example.com', 'address' => '203.0.113.10']),
            'alice',
        );

        self::assertSame(0, $this->queueDepth('alice'));
    }

    public function testHandleSkipsWhenUserSourceWithoutToken(): void
    {
        // source=user but no token_encrypted on file: there's no
        // credential path to Cloudflare, so the hook must drop the event.
        $this->seedUserConfig('alice', [
            'version' => 2,
            // intentionally no token_encrypted
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'example.com',
                'enabled' => true,
                'defaults' => ['proxied' => false],
                'source' => 'user',
            ]],
        ]);
        $this->seedSystemConfig(['allowed_users' => 'all']);

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        $handler->handle(
            $this->payload('example.com', ['type' => 'A', 'name' => 'www.example.com', 'address' => '203.0.113.10']),
            'alice',
        );

        self::assertSame(0, $this->queueDepth('alice'));
    }

    public function testHandleSkipsWhenUserNotAllowed(): void
    {
        // The user is enrolled with a synced zone and a credential path,
        // but the WHM-admin allowlist excludes them. Must not enqueue
        // and must log a 'hook skipped' info entry.
        $this->seedUserConfig('alice', [
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'example.com',
                'enabled' => true,
                'defaults' => ['proxied' => false],
                'source' => 'admin',
            ]],
        ]);
        $this->seedSystemConfig(['allowed_users' => ['bob']]); // not alice

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        $handler->handle(
            $this->payload('example.com', ['type' => 'A', 'name' => 'www.example.com', 'address' => '203.0.113.10']),
            'alice',
        );

        self::assertSame(0, $this->queueDepth('alice'));
        $log = $this->readUserLog('alice');
        self::assertStringContainsString('hook skipped: user not allowed', $log);
    }

    public function testHandleSkipsWhenMapperReturnsNull(): void
    {
        // NS records are dropped by CpanelToCloudflareMapper (authoritative
        // on Cloudflare). Hook must not enqueue.
        $this->seedUserConfig('alice', [
            'version' => 2,
            'zones' => [[
                'zone_id' => 'z1',
                'zone_name' => 'example.com',
                'enabled' => true,
                'defaults' => ['proxied' => false],
                'source' => 'admin',
            ]],
        ]);
        $this->seedSystemConfig(['allowed_users' => 'all']);

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        $handler->handle(
            $this->payload('example.com', ['type' => 'NS', 'name' => 'example.com', 'nsdname' => 'ns1.example.com']),
            'alice',
        );

        self::assertSame(0, $this->queueDepth('alice'));
    }

    public function testHandleEnqueuesEventForAdminSource(): void
    {
        // Happy path: admin token covers the zone, user is allowed, payload
        // maps to a valid A record. Must enqueue exactly one event and log
        // 'event enqueued'.
        $this->seedUserConfig('alice', [
            'version' => 2,
            'zones' => [[
                'zone_id' => 'zone-abc',
                'zone_name' => 'example.com',
                'enabled' => true,
                'defaults' => ['proxied' => false],
                'source' => 'admin',
            ]],
        ]);
        $this->seedSystemConfig(['allowed_users' => 'all']);

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        $handler->handle(
            $this->payload('example.com', ['type' => 'A', 'name' => 'www.example.com.', 'address' => '203.0.113.10', 'ttl' => 300]),
            'alice',
        );

        self::assertSame(1, $this->queueDepth('alice'));

        $row = $this->fetchFirstQueueRow('alice');
        self::assertSame('example.com', $row['domain']);
        self::assertSame('UPSERT', $row['action']);
        self::assertSame('zone-abc', $row['zone_id']);
        $payload = json_decode((string) $row['record_json'], true);
        self::assertIsArray($payload);
        self::assertSame('A', $payload['type']);
        self::assertSame('www.example.com', $payload['name']); // trailing dot stripped
        self::assertSame('203.0.113.10', $payload['content']);

        $log = $this->readUserLog('alice');
        self::assertStringContainsString('event enqueued', $log);
    }

    public function testHandleEnqueuesEventForUserSourceWithToken(): void
    {
        // source=user requires has_token=true on the user-level metadata;
        // the cipher blob does not have to decrypt because the hook never
        // touches the master key. A non-empty token_encrypted string is
        // enough for the hook to consider the credential path present.
        $this->seedUserConfig('alice', [
            'version' => 2,
            'token_encrypted' => 'opaque-blob-not-decrypted-by-hook',
            'zones' => [[
                'zone_id' => 'zone-xyz',
                'zone_name' => 'example.com',
                'enabled' => true,
                'defaults' => ['proxied' => true],
                'source' => 'user',
            ]],
        ]);
        $this->seedSystemConfig(['allowed_users' => 'all']);

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::edit_zone_record');
        $handler->handle(
            $this->payload('example.com', ['type' => 'A', 'name' => 'app.example.com', 'address' => '203.0.113.20']),
            'alice',
        );

        self::assertSame(1, $this->queueDepth('alice'));
        $row = $this->fetchFirstQueueRow('alice');
        self::assertSame('zone-xyz', $row['zone_id']);
        $payload = json_decode((string) $row['record_json'], true);
        self::assertIsArray($payload);
        self::assertTrue($payload['proxied']);
    }

    public function testHandleEnqueuesDeleteActionWhenConstructedWithDelete(): void
    {
        // The action constructor parameter controls what gets written to
        // the queue's action column; verify Delete propagates end-to-end.
        $this->seedUserConfig('alice', [
            'version' => 2,
            'zones' => [[
                'zone_id' => 'zone-abc',
                'zone_name' => 'example.com',
                'enabled' => true,
                'defaults' => ['proxied' => false],
                'source' => 'admin',
            ]],
        ]);
        $this->seedSystemConfig(['allowed_users' => 'all']);

        $handler = new HookHandler(EventAction::Delete, 'ZoneEdit::remove_zone_record');
        $handler->handle(
            $this->payload('example.com', ['type' => 'A', 'name' => 'www.example.com', 'address' => '203.0.113.10']),
            'alice',
        );

        $row = $this->fetchFirstQueueRow('alice');
        self::assertSame('DELETE', $row['action']);
    }

    public function testHandleIsIdempotentOnDuplicatePayloads(): void
    {
        // Two consecutive handle() calls with the same payload must
        // collapse to a single row: idempotency_key is UNIQUE and the
        // queue uses INSERT OR IGNORE.
        $this->seedUserConfig('alice', [
            'version' => 2,
            'zones' => [[
                'zone_id' => 'zone-abc',
                'zone_name' => 'example.com',
                'enabled' => true,
                'defaults' => ['proxied' => false],
                'source' => 'admin',
            ]],
        ]);
        $this->seedSystemConfig(['allowed_users' => 'all']);

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        $payload = $this->payload(
            'example.com',
            ['type' => 'A', 'name' => 'www.example.com', 'address' => '203.0.113.10'],
        );
        $handler->handle($payload, 'alice');
        $handler->handle($payload, 'alice');

        self::assertSame(1, $this->queueDepth('alice'));
    }

    public function testHandleNeverPropagatesExceptionsToCaller(): void
    {
        // Force a failure deep in the pipeline: the system dir is replaced
        // with a regular file so SystemConfigStorage::isUserAllowed (which
        // load()s a json file under that dir) trips on the path. The hook
        // must swallow whatever Throwable bubbles up and log 'hook failed'
        // without throwing — cPanel hooks must never crash cPanel.
        $this->seedUserConfig('alice', [
            'version' => 2,
            'zones' => [[
                'zone_id' => 'zone-abc',
                'zone_name' => 'example.com',
                'enabled' => true,
                'defaults' => ['proxied' => false],
                'source' => 'admin',
            ]],
        ]);
        // Make the user queue file path un-creatable: replace the user
        // home's .zonemirror directory with a file so SqliteQueue::pdo()
        // throws when it tries to mkdir/open the sqlite file.
        $this->seedSystemConfig(['allowed_users' => 'all']);
        // Sabotage the queue: the .zonemirror dir already exists from
        // seedUserConfig; replace the queue file path with a directory
        // entry that PDO cannot open as a sqlite database.
        $queueDirEntry = $this->userHome . '/.zonemirror/queue.sqlite';
        mkdir($queueDirEntry, 0700, true);

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        // Must NOT throw.
        $handler->handle(
            $this->payload('example.com', ['type' => 'A', 'name' => 'www.example.com', 'address' => '203.0.113.10']),
            'alice',
        );

        $log = $this->readUserLog('alice');
        self::assertStringContainsString('hook failed', $log);
    }

    public function testHandleAcceptsEmptyUserStringWithoutThrowing(): void
    {
        // Boundary: an empty user name shouldn't crash the hook. There's
        // no config for '', so the read returns empty zones → no enqueue.
        // Nothing to assert about queue depth (path may not exist); the
        // assertion is "we got here without throwing".
        $this->expectNotToPerformAssertions();

        $handler = new HookHandler(EventAction::Upsert, 'ZoneEdit::add_zone_record');
        $handler->handle(
            $this->payload('example.com', ['type' => 'A', 'name' => 'www.example.com', 'address' => '203.0.113.10']),
            '',
        );
    }

    /**
     * @param array<string, mixed> $rawRecord
     * @return array<string, mixed>
     */
    private function payload(string $domain, array $rawRecord): array
    {
        return [
            'data' => [
                'args' => ['domain' => $domain],
                'result' => ['data' => $rawRecord],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedUserConfig(string $user, array $payload): void
    {
        $dir = $this->userHome . '/.zonemirror';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/config.json', (string) json_encode($payload));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedSystemConfig(array $overrides): void
    {
        $base = [
            'defaults' => ['proxied' => false, 'ttl' => 300, 'auto_ttl' => true],
            'allowed_users' => [],
            'rate_limit_rps' => 5,
            'dry_run' => true,
        ];
        $merged = array_merge($base, $overrides);
        file_put_contents(
            $this->systemDir . '/system.json',
            (string) json_encode($merged),
        );
    }

    private function queueExists(string $user): bool
    {
        return is_file($this->userHome . '/.zonemirror/queue.sqlite');
    }

    private function queueDepth(string $user): int
    {
        if (!$this->queueExists($user)) {
            return 0;
        }
        $pdo = new PDO('sqlite:' . $this->userHome . '/.zonemirror/queue.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->query('SELECT COUNT(*) AS n FROM events');
        if ($stmt === false) {
            return 0;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) ($row['n'] ?? 0) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFirstQueueRow(string $user): array
    {
        $pdo = new PDO('sqlite:' . $this->userHome . '/.zonemirror/queue.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->query('SELECT * FROM events ORDER BY id ASC LIMIT 1');
        if ($stmt === false) {
            self::fail('Could not query events table');
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            self::fail('No row found in events table');
        }

        return $row;
    }

    private function readUserLog(string $user): string
    {
        $path = $this->userHome . '/.zonemirror/log.txt';
        if (!is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
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
