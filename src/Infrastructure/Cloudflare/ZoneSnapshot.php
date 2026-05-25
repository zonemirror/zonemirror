<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Cloudflare;

use ZoneMirror\Domain\DnsRecord;

/**
 * In-memory mirror of a Cloudflare zone's DNS records, refreshed once per
 * worker cycle per user. ProcessEvent looks up existing records here instead
 * of issuing one filtered listRecords API call per queue event — a single
 * 50-event burst goes from 100 Cloudflare requests (50 list + 50 mutate) to
 * 51 (1 list + 50 mutate), keeping us inside Cloudflare's 1,200 req / 5 min
 * per-token rate limit.
 *
 * Mutations (create/update/delete) are applied locally after the corresponding
 * API call succeeds so subsequent events in the same batch see fresh state.
 *
 * @phpstan-type Record array<string, mixed>
 */
final class ZoneSnapshot
{
    /**
     * @param list<Record> $records
     */
    public function __construct(
        private array $records,
        private readonly RecordMatcher $matcher = new RecordMatcher(),
    ) {
    }

    /**
     * @return Record|null
     */
    public function find(DnsRecord $record): ?array
    {
        return $this->matcher->findEquivalent($this->records, $record);
    }

    /**
     * Direct lookup by Cloudflare record id. Used by the diff-apply path
     * where the UI knows exactly which record to act on; the (type, name)
     * lookup of {@see find()} can be ambiguous for multi-row owners.
     *
     * @return Record|null
     */
    public function findById(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        foreach ($this->records as $r) {
            if ((string) ($r['id'] ?? '') === $id) {
                return $r;
            }
        }

        return null;
    }

    /**
     * @param Record $created
     */
    public function applyCreate(array $created): void
    {
        $this->records[] = $created;
    }

    /**
     * @param Record $updated
     */
    public function applyUpdate(string $id, array $updated): void
    {
        foreach ($this->records as $i => $existing) {
            if ((string) ($existing['id'] ?? '') === $id) {
                $this->records[$i] = array_merge($existing, $updated, ['id' => $id]);

                return;
            }
        }
        $this->records[] = array_merge($updated, ['id' => $id]);
    }

    public function applyDelete(string $id): void
    {
        $this->records = array_values(array_filter(
            $this->records,
            static fn (array $r): bool => (string) ($r['id'] ?? '') !== $id,
        ));
    }

    /**
     * @return list<Record>
     */
    public function all(): array
    {
        return $this->records;
    }
}
