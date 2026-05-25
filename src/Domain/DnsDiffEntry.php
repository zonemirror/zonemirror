<?php

declare(strict_types=1);

namespace ZoneMirror\Domain;

/**
 * A single row in a {@see DnsDiff}. Carries enough of both sides for the
 * UI to render a "cPanel: X / Cloudflare: Y" comparison and for the apply
 * step to enqueue the right action without re-reading either source.
 *
 * `key` is a stable identity used by the UI to reference this row in a
 * later POST (so user selections survive a recompute as long as the
 * record still exists). For most rrtypes it is "{type}:{name}"; SRV/MX
 * include the target so multiple records with the same owner name don't
 * collapse into one row.
 *
 * `local` is the BindZoneParser DnsRecord; `remote` is the raw
 * Cloudflare record array (`id`, `content`, `ttl`, `proxied`, `priority`,
 * `data`...). At most one of the two is null:
 *   - cpanel_only:    remote == null
 *   - cloudflare_only: local == null
 *   - identical/different: both present
 */
final class DnsDiffEntry
{
    public function __construct(
        public readonly string $key,
        public readonly string $status,
        public readonly string $type,
        public readonly string $name,
        public readonly ?DnsRecord $local,
        /** @var array<string, mixed>|null */
        public readonly ?array $remote,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status,
            'type' => $this->type,
            'name' => $this->name,
            'local' => $this->local?->toCloudflarePayload(),
            'remote' => $this->remote === null
                ? null
                : [
                    'id' => (string) ($this->remote['id'] ?? ''),
                    'type' => (string) ($this->remote['type'] ?? ''),
                    'name' => (string) ($this->remote['name'] ?? ''),
                    'content' => $this->remote['content'] ?? null,
                    'ttl' => (int) ($this->remote['ttl'] ?? 0),
                    'priority' => $this->remote['priority'] ?? null,
                    'proxied' => $this->remote['proxied'] ?? null,
                    'data' => $this->remote['data'] ?? null,
                ],
        ];
    }
}
