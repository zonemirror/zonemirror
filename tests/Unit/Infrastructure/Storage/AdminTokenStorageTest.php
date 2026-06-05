<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZoneMirror\Domain\AdminToken;
use ZoneMirror\Infrastructure\Storage\AdminTokenStorage;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\Paths;

final class AdminTokenStorageTest extends TestCase
{
    private string $tmpDir;
    private string $systemDir;
    private AdminTokenStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-ats-' . bin2hex(random_bytes(4));
        $this->systemDir = $this->tmpDir . '/system';
        mkdir($this->systemDir, 0700, true);
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $this->systemDir);

        $keyFile = $this->systemDir . '/master.key';
        $this->storage = new AdminTokenStorage(new ConfigCrypto(new KeyStore($keyFile)));
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_SYSTEM_DIR . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testAllReturnsEmptyListWhenFileMissing(): void
    {
        self::assertSame([], $this->storage->all());
    }

    public function testFindReturnsNullWhenFileMissing(): void
    {
        self::assertNull($this->storage->find('does-not-exist'));
    }

    public function testPlaintextForReturnsNullWhenFileMissing(): void
    {
        self::assertNull($this->storage->plaintextFor('does-not-exist'));
    }

    public function testAddPersistsTokenAndReturnsAdminToken(): void
    {
        $token = $this->storage->add('prod-admin', 'cf_pat_secret_value');

        self::assertSame('prod-admin', $token->name);
        self::assertNotSame('', $token->id);
        self::assertSame(AdminToken::STATUS_UNVERIFIED, $token->status);
        self::assertSame(0, $token->zonesIndexed);
        self::assertSame(0, $token->lastVerifiedAt);
        self::assertGreaterThan(0, $token->createdAt);

        $all = $this->storage->all();
        self::assertCount(1, $all);
        self::assertSame($token->id, $all[0]->id);
        self::assertSame('prod-admin', $all[0]->name);
    }

    public function testAddWritesFileWithRestrictivePermissions(): void
    {
        $this->storage->add('prod-admin', 'cf_pat_secret_value');
        $path = $this->systemDir . '/admin-tokens.json';
        self::assertFileExists($path);
        $perms = fileperms($path) & 0777;
        self::assertSame(0600, $perms);
    }

    public function testAddDoesNotStorePlaintextOnDisk(): void
    {
        $this->storage->add('prod-admin', 'cf_pat_super_secret_abc123');
        $raw = (string) file_get_contents($this->systemDir . '/admin-tokens.json');
        self::assertStringNotContainsString('cf_pat_super_secret_abc123', $raw);
        self::assertStringContainsString('ciphertext', $raw);
    }

    public function testAddTrimsWhitespaceFromName(): void
    {
        $token = $this->storage->add("  spaced-name  \n", 'cf_pat_secret_value');
        self::assertSame('spaced-name', $token->name);
    }

    public function testAddThrowsWhenNameIsEmpty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin token name must not be empty.');
        $this->storage->add('', 'cf_pat_secret_value');
    }

    public function testAddThrowsWhenNameIsOnlyWhitespace(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin token name must not be empty.');
        $this->storage->add("   \t\n", 'cf_pat_secret_value');
    }

    public function testAddThrowsWhenPlaintextIsEmpty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin token plaintext must not be empty.');
        $this->storage->add('prod-admin', '');
    }

    public function testAddAppendsMultipleTokensWithUniqueIds(): void
    {
        $a = $this->storage->add('first', 'plaintext-a');
        $b = $this->storage->add('second', 'plaintext-b');
        $c = $this->storage->add('third', 'plaintext-c');

        self::assertNotSame($a->id, $b->id);
        self::assertNotSame($a->id, $c->id);
        self::assertNotSame($b->id, $c->id);

        $all = $this->storage->all();
        self::assertCount(3, $all);
        self::assertSame(['first', 'second', 'third'], array_map(static fn (AdminToken $t): string => $t->name, $all));
    }

    public function testFindReturnsMatchingToken(): void
    {
        $a = $this->storage->add('first', 'plaintext-a');
        $b = $this->storage->add('second', 'plaintext-b');

        $found = $this->storage->find($b->id);
        self::assertNotNull($found);
        self::assertSame($b->id, $found->id);
        self::assertSame('second', $found->name);

        $foundA = $this->storage->find($a->id);
        self::assertNotNull($foundA);
        self::assertSame('first', $foundA->name);
    }

    public function testFindReturnsNullWhenIdMissing(): void
    {
        $this->storage->add('first', 'plaintext-a');
        self::assertNull($this->storage->find('nonexistent-id'));
    }

    public function testPlaintextForReturnsDecryptedPlaintext(): void
    {
        $token = $this->storage->add('prod-admin', 'cf_pat_decryption_target');
        self::assertSame('cf_pat_decryption_target', $this->storage->plaintextFor($token->id));
    }

    public function testPlaintextForReturnsNullWhenIdMissing(): void
    {
        $this->storage->add('prod-admin', 'cf_pat_secret_value');
        self::assertNull($this->storage->plaintextFor('nonexistent-id'));
    }

    public function testPlaintextForReturnsNullWhenCiphertextIsEmpty(): void
    {
        // Hand-craft a token row with an empty ciphertext to ensure
        // the early-return branch is exercised without throwing.
        $payload = [
            'tokens' => [[
                'id' => 'token-zero',
                'name' => 'no-ciphertext',
                'created_at' => 1700000000,
                'last_verified_at' => 0,
                'status' => AdminToken::STATUS_UNVERIFIED,
                'zones_indexed' => 0,
                'ciphertext' => '',
            ]],
        ];
        file_put_contents($this->systemDir . '/admin-tokens.json', (string) json_encode($payload));

        self::assertNull($this->storage->plaintextFor('token-zero'));
    }

    public function testRemoveDropsMatchingToken(): void
    {
        $a = $this->storage->add('first', 'plaintext-a');
        $b = $this->storage->add('second', 'plaintext-b');

        $this->storage->remove($a->id);

        $all = $this->storage->all();
        self::assertCount(1, $all);
        self::assertSame($b->id, $all[0]->id);
        self::assertNull($this->storage->find($a->id));
    }

    public function testRemoveIsNoopWhenIdMissing(): void
    {
        $a = $this->storage->add('first', 'plaintext-a');
        $this->storage->remove('nonexistent-id');
        self::assertCount(1, $this->storage->all());
        self::assertNotNull($this->storage->find($a->id));
    }

    public function testRemoveOnEmptyStoreCreatesFileWithEmptyList(): void
    {
        $this->storage->remove('whatever');
        self::assertSame([], $this->storage->all());
    }

    public function testUpdateVerificationUpdatesStatusZonesIndexedAndTimestamp(): void
    {
        $token = $this->storage->add('prod-admin', 'cf_pat_secret_value');

        $before = time();
        $this->storage->updateVerification($token->id, AdminToken::STATUS_OK, 7);
        $after = time();

        $found = $this->storage->find($token->id);
        self::assertNotNull($found);
        self::assertSame(AdminToken::STATUS_OK, $found->status);
        self::assertSame(7, $found->zonesIndexed);
        self::assertGreaterThanOrEqual($before, $found->lastVerifiedAt);
        self::assertLessThanOrEqual($after, $found->lastVerifiedAt);
        // Plaintext must still round-trip after update (ciphertext preserved).
        self::assertSame('cf_pat_secret_value', $this->storage->plaintextFor($token->id));
    }

    public function testUpdateVerificationNormalizesUnknownStatusToUnverified(): void
    {
        $token = $this->storage->add('prod-admin', 'cf_pat_secret_value');
        $this->storage->updateVerification($token->id, 'bogus-status', 3);

        $found = $this->storage->find($token->id);
        self::assertNotNull($found);
        self::assertSame(AdminToken::STATUS_UNVERIFIED, $found->status);
        self::assertSame(3, $found->zonesIndexed);
    }

    public function testUpdateVerificationClampsNegativeZonesIndexedToZero(): void
    {
        $token = $this->storage->add('prod-admin', 'cf_pat_secret_value');
        $this->storage->updateVerification($token->id, AdminToken::STATUS_OK, -42);

        $found = $this->storage->find($token->id);
        self::assertNotNull($found);
        self::assertSame(0, $found->zonesIndexed);
    }

    public function testUpdateVerificationIsNoopWhenIdMissing(): void
    {
        // No tokens yet — calling updateVerification must not write a file
        // (no `changed` flag) and must not throw.
        $this->storage->updateVerification('nonexistent', AdminToken::STATUS_OK, 1);
        self::assertFileDoesNotExist($this->systemDir . '/admin-tokens.json');
    }

    public function testUpdateVerificationLeavesOtherTokensUntouched(): void
    {
        $a = $this->storage->add('first', 'plaintext-a');
        $b = $this->storage->add('second', 'plaintext-b');

        $this->storage->updateVerification($b->id, AdminToken::STATUS_UNAUTHORIZED, 5);

        $foundA = $this->storage->find($a->id);
        $foundB = $this->storage->find($b->id);
        self::assertNotNull($foundA);
        self::assertNotNull($foundB);
        self::assertSame(AdminToken::STATUS_UNVERIFIED, $foundA->status);
        self::assertSame(0, $foundA->zonesIndexed);
        self::assertSame(AdminToken::STATUS_UNAUTHORIZED, $foundB->status);
        self::assertSame(5, $foundB->zonesIndexed);
        self::assertSame('plaintext-a', $this->storage->plaintextFor($a->id));
        self::assertSame('plaintext-b', $this->storage->plaintextFor($b->id));
    }

    public function testAllSkipsNonArrayRowsInRawFile(): void
    {
        // Tokens shape with one invalid entry — the loader must skip it
        // rather than crashing.
        $valid = [
            'id' => 'real-id',
            'name' => 'real',
            'created_at' => 1700000000,
            'last_verified_at' => 0,
            'status' => AdminToken::STATUS_OK,
            'zones_indexed' => 2,
            'ciphertext' => '',
        ];
        $payload = ['tokens' => ['not-an-array', $valid, 123]];
        file_put_contents($this->systemDir . '/admin-tokens.json', (string) json_encode($payload));

        $all = $this->storage->all();
        self::assertCount(1, $all);
        self::assertSame('real-id', $all[0]->id);
    }

    public function testAllReturnsEmptyListWhenFileHasMalformedJson(): void
    {
        file_put_contents($this->systemDir . '/admin-tokens.json', '{not valid json');
        self::assertSame([], $this->storage->all());
    }

    public function testAllReturnsEmptyListWhenFileMissesTokensKey(): void
    {
        file_put_contents($this->systemDir . '/admin-tokens.json', '{"other":"shape"}');
        self::assertSame([], $this->storage->all());
    }

    public function testAddCreatesSystemDirIfMissing(): void
    {
        // Point ENV at a system dir that does not exist yet. The store
        // must create it on first write.
        $newSysDir = $this->tmpDir . '/freshly-created';
        $keyFile = $this->systemDir . '/master.key';
        putenv(Paths::ENV_SYSTEM_DIR . '=' . $newSysDir);
        $storage = new AdminTokenStorage(new ConfigCrypto(new KeyStore($keyFile)));

        $token = $storage->add('prod-admin', 'cf_pat_secret_value');

        self::assertDirectoryExists($newSysDir);
        self::assertFileExists($newSysDir . '/admin-tokens.json');
        self::assertNotSame('', $token->id);
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
