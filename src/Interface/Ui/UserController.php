<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Ui;

use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Logging\LogLevel;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\EnrolledUsers;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;
use ZoneMirror\Infrastructure\Storage\UserConfigStorage;

/**
 * Glue between the cPanel UI template and the storage/service layer. Owns
 * input validation, CSRF, and the user-scoped allowlist gate. The template
 * itself stays a thin view: it calls render() and consumes the returned
 * view-model.
 *
 * @phpstan-type ViewModel array{
 *     user: string,
 *     allowed: bool,
 *     saved: bool,
 *     errors: list<string>,
 *     enabled: bool,
 *     zone_id: string,
 *     zone_name: string,
 *     defaults_proxied: bool,
 *     token_set: bool,
 *     csrf: string,
 *     queue_depth: int,
 *     dead_letters: int,
 *     test_result: ?string
 * }
 */
final class UserController
{
    private ?UserConfigStorage $storage;
    private readonly SystemConfigStorage $systemStorage;
    private readonly EnrolledUsers $enrolled;
    private ?FileLogger $log;

    public function __construct(?UserConfigStorage $storage = null, ?FileLogger $log = null)
    {
        // Storage and log are bound to a specific user (the AEAD key and the
        // log path both live under <user-home>/.zonemirror/). They are
        // materialized lazily in handle()/save() once the user is known.
        // Tests can inject pre-built collaborators here.
        $this->storage = $storage;
        $this->log = $log;
        $this->systemStorage = new SystemConfigStorage();
        $this->enrolled = new EnrolledUsers();
    }

    private function storageFor(string $user): UserConfigStorage
    {
        return $this->storage ?? new UserConfigStorage(
            new ConfigCrypto(new KeyStore(Paths::userKeyFile($user)))
        );
    }

    private function logFor(string $user): FileLogger
    {
        return $this->log ?? new FileLogger(Paths::userLogFile($user), LogLevel::Info);
    }

    /**
     * @param array<string, mixed> $post
     * @return ViewModel
     */
    public function handle(string $user, string $method, array $post): array
    {
        $saved = false;
        $errors = [];
        $testResult = null;

        $allowed = $this->systemStorage->isUserAllowed($user);

        if ($method === 'POST' && $allowed) {
            if (!Csrf::verify(isset($post['csrf']) ? (string) $post['csrf'] : null)) {
                $errors[] = 'Invalid CSRF token. Please reload the page and try again.';
            } else {
                $action = (string) ($post['action'] ?? 'save');
                if ($action === 'test') {
                    $testResult = $this->testConnection((string) ($post['token'] ?? ''), (string) ($post['zone_name'] ?? ''));
                } else {
                    [$saved, $errors] = $this->save($user, $post);
                }
            }
        }

        $cfg = $this->storageFor($user)->load($user);
        $depth = 0;
        $dead = 0;
        if ($cfg['enabled']) {
            try {
                $queue = new \ZoneMirror\Infrastructure\Queue\SqliteQueue($user);
                $depth = $queue->depth();
                $dead = $queue->deadLetterCount();
            } catch (\Throwable) {
                // Queue not yet initialized; show zeros.
            }
        }

        return [
            'user' => $user,
            'allowed' => $allowed,
            'saved' => $saved,
            'errors' => $errors,
            'enabled' => $cfg['enabled'],
            'zone_id' => $cfg['zone_id'],
            'zone_name' => $cfg['zone_name'],
            'defaults_proxied' => $cfg['defaults']['proxied'],
            'token_set' => $cfg['token'] !== '',
            'csrf' => Csrf::token(),
            'queue_depth' => $depth,
            'dead_letters' => $dead,
            'test_result' => $testResult,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>}
     */
    private function save(string $user, array $post): array
    {
        $errors = [];
        $token = trim((string) ($post['token'] ?? ''));
        $zoneName = strtolower(trim((string) ($post['zone_name'] ?? '')));
        $enabled = isset($post['enabled']) && (string) $post['enabled'] !== '';
        $defaultsProxied = isset($post['defaults_proxied']) && (string) $post['defaults_proxied'] !== '';

        if ($zoneName !== '' && preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $zoneName) !== 1) {
            $errors[] = 'Zone name is not a valid domain.';
        }

        $storage = $this->storageFor($user);
        $current = $storage->load($user);
        $effectiveToken = $token !== '' ? $token : $current['token'];
        $zoneId = $current['zone_id'];

        if ($enabled && $zoneName !== '' && $effectiveToken !== '') {
            $client = new CloudflareApiClient($effectiveToken);
            $resolved = $client->findZoneId($zoneName);
            if ($resolved === null) {
                $errors[] = 'Could not resolve that zone from Cloudflare. Check the token scope and zone name.';
            } else {
                $zoneId = $resolved;
            }
        }

        if ($errors !== []) {
            return [false, $errors];
        }

        $storage->save($user, [
            'enabled' => $enabled,
            'zone_id' => $zoneId,
            'zone_name' => $zoneName,
            'defaults' => ['proxied' => $defaultsProxied],
            'token' => $token,
        ]);

        if ($enabled) {
            $this->enrolled->enroll($user);
        } else {
            $this->enrolled->remove($user);
        }

        $this->logFor($user)->info('user config saved', [
            'user' => $user,
            'enabled' => $enabled,
            'zone_name' => $zoneName,
            'token_provided' => $token !== '',
        ]);

        return [true, []];
    }

    private function testConnection(string $token, string $zoneName): string
    {
        if ($token === '') {
            return 'Provide a token to test.';
        }
        $client = new CloudflareApiClient($token);
        if (!$client->verifyToken()) {
            return 'Token is not active or has no permissions.';
        }
        if ($zoneName === '') {
            return 'Token verified. Provide a zone to confirm scope.';
        }
        $zoneId = $client->findZoneId($zoneName);

        return $zoneId === null
            ? 'Token verified, but the zone is not visible to this token.'
            : 'Token verified. Zone visible (id: ' . substr($zoneId, 0, 8) . '...).';
    }
}
