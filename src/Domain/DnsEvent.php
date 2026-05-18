<?php

declare(strict_types=1);

namespace CfSync\Domain;

/**
 * A pending DNS change captured from a cPanel hook, awaiting replay to
 * Cloudflare. Immutable. Identified by a deterministic idempotency key so
 * duplicate hooks (e.g. retries) collapse into a single Cloudflare call.
 */
final readonly class DnsEvent
{
    public function __construct(
        public string $domain,
        public EventAction $action,
        public DnsRecord $record,
        public string $idempotencyKey,
        public int $createdAt,
    ) {
    }
}
