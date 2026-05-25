<?php

declare(strict_types=1);

namespace ZoneMirror\Application;

use ZoneMirror\Domain\DnsEvent;
use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Domain\RecordType;
use ZoneMirror\Infrastructure\Cpanel\BindZoneParser;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Queue\SqliteQueue;

/**
 * Backfill the user's queue with one Upsert per syncable record from the
 * cPanel-local zone file. Used the first time a user connects a domain so
 * Cloudflare ends up reflecting the state that was already in cPanel,
 * instead of only catching the deltas that hooks would fire afterwards.
 *
 * Runs as root from the daemon (the zone file under /var/named is
 * named:named 0600 — the cPanel user cannot read it, so we cannot do this
 * at hook time or from the LiveAPI session). The seed is intentionally
 * non-destructive: ProcessEvent::upsert will create-or-update on the
 * Cloudflare side, but never delete anything that lives on CF only.
 * Cleanup of CF-only records is a future, opt-in step (M4 conflict
 * review).
 */
final class InitialSync
{
    /**
     * Some cPanel-managed subdomains expose internal services (webmail,
     * cpanel control panel, whm, autoconfig, …) on the server's public IP.
     * Pushing them to Cloudflare is safe (they're real records that cPanel
     * itself publishes), but we never want them proxied — Cloudflare's
     * proxy only handles HTTP(S) ports, and webmail/cPanel-Login run on
     * 2083/2096 etc. The mapping below also covers _acme-challenge and
     * _dmarc, which BindZoneParser leaves with proxied=false anyway.
     *
     * @var list<string>
     */
    private const NEVER_PROXY_LABEL_PREFIXES = [
        'cpanel.', 'webmail.', 'webdisk.', 'whm.',
        'autoconfig.', 'autodiscover.', 'cpcalendars.', 'cpcontacts.',
        'mail.', 'ftp.',
        '_acme-challenge.', '_dmarc.', '_domainkey.',
    ];

    public function __construct(
        private readonly BindZoneParser $parser = new BindZoneParser(),
    ) {
    }

    /**
     * Read the zone file, build a list of canonical DnsRecords, and enqueue
     * an Upsert event for each one. Returns the number of events enqueued.
     *
     * @throws \RuntimeException if the zone file cannot be read.
     */
    public function seed(
        string $user,
        string $zoneName,
        bool $defaultProxied,
        FileLogger $log,
        ?SqliteQueue $queue = null,
        ?string $zoneFilePath = null,
    ): int {
        $path = $zoneFilePath ?? '/var/named/' . $zoneName . '.db';
        if (!is_readable($path)) {
            throw new \RuntimeException('Zone file not readable: ' . $path);
        }
        $contents = (string) file_get_contents($path);
        if ($contents === '') {
            throw new \RuntimeException('Zone file empty: ' . $path);
        }

        $records = $this->parser->parse($contents, $zoneName);
        $queue ??= new SqliteQueue($user);

        $enqueued = 0;
        $skipped = 0;
        foreach ($records as $record) {
            $normalised = $this->normaliseForCloudflare($record, $defaultProxied);
            if ($normalised === null) {
                $skipped++;

                continue;
            }
            $event = new DnsEvent(
                domain: $zoneName,
                action: EventAction::Upsert,
                record: $normalised,
                idempotencyKey: $this->idempotencyKey($zoneName, $normalised),
                createdAt: time(),
            );
            $queue->enqueue($event);
            $enqueued++;
        }

        $log->info('initial seed enqueued', [
            'user' => $user,
            'zone' => $zoneName,
            'enqueued' => $enqueued,
            'skipped' => $skipped,
            'source_records' => count($records),
        ]);

        return $enqueued;
    }

    /**
     * Decide proxied for A/AAAA/CNAME, drop records we don't want to
     * propagate (e.g. apex CNAME would clash with the SOA on Cloudflare,
     * but cPanel zone files never emit one — defensive check only).
     */
    private function normaliseForCloudflare(DnsRecord $record, bool $defaultProxied): ?DnsRecord
    {
        if (!$record->type->supportsProxy()) {
            return $record;
        }

        $proxied = $defaultProxied && !$this->isNeverProxyName($record->name);

        return new DnsRecord(
            type: $record->type,
            name: $record->name,
            content: $record->content,
            ttl: $record->ttl,
            priority: $record->priority,
            proxied: $proxied,
            data: $record->data,
        );
    }

    private function isNeverProxyName(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::NEVER_PROXY_LABEL_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Idempotency key for a seed-originated event. Distinct from hook keys
     * (which include the hook's event id) so a seed and a future hook for
     * the same record don't accidentally dedupe each other.
     */
    private function idempotencyKey(string $zone, DnsRecord $r): string
    {
        $payload = $r->type === RecordType::SRV || $r->type === RecordType::CAA
            ? json_encode($r->data, JSON_UNESCAPED_SLASHES) ?: ''
            : ($r->content ?? '');

        return 'seed:' . hash(
            'sha256',
            implode("\x1f", [$zone, $r->type->value, $r->name, $payload, (string) ($r->priority ?? '')]),
        );
    }
}
