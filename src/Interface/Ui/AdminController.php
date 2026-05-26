<?php

declare(strict_types=1);

namespace ZoneMirror\Interface\Ui;

use ZoneMirror\Infrastructure\Mapping\EmailDnsPolicyComposer;
use ZoneMirror\Infrastructure\Storage\EnrolledUsers;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;
use ZoneMirror\Infrastructure\Version\VersionReader;

/**
 * WHM admin view-model: global defaults, allowlist, dry-run kill switch.
 *
 * @phpstan-type DmarcBuilderVm array{enabled: bool, policy: string, email: string, rua: bool, ruf: bool, sp: string, pct: ?int, custom: string}
 * @phpstan-type AdminViewModel array{
 *     saved: bool,
 *     errors: list<string>,
 *     csrf: string,
 *     defaults_proxied: bool,
 *     default_ttl: int,
 *     auto_ttl: bool,
 *     allowed_users_mode: string,
 *     allowed_users_list: string,
 *     rate_limit_rps: int,
 *     dry_run: bool,
 *     dmarc_template: string,
 *     spf_extras: string,
 *     dmarc: DmarcBuilderVm,
 *     spf_presets: list<string>,
 *     spf_custom: string,
 *     spf_preset_options: array<string, array{label: string, mechanism: string}>,
 *     server_ipv6: string,
 *     enrolled: list<string>,
 *     installed_version: string
 * }
 */
final class AdminController
{
    private readonly SystemConfigStorage $storage;
    private readonly EnrolledUsers $enrolled;
    private readonly EmailDnsPolicyComposer $composer;

    public function __construct(?SystemConfigStorage $storage = null)
    {
        $this->storage = $storage ?? new SystemConfigStorage();
        $this->enrolled = new EnrolledUsers();
        $this->composer = new EmailDnsPolicyComposer();
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
        $en = $cfg['email_normalization'];

        $presetOptions = [];
        foreach (EmailDnsPolicyComposer::SPF_PRESETS as $slug => $mechanism) {
            $presetOptions[$slug] = [
                'label' => EmailDnsPolicyComposer::PRESET_LABELS[$slug],
                'mechanism' => $mechanism,
            ];
        }

        return [
            'saved' => $saved,
            'errors' => $errors,
            'csrf' => Csrf::token(),
            'defaults_proxied' => $cfg['defaults']['proxied'],
            'default_ttl' => $cfg['defaults']['ttl'],
            'auto_ttl' => (bool) ($cfg['defaults']['auto_ttl'] ?? true),
            'allowed_users_mode' => $mode,
            'allowed_users_list' => $list,
            'rate_limit_rps' => $cfg['rate_limit_rps'],
            'dry_run' => $cfg['dry_run'],
            'dmarc_template' => (string) ($en['dmarc_template'] ?? ''),
            'spf_extras' => implode("\n", (array) ($en['spf_extras'] ?? [])),
            'dmarc' => $en['dmarc'],
            'spf_presets' => (array) ($en['spf_presets'] ?? []),
            'spf_custom' => (string) ($en['spf_custom'] ?? ''),
            'spf_preset_options' => $presetOptions,
            'server_ipv6' => EmailDnsPolicyComposer::detectServerIpv6(),
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
        $autoTtl = isset($post['auto_ttl']) && (string) $post['auto_ttl'] !== '';
        $rps = max(1, min(50, (int) ($post['rate_limit_rps'] ?? 5)));

        // DMARC builder → both the structured form-state (for round-trip)
        // and the composed canonical template (what the runtime reads).
        $pctRaw = isset($post['dmarc_pct']) && (string) $post['dmarc_pct'] !== ''
            ? (int) $post['dmarc_pct']
            : null;
        $dmarcCfg = [
            'enabled' => isset($post['dmarc_enable']) && (string) $post['dmarc_enable'] !== '',
            'policy'  => (string) ($post['dmarc_policy'] ?? 'none'),
            'email'   => trim((string) ($post['dmarc_email'] ?? '')),
            'rua'     => isset($post['dmarc_rua']) && (string) $post['dmarc_rua'] !== '',
            'ruf'     => isset($post['dmarc_ruf']) && (string) $post['dmarc_ruf'] !== '',
            'sp'      => (string) ($post['dmarc_sp'] ?? ''),
            'pct'     => ($pctRaw !== null && $pctRaw >= 1 && $pctRaw <= 100) ? $pctRaw : null,
            'custom'  => trim((string) ($post['dmarc_custom'] ?? '')),
        ];
        $dmarcTemplate = $this->composer->composeDmarc($dmarcCfg);

        // SPF: combine ticked presets with the free-form custom list.
        $presetSlugs = [];
        if (isset($post['spf_preset']) && is_array($post['spf_preset'])) {
            foreach ($post['spf_preset'] as $slug) {
                if (is_string($slug) && $slug !== '') {
                    $presetSlugs[] = $slug;
                }
            }
        }
        $spfCustomRaw = (string) ($post['spf_custom'] ?? '');
        $serverIpv6 = EmailDnsPolicyComposer::detectServerIpv6();
        $spfExtras = $this->composer->composeSpfExtras($presetSlugs, $spfCustomRaw, $serverIpv6);

        try {
            // Preserve local_rewrite as-is: this form doesn't yet expose
            // the rewrite-side knobs, but the SystemConfig contract now
            // requires the key to be present on save(). Read the current
            // value back and pass it through unchanged. When the WHM form
            // grows the rewrite section, this is where the post fields
            // will feed in.
            $existing = $this->storage->load();
            $this->storage->save([
                'defaults' => [
                    'proxied' => isset($post['defaults_proxied']) && (string) $post['defaults_proxied'] !== '',
                    'ttl' => $ttl,
                    'auto_ttl' => $autoTtl,
                ],
                'allowed_users' => $mode === 'all' ? 'all' : $list,
                'rate_limit_rps' => $rps,
                'dry_run' => isset($post['dry_run']) && (string) $post['dry_run'] !== '',
                'email_normalization' => [
                    'dmarc_template' => $dmarcTemplate,
                    'spf_extras' => $spfExtras,
                    'dmarc' => $dmarcCfg,
                    'spf_presets' => $presetSlugs,
                    'spf_custom' => $spfCustomRaw,
                ],
                'local_rewrite' => $existing['local_rewrite'],
            ]);

            return [true, []];
        } catch (\Throwable $e) {
            $errors[] = 'Could not save: ' . $e->getMessage();

            return [false, $errors];
        }
    }
}
