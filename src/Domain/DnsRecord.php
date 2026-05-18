<?php

declare(strict_types=1);

namespace CfSync\Domain;

/**
 * Cloudflare-shaped DNS record payload. Immutable.
 *
 * The shape is intentionally close to the Cloudflare DNS records API so that
 * the mapping layer (CpanelToCloudflareMapper) is the single point of
 * translation from cPanel/WHM payloads.
 *
 * @phpstan-type RecordData array<string, scalar|null>
 */
final readonly class DnsRecord
{
    /**
     * @param array<string, mixed> $data Cloudflare structured data (SRV, CAA).
     */
    public function __construct(
        public RecordType $type,
        public string $name,
        public ?string $content,
        public int $ttl,
        public ?int $priority,
        public ?bool $proxied,
        public array $data,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toCloudflarePayload(): array
    {
        $payload = [
            'type' => $this->type->value,
            'name' => $this->name,
            'ttl' => $this->ttl,
        ];

        if ($this->content !== null) {
            $payload['content'] = $this->content;
        }
        if ($this->priority !== null) {
            $payload['priority'] = $this->priority;
        }
        if ($this->proxied !== null && $this->type->supportsProxy()) {
            $payload['proxied'] = $this->proxied;
        }
        if ($this->data !== []) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }
}
