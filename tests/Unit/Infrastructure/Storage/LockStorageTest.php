<?php

declare(strict_types=1);

namespace ZoneMirror\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use ZoneMirror\Infrastructure\Storage\LockStorage;
use ZoneMirror\Infrastructure\Storage\Paths;

final class LockStorageTest extends TestCase
{
    private string $tmpDir;
    private string $userHome;
    private LockStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zm-locks-' . bin2hex(random_bytes(4));
        $this->userHome = $this->tmpDir . '/home';
        mkdir($this->userHome, 0700, true);
        putenv(Paths::ENV_USER_HOME . '=' . $this->userHome);

        $this->storage = new LockStorage();
    }

    protected function tearDown(): void
    {
        putenv(Paths::ENV_USER_HOME . '=');
        $this->rmrf($this->tmpDir);
    }

    public function testAllReturnsEmptyArrayWhenNoFileExists(): void
    {
        self::assertSame([], $this->storage->all('alice', 'zone-1'));
    }

    public function testIsLockedByIdReturnsFalseWhenLockMissing(): void
    {
        self::assertFalse($this->storage->isLockedById('alice', 'zone-1', 'zone:'));
    }

    public function testAddZoneScopeStoresAndReturnsId(): void
    {
        $id = $this->storage->add('alice', 'zone-1', LockStorage::SCOPE_ZONE);
        self::assertSame('zone:', $id);

        $all = $this->storage->all('alice', 'zone-1');
        self::assertArrayHasKey('zone:', $all);
        self::assertSame(LockStorage::SCOPE_ZONE, $all['zone:']['scope']);
        self::assertTrue($this->storage->isLockedById('alice', 'zone-1', 'zone:'));
    }

    public function testAddSubtreeNormalisesNameAndPersistsId(): void
    {
        $id = $this->storage->add(
            'alice',
            'zone-1',
            LockStorage::SCOPE_SUBTREE,
            '',
            'Foo.Example.COM.',
        );
        self::assertSame('subtree:foo.example.com', $id);

        $all = $this->storage->all('alice', 'zone-1');
        self::assertArrayHasKey('subtree:foo.example.com', $all);
        self::assertSame('foo.example.com', $all['subtree:foo.example.com']['name']);
    }

    public function testAddNameScopeStoresLowercasedName(): void
    {
        $id = $this->storage->add(
            'alice',
            'zone-1',
            LockStorage::SCOPE_NAME,
            '',
            'Example.COM',
        );
        self::assertSame('name:example.com', $id);
    }

    public function testAddTypeNameScopeUppercasesTypeAndLowercasesName(): void
    {
        $id = $this->storage->add(
            'alice',
            'zone-1',
            LockStorage::SCOPE_TYPE_NAME,
            'txt',
            '_DMARC.Example.com',
        );
        self::assertSame('type_name:TXT:_dmarc.example.com', $id);
        $row = $this->storage->all('alice', 'zone-1')[$id];
        self::assertSame('TXT', $row['type']);
        self::assertSame('_dmarc.example.com', $row['name']);
    }

    public function testAddExactScopeStoresContentAndPriority(): void
    {
        $id = $this->storage->add(
            'alice',
            'zone-1',
            LockStorage::SCOPE_EXACT,
            'MX',
            'example.com',
            'aspmx.l.google.com',
            10,
            'Google MX',
        );
        self::assertSame('exact:MX:example.com:10|aspmx.l.google.com', $id);
        $row = $this->storage->all('alice', 'zone-1')[$id];
        self::assertSame('MX', $row['type']);
        self::assertSame('example.com', $row['name']);
        self::assertSame('aspmx.l.google.com', $row['content']);
        self::assertSame(10, $row['priority']);
        self::assertSame('Google MX', $row['reason']);
    }

    public function testAddRejectsUnknownScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->add('alice', 'zone-1', 'nope');
    }

    public function testAddSubtreeRequiresName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->add('alice', 'zone-1', LockStorage::SCOPE_SUBTREE);
    }

    public function testAddNameRequiresName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->add('alice', 'zone-1', LockStorage::SCOPE_NAME);
    }

    public function testAddTypeNameRequiresTypeAndName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->add('alice', 'zone-1', LockStorage::SCOPE_TYPE_NAME, 'TXT', '');
    }

    public function testAddExactRequiresContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->add(
            'alice',
            'zone-1',
            LockStorage::SCOPE_EXACT,
            'A',
            'example.com',
            null,
        );
    }

    public function testAddExactRejectsEmptyContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->add(
            'alice',
            'zone-1',
            LockStorage::SCOPE_EXACT,
            'A',
            'example.com',
            '',
        );
    }

    public function testAddIsIdempotentForSameId(): void
    {
        $this->storage->add('alice', 'zone-1', LockStorage::SCOPE_NAME, '', 'example.com');
        $this->storage->add('alice', 'zone-1', LockStorage::SCOPE_NAME, '', 'EXAMPLE.com');
        self::assertCount(1, $this->storage->all('alice', 'zone-1'));
    }

    public function testRemoveReturnsTrueWhenLockExisted(): void
    {
        $id = $this->storage->add('alice', 'zone-1', LockStorage::SCOPE_NAME, '', 'example.com');
        self::assertTrue($this->storage->remove('alice', 'zone-1', $id));
        self::assertFalse($this->storage->isLockedById('alice', 'zone-1', $id));
    }

    public function testRemoveReturnsFalseWhenLockMissing(): void
    {
        self::assertFalse($this->storage->remove('alice', 'zone-1', 'name:nope.example.com'));
    }

    public function testLocksAreScopedPerZone(): void
    {
        $this->storage->add('alice', 'zone-a', LockStorage::SCOPE_NAME, '', 'a.example');
        self::assertCount(1, $this->storage->all('alice', 'zone-a'));
        self::assertCount(0, $this->storage->all('alice', 'zone-b'));
    }

    public function testLocksAreScopedPerUser(): void
    {
        $this->storage->add('alice', 'zone-1', LockStorage::SCOPE_NAME, '', 'a.example');
        // Different user shares the same ENV-overridden home, so we need
        // a separate home to keep them isolated.
        $bobHome = $this->tmpDir . '/home-bob';
        mkdir($bobHome, 0700, true);
        putenv(Paths::ENV_USER_HOME . '=' . $bobHome);
        self::assertCount(0, $this->storage->all('bob', 'zone-1'));
        // Restore alice's home so tearDown's rmrf doesn't drift.
        putenv(Paths::ENV_USER_HOME . '=' . $this->userHome);
    }

    public function testLockIdForZone(): void
    {
        self::assertSame('zone:', LockStorage::lockIdFor(LockStorage::SCOPE_ZONE));
    }

    public function testLockIdForSubtreeNormalises(): void
    {
        self::assertSame(
            'subtree:foo.example.com',
            LockStorage::lockIdFor(LockStorage::SCOPE_SUBTREE, '', 'Foo.Example.COM.'),
        );
    }

    public function testLockIdForName(): void
    {
        self::assertSame(
            'name:example.com',
            LockStorage::lockIdFor(LockStorage::SCOPE_NAME, '', 'Example.com'),
        );
    }

    public function testLockIdForTypeName(): void
    {
        self::assertSame(
            'type_name:TXT:_dmarc.example.com',
            LockStorage::lockIdFor(LockStorage::SCOPE_TYPE_NAME, 'txt', '_DMARC.Example.com'),
        );
    }

    public function testLockIdForExactWithoutPriority(): void
    {
        self::assertSame(
            'exact:A:example.com:|1.2.3.4',
            LockStorage::lockIdFor(LockStorage::SCOPE_EXACT, 'A', 'example.com', '1.2.3.4'),
        );
    }

    public function testLockIdForExactWithPriority(): void
    {
        self::assertSame(
            'exact:MX:example.com:10|aspmx.l.google.com',
            LockStorage::lockIdFor(
                LockStorage::SCOPE_EXACT,
                'MX',
                'example.com',
                'aspmx.l.google.com',
                10,
            ),
        );
    }

    public function testLockIdForUnknownScopeReturnsEmptyString(): void
    {
        self::assertSame('', LockStorage::lockIdFor('mystery'));
    }

    public function testLockIdForEntryDefaultsToTypeNameScope(): void
    {
        $entry = [
            'type' => 'A',
            'name' => 'example.com',
            'local' => ['content' => '1.2.3.4'],
        ];
        self::assertSame(
            'type_name:A:example.com',
            LockStorage::lockIdForEntry($entry),
        );
    }

    public function testLockIdForEntryUsesLocalSourceForExact(): void
    {
        $entry = [
            'type' => 'MX',
            'name' => 'example.com',
            'local' => ['content' => 'aspmx.l.google.com', 'priority' => 10],
            'remote' => ['content' => 'other.example.com', 'priority' => 20],
        ];
        self::assertSame(
            'exact:MX:example.com:10|aspmx.l.google.com',
            LockStorage::lockIdForEntry($entry, LockStorage::SCOPE_EXACT),
        );
    }

    public function testLockIdForEntryFallsBackToRemoteWhenNoLocal(): void
    {
        $entry = [
            'type' => 'A',
            'name' => 'example.com',
            'remote' => ['content' => '9.9.9.9'],
        ];
        self::assertSame(
            'exact:A:example.com:|9.9.9.9',
            LockStorage::lockIdForEntry($entry, LockStorage::SCOPE_EXACT),
        );
    }

    public function testEntryMatchesZoneScopeAlwaysMatches(): void
    {
        $lock = $this->lockRow(['scope' => LockStorage::SCOPE_ZONE]);
        self::assertTrue(LockStorage::entryMatches($lock, ['type' => 'A', 'name' => 'whatever.example']));
    }

    public function testEntryMatchesSubtreeMatchesSelfAndChildren(): void
    {
        $lock = $this->lockRow([
            'scope' => LockStorage::SCOPE_SUBTREE,
            'name' => 'foo.example.com',
        ]);
        self::assertTrue(LockStorage::entryMatches($lock, ['type' => 'A', 'name' => 'foo.example.com']));
        self::assertTrue(LockStorage::entryMatches($lock, ['type' => 'A', 'name' => 'bar.foo.example.com']));
        self::assertFalse(LockStorage::entryMatches($lock, ['type' => 'A', 'name' => 'example.com']));
        self::assertFalse(LockStorage::entryMatches($lock, ['type' => 'A', 'name' => 'notfoo.example.com']));
    }

    public function testEntryMatchesSubtreeWithEmptyNameDoesNotMatch(): void
    {
        $lock = $this->lockRow(['scope' => LockStorage::SCOPE_SUBTREE, 'name' => '']);
        self::assertFalse(LockStorage::entryMatches($lock, ['type' => 'A', 'name' => 'foo.example.com']));
    }

    public function testEntryMatchesNameRequiresExactName(): void
    {
        $lock = $this->lockRow(['scope' => LockStorage::SCOPE_NAME, 'name' => 'example.com']);
        self::assertTrue(LockStorage::entryMatches($lock, ['type' => 'TXT', 'name' => 'EXAMPLE.com.']));
        self::assertFalse(LockStorage::entryMatches($lock, ['type' => 'TXT', 'name' => 'sub.example.com']));
    }

    public function testEntryMatchesTypeNameRequiresBoth(): void
    {
        $lock = $this->lockRow([
            'scope' => LockStorage::SCOPE_TYPE_NAME,
            'type' => 'TXT',
            'name' => '_dmarc.example.com',
        ]);
        self::assertTrue(LockStorage::entryMatches($lock, ['type' => 'txt', 'name' => '_DMARC.example.com.']));
        self::assertFalse(LockStorage::entryMatches($lock, ['type' => 'A', 'name' => '_dmarc.example.com']));
        self::assertFalse(LockStorage::entryMatches($lock, ['type' => 'TXT', 'name' => 'other.example.com']));
    }

    public function testEntryMatchesExactComparesAgainstLocalAndRemote(): void
    {
        $lock = $this->lockRow([
            'scope' => LockStorage::SCOPE_EXACT,
            'type' => 'A',
            'name' => 'example.com',
            'content' => '1.2.3.4',
        ]);
        $entryLocal = [
            'type' => 'A',
            'name' => 'example.com',
            'local' => ['content' => '1.2.3.4'],
            'remote' => ['content' => '9.9.9.9'],
        ];
        $entryRemote = [
            'type' => 'A',
            'name' => 'example.com',
            'local' => ['content' => '9.9.9.9'],
            'remote' => ['content' => '1.2.3.4'],
        ];
        $entryNeither = [
            'type' => 'A',
            'name' => 'example.com',
            'local' => ['content' => '5.5.5.5'],
            'remote' => ['content' => '6.6.6.6'],
        ];
        self::assertTrue(LockStorage::entryMatches($lock, $entryLocal));
        self::assertTrue(LockStorage::entryMatches($lock, $entryRemote));
        self::assertFalse(LockStorage::entryMatches($lock, $entryNeither));
    }

    public function testEntryMatchesExactRejectsMismatchedTypeOrName(): void
    {
        $lock = $this->lockRow([
            'scope' => LockStorage::SCOPE_EXACT,
            'type' => 'A',
            'name' => 'example.com',
            'content' => '1.2.3.4',
        ]);
        $entry = [
            'type' => 'AAAA',
            'name' => 'example.com',
            'local' => ['content' => '1.2.3.4'],
        ];
        self::assertFalse(LockStorage::entryMatches($lock, $entry));
    }

    public function testEntryMatchesExactWithPriorityRequiresMatchingPriority(): void
    {
        $lock = $this->lockRow([
            'scope' => LockStorage::SCOPE_EXACT,
            'type' => 'MX',
            'name' => 'example.com',
            'content' => 'aspmx.l.google.com',
            'priority' => 10,
        ]);
        $matching = [
            'type' => 'MX',
            'name' => 'example.com',
            'local' => ['content' => 'aspmx.l.google.com', 'priority' => 10],
        ];
        $wrongPriority = [
            'type' => 'MX',
            'name' => 'example.com',
            'local' => ['content' => 'aspmx.l.google.com', 'priority' => 20],
        ];
        self::assertTrue(LockStorage::entryMatches($lock, $matching));
        self::assertFalse(LockStorage::entryMatches($lock, $wrongPriority));
    }

    public function testEntryMatchesUnknownScopeReturnsFalse(): void
    {
        $lock = $this->lockRow(['scope' => 'mystery']);
        self::assertFalse(LockStorage::entryMatches($lock, ['type' => 'A', 'name' => 'example.com']));
    }

    public function testEntryMatchesAnyShortCircuitsOnFirstMatch(): void
    {
        $locks = [
            'name:other.example' => $this->lockRow([
                'scope' => LockStorage::SCOPE_NAME,
                'name' => 'other.example',
            ]),
            'name:example.com' => $this->lockRow([
                'scope' => LockStorage::SCOPE_NAME,
                'name' => 'example.com',
            ]),
        ];
        self::assertTrue(LockStorage::entryMatchesAny($locks, ['type' => 'A', 'name' => 'example.com']));
    }

    public function testEntryMatchesAnyReturnsFalseWhenEmpty(): void
    {
        self::assertFalse(LockStorage::entryMatchesAny([], ['type' => 'A', 'name' => 'example.com']));
    }

    public function testEntryMatchesAnyReturnsFalseWhenNothingMatches(): void
    {
        $locks = [
            'name:other.example' => $this->lockRow([
                'scope' => LockStorage::SCOPE_NAME,
                'name' => 'other.example',
            ]),
        ];
        self::assertFalse(LockStorage::entryMatchesAny($locks, ['type' => 'A', 'name' => 'example.com']));
    }

    public function testLoadIgnoresCorruptedJson(): void
    {
        $this->seedZoneFile('alice', 'zone-1', '{this is not valid json');
        self::assertSame([], $this->storage->all('alice', 'zone-1'));
    }

    public function testLoadIgnoresEmptyFile(): void
    {
        $this->seedZoneFile('alice', 'zone-1', '');
        self::assertSame([], $this->storage->all('alice', 'zone-1'));
    }

    public function testLoadSkipsRowsWithUnknownScope(): void
    {
        $this->seedZoneFile('alice', 'zone-1', (string) json_encode([
            'version' => 2,
            'locks' => [
                'name:example.com' => [
                    'scope' => LockStorage::SCOPE_NAME,
                    'type' => '',
                    'name' => 'example.com',
                ],
                'broken:foo' => [
                    'scope' => 'mystery',
                    'type' => '',
                    'name' => 'foo',
                ],
            ],
        ]));
        $all = $this->storage->all('alice', 'zone-1');
        self::assertCount(1, $all);
        self::assertArrayHasKey('name:example.com', $all);
    }

    public function testLoadMigratesV1TypeNameLocksAndRekeys(): void
    {
        // v1 stored locks under "TYPE:NAME" with no scope field.
        $this->seedZoneFile('alice', 'zone-1', (string) json_encode([
            'version' => 1,
            'locks' => [
                'TXT:_dmarc.example.com' => [
                    'type' => 'TXT',
                    'name' => '_dmarc.example.com',
                    'reason' => 'legacy',
                    'created_at' => 123,
                ],
            ],
        ]));
        $all = $this->storage->all('alice', 'zone-1');
        self::assertArrayHasKey('type_name:TXT:_dmarc.example.com', $all);
        $row = $all['type_name:TXT:_dmarc.example.com'];
        self::assertSame(LockStorage::SCOPE_TYPE_NAME, $row['scope']);
        self::assertSame('TXT', $row['type']);
        self::assertSame('_dmarc.example.com', $row['name']);
        self::assertSame('legacy', $row['reason']);
        self::assertSame(123, $row['created_at']);
    }

    public function testLoadFallsBackToLegacySingleFilePath(): void
    {
        // Pre-v2 single file path: ~/.zonemirror/locks.json (no zones/<zid>/).
        $legacyDir = $this->userHome . '/.zonemirror';
        mkdir($legacyDir, 0700, true);
        file_put_contents($legacyDir . '/locks.json', (string) json_encode([
            'version' => 2,
            'locks' => [
                'name:example.com' => [
                    'scope' => LockStorage::SCOPE_NAME,
                    'type' => '',
                    'name' => 'example.com',
                    'content' => null,
                    'priority' => null,
                    'reason' => 'legacy single file',
                    'created_at' => 100,
                ],
            ],
        ]));
        $all = $this->storage->all('alice', 'zone-1');
        self::assertArrayHasKey('name:example.com', $all);
        self::assertSame('legacy single file', $all['name:example.com']['reason']);
    }

    public function testAddPersistsAcrossInstances(): void
    {
        $this->storage->add('alice', 'zone-1', LockStorage::SCOPE_NAME, '', 'example.com');
        $other = new LockStorage();
        self::assertTrue($other->isLockedById('alice', 'zone-1', 'name:example.com'));
    }

    public function testScopesConstantListsAllScopes(): void
    {
        self::assertSame(
            [
                LockStorage::SCOPE_ZONE,
                LockStorage::SCOPE_SUBTREE,
                LockStorage::SCOPE_NAME,
                LockStorage::SCOPE_TYPE_NAME,
                LockStorage::SCOPE_EXACT,
            ],
            LockStorage::SCOPES,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{scope: string, type: string, name: string, content: ?string, priority: ?int, reason: string, created_at: int}
     */
    private function lockRow(array $overrides): array
    {
        $base = [
            'scope' => LockStorage::SCOPE_NAME,
            'type' => '',
            'name' => '',
            'content' => null,
            'priority' => null,
            'reason' => '',
            'created_at' => 0,
        ];
        /** @var array{scope: string, type: string, name: string, content: ?string, priority: ?int, reason: string, created_at: int} $merged */
        $merged = array_merge($base, $overrides);

        return $merged;
    }

    private function seedZoneFile(string $user, string $zoneId, string $contents): void
    {
        $dir = $this->userHome . '/.zonemirror/zones/' . $zoneId;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/locks.json', $contents);
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
