<?php

declare(strict_types=1);

namespace ZoneMirror\Application;

use Closure;
use Throwable;
use ZoneMirror\Domain\AdminToken;
use ZoneMirror\Infrastructure\Cloudflare\CloudflareApiClient;
use ZoneMirror\Infrastructure\Logging\FileLogger;
use ZoneMirror\Infrastructure\Storage\AdminTokenStorage;
use ZoneMirror\Infrastructure\Storage\ZoneIndex;

/**
 * Walks the configured admin tokens, asks Cloudflare for the zones each
 * one can reach, and rewrites the ZoneIndex slice owned by that token.
 * Also writes the verification status (ok / expired / unauthorized /
 * partial-scope) and the zone count back to AdminTokenStorage so the
 * WHM UI can render the status pill without a fresh CF round-trip.
 *
 * Runs in two contexts:
 *   - From the daemon (WorkerLoop), on a slow timer, to catch zones the
 *     admin added or removed in Cloudflare since the last refresh.
 *   - From AdminTokensController, immediately after the admin adds or
 *     re-verifies a token, so the WHM page reflects reality without
 *     waiting for the daemon's next tick.
 *
 * Failures of any individual token are logged and isolated: a network
 * timeout on token A does not blank token B's slice of the index.
 */
final class IndexZones
{
    public function __construct(
        private readonly AdminTokenStorage $tokens,
        private readonly ZoneIndex $index,
        private readonly FileLogger $log,
        /** Override only for tests — production uses the real CF client. */
        private readonly ?Closure $clientFactory = null,
    ) {
    }

    public function runOnce(): void
    {
        foreach ($this->tokens->all() as $token) {
            try {
                $this->refreshOne($token->id);
            } catch (Throwable $e) {
                // Never let one token's failure abort the whole sweep.
                $this->log->error('zone-index: refresh failed', [
                    'token' => $token->id,
                    'msg' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Refresh a single token's slice. Public so AdminTokensController can
     * trigger an immediate refresh after add/verify without spinning the
     * full sweep. Caller is responsible for catching exceptions; runOnce()
     * already does that for the batch path.
     */
    public function refreshOne(string $tokenId): void
    {
        $existing = $this->tokens->find($tokenId);
        if ($existing === null) {
            $this->index->removeForToken($tokenId);

            return;
        }

        $plaintext = $this->tokens->plaintextFor($tokenId);
        if ($plaintext === null || $plaintext === '') {
            // The ciphertext is unreadable (corrupted master.key, etc.).
            // Treat as unauthorized so the operator sees the red pill.
            $this->tokens->updateVerification($tokenId, AdminToken::STATUS_UNAUTHORIZED, 0);
            $this->index->removeForToken($tokenId);

            return;
        }

        $client = $this->makeClient($plaintext);

        $rawStatus = '';
        try {
            $rawStatus = $client->verifyTokenStatus();
        } catch (Throwable $e) {
            // Transient network failure. Keep the index slice as-is so a
            // brief CF outage does not blank every domain on the server.
            $this->log->warning('zone-index: verify failed; keeping index', [
                'token' => $tokenId,
                'msg' => $e->getMessage(),
            ]);

            return;
        }

        $status = self::mapStatus($rawStatus);

        if ($status !== AdminToken::STATUS_OK) {
            // Token is definitively broken (expired, revoked, wrong).
            // Drop its zones — they're unusable until the admin replaces
            // the token. This is intentionally different from the
            // "verify threw" branch above: there we don't know yet.
            $this->log->info('zone-index: token not OK; clearing slice', [
                'token' => $tokenId,
                'status' => $status,
            ]);
            $this->index->removeForToken($tokenId);
            $this->tokens->updateVerification($tokenId, $status, 0);

            return;
        }

        $zones = [];
        try {
            $zones = $client->listZones();
        } catch (Throwable $e) {
            $this->log->warning('zone-index: listZones failed; keeping index', [
                'token' => $tokenId,
                'msg' => $e->getMessage(),
            ]);

            return;
        }

        $rows = [];
        foreach ($zones as $z) {
            if (!is_array($z)) {
                continue;
            }
            $cfZoneId = (string) ($z['id'] ?? '');
            $name = (string) ($z['name'] ?? '');
            if ($cfZoneId === '' || $name === '') {
                continue;
            }
            $cfAccountId = '';
            if (isset($z['account']) && is_array($z['account'])) {
                $cfAccountId = (string) ($z['account']['id'] ?? '');
            }
            $rows[] = [
                'cf_zone_id' => $cfZoneId,
                'name' => $name,
                'cf_account_id' => $cfAccountId,
            ];
        }

        $this->index->replaceForToken($tokenId, $rows);
        $this->tokens->updateVerification($tokenId, AdminToken::STATUS_OK, count($rows));
        $this->log->info('zone-index: refreshed', [
            'token' => $tokenId,
            'zones' => count($rows),
        ]);
    }

    private function makeClient(string $plaintext): CloudflareApiClient
    {
        if ($this->clientFactory !== null) {
            $client = ($this->clientFactory)($plaintext);
            if (!$client instanceof CloudflareApiClient) {
                throw new \LogicException('clientFactory must return CloudflareApiClient');
            }

            return $client;
        }

        return new CloudflareApiClient($plaintext);
    }

    /**
     * Translate a raw Cloudflare status string into an AdminToken status.
     * Kept as a static method so AdminTokensController can reuse the
     * exact same mapping without duplicating the match block.
     */
    public static function mapStatus(string $rawCloudflareStatus): string
    {
        return match ($rawCloudflareStatus) {
            'active' => AdminToken::STATUS_OK,
            'expired' => AdminToken::STATUS_EXPIRED,
            'disabled' => AdminToken::STATUS_UNAUTHORIZED,
            '' => AdminToken::STATUS_UNAUTHORIZED,
            default => AdminToken::STATUS_PARTIAL_SCOPE,
        };
    }
}
