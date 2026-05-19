<?php

declare(strict_types=1);

namespace CfSync\Interface\Ui;

use CfSync\Infrastructure\Storage\EnrolledUsers;
use CfSync\Infrastructure\Storage\SystemConfigStorage;
use CfSync\Infrastructure\Version\VersionReader;

/**
 * WHM admin view-model: global defaults, allowlist, dry-run kill switch.
 *
 * @phpstan-type AdminViewModel array{
 *     saved: bool,
 *     errors: list<string>,
 *     csrf: string,
 *     defaults_proxied: bool,
 *     default_ttl: int,
 *     allowed_users_mode: string,
 *     allowed_users_list: string,
 *     rate_limit_rps: int,
 *     dry_run: bool,
 *     enrolled: list<string>,
 *     installed_version: string
 * }
 */
final class AdminController
{
    private readonly SystemConfigStorage $storage;
    private readonly EnrolledUsers $enrolled;

    public function __construct(?SystemConfigStorage $storage = null)
    {
        $this->storage = $storage ?? new SystemConfigStorage();
        $this->enrolled = new EnrolledUsers();
    }

    /**
     * @param array<string, mixed> $post
     * @return AdminViewModel
     */
    public function handle(string $method, array $post): array
    {
        $saved = false;
        $errors = [];

        if ($method === 'POST') {
            if (!Csrf::verify(isset($post['csrf']) ? (string) $post['csrf'] : null)) {
                $errors[] = 'Invalid CSRF token.';
            } else {
                [$saved, $errors] = $this->save($post);
            }
        }

        $cfg = $this->storage->load();
        $mode = $cfg['allowed_users'] === 'all' ? 'all' : 'list';
        $list = $cfg['allowed_users'] === 'all' ? '' : implode("\n", $cfg['allowed_users']);

        return [
            'saved' => $saved,
            'errors' => $errors,
            'csrf' => Csrf::token(),
            'defaults_proxied' => $cfg['defaults']['proxied'],
            'default_ttl' => $cfg['defaults']['ttl'],
            'allowed_users_mode' => $mode,
            'allowed_users_list' => $list,
            'rate_limit_rps' => $cfg['rate_limit_rps'],
            'dry_run' => $cfg['dry_run'],
            'enrolled' => $this->enrolled->all(),
            'installed_version' => VersionReader::installed(),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: bool, 1: list<string>}
     */
    private function save(array $post): array
    {
        $errors = [];
        $mode = (string) ($post['allowed_users_mode'] ?? 'all');
        $listRaw = (string) ($post['allowed_users_list'] ?? '');
        $lines = preg_split('/\R+/', $listRaw);
        $linesArray = is_array($lines) ? $lines : [];
        $list = array_values(array_filter(
            array_map(
                static fn (string $u): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($u))) ?? '',
                $linesArray,
            ),
            static fn (string $u): bool => $u !== '',
        ));

        $ttl = max(60, (int) ($post['default_ttl'] ?? 300));
        $rps = max(1, min(50, (int) ($post['rate_limit_rps'] ?? 5)));

        try {
            $this->storage->save([
                'defaults' => [
                    'proxied' => isset($post['defaults_proxied']) && (string) $post['defaults_proxied'] !== '',
                    'ttl' => $ttl,
                ],
                'allowed_users' => $mode === 'all' ? 'all' : $list,
                'rate_limit_rps' => $rps,
                'dry_run' => isset($post['dry_run']) && (string) $post['dry_run'] !== '',
            ]);

            return [true, []];
        } catch (\Throwable $e) {
            $errors[] = 'Could not save: ' . $e->getMessage();

            return [false, $errors];
        }
    }
}
