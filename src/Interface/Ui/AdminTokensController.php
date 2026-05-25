<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Ui;

use RuntimeException;
use ZoneMirror\Domain\AdminToken;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Storage\AdminTokenStorage;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\Paths;

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

    public function __construct(?AdminTokenStorage $storage = null)
    {
        $this->storage = $storage;
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

            // Best-effort verify. If the network or CF rejects, the token
            // stays in storage with status=unverified — the operator can
            // re-verify later from the UI without re-pasting it.
            $verifyError = null;
            try {
                [$status, $zones] = $this->probe($plain);
                $storage->updateVerification($tok->id, $status, $zones);
                $msg = sprintf(
                    'Token "%s" added. Verification: %s (%d zone%s reachable).',
                    $tok->name,
                    $status,
                    $zones,
                    $zones === 1 ? '' : 's',
                );
            } catch (\Throwable $e) {
                $verifyError = 'Token saved but verification failed: ' . $e->getMessage();
                $msg = sprintf('Token "%s" added (verification pending).', $tok->name);
            }

            return [$msg, $verifyError];
        }

        if ($action === 'verify') {
            $id = (string) ($post['id'] ?? '');
            $existing = $storage->find($id);
            if ($existing === null) {
                return ['', 'Token not found.'];
            }
            $plain = $storage->plaintextFor($id);
            if ($plain === null || $plain === '') {
                return ['', 'Token ciphertext could not be decrypted.'];
            }
            [$status, $zones] = $this->probe($plain);
            $storage->updateVerification($id, $status, $zones);

            return [
                sprintf(
                    'Token "%s" re-verified: %s (%d zone%s).',
                    $existing->name,
                    $status,
                    $zones,
                    $zones === 1 ? '' : 's',
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

            return [sprintf('Token "%s" removed.', $existing->name), null];
        }

        return ['', 'Unknown action.'];
    }

    /**
     * Hit Cloudflare's /user/tokens/verify and, if the token is active,
     * /zones to count what it can see. Returns (AdminToken::STATUS_*, zoneCount).
     *
     * @return array{0: string, 1: int}
     */
    private function probe(string $plaintext): array
    {
        if ($plaintext === '') {
            throw new RuntimeException('Token is empty.');
        }

        $client = new CloudflareApiClient($plaintext);
        $raw = $client->verifyTokenStatus();

        $status = match ($raw) {
            'active' => AdminToken::STATUS_OK,
            'expired' => AdminToken::STATUS_EXPIRED,
            'disabled' => AdminToken::STATUS_UNAUTHORIZED,
            '' => AdminToken::STATUS_UNAUTHORIZED,
            default => AdminToken::STATUS_PARTIAL_SCOPE,
        };

        $zones = 0;
        if ($status === AdminToken::STATUS_OK) {
            // Best-effort: a working token with zero visible zones still
            // counts as ok — the operator may add scopes later. We do not
            // demote the status here.
            $zones = count($client->listZones());
        }

        return [$status, $zones];
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
}
