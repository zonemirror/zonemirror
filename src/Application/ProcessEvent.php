<?php

declare(strict_types=1);

namespace ZoneMirror\Application;

use ZoneMirror\Domain\DnsRecord;
use ZoneMirror\Domain\EventAction;
use ZoneMirror\Domain\SyncResult;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareException;
use ZoneMirror\Infrastructure\Cloudflare\ZoneSnapshot;
use ZoneMirror\Infrastructure\Logging\FileLogger;

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
        ?string $targetCloudflareId = null,
    ): SyncResult {
        if ($zoneId === '') {
            $this->log->warning('skipping event: empty zone id', ['type' => $record->type->value]);

            return SyncResult::Skipped;
        }

        // When the UI's diff-apply flow tells us exactly which Cloudflare
        // record id to act on, use that — the snapshot's (type, name)
        // match is ambiguous for multi-row owners (SRV, MX, CAA) and
        // would otherwise either no-op or hit the wrong row.
        if ($targetCloudflareId !== null && $targetCloudflareId !== '') {
            $match = $snapshot->findById($targetCloudflareId);
        } else {
            $match = $snapshot->find($record);
        }

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

            try {
                $created = $this->client->createRecord($zoneId, $payload);
            } catch (CloudflareException $e) {
                // Cloudflare 81058 = identical record already exists. Happens
                // when our snapshot is stale (someone created the same row
                // in the dashboard between Compute Diff and Apply, or two
                // pushes raced). Desired state == actual state, so swallow
                // it; the next worker cycle's snapshot refresh will pick up
                // the existing record.
                if ($e->cloudflareCode === CloudflareException::CODE_DUPLICATE_RECORD) {
                    $this->log->info('create skipped: identical record already exists', [
                        'type' => $record->type->value,
                        'name' => $record->name,
                    ]);

                    return SyncResult::NoChange;
                }

                throw $e;
            }
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
