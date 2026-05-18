<?php

declare(strict_types=1);

namespace CfSync\Application;

use CfSync\Domain\DnsRecord;
use CfSync\Domain\EventAction;
use CfSync\Domain\SyncResult;
use CfSync\Infrastructure\Cloudflare\CloudflareApiClient;
use CfSync\Infrastructure\Cloudflare\CloudflareException;
use CfSync\Infrastructure\Cloudflare\ZoneSnapshot;
use CfSync\Infrastructure\Logging\FileLogger;

/**
 * Use case: apply one queued DNS event to Cloudflare. The zone snapshot is
 * passed in by the caller (the WorkerLoop fetches it once per user per
 * cycle) so we avoid one filtered listRecords API call per event. Mutations
 * are written back into the snapshot so the next event in the batch sees
 * fresh state.
 *
 * Returns a SyncResult so the worker can keep telemetry without crashing on
 * expected outcomes (no-change, skipped) being thrown as exceptions.
 */
final class ProcessEvent
{
    public function __construct(
        private readonly CloudflareApiClient $client,
        private readonly FileLogger $log,
        private readonly bool $dryRun = false,
    ) {
    }

    public function handle(
        string $zoneId,
        EventAction $action,
        DnsRecord $record,
        ZoneSnapshot $snapshot,
    ): SyncResult {
        if ($zoneId === '') {
            $this->log->warning('skipping event: empty zone id', ['type' => $record->type->value]);

            return SyncResult::Skipped;
        }

        $match = $snapshot->find($record);

        return match ($action) {
            EventAction::Upsert => $this->upsert($zoneId, $record, $match, $snapshot),
            EventAction::Delete => $this->delete($zoneId, $record, $match, $snapshot),
        };
    }

    /**
     * @param array<string, mixed>|null $match
     */
    private function upsert(string $zoneId, DnsRecord $record, ?array $match, ZoneSnapshot $snapshot): SyncResult
    {
        $payload = $record->toCloudflarePayload();

        if ($match === null) {
            if ($this->dryRun) {
                $this->log->info('dry-run: would create', ['type' => $record->type->value, 'name' => $record->name]);

                return SyncResult::Skipped;
            }
            $created = $this->client->createRecord($zoneId, $payload);
            $snapshot->applyCreate($created);
            $this->log->info('created record', ['type' => $record->type->value, 'name' => $record->name]);

            return SyncResult::Applied;
        }

        if ($this->payloadEquals($match, $payload)) {
            return SyncResult::NoChange;
        }

        $id = (string) ($match['id'] ?? '');
        if ($id === '') {
            throw new CloudflareException('matched record without id');
        }
        if ($this->dryRun) {
            $this->log->info('dry-run: would update', ['id' => $id]);

            return SyncResult::Skipped;
        }
        $updated = $this->client->updateRecord($zoneId, $id, $payload);
        $snapshot->applyUpdate($id, $updated);
        $this->log->info('updated record', ['id' => $id, 'type' => $record->type->value, 'name' => $record->name]);

        return SyncResult::Applied;
    }

    /**
     * @param array<string, mixed>|null $match
     */
    private function delete(string $zoneId, DnsRecord $record, ?array $match, ZoneSnapshot $snapshot): SyncResult
    {
        if ($match === null) {
            return SyncResult::NoChange;
        }
        $id = (string) ($match['id'] ?? '');
        if ($id === '') {
            return SyncResult::NoChange;
        }
        if ($this->dryRun) {
            $this->log->info('dry-run: would delete', ['id' => $id]);

            return SyncResult::Skipped;
        }
        $this->client->deleteRecord($zoneId, $id);
        $snapshot->applyDelete($id);
        $this->log->info('deleted record', ['id' => $id, 'type' => $record->type->value, 'name' => $record->name]);

        return SyncResult::Applied;
    }

    /**
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $local
     */
    private function payloadEquals(array $remote, array $local): bool
    {
        foreach (['type', 'name', 'content', 'priority', 'proxied', 'ttl'] as $k) {
            if (array_key_exists($k, $local) && (string) ($remote[$k] ?? '') !== (string) $local[$k]) {
                return false;
            }
        }

        return true;
    }
}
