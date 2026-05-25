<?php

declare(strict_types=1);

namespace ZoneMirror\Infrastructure\Storage;

use RuntimeException;

/**
 * WHM-admin level configuration: global defaults, allowlist of users that may
 * enable the plugin, and rate-limit guardrails. Stored under
 * /var/cpanel/zonemirror/system.json owned by root:root, mode 0644 — the file
 * carries no secrets (dry-run flag, allowlist, rate limit) and must be
 * readable by the cPanel-user processes that run hooks and the UI.
 *
 * `email_normalization` is the WHM-admin DNS rewrite policy applied to every
 * zone the plugin syncs. It exists so the operator can centralise the email
 * authentication stack (DMARC reporting to the sysadmin mailbox, SPF includes
 * for the server's IPv6 and outbound mail host, etc.) without having to
 * touch each per-user zone file in cPanel.
 *
 * @phpstan-type SystemConfig array{
 *     defaults: array{proxied: bool, ttl: int},
 *     allowed_users: 'all'|list<string>,
 *     rate_limit_rps: int,
 *     dry_run: bool,
 *     email_normalization: array{
 *         dmarc_template: string,
 *         spf_extras: list<string>
 *     }
 * }
 */
final class SystemConfigStorage
{
    /**
     * @return SystemConfig
     */
    public function load(): array
    {
        $path = Paths::systemConfigFile();
        if (!is_file($path)) {
            return $this->defaults();
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $this->defaults();
        }
        /** @var array<string, mixed>|null $json */
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $this->defaults();
        }

        $defaults = $this->defaults();
        $merged = $defaults;

        if (isset($json['defaults']) && is_array($json['defaults'])) {
            $merged['defaults']['proxied'] = (bool) ($json['defaults']['proxied'] ?? $defaults['defaults']['proxied']);
            $merged['defaults']['ttl'] = max(60, (int) ($json['defaults']['ttl'] ?? $defaults['defaults']['ttl']));
        }
        if (isset($json['allowed_users'])) {
            if ($json['allowed_users'] === 'all') {
                $merged['allowed_users'] = 'all';
            } elseif (is_array($json['allowed_users'])) {
                $list = array_values(array_filter(
                    array_map('strval', $json['allowed_users']),
                    static fn (string $u): bool => $u !== '',
                ));
                $merged['allowed_users'] = $list;
            }
        }
        if (isset($json['rate_limit_rps'])) {
            $merged['rate_limit_rps'] = max(1, min(50, (int) $json['rate_limit_rps']));
        }
        if (isset($json['dry_run'])) {
            $merged['dry_run'] = (bool) $json['dry_run'];
        }
        if (isset($json['email_normalization']) && is_array($json['email_normalization'])) {
            $en = $json['email_normalization'];
            if (isset($en['dmarc_template']) && is_string($en['dmarc_template'])) {
                $merged['email_normalization']['dmarc_template'] = trim($en['dmarc_template']);
            }
            if (isset($en['spf_extras']) && is_array($en['spf_extras'])) {
                $extras = [];
                foreach ($en['spf_extras'] as $e) {
                    if (is_string($e)) {
                        $e = trim($e);
                        if ($e !== '') {
                            $extras[] = $e;
                        }
                    }
                }
                $merged['email_normalization']['spf_extras'] = $extras;
            }
        }

        return $merged;
    }

    /**
     * @param SystemConfig $data
     */
    public function save(array $data): void
    {
        $dir = Paths::systemDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create system dir: ' . $dir);
        }
        $path = Paths::systemConfigFile();
        $tmp = $path . '.tmp';
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to serialize system config.');
        }
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write system config.');
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('Unable to install system config.');
        }
    }

    public function isUserAllowed(string $user): bool
    {
        $cfg = $this->load();
        if ($cfg['allowed_users'] === 'all') {
            return true;
        }

        return in_array($user, $cfg['allowed_users'], true);
    }

    /**
     * @return SystemConfig
     */
    private function defaults(): array
    {
        // Fail-safe defaults: a freshly installed server with no system.json
        // yet must NOT push to Cloudflare. The admin opts in by enrolling
        // users (allowed_users) and explicitly disabling dry-run from WHM.
        return [
            'defaults' => ['proxied' => false, 'ttl' => 300],
            'allowed_users' => [],
            'rate_limit_rps' => 5,
            'dry_run' => true,
            'email_normalization' => [
                'dmarc_template' => '',
                'spf_extras' => [],
            ],
        ];
    }
}
