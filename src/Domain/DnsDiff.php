<?php

declare(strict_types=1);

namespace ZoneMirror\Domain;

/**
 * Result of comparing the cPanel-local zone state with the Cloudflare
 * zone state for a single domain. Designed to be serialised to a JSON
 * file the cPanel user-side UI can read without touching Cloudflare.
 *
 * Each entry pairs a logical record identity (type+name+optional
 * discriminator) with the cPanel-local view and/or the Cloudflare view
 * and a status:
 *  - `identical`: both sides match, nothing to do.
 *  - `different`: same identity, different content/ttl/proxied/priority.
 *    The default action is "push local to Cloudflare" (replace remote).
 *  - `cpanel_only`: cPanel has it, Cloudflare does not. Default action
 *    is "create on Cloudflare".
 *  - `cloudflare_only`: Cloudflare has it, cPanel does not. Default
 *    action is none — the user opts in to delete from the UI.
 */
final class DnsDiff
{
    public const STATUS_IDENTICAL = 'identical';
    public const STATUS_DIFFERENT = 'different';
    public const STATUS_CPANEL_ONLY = 'cpanel_only';
    public const STATUS_CLOUDFLARE_ONLY = 'cloudflare_only';

    /**
     * @param list<DnsDiffEntry> $entries
     * @param list<array{level: string, code: string, message: string}> $advisories
     *        Zone-level notices for the UI banner (e.g. "this zone is served
     *        by Cloudflare and your local copy is behind").
     */
    public function __construct(
        public readonly string $zoneName,
        public readonly string $zoneId,
        public readonly int $computedAt,
        public readonly array $entries,
        public readonly array $advisories = [],
    ) {
    }

    /**
     * @return array<string, int> Counts keyed by status.
     */
    public function summary(): array
    {
        $out = [
            self::STATUS_IDENTICAL => 0,
            self::STATUS_DIFFERENT => 0,
            self::STATUS_CPANEL_ONLY => 0,
            self::STATUS_CLOUDFLARE_ONLY => 0,
        ];
        foreach ($this->entries as $e) {
            $out[$e->status] = ($out[$e->status] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'zone_name' => $this->zoneName,
            'zone_id' => $this->zoneId,
            'computed_at' => $this->computedAt,
            'summary' => $this->summary(),
            'advisories' => $this->advisories,
            'entries' => array_map(static fn (DnsDiffEntry $e): array => $e->toArray(), $this->entries),
        ];
    }
}
