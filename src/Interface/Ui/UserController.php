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
use ZoneMirror\Infrastructure\Storage\ZoneIndex;

/**
 * Glue between the cPanel UI template and the storage/service layer. Owns
 * input validation, CSRF, and the user-scoped allowlist gate. The template
 * itself stays a thin view: it calls handle() and consumes the returned
 * view-model.
 *
 * The new (v0.2) entry point is the per-domain list: the template passes
 * every domain that belongs to the calling cPanel user, and the view-model
 * carries, for each one, whether it is already connected, available for
 * 1-click connect (admin token covers it), or not in any indexed zone.
 *
 * @phpstan-type DomainStatus array{
 *     name: string,
 *     status: string,
 *     zone_id: string,
 *     admin_token_id: string,
 *     is_current: bool
 * }
 *
 * @phpstan-type ViewModel array{
 *     user: string,
 *     allowed: bool,
 *     saved: bool,
 *     errors: list<string>,
 *     message: string,
 *     enabled: bool,
 *     zone_id: string,
 *     zone_name: string,
 *     source: string,
 *     defaults_proxied: bool,
 *     token_set: bool,
 *     csrf: string,
 *     queue_depth: int,
 *     dead_letters: int,
 *     test_result: ?string,
 *     domains: list<DomainStatus>
 * }
 */
final class UserController
{
    public const DOMAIN_NOT_CONNECTED = 'not-connected';
    public const DOMAIN_AVAILABLE = 'available';
    public const DOMAIN_CONNECTED_ADMIN = 'connected-admin';
    public const DOMAIN_CONNECTED_USER = 'connected-user';
    public const DOMAIN_NOT_IN_ZONE = 'not-in-zone';

    private ?UserConfigStorage $storage;
    private readonly SystemConfigStorage $systemStorage;
    private readonly EnrolledUsers $enrolled;
    private ?FileLogger $log;
    private ?ZoneIndex $zoneIndex;

    public function __construct(
        ?UserConfigStorage $storage = null,
        ?FileLogger $log = null,
        ?ZoneIndex $zoneIndex = null,
    ) {
        $this->storage = $storage;
        $this->log = $log;
        $this->zoneIndex = $zoneIndex;
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

    private function zoneIndex(): ZoneIndex
    {
        return $this->zoneIndex ??= new ZoneIndex(Paths::zoneIndexFile());
    }

    /**
     * @param array<string, mixed> $post
     * @param list<string>         $allDomains  The cPanel user's domains
     *                                          (main + addon + parked + sub).
     *                                          Caller supplies these from
     *                                          UAPI DomainInfo::list_domains
     *                                          so this class stays free of
     *                                          LiveAPI coupling.
     * @return ViewModel
     */
    public function handle(string $user, string $method, array $post, array $allDomains = []): array
    {
        $saved = false;
        $errors = [];
        $message = '';
        $testResult = null;

        $allowed = $this->systemStorage->isUserAllowed($user);

        if ($method === 'POST' && $allowed) {
            if (!Csrf::verify(isset($post['csrf']) ? (string) $post['csrf'] : null)) {
                $errors[] = 'Invalid CSRF token. Please reload the page and try again.';
            } else {
                $action = (string) ($post['action'] ?? 'save');
                if ($action === 'connect_domain') {
                    [$saved, $errors, $message] = $this->connectDomain($user, $post, $allDomains);
                } elseif ($action === 'disconnect') {
                    [$saved, $errors, $message] = $this->disconnect($user);
                } elseif ($action === 'test') {
                    $testResult = $this->testConnection(
                        (string) ($post['token'] ?? ''),
                        (string) ($post['zone_name'] ?? '')
                    );
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
            'message' => $message,
            'enabled' => $cfg['enabled'],
            'zone_id' => $cfg['zone_id'],
            'zone_name' => $cfg['zone_name'],
            'source' => $cfg['source'],
            'defaults_proxied' => $cfg['defaults']['proxied'],
            'token_set' => $cfg['token'] !== '',
            'csrf' => Csrf::token(),
            'queue_depth' => $depth,
            'dead_letters' => $dead,
            'test_result' => $testResult,
            'domains' => $this->buildDomainsStatus($allDomains, $cfg),
        ];
    }

    /**
     * @param list<string>                                                                                     $allDomains
     * @param array{enabled: bool, zone_id: string, zone_name: string, source: string, token: string, ...}     $cfg
     * @return list<DomainStatus>
     */
    private function buildDomainsStatus(array $allDomains, array $cfg): array
    {
        $out = [];
        $currentZone = strtolower($cfg['zone_name']);
        $currentSource = $cfg['source'];
        $currentEnabled = $cfg['enabled'];
        $index = $this->zoneIndex();

        // Dedupe + lowercase. cPanel sometimes returns trailing dots or
        // mixed case in sub_domains; normalise once here.
        $seen = [];
        $clean = [];
        foreach ($allDomains as $d) {
            $name = strtolower(trim((string) $d, " \t\n\r\0\x0B."));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $clean[] = $name;
        }

        foreach ($clean as $domain) {
            $isCurrent = $currentEnabled && $domain === $currentZone;
            $isCurrentAdmin = $isCurrent && $currentSource === UserConfigStorage::SOURCE_ADMIN;
            $isCurrentUser = $isCurrent && $currentSource === UserConfigStorage::SOURCE_USER;

            $hit = $index->findByDomain($domain);

            if ($isCurrentAdmin) {
                $status = self::DOMAIN_CONNECTED_ADMIN;
            } elseif ($isCurrentUser) {
                $status = self::DOMAIN_CONNECTED_USER;
            } elseif ($hit !== null) {
                $status = self::DOMAIN_AVAILABLE;
            } else {
                $status = self::DOMAIN_NOT_IN_ZONE;
            }

            $out[] = [
                'name' => $domain,
                'status' => $status,
                'zone_id' => $hit['cf_zone_id'] ?? '',
                'admin_token_id' => $hit['admin_token_id'] ?? '',
                'is_current' => $isCurrent,
            ];
        }

        return $out;
    }

    /**
     * The mainstream 1-click path: the user picks one of their cPanel
     * domains, we look it up in the zone index, and persist a
     * source=admin connection with the matching zone id. No token paste,
     * no DNS knowledge required from the user.
     *
     * @param array<string, mixed> $post
     * @param list<string>         $allDomains
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function connectDomain(string $user, array $post, array $allDomains): array
    {
        $domain = strtolower(trim((string) ($post['domain'] ?? ''), " \t\n\r\0\x0B."));
        if ($domain === '') {
            return [false, ['No domain provided.'], ''];
        }

        // The domain MUST belong to this cPanel user — otherwise a
        // crafted POST could connect any zone covered by an admin token
        // under any user's identity.
        $ownedLowercase = array_map(
            static fn (string $d): string => strtolower(trim($d, " \t\n\r\0\x0B.")),
            $allDomains
        );
        if (!in_array($domain, $ownedLowercase, true)) {
            return [false, ['That domain does not belong to this cPanel account.'], ''];
        }

        $hit = $this->zoneIndex()->findByDomain($domain);
        if ($hit === null) {
            return [false, ['That domain is not covered by any Cloudflare account on this server.'], ''];
        }

        $storage = $this->storageFor($user);
        $existing = $storage->load($user);
        // initial_seed_state=pending tells the daemon to backfill from
        // /var/named/<zone>.db on its next cycle, so Cloudflare ends up
        // mirroring the records that already exist locally — not just the
        // future deltas the hooks would catch.
        $storage->save($user, [
            'enabled' => true,
            'zone_id' => $hit['cf_zone_id'],
            'zone_name' => $domain,
            'defaults' => $existing['defaults'],
            'source' => UserConfigStorage::SOURCE_ADMIN,
            'initial_seed_state' => UserConfigStorage::SEED_PENDING,
        ]);

        $this->enrolled->enroll($user);
        $this->logFor($user)->info('domain connected via admin token', [
            'user' => $user,
            'domain' => $domain,
            'zone_id' => $hit['cf_zone_id'],
            'admin_token_id' => $hit['admin_token_id'],
        ]);

        return [true, [], sprintf('%s is now syncing to Cloudflare. Existing DNS records will be propagated in the background.', $domain)];
    }

    /**
     * @return array{0: bool, 1: list<string>, 2: string}
     */
    private function disconnect(string $user): array
    {
        $storage = $this->storageFor($user);
        $existing = $storage->load($user);
        $storage->save($user, [
            'enabled' => false,
            'zone_id' => $existing['zone_id'],
            'zone_name' => $existing['zone_name'],
            'defaults' => $existing['defaults'],
            'source' => $existing['source'],
            // Keep the user's own token on file if they had one, so they
            // can re-enable without re-pasting. For source=admin there
            // is nothing token-ish to keep.
            'token' => $existing['source'] === UserConfigStorage::SOURCE_USER ? $existing['token'] : '',
        ]);

        $this->enrolled->remove($user);
        $this->logFor($user)->info('disconnected', ['user' => $user]);

        return [true, [], 'Disconnected. No more changes will be pushed to Cloudflare.'];
    }

    /**
     * Legacy "case 2" path: the user pastes their own Cloudflare token.
     * Kept for advanced users whose domains are not covered by any admin
     * token; the cPanel UI hides this behind an "Advanced" disclosure.
     *
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
            'source' => UserConfigStorage::SOURCE_USER,
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
