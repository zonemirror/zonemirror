<?php

declare(strict_types=1);

namespace CfSync\Interface\Ui;

use CfSync\Infrastructure\Cloudflare\CloudflareApiClient;
use CfSync\Infrastructure\Logging\FileLogger;
use CfSync\Infrastructure\Logging\LogLevel;
use CfSync\Infrastructure\Storage\ConfigCrypto;
use CfSync\Infrastructure\Storage\EnrolledUsers;
use CfSync\Infrastructure\Storage\KeyStore;
use CfSync\Infrastructure\Storage\Paths;
use CfSync\Infrastructure\Storage\SystemConfigStorage;
use CfSync\Infrastructure\Storage\UserConfigStorage;

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
    private readonly UserConfigStorage $storage;
    private readonly SystemConfigStorage $systemStorage;
    private readonly EnrolledUsers $enrolled;
    private readonly FileLogger $log;

    public function __construct(?UserConfigStorage $storage = null)
    {
        $crypto = new ConfigCrypto(new KeyStore(Paths::systemKeyFile()));
        $this->storage = $storage ?? new UserConfigStorage($crypto);
        $this->systemStorage = new SystemConfigStorage();
        $this->enrolled = new EnrolledUsers();
        $this->log = new FileLogger(Paths::logFile(), LogLevel::Info);
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

        $cfg = $this->storage->load($user);
        $depth = 0;
        $dead = 0;
        if ($cfg['enabled']) {
            try {
                $queue = new \CfSync\Infrastructure\Queue\SqliteQueue($user);
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

        $current = $this->storage->load($user);
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

        $this->storage->save($user, [
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

        $this->log->info('user config saved', [
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
