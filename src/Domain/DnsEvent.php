<?php

declare(strict_types=1);

namespace ZoneMirror\Domain;

/**
 * A pending DNS change captured from a cPanel hook or queued by the
 * cPanel UI's diff-apply step, awaiting replay to Cloudflare. Immutable.
 * Identified by a deterministic idempotency key so duplicate enqueues
 * (e.g. retries, or a Refresh+Apply hammered twice) collapse into a
 * single Cloudflare call.
 *
 * `targetCloudflareId` is optional metadata for diff-originated events
 * where the UI knows the exact Cloudflare record id to act on. It lets
 * the daemon bypass the snapshot-based (type, name) lookup, which would
 * otherwise be ambiguous when several rows share an owner name — most
 * commonly the cloudflare_only-row Delete path. Hook-originated events
 * leave this null and rely on the snapshot.
 */
final class DnsEvent
{
    public function __construct(
        public readonly string $domain,
        public readonly EventAction $action,
        public readonly DnsRecord $record,
        public readonly string $idempotencyKey,
        public readonly int $createdAt,
        public readonly ?string $targetCloudflareId = null,
        /**
         * Cloudflare zone id this event targets. Defaults to '' so v1
         * call sites that haven't been updated yet still compile; the
         * queue's enqueue path tolerates an empty value (rows get
         * back-filled from the user's only zone when the daemon
         * processes them — see WorkerLoop).
         */
        public readonly string $zoneId = '',
    ) {
    }
}
