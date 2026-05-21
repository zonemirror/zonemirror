<?php

declare(strict_types=1);

namespace ZoneMirror\Domain;

/**
 * A pending DNS change captured from a cPanel hook, awaiting replay to
 * Cloudflare. Immutable. Identified by a deterministic idempotency key so
 * duplicate hooks (e.g. retries) collapse into a single Cloudflare call.
 */
final class DnsEvent
{
    public function __construct(
        public readonly string $domain,
        public readonly EventAction $action,
        public readonly DnsRecord $record,
        public readonly string $idempotencyKey,
        public readonly int $createdAt,
    ) {
    }
}
