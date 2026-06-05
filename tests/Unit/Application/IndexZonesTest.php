<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Application;

use Closure;
use FilesystemIterator;
use LogicException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZoneMirror\Application\IndexZones;
use ZoneMirror\Domain\AdminToken;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Storage\AdminTokenStorage;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\ZoneIndex;

final class IndexZonesTest extends TestCase
{
    private string $tmpDir;
    private string $systemDir;
    private string $logPath;
    private AdminTokenStorage $tokens;
    private ZoneIndex $index;
    private FileLogger $logger;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-iz-' . bin2hex(random_bytes(4));
        $this->systemDir = $this->tmpDir . '/system';
        if (!mkdir($this->systemDir, 0700, true) && !is_dir($this->systemDir)) {
            throw new RuntimeException('Unable to create system dir.');
        }
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);

        $this->logPath = $this->tmpDir . '/zonemirror.log';
        $this->logger = new FileLogger($this->logPath);

        $keyStore = new KeyStore($this->systemDir . '/master.key');
        $this->tokens = new AdminTokenStorage(new ConfigCrypto($keyStore));

        $this->index = new ZoneIndex($this->systemDir . '/zone-index.sqlite');
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        $this->rmrf($this->tmpDir);
    }

    // -----------------------------------------------------------------
    // mapStatus()
    // -----------------------------------------------------------------

    public function testMapStatusActiveMapsToOk(): void
    {
        self::assertSame(AdminToken::STATUS_OK, IndexZones::mapStatus('active'));
    }

    public function testMapStatusExpiredMapsToExpired(): void
    {
        self::assertSame(AdminToken::STATUS_EXPIRED, IndexZones::mapStatus('expired'));
    }

    public function testMapStatusDisabledMapsToUnauthorized(): void
    {
        self::assertSame(AdminToken::STATUS_UNAUTHORIZED, IndexZones::mapStatus('disabled'));
    }

    public function testMapStatusEmptyStringMapsToUnauthorized(): void
    {
        self::assertSame(AdminToken::STATUS_UNAUTHORIZED, IndexZones::mapStatus(''));
    }

    public function testMapStatusUnknownMapsToPartialScope(): void
    {
        self::assertSame(AdminToken::STATUS_PARTIAL_SCOPE, IndexZones::mapStatus('something-cf-invented'));
    }

    // -----------------------------------------------------------------
    // refreshOne() — error / edge paths
    // -----------------------------------------------------------------

    public function testRefreshOneRemovesIndexSliceWhenTokenIsUnknown(): void
    {
        // Seed two stale rows for an unrelated token id directly into the
        // index, then ask IndexZones to refresh that id. With no matching
        // AdminToken on disk, the slice must be wiped.
        $this->index->replaceForToken('ghost-token', [
            [
                'cf_zone_id' => 'cf-zone-1',
                'name' => 'stale.example',
                'cf_account_id' => 'acct-1',
                'cf_account_name' => 'Acme',
                'permissions' => ['#dns_records:edit'],
            ],
        ]);
        self::assertSame(1, $this->index->countForToken('ghost-token'));

        $indexer = $this->makeIndexer($this->failingFactory());
        $indexer->refreshOne('ghost-token');

        self::assertSame(0, $this->index->countForToken('ghost-token'));
    }

    public function testRefreshOneMarksTokenUnauthorizedWhenPlaintextIsUnreadable(): void
    {
        // Add the token, then surgically corrupt its ciphertext on disk so
        // plaintextFor() returns null. We expect the indexer to flip the
        // status pill to unauthorized AND clear the slice without ever
        // calling Cloudflare.
        $token = $this->tokens->add('admin', 'cf-real-token');
        $this->index->replaceForToken($token->id, [
            [
                'cf_zone_id' => 'z1',
                'name' => 'kept.example',
                'cf_account_id' => '',
                'cf_account_name' => '',
                'permissions' => [],
            ],
        ]);
        $this->corruptCiphertext($token->id);

        $indexer = $this->makeIndexer($this->failingFactory());
        $indexer->refreshOne($token->id);

        $after = $this->tokens->find($token->id);
        self::assertNotNull($after);
        self::assertSame(AdminToken::STATUS_UNAUTHORIZED, $after->status);
        self::assertSame(0, $after->zonesIndexed);
        self::assertSame(0, $this->index->countForToken($token->id));
    }

    public function testRefreshOneKeepsIndexSliceWhenVerifyThrows(): void
    {
        // Transient CF outage: the previous slice must survive untouched
        // and the AdminToken metadata must NOT be flipped.
        $token = $this->tokens->add('admin', 'cf-real-token');
        $this->index->replaceForToken($token->id, [
            [
                'cf_zone_id' => 'z1',
                'name' => 'stable.example',
                'cf_account_id' => 'acct-1',
                'cf_account_name' => 'Acme',
                'permissions' => ['#dns_records:edit'],
            ],
        ]);
        $statusBefore = $this->tokens->find($token->id)?->status;

        $client = new class ('x') extends CloudflareApiClient {
            public function verifyTokenStatus(): string
            {
                throw new RuntimeException('boom: connect timeout');
            }
        };
        $indexer = $this->makeIndexer(static fn (): CloudflareApiClient => $client);
        $indexer->refreshOne($token->id);

        self::assertSame(1, $this->index->countForToken($token->id));
        self::assertSame($statusBefore, $this->tokens->find($token->id)?->status);
    }

    public function testRefreshOneClearsSliceWhenStatusIsNotOk(): void
    {
        $token = $this->tokens->add('admin', 'cf-real-token');
        $this->index->replaceForToken($token->id, [
            [
                'cf_zone_id' => 'z1',
                'name' => 'stale.example',
                'cf_account_id' => '',
                'cf_account_name' => '',
                'permissions' => [],
            ],
        ]);

        $client = new class ('x') extends CloudflareApiClient {
            public function verifyTokenStatus(): string
            {
                return 'expired';
            }
        };
        $indexer = $this->makeIndexer(static fn (): CloudflareApiClient => $client);
        $indexer->refreshOne($token->id);

        $after = $this->tokens->find($token->id);
        self::assertNotNull($after);
        self::assertSame(AdminToken::STATUS_EXPIRED, $after->status);
        self::assertSame(0, $after->zonesIndexed);
        self::assertSame(0, $this->index->countForToken($token->id));
    }

    public function testRefreshOneKeepsSliceWhenListZonesThrows(): void
    {
        $token = $this->tokens->add('admin', 'cf-real-token');
        $this->index->replaceForToken($token->id, [
            [
                'cf_zone_id' => 'z1',
                'name' => 'survives.example',
                'cf_account_id' => '',
                'cf_account_name' => '',
                'permissions' => [],
            ],
        ]);

        $client = new class ('x') extends CloudflareApiClient {
            public function verifyTokenStatus(): string
            {
                return 'active';
            }

            public function listZones(): array
            {
                throw new RuntimeException('boom: rate limited');
            }
        };
        $indexer = $this->makeIndexer(static fn (): CloudflareApiClient => $client);
        $indexer->refreshOne($token->id);

        self::assertSame(1, $this->index->countForToken($token->id));
    }

    // -----------------------------------------------------------------
    // refreshOne() — happy path
    // -----------------------------------------------------------------

    public function testRefreshOneReplacesSliceWithFreshZones(): void
    {
        $token = $this->tokens->add('admin', 'cf-real-token');
        // Pre-existing stale row that must disappear after the refresh.
        $this->index->replaceForToken($token->id, [
            [
                'cf_zone_id' => 'stale-id',
                'name' => 'stale.example',
                'cf_account_id' => '',
                'cf_account_name' => '',
                'permissions' => [],
            ],
        ]);

        $client = new class ('x') extends CloudflareApiClient {
            public function verifyTokenStatus(): string
            {
                return 'active';
            }

            public function listZones(): array
            {
                return [
                    [
                        'id' => 'zone-aaa',
                        'name' => 'one.example',
                        'account' => ['id' => 'acct-1', 'name' => 'Acme Corp'],
                        'permissions' => ['#dns_records:edit', '#zone:read'],
                    ],
                    [
                        'id' => 'zone-bbb',
                        'name' => 'two.example',
                        // No account block on purpose — code path must
                        // tolerate the absence and default both fields
                        // to empty strings.
                        'permissions' => ['#zone:read'],
                    ],
                ];
            }
        };
        $indexer = $this->makeIndexer(static fn (): CloudflareApiClient => $client);
        $indexer->refreshOne($token->id);

        $rows = $this->index->allForToken($token->id);
        self::assertCount(2, $rows);

        $byZoneId = [];
        foreach ($rows as $r) {
            $byZoneId[$r['cf_zone_id']] = $r;
        }

        self::assertArrayHasKey('zone-aaa', $byZoneId);
        self::assertSame('one.example', $byZoneId['zone-aaa']['name']);
        self::assertSame('acct-1', $byZoneId['zone-aaa']['cf_account_id']);
        self::assertSame('Acme Corp', $byZoneId['zone-aaa']['cf_account_name']);
        self::assertSame(['#dns_records:edit', '#zone:read'], $byZoneId['zone-aaa']['permissions']);

        self::assertArrayHasKey('zone-bbb', $byZoneId);
        self::assertSame('', $byZoneId['zone-bbb']['cf_account_id']);
        self::assertSame('', $byZoneId['zone-bbb']['cf_account_name']);
        self::assertSame(['#zone:read'], $byZoneId['zone-bbb']['permissions']);

        $after = $this->tokens->find($token->id);
        self::assertNotNull($after);
        self::assertSame(AdminToken::STATUS_OK, $after->status);
        self::assertSame(2, $after->zonesIndexed);
    }

    public function testRefreshOneSkipsMalformedZoneEntries(): void
    {
        $token = $this->tokens->add('admin', 'cf-real-token');

        $client = new class ('x') extends CloudflareApiClient {
            public function verifyTokenStatus(): string
            {
                return 'active';
            }

            public function listZones(): array
            {
                /** @phpstan-ignore-next-line return.type test feeds intentionally malformed entries */
                return [
                    'not-an-array',           // skipped: not array
                    ['id' => '', 'name' => 'noid.example'],   // skipped: empty id
                    ['id' => 'has-id', 'name' => ''],         // skipped: empty name
                    [
                        'id' => 'keep',
                        'name' => 'kept.example',
                        'permissions' => ['#zone:read', 42, 'ok'], // 42 dropped, two strings kept
                    ],
                ];
            }
        };
        $indexer = $this->makeIndexer(static fn (): CloudflareApiClient => $client);
        $indexer->refreshOne($token->id);

        $rows = $this->index->allForToken($token->id);
        self::assertCount(1, $rows);
        self::assertSame('keep', $rows[0]['cf_zone_id']);
        self::assertSame(['#zone:read', 'ok'], $rows[0]['permissions']);

        $after = $this->tokens->find($token->id);
        self::assertNotNull($after);
        self::assertSame(1, $after->zonesIndexed);
    }

    public function testRefreshOneWithEmptyZoneListMarksOkWithZeroCount(): void
    {
        $token = $this->tokens->add('admin', 'cf-real-token');
        // Pre-existing row that must disappear.
        $this->index->replaceForToken($token->id, [[
            'cf_zone_id' => 'stale',
            'name' => 'stale.example',
            'cf_account_id' => '',
            'cf_account_name' => '',
            'permissions' => [],
        ]]);

        $client = new class ('x') extends CloudflareApiClient {
            public function verifyTokenStatus(): string
            {
                return 'active';
            }

            public function listZones(): array
            {
                return [];
            }
        };
        $indexer = $this->makeIndexer(static fn (): CloudflareApiClient => $client);
        $indexer->refreshOne($token->id);

        self::assertSame(0, $this->index->countForToken($token->id));
        $after = $this->tokens->find($token->id);
        self::assertNotNull($after);
        self::assertSame(AdminToken::STATUS_OK, $after->status);
        self::assertSame(0, $after->zonesIndexed);
    }

    // -----------------------------------------------------------------
    // runOnce() — orchestration / isolation
    // -----------------------------------------------------------------

    public function testRunOnceIsolatesFailureSoOtherTokensStillRefresh(): void
    {
        $a = $this->tokens->add('first', 'cf-real-token-a');
        $b = $this->tokens->add('second', 'cf-real-token-b');

        $factory = static function (string $plaintext): CloudflareApiClient {
            if ($plaintext === 'cf-real-token-a') {
                return new class ('a') extends CloudflareApiClient {
                    public function verifyTokenStatus(): string
                    {
                        throw new RuntimeException('boom for A');
                    }
                };
            }
            if ($plaintext === 'cf-real-token-b') {
                return new class ('b') extends CloudflareApiClient {
                    public function verifyTokenStatus(): string
                    {
                        return 'active';
                    }

                    public function listZones(): array
                    {
                        return [[
                            'id' => 'z-b',
                            'name' => 'b.example',
                            'account' => ['id' => 'acct-b', 'name' => 'Bravo'],
                            'permissions' => ['#dns_records:edit'],
                        ]];
                    }
                };
            }

            throw new RuntimeException('unexpected plaintext');
        };

        // Pre-seed A so we can confirm its slice is preserved (verify-throw
        // path) instead of wiped by the failure.
        $this->index->replaceForToken($a->id, [[
            'cf_zone_id' => 'z-a',
            'name' => 'a.example',
            'cf_account_id' => '',
            'cf_account_name' => '',
            'permissions' => [],
        ]]);

        $indexer = $this->makeIndexer($factory);
        $indexer->runOnce();

        self::assertSame(1, $this->index->countForToken($a->id), 'A slice should be preserved on verify failure');
        self::assertSame(1, $this->index->countForToken($b->id), 'B should still get its fresh slice');
        self::assertSame('b.example', $this->index->allForToken($b->id)[0]['name']);

        $afterB = $this->tokens->find($b->id);
        self::assertNotNull($afterB);
        self::assertSame(AdminToken::STATUS_OK, $afterB->status);
    }

    public function testRunOnceCatchesLogicExceptionFromBadClientFactory(): void
    {
        // The clientFactory contract is enforced inside makeClient() and
        // throws LogicException when violated. runOnce() must catch it
        // (it's a Throwable) so a single misconfigured token never aborts
        // the sweep. Verify: with a single token + bad factory, runOnce
        // returns without raising, and the slice is left untouched.
        $token = $this->tokens->add('only', 'cf-real-token');
        $this->index->replaceForToken($token->id, [[
            'cf_zone_id' => 'kept',
            'name' => 'kept.example',
            'cf_account_id' => '',
            'cf_account_name' => '',
            'permissions' => [],
        ]]);

        $factory = static fn (string $plaintext): string => 'not a client';

        $indexer = new IndexZones($this->tokens, $this->index, $this->logger, $factory);
        $indexer->runOnce();

        self::assertSame(1, $this->index->countForToken($token->id));
    }

    public function testRefreshOneSurfacesLogicExceptionFromBadClientFactory(): void
    {
        // Same misconfiguration, but via the public single-token path:
        // here the caller IS responsible for catching, so the exception
        // must surface untouched.
        $token = $this->tokens->add('only', 'cf-real-token');

        $factory = static fn (string $plaintext): string => 'not a client';

        $indexer = new IndexZones($this->tokens, $this->index, $this->logger, $factory);

        self::expectException(LogicException::class);
        $indexer->refreshOne($token->id);
    }

    public function testRunOnceIsNoopWhenNoTokensConfigured(): void
    {
        // No tokens added. runOnce must complete silently without ever
        // calling the factory (the failing factory below would throw).
        $indexer = $this->makeIndexer($this->failingFactory());
        $indexer->runOnce();

        // Nothing to assert besides "didn't throw"; verify the index is
        // still empty and no log entries were written.
        self::assertSame(0, $this->index->count());
        self::assertFileDoesNotExist($this->logPath);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function makeIndexer(Closure $factory): IndexZones
    {
        return new IndexZones($this->tokens, $this->index, $this->logger, $factory);
    }

    /**
     * A factory that fails loudly if the indexer ever asks for a client
     * during a test where Cloudflare should not be touched.
     */
    private function failingFactory(): Closure
    {
        return static function (string $plaintext): CloudflareApiClient {
            throw new RuntimeException('clientFactory should not be called in this test');
        };
    }

    /**
     * Replace the on-disk ciphertext for a token with junk that decrypt()
     * will reject. plaintextFor() catches that path by returning null in
     * AdminTokenStorage, but here we want the OUTER null path — so the
     * simplest is to set the ciphertext to an empty string, which short-
     * circuits to null without decrypt() running.
     */
    private function corruptCiphertext(string $tokenId): void
    {
        $path = $this->systemDir . '/admin-tokens.json';
        $raw = (string) file_get_contents($path);
        /** @var array{tokens: list<array<string, mixed>>} $json */
        $json = json_decode($raw, true);
        foreach ($json['tokens'] as $i => $row) {
            if (($row['id'] ?? null) === $tokenId) {
                $json['tokens'][$i]['ciphertext'] = '';
            }
        }
        file_put_contents($path, (string) json_encode($json));
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
