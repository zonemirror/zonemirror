<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Ui;

use Throwable;
use ZoneMirror\Infrastructure\Storage\AdminTokenStorage;
use ZoneMirror\Infrastructure\Storage\ConfigCrypto;
use ZoneMirror\Infrastructure\Storage\KeyStore;
use ZoneMirror\Infrastructure\Storage\Paths;
use ZoneMirror\Infrastructure\Storage\ZoneIndex;

/**
 * JSON view-model for the "expand a connection" disclosure on the WHM
 * admin page. Backs a single GET endpoint:
 *
 *   GET ?ajax=list-zones&token=<id>
 *       Returns the accounts + zones the token can reach, grouped by
 *       Cloudflare account, each zone annotated with whether the token
 *       holds `#dns_records:edit` on it.
 *
 * Everything is served from the local SQLite zone-index — IndexZones
 * caches both the account name and the per-zone permissions array
 * during its periodic CF sweep (and the immediate one triggered by the
 * "Verify now" button on the token row), so opening this disclosure
 * never hits Cloudflare. The cost of "expand" is one local read; the
 * cost of "refresh" is the existing token-level Verify Now button.
 *
 * One Cloudflare token can reach zones across every account the user
 * belongs to (provided the operator picked "All accounts" at token
 * creation in the CF dash), so the answer to "do I need a second token
 * for the other accounts?" is normally "no, just widen this one's
 * permissions" — the per-zone edit badge makes that decision visible.
 *
 * Root-only by construction: the page that loads this lives behind WHM
 * root authentication and `posix_geteuid() !== 0` is enforced upstream
 * in index.live.php before the AJAX dispatch runs.
 *
 * @phpstan-type ZoneEntry array{
 *     cf_zone_id: string,
 *     name: string,
 *     can_edit_dns: bool,
 *     can_read_dns: bool,
 *     permissions: list<string>,
 *     probed_at: int
 * }
 * @phpstan-type AccountEntry array{
 *     cf_account_id: string,
 *     cf_account_name: string,
 *     zones: list<ZoneEntry>
 * }
 * @phpstan-type ListResult array{
 *     ok: bool,
 *     accounts: list<AccountEntry>,
 *     error: string|null
 * }
 */
final class AdminTokensZonesController
{
    public function __construct(
        private ?AdminTokenStorage $tokens = null,
        private ?ZoneIndex $index = null,
    ) {
    }

    /**
     * @return ListResult
     */
    public function listZones(string $tokenId): array
    {
        if ($tokenId === '') {
            return self::failure('Missing token id.');
        }

        $token = $this->resolveTokens()->find($tokenId);
        if ($token === null) {
            return self::failure('Token not found.');
        }

        try {
            $rows = $this->resolveIndex()->allForToken($tokenId);
        } catch (Throwable $e) {
            return self::failure('Zone index unreadable: ' . $e->getMessage());
        }

        /** @var array<string, array{name: string, zones: list<ZoneEntry>}> $byAccount */
        $byAccount = [];
        foreach ($rows as $r) {
            $acctId = $r['cf_account_id'];
            $canEdit = in_array('#dns_records:edit', $r['permissions'], true);
            $entry = [
                'cf_zone_id' => $r['cf_zone_id'],
                'name' => $r['name'],
                'can_edit_dns' => $canEdit,
                'can_read_dns' => $canEdit
                    || in_array('#dns_records:read', $r['permissions'], true),
                'permissions' => $r['permissions'],
                'probed_at' => $r['probed_at'],
            ];
            if (!isset($byAccount[$acctId])) {
                $byAccount[$acctId] = [
                    'name' => $r['cf_account_name'],
                    'zones' => [],
                ];
            } elseif ($byAccount[$acctId]['name'] === '' && $r['cf_account_name'] !== '') {
                // First row of an account had no name (legacy slice that
                // pre-dates the cache); a later one filled it in. Keep
                // the first non-empty value we see.
                $byAccount[$acctId]['name'] = $r['cf_account_name'];
            }
            $byAccount[$acctId]['zones'][] = $entry;
        }

        $accounts = [];
        foreach ($byAccount as $acctId => $group) {
            $zones = $group['zones'];
            usort($zones, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            $accounts[] = [
                'cf_account_id' => $acctId,
                'cf_account_name' => $group['name'],
                'zones' => $zones,
            ];
        }
        // Sort accounts by their display name (falling back to id) so
        // the operator sees a stable, human-readable order between
        // page loads.
        usort($accounts, static function (array $a, array $b): int {
            $an = $a['cf_account_name'] !== '' ? $a['cf_account_name'] : $a['cf_account_id'];
            $bn = $b['cf_account_name'] !== '' ? $b['cf_account_name'] : $b['cf_account_id'];

            return strcasecmp($an, $bn);
        });

        return ['ok' => true, 'accounts' => $accounts, 'error' => null];
    }

    /**
     * @return ListResult
     */
    private static function failure(string $error): array
    {
        return ['ok' => false, 'accounts' => [], 'error' => $error];
    }

    private function resolveTokens(): AdminTokenStorage
    {
        if ($this->tokens === null) {
            $this->tokens = new AdminTokenStorage(
                new ConfigCrypto(new KeyStore(Paths::adminKeyFile()))
            );
        }

        return $this->tokens;
    }

    private function resolveIndex(): ZoneIndex
    {
        if ($this->index === null) {
            $this->index = new ZoneIndex(Paths::zoneIndexFile());
        }

        return $this->index;
    }
}
