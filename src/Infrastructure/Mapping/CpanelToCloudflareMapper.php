<?php

declare(strict_types=1);

namespace CfSync\Infrastructure\Mapping;

use CfSync\Domain\DnsRecord;
use CfSync\Domain\RecordType;

/**
 * Translates a raw cPanel/WHM ZoneEdit hook payload into a canonical
 * DnsRecord. The cPanel payload is loosely typed and varies by record type;
 * this is the only place in the codebase allowed to know about that shape.
 *
 * Trailing dots on FQDNs are stripped (Cloudflare rejects them). TXT values
 * have their cPanel-added surrounding quotes removed so equality with what
 * the user typed in the UI holds. _acme-challenge and _dmarc records are
 * never proxied even when defaults.proxied is true (would break ACME and
 * DMARC reporting respectively).
 */
final class CpanelToCloudflareMapper
{
    /**
     * @param array<string, mixed> $raw   cPanel hook payload's record block.
     * @param array{proxied: bool, ttl?: int} $defaults
     */
    public function map(array $raw, array $defaults): ?DnsRecord
    {
        $type = RecordType::tryFromString(isset($raw['type']) ? (string) $raw['type'] : null);
        if ($type === null) {
            return null;
        }

        $name = rtrim((string) ($raw['name'] ?? $raw['dname'] ?? ''), '.');
        if ($name === '') {
            return null;
        }

        $ttl = max(60, (int) ($raw['ttl'] ?? $defaults['ttl'] ?? 300));

        return match ($type) {
            RecordType::A, RecordType::AAAA => new DnsRecord(
                type: $type,
                name: $name,
                content: $this->str($raw, ['address', 'content']),
                ttl: $ttl,
                priority: null,
                proxied: $this->resolveProxied($name, $defaults),
                data: [],
            ),
            RecordType::CNAME => new DnsRecord(
                type: $type,
                name: $name,
                content: rtrim($this->str($raw, ['cname', 'content']) ?? '', '.'),
                ttl: $ttl,
                priority: null,
                proxied: $this->resolveProxied($name, $defaults),
                data: [],
            ),
            RecordType::MX => new DnsRecord(
                type: $type,
                name: $name,
                content: rtrim($this->str($raw, ['exchange', 'content']) ?? '', '.'),
                ttl: $ttl,
                priority: (int) ($raw['preference'] ?? $raw['priority'] ?? 10),
                proxied: null,
                data: [],
            ),
            RecordType::TXT => new DnsRecord(
                type: $type,
                name: $name,
                content: $this->stripQuotes($this->str($raw, ['txtdata', 'content']) ?? ''),
                ttl: $ttl,
                priority: null,
                proxied: null,
                data: [],
            ),
            RecordType::SRV => $this->mapSrv($raw, $name, $ttl),
            RecordType::CAA => new DnsRecord(
                type: $type,
                name: $name,
                content: null,
                ttl: $ttl,
                priority: null,
                proxied: null,
                data: [
                    'flags' => (int) ($raw['flag'] ?? $raw['flags'] ?? 0),
                    'tag' => (string) ($raw['tag'] ?? 'issue'),
                    'value' => (string) ($raw['value'] ?? ''),
                ],
            ),
            RecordType::NS => null,
        };
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapSrv(array $raw, string $name, int $ttl): DnsRecord
    {
        $parts = explode('.', $name);
        $service = isset($parts[0]) && str_starts_with($parts[0], '_') ? $parts[0] : '_service';
        $proto = isset($parts[1]) && str_starts_with($parts[1], '_') ? $parts[1] : '_tcp';
        $domain = count($parts) >= 3 ? implode('.', array_slice($parts, 2)) : $name;

        return new DnsRecord(
            type: RecordType::SRV,
            name: $name,
            content: null,
            ttl: $ttl,
            priority: null,
            proxied: null,
            data: [
                'service' => $service,
                'proto' => $proto,
                'name' => $domain,
                'priority' => (int) ($raw['priority'] ?? 0),
                'weight' => (int) ($raw['weight'] ?? 0),
                'port' => (int) ($raw['port'] ?? 0),
                'target' => rtrim((string) ($raw['target'] ?? ''), '.'),
            ],
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string> $keys
     */
    private function str(array $raw, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($raw[$k]) && $raw[$k] !== '') {
                return (string) $raw[$k];
            }
        }

        return null;
    }

    private function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * @param array{proxied: bool} $defaults
     */
    private function resolveProxied(string $name, array $defaults): bool
    {
        $lower = strtolower($name);
        if (str_starts_with($lower, '_acme-challenge') || str_starts_with($lower, '_dmarc')) {
            return false;
        }

        return (bool) ($defaults['proxied'] ?? false);
    }
}
