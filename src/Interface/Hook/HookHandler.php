<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Hook;

use ZoneMirror\Domain\DnsEvent;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Logging\LogLevel;
use ZoneMirror\Infrastructure\Mapping\CpanelToCloudflareMapper;
use ZoneMirror\Infrastructure\Queue\SqliteQueue;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;
use ZoneMirror\Infrastructure\Storage\UserConfigMetadataReader;

/**
 * Entry point for the four cPanel ZoneEdit hook scripts. Hooks run as the
 * cPanel user (not root), so this code must NOT touch the root-owned master
 * encryption key. It reads only the unencrypted half of the user's config
 * (UserConfigMetadataReader) and enqueues an event for the daemon to apply.
 *
 * Hook responsibilities are intentionally narrow:
 *  1. Bail out fast if the user is not enrolled, not enabled, or not allowed.
 *  2. Translate the payload into a DnsRecord.
 *  3. Enqueue an event with a stable idempotency key.
 *  4. Never crash cPanel: any unexpected failure is swallowed and logged.
 */
final class HookHandler
{
    public function __construct(
        private readonly EventAction $action,
        private readonly string $eventName,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, string $user): void
    {
        $log = new FileLogger(Paths::userLogFile($user), LogLevel::Info);

        try {
            $meta = UserConfigMetadataReader::read($user);
            // Two paths to Cloudflare: the user pasted their own token
            // (source=user, has_token=true) OR an admin token covers this
            // domain (source=admin, no per-user token). Either is enough
            // to enqueue; the daemon resolves the credential at sync time.
            $hasCredentialPath = $meta['has_token']
                || $meta['source'] === \ZoneMirror\Infrastructure\Storage\UserConfigStorage::SOURCE_ADMIN;
            if (!$meta['enabled'] || $meta['zone_id'] === '' || !$hasCredentialPath) {
                return;
            }

            $systemStorage = new SystemConfigStorage();
            if (!$systemStorage->isUserAllowed($user)) {
                $log->info('hook skipped: user not allowed', ['event' => $this->eventName, 'user' => $user]);

                return;
            }
            $systemDefaults = $systemStorage->load();

            $extracted = HookPayloadParser::extract($payload);
            if ($extracted === null) {
                return;
            }

            $defaults = [
                'proxied' => $meta['defaults']['proxied'] || $systemDefaults['defaults']['proxied'],
                'ttl' => $systemDefaults['defaults']['ttl'],
                'auto_ttl' => (bool) ($systemDefaults['defaults']['auto_ttl'] ?? true),
            ];

            $record = (new CpanelToCloudflareMapper())->map($extracted['raw'], $defaults);
            if ($record === null) {
                return;
            }

            $event = new DnsEvent(
                domain: $extracted['domain'],
                action: $this->action,
                record: $record,
                idempotencyKey: HookPayloadParser::idempotencyKey(
                    $this->action->value,
                    $extracted['domain'],
                    $extracted['raw'],
                ),
                createdAt: time(),
            );

            (new SqliteQueue($user))->enqueue($event);
            $log->info('event enqueued', [
                'event' => $this->eventName,
                'user' => $user,
                'action' => $this->action->value,
                'type' => $record->type->value,
                'name' => $record->name,
            ]);
        } catch (\Throwable $e) {
            $log->error('hook failed', [
                'event' => $this->eventName,
                'user' => $user,
                'error' => $e->getMessage(),
            ]);
            // Never propagate: cPanel hooks must be best-effort.
        }
    }
}
