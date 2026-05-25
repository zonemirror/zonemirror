<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Ui;

use ZoneMirror\Application\IndexZones;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Logging\LogLevel;
use ZoneMirror\Infrastructure\Storage\AdminTokenStorage;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\ZoneIndex;

/**
 * WHM admin view-model for the Cloudflare API tokens section.
 *
 * Manages the encrypted list of tokens that the daemon uses to talk to
 * Cloudflare on behalf of enrolled cPanel users (case 1 in the v0.2
 * redesign). Storage lives at /var/cpanel/zonemirror/admin-tokens.json
 * (0600 root); we refuse to run if the controller is not invoked as
 * root, both as a defence-in-depth check and because the AEAD key file
 * is unreadable to non-root anyway.
 *
 * @phpstan-type AdminTokensViewModel array{
 *     allowed: bool,
 *     tokens: list<AdminToken>,
 *     errors: list<string>,
 *     message: string,
 *     csrf: string
 * }
 */
final class AdminTokensController
{
    private ?AdminTokenStorage $storage;
    private ?IndexZones $indexer;

    public function __construct(?AdminTokenStorage $storage = null, ?IndexZones $indexer = null)
    {
        $this->storage = $storage;
        $this->indexer = $indexer;
    }

    /**
     * @param array<string, mixed> $post
     * @return AdminTokensViewModel
     */
    public function handle(string $method, array $post): array
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return [
                'allowed' => false,
                'tokens' => [],
                'errors' => ['Admin tokens can only be managed as root.'],
                'message' => '',
                'csrf' => Csrf::token(),
            ];
        }

        $storage = $this->resolveStorage();

        $errors = [];
        $message = '';

        if ($method === 'POST') {
            if (!Csrf::verify(isset($post['csrf']) ? (string) $post['csrf'] : null)) {
                $errors[] = 'Invalid CSRF token. Please reload the page and try again.';
            } else {
                $action = (string) ($post['action'] ?? '');
                try {
                    [$message, $maybeError] = $this->dispatch($storage, $action, $post);
                    if ($maybeError !== null) {
                        $errors[] = $maybeError;
                    }
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        return [
            'allowed' => true,
            'tokens' => $storage->all(),
            'errors' => $errors,
            'message' => $message,
            'csrf' => Csrf::token(),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: string, 1: ?string}
     */
    private function dispatch(AdminTokenStorage $storage, string $action, array $post): array
    {
        if ($action === 'add') {
            $name = trim((string) ($post['name'] ?? ''));
            $plain = (string) ($post['token'] ?? '');
            if ($name === '' || $plain === '') {
                return ['', 'Both name and token are required.'];
            }
            $tok = $storage->add($name, $plain);

            // Refresh runs verify + listZones + writes the ZoneIndex slice
            // for this token. Failures are logged and isolated by the
            // indexer itself, so we never leave the storage in a broken
            // state if CF is slow or down — the operator can re-verify.
            try {
                $this->resolveIndexer()->refreshOne($tok->id);
            } catch (\Throwable $e) {
                $refreshed = $storage->find($tok->id);

                return [
                    sprintf('Token "%s" added (verification deferred).', $tok->name),
                    'Verification failed: ' . $e->getMessage(),
                ];
            }
            $refreshed = $storage->find($tok->id);
            $zones = $refreshed?->zonesIndexed ?? 0;
            $status = $refreshed?->status ?? '';

            return [
                sprintf(
                    'Token "%s" added. Verification: %s (%d zone%s reachable).',
                    $tok->name,
                    $status,
                    $zones,
                    $zones === 1 ? '' : 's',
                ),
                null,
            ];
        }

        if ($action === 'verify') {
            $id = (string) ($post['id'] ?? '');
            $existing = $storage->find($id);
            if ($existing === null) {
                return ['', 'Token not found.'];
            }
            try {
                $this->resolveIndexer()->refreshOne($id);
            } catch (\Throwable $e) {
                return ['', 'Re-verify failed: ' . $e->getMessage()];
            }
            $after = $storage->find($id);

            return [
                sprintf(
                    'Token "%s" re-verified: %s (%d zone%s).',
                    $existing->name,
                    $after?->status ?? '',
                    $after?->zonesIndexed ?? 0,
                    ($after?->zonesIndexed ?? 0) === 1 ? '' : 's',
                ),
                null,
            ];
        }

        if ($action === 'remove') {
            $id = (string) ($post['id'] ?? '');
            $existing = $storage->find($id);
            if ($existing === null) {
                return ['', 'Token not found.'];
            }
            $storage->remove($id);
            // Drop the token's zones from the index right away so cPanel
            // users stop seeing this token as a possible source for their
            // domains. Index lives in the daemon's territory but we have
            // root here too.
            try {
                $this->resolveZoneIndex()->removeForToken($id);
            } catch (\Throwable) {
                // Index will self-heal on the next sweep.
            }

            return [sprintf('Token "%s" removed.', $existing->name), null];
        }

        return ['', 'Unknown action.'];
    }

    private function resolveStorage(): AdminTokenStorage
    {
        if ($this->storage !== null) {
            return $this->storage;
        }
        $this->storage = new AdminTokenStorage(
            new ConfigCrypto(new KeyStore(Paths::adminKeyFile()))
        );

        return $this->storage;
    }

    private function resolveIndexer(): IndexZones
    {
        if ($this->indexer !== null) {
            return $this->indexer;
        }
        $this->indexer = new IndexZones(
            $this->resolveStorage(),
            $this->resolveZoneIndex(),
            new FileLogger(Paths::logFile(), LogLevel::Info),
        );

        return $this->indexer;
    }

    private ?ZoneIndex $zoneIndex = null;

    private function resolveZoneIndex(): ZoneIndex
    {
        if ($this->zoneIndex === null) {
            $this->zoneIndex = new ZoneIndex(Paths::zoneIndexFile());
        }

        return $this->zoneIndex;
    }
}
