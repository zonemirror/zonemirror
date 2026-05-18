<?php

declare(strict_types=1);

namespace CfSync\Application;

use CfSync\Infrastructure\Cloudflare\CloudflareApiClient;

/**
 * One-shot read of the live Cloudflare zone, returned to the UI so the user
 * can review/import records into cPanel out-of-band. Pure transport here; the
 * actual write into cPanel happens via WHM API on the UI side.
 *
 * @phpstan-type Record array<string, mixed>
 */
final class ImportFromCloudflare
{
    public function __construct(private readonly CloudflareApiClient $client)
    {
    }

    /**
     * @return list<Record>
     */
    public function listRemote(string $zoneId): array
    {
        return $this->client->listRecords($zoneId);
    }
}
