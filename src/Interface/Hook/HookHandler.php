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
            $extracted = HookPayloadParser::extract($payload);
            if ($extracted === null) {
                return;
            }

            // Route the hook to the right zone by matching the affected
            // domain against the user's connected zones. A user with N
            // synced zones might edit DNS for one of them, all of them,
            // or none — the hook ignores edits on non-synced zones.
            $zone = UserConfigMetadataReader::zoneForDomain($user, $extracted['domain']);
            if ($zone === null) {
                return;
            }

            // Two paths to Cloudflare: the user pasted their own token
            // (source=user, plus has_token=true at the user level) OR
            // an admin token covers this zone (source=admin, no per-user
            // token). Either is enough to enqueue; the daemon resolves
            // the credential at sync time.
            $meta = UserConfigMetadataReader::read($user);
            $hasCredentialPath = $meta['has_token']
                || $zone['source'] === \ZoneMirror\Infrastructure\Storage\UserConfigStorage::SOURCE_ADMIN;
            if (!$hasCredentialPath) {
                return;
            }

            $systemStorage = new SystemConfigStorage();
            if (!$systemStorage->isUserAllowed($user)) {
                $log->info('hook skipped: user not allowed', ['event' => $this->eventName, 'user' => $user]);

                return;
            }
            $systemDefaults = $systemStorage->load();

            $defaults = [
                'proxied' => $zone['defaults']['proxied'] || $systemDefaults['defaults']['proxied'],
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
                zoneId: $zone['zone_id'],
            );

            (new SqliteQueue($user))->enqueue($event);
            $log->info('event enqueued', [
                'event' => $this->eventName,
                'user' => $user,
                'zone' => $zone['zone_name'],
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
