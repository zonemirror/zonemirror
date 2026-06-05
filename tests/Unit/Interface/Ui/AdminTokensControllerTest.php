<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Interface\Ui;

use Closure;
use FilesystemIterator;
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
use ZoneMirror\Interface\Ui\AdminTokensController;

final class AdminTokensControllerTest extends TestCase
{
    private const CSRF_SESSION_KEY = 'zonemirror_csrf';
    private const KNOWN_CSRF = 'unit-test-csrf-token-value';

    private string $tmpDir;
    private string $systemDir;
    private AdminTokenStorage $tokens;
    private ZoneIndex $index;
    private FileLogger $logger;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-atc-' . bin2hex(random_bytes(4));
        $this->systemDir = $this->tmpDir . '/system';
        if (!mkdir($this->systemDir, 0700, true) && !is_dir($this->systemDir)) {
            throw new RuntimeException('Unable to create system dir.');
        }
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);

        $this->logger = new FileLogger($this->tmpDir . '/zonemirror.log');

        $keyStore = new KeyStore($this->systemDir . '/master.key');
        $this->tokens = new AdminTokenStorage(new ConfigCrypto($keyStore));
        $this->index = new ZoneIndex($this->systemDir . '/zone-index.sqlite');

        // Ensure a clean, predictable session for every test. The controller
        // delegates to Csrf which reads/writes $_SESSION directly.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        $_SESSION = [];
        $this->rmrf($this->tmpDir);
    }

    // -----------------------------------------------------------------
    // GET path
    // -----------------------------------------------------------------

    public function testHandleGetReturnsEmptyViewModelWhenNoTokensExist(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );

        $vm = $controller->handle('GET', []);

        self::assertTrue($vm['allowed']);
        self::assertSame([], $vm['tokens']);
        self::assertSame([], $vm['accounts_count_by_token']);
        self::assertSame([], $vm['errors']);
        self::assertSame('', $vm['message']);
        self::assertNotSame('', $vm['csrf']);
    }

    public function testHandleGetIncludesExistingTokensAndAccountCounts(): void
    {
        $token = $this->tokens->add('reseller-A', 'cf_pat_abc');
        $this->index->replaceForToken($token->id, [
            [
                'cf_zone_id' => 'z1',
                'name' => 'one.example',
                'cf_account_id' => 'acct-1',
                'cf_account_name' => 'Acme',
                'permissions' => ['#dns_records:edit'],
            ],
            [
                'cf_zone_id' => 'z2',
                'name' => 'two.example',
                'cf_account_id' => 'acct-2',
                'cf_account_name' => 'Brand B',
                'permissions' => [],
            ],
        ]);

        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );

        $vm = $controller->handle('GET', []);

        self::assertTrue($vm['allowed']);
        self::assertCount(1, $vm['tokens']);
        self::assertSame($token->id, $vm['tokens'][0]->id);
        self::assertArrayHasKey($token->id, $vm['accounts_count_by_token']);
        self::assertSame(2, $vm['accounts_count_by_token'][$token->id]);
        self::assertSame([], $vm['errors']);
        self::assertSame('', $vm['message']);
    }

    // -----------------------------------------------------------------
    // POST — CSRF guard
    // -----------------------------------------------------------------

    public function testHandlePostWithMissingCsrfReturnsError(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );

        // Seed session so Csrf::verify has something stored to fail against.
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'action' => 'add',
            'name' => 'reseller-A',
            'token' => 'cf_pat_abc',
        ]);

        self::assertTrue($vm['allowed']);
        self::assertCount(1, $vm['errors']);
        self::assertStringContainsString('CSRF', $vm['errors'][0]);
        self::assertSame('', $vm['message']);
        // No token should have been persisted.
        self::assertSame([], $this->tokens->all());
    }

    public function testHandlePostWithWrongCsrfReturnsError(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => 'totally-wrong',
            'action' => 'add',
            'name' => 'reseller-A',
            'token' => 'cf_pat_abc',
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertStringContainsString('CSRF', $vm['errors'][0]);
        self::assertSame([], $this->tokens->all());
    }

    // -----------------------------------------------------------------
    // POST add — happy path and edge cases
    // -----------------------------------------------------------------

    public function testHandlePostAddSucceedsAndRefreshesZoneIndex(): void
    {
        $client = new class ('x') extends CloudflareApiClient {
            public function verifyTokenStatus(): string
            {
                return 'active';
            }

            /**
             * @return list<array<string, mixed>>
             */
            public function listZones(): array
            {
                return [
                    [
                        'id' => 'cf-zone-1',
                        'name' => 'one.example',
                        'account' => ['id' => 'acct-1', 'name' => 'Acme'],
                        'permissions' => ['#dns_records:edit'],
                    ],
                ];
            }
        };
        $indexer = $this->makeIndexer(static fn (): CloudflareApiClient => $client);
        $controller = new AdminTokensController($this->tokens, $indexer);

        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'add',
            'name' => 'reseller-A',
            'token' => 'cf_pat_abc',
        ]);

        self::assertSame([], $vm['errors']);
        self::assertStringContainsString('reseller-A', $vm['message']);
        self::assertStringContainsString('1 zone', $vm['message']);
        self::assertCount(1, $vm['tokens']);
        self::assertSame('reseller-A', $vm['tokens'][0]->name);
        self::assertSame(AdminToken::STATUS_OK, $vm['tokens'][0]->status);
        self::assertSame(1, $vm['tokens'][0]->zonesIndexed);
    }

    public function testHandlePostAddDeferredVerificationOnRefreshFailureStillKeepsToken(): void
    {
        // IndexZones swallows verifyTokenStatus / listZones throws (keeps
        // the slice on transient errors). To exercise the controller's
        // "deferred" branch we need refreshOne itself to throw, which
        // happens when makeClient() is reached — it's outside IndexZones'
        // try/catch — and the factory throws.
        $throwingIndexer = $this->makeIndexer(
            static function (): CloudflareApiClient {
                throw new RuntimeException('factory exploded');
            },
        );
        $controller = new AdminTokensController($this->tokens, $throwingIndexer);

        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'add',
            'name' => 'reseller-A',
            'token' => 'cf_pat_abc',
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertStringContainsString('Verification failed', $vm['errors'][0]);
        self::assertStringContainsString('reseller-A', $vm['message']);
        self::assertStringContainsString('verification deferred', $vm['message']);
        // The token was still added before the indexer call.
        self::assertCount(1, $this->tokens->all());
    }

    public function testHandlePostAddWithEmptyNameReturnsValidationError(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'add',
            'name' => '   ',
            'token' => 'cf_pat_abc',
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertSame('Both name and token are required.', $vm['errors'][0]);
        self::assertSame([], $this->tokens->all());
    }

    public function testHandlePostAddWithEmptyTokenReturnsValidationError(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'add',
            'name' => 'reseller-A',
            'token' => '',
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertSame('Both name and token are required.', $vm['errors'][0]);
        self::assertSame([], $this->tokens->all());
    }

    public function testHandlePostAddWithMissingFieldsReturnsValidationError(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'add',
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertSame('Both name and token are required.', $vm['errors'][0]);
    }

    public function testHandlePostAddMentionsMultiAccountCoverageWhenManyAccounts(): void
    {
        $client = new class ('x') extends CloudflareApiClient {
            public function verifyTokenStatus(): string
            {
                return 'active';
            }

            /**
             * @return list<array<string, mixed>>
             */
            public function listZones(): array
            {
                return [
                    [
                        'id' => 'cf-zone-1',
                        'name' => 'one.example',
                        'account' => ['id' => 'acct-1', 'name' => 'Acme'],
                        'permissions' => [],
                    ],
                    [
                        'id' => 'cf-zone-2',
                        'name' => 'two.example',
                        'account' => ['id' => 'acct-2', 'name' => 'Brand B'],
                        'permissions' => [],
                    ],
                ];
            }
        };
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer(static fn (): CloudflareApiClient => $client),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'add',
            'name' => 'multi',
            'token' => 'cf_pat_xyz',
        ]);

        self::assertSame([], $vm['errors']);
        self::assertStringContainsString('2 zones', $vm['message']);
        self::assertStringContainsString('across 2 Cloudflare accounts', $vm['message']);
        self::assertStringContainsString('connect another token', $vm['message']);
    }

    // -----------------------------------------------------------------
    // POST verify
    // -----------------------------------------------------------------

    public function testHandlePostVerifyOnUnknownTokenReturnsNotFound(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'verify',
            'id' => 'does-not-exist',
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertSame('Token not found.', $vm['errors'][0]);
    }

    public function testHandlePostVerifyReportsZoneCountOnSuccess(): void
    {
        $token = $this->tokens->add('reseller-A', 'cf_pat_abc');

        $client = new class ('x') extends CloudflareApiClient {
            public function verifyTokenStatus(): string
            {
                return 'active';
            }

            /**
             * @return list<array<string, mixed>>
             */
            public function listZones(): array
            {
                return [
                    [
                        'id' => 'cf-zone-1',
                        'name' => 'only.example',
                        'account' => ['id' => 'acct-1', 'name' => 'Acme'],
                        'permissions' => ['#dns_records:edit'],
                    ],
                ];
            }
        };
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer(static fn (): CloudflareApiClient => $client),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'verify',
            'id' => $token->id,
        ]);

        self::assertSame([], $vm['errors']);
        self::assertStringContainsString('re-verified', $vm['message']);
        self::assertStringContainsString('1 zone', $vm['message']);
        self::assertStringContainsString('reseller-A', $vm['message']);
    }

    public function testHandlePostVerifyReportsErrorWhenIndexerThrows(): void
    {
        $token = $this->tokens->add('reseller-A', 'cf_pat_abc');

        $throwingIndexer = $this->makeIndexer(
            static function (): CloudflareApiClient {
                throw new RuntimeException('boom: indexer failed');
            },
        );
        $controller = new AdminTokensController($this->tokens, $throwingIndexer);
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'verify',
            'id' => $token->id,
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertStringContainsString('Re-verify failed', $vm['errors'][0]);
        self::assertSame('', $vm['message']);
    }

    public function testHandlePostVerifyWithEmptyIdReturnsNotFound(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'verify',
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertSame('Token not found.', $vm['errors'][0]);
    }

    // -----------------------------------------------------------------
    // POST remove
    // -----------------------------------------------------------------

    public function testHandlePostRemoveDeletesTokenAndClearsIndex(): void
    {
        $token = $this->tokens->add('reseller-A', 'cf_pat_abc');
        $this->index->replaceForToken($token->id, [
            [
                'cf_zone_id' => 'z1',
                'name' => 'one.example',
                'cf_account_id' => 'acct-1',
                'cf_account_name' => 'Acme',
                'permissions' => [],
            ],
        ]);
        self::assertSame(1, $this->index->countForToken($token->id));

        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'remove',
            'id' => $token->id,
        ]);

        self::assertSame([], $vm['errors']);
        self::assertStringContainsString('reseller-A', $vm['message']);
        self::assertStringContainsString('removed', $vm['message']);
        self::assertSame([], $this->tokens->all());
        self::assertSame(0, $this->index->countForToken($token->id));
    }

    public function testHandlePostRemoveOnUnknownTokenReturnsNotFound(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'remove',
            'id' => 'ghost',
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertSame('Token not found.', $vm['errors'][0]);
    }

    // -----------------------------------------------------------------
    // POST unknown action
    // -----------------------------------------------------------------

    public function testHandlePostUnknownActionReturnsError(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'flibbertigibbet',
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertSame('Unknown action.', $vm['errors'][0]);
    }

    public function testHandlePostMissingActionReturnsUnknownAction(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
        ]);

        self::assertCount(1, $vm['errors']);
        self::assertSame('Unknown action.', $vm['errors'][0]);
    }

    // -----------------------------------------------------------------
    // Always-on invariants
    // -----------------------------------------------------------------

    public function testHandleAlwaysIncludesFreshCsrfTokenInViewModel(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );

        $vm = $controller->handle('GET', []);

        self::assertNotSame('', $vm['csrf']);
        // Csrf::token() returns 64 hex characters when freshly generated.
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $vm['csrf']));
    }

    public function testHandleSuccessfulPostRotatesCsrfTokenInViewModel(): void
    {
        $controller = new AdminTokensController(
            $this->tokens,
            $this->makeIndexer($this->failingFactory()),
        );
        $_SESSION[self::CSRF_SESSION_KEY] = self::KNOWN_CSRF;

        $vm = $controller->handle('POST', [
            'csrf' => self::KNOWN_CSRF,
            'action' => 'flibbertigibbet',
        ]);

        // The verified token must be rotated; the view-model carries a new one.
        self::assertNotSame(self::KNOWN_CSRF, $vm['csrf']);
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $vm['csrf']));
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function makeIndexer(Closure $factory): IndexZones
    {
        return new IndexZones($this->tokens, $this->index, $this->logger, $factory);
    }

    private function failingFactory(): Closure
    {
        return static function (string $plaintext): CloudflareApiClient {
            throw new RuntimeException('clientFactory should not be called in this test');
        };
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
