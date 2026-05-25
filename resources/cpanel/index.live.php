<?php

declare(strict_types=1);

/**
 * cPanel UI entry point for ZoneMirror.
 *
 * The page is rendered inside Jupiter's chrome via $cpanel->header()/
 * footer(). The body is one card per cPanel domain owned by the calling
 * user; each domain shows whether it is already connected to Cloudflare
 * (and by which path — admin token or user-pasted token), whether it is
 * available for 1-click connect (the WHM admin has a token that covers
 * the zone), or whether it is outside any configured Cloudflare account.
 *
 * The page does not ask the user for a Cloudflare token unless they
 * explicitly open the "advanced" disclosure. Admin-covered domains
 * connect with a single button click and never see token material.
 */

use ZoneMirror\Interface\Ui\UserController;

include '/usr/local/cpanel/php/cpanel.php';
$cpanel = new CPANEL();

$autoload = '/usr/local/cpanel/3rdparty/zonemirror/vendor/autoload.php';
if (!is_file($autoload)) {
    print $cpanel->header('ZoneMirror');
    echo '<div class="callout callout-danger"><strong>Plugin not installed correctly.</strong> Missing vendor/autoload.php — re-run packaging/install.sh as root.</div>';
    print $cpanel->footer();
    $cpanel->end();
    exit;
}
require $autoload;

$user = (string) ($_ENV['REMOTE_USER'] ?? ($_SERVER['REMOTE_USER'] ?? ''));
if ($user === '') {
    $pw = posix_getpwuid(posix_geteuid());
    $user = is_array($pw) ? (string) ($pw['name'] ?? '') : '';
}

// Collect all domains attached to this cPanel account. UAPI is the
// canonical source; we fall back to /var/cpanel/userdata/<user>/main
// only if UAPI returns nothing recognisable, since that file is
// readable from inside the user's cagefs.
$allDomains = zm_list_user_domains($cpanel, $user);

$controller = new UserController();
$vm = $controller->handle(
    $user,
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_POST,
    $allDomains,
);

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

print $cpanel->header('ZoneMirror');
?>

<style>
  .zonemirror-wrap { max-width: 820px; }
  .zonemirror-intro { color: #555; margin-bottom: 1.5rem; }
  .zm-domains { border: 1px solid #e5e5e5; border-radius: 6px; overflow: hidden; margin-top: 1rem; }
  .zm-domain { display: flex; align-items: center; justify-content: space-between; padding: 0.9rem 1rem; border-bottom: 1px solid #eee; gap: 1rem; }
  .zm-domain:last-child { border-bottom: 0; }
  .zm-domain .name { font-weight: 600; word-break: break-all; }
  .zm-domain .meta { font-size: 0.85em; color: #888; margin-top: 0.15rem; }
  .zm-domain .actions form { display: inline-block; margin: 0; }
  .zm-empty { padding: 1.5rem; text-align: center; color: #777; }
  .zm-pill {
    display: inline-block; padding: 2px 9px; border-radius: 999px;
    font-size: 0.8em; font-weight: 500;
  }
  .zm-pill.ok { background: #e6f7ec; color: #0a7d2e; }
  .zm-pill.avail { background: #eaf2fb; color: #1f5fa6; }
  .zm-pill.unavail { background: #f0f0f0; color: #777; }
  .zm-pill.warn { background: #fff7d6; color: #7a5b00; }
  .zm-btn-row form { display: inline-block; margin: 0; }
  details.advanced { margin-top: 2rem; padding: 0.75rem 0; border-top: 1px solid #eee; }
  details.advanced > summary { cursor: pointer; color: #555; padding: 0.4rem 0; }
  .form-stack label { display: block; margin: 0.75rem 0 0.25rem; font-weight: 500; }
  .form-stack input { width: 100%; padding: 0.5rem 0.6rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
</style>

<div class="body-content zonemirror-wrap">
  <h2>Your domains on Cloudflare</h2>
  <p class="zonemirror-intro">
    Keep your Cloudflare DNS in sync with cPanel's Zone Editor automatically.
    Pick a domain to connect.
  </p>

  <?php if (!$vm['allowed']): ?>
    <div class="callout callout-warning">
      <strong>This plugin is not available for your account.</strong>
      Ask your hosting provider to enable it.
    </div>
  <?php else: ?>

    <?php if ($vm['message'] !== ''): ?>
      <div class="callout callout-success"><?= $h($vm['message']) ?></div>
    <?php endif; ?>
    <?php foreach ($vm['errors'] as $err): ?>
      <div class="callout callout-danger"><?= $h($err) ?></div>
    <?php endforeach; ?>
    <?php if ($vm['test_result'] !== null): ?>
      <div class="callout callout-info"><?= $h($vm['test_result']) ?></div>
    <?php endif; ?>

    <?php if ($vm['domains'] === []): ?>
      <div class="zm-domains">
        <div class="zm-empty">
          We couldn&rsquo;t list any domains for your account. If this looks wrong,
          try reloading the page.
        </div>
      </div>
    <?php else: ?>
      <div class="zm-domains">
        <?php foreach ($vm['domains'] as $d): ?>
          <div class="zm-domain">
            <div>
              <div class="name"><?= $h($d['name']) ?></div>
              <?php if ($d['status'] === UserController::DOMAIN_CONNECTED_ADMIN): ?>
                <div class="meta">
                  <span class="zm-pill ok">Connected</span>
                  &nbsp;Syncing to Cloudflare automatically.
                </div>
              <?php elseif ($d['status'] === UserController::DOMAIN_CONNECTED_USER): ?>
                <div class="meta">
                  <span class="zm-pill ok">Connected</span>
                  &nbsp;Using your own Cloudflare token.
                </div>
              <?php elseif ($d['status'] === UserController::DOMAIN_AVAILABLE): ?>
                <div class="meta">
                  <span class="zm-pill avail">Available</span>
                  &nbsp;Ready to connect with one click.
                </div>
              <?php else: /* not-in-zone */ ?>
                <div class="meta">
                  <span class="zm-pill unavail">Not available</span>
                  &nbsp;Not in any Cloudflare account this server can reach.
                </div>
              <?php endif; ?>
            </div>

            <div class="actions zm-btn-row">
              <?php if ($d['is_current']): ?>
                <form method="post" onsubmit="return confirm('Stop syncing <?= $h($d['name']) ?> to Cloudflare?');">
                  <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
                  <input type="hidden" name="action" value="disconnect">
                  <button type="submit" class="btn btn-default">Disconnect</button>
                </form>
              <?php elseif ($d['status'] === UserController::DOMAIN_AVAILABLE): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
                  <input type="hidden" name="action" value="connect_domain">
                  <input type="hidden" name="domain" value="<?= $h($d['name']) ?>">
                  <button type="submit" class="btn btn-primary">Connect</button>
                </form>
              <?php else: ?>
                <span class="meta" title="No connected Cloudflare account covers this zone">&nbsp;</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($vm['enabled']): ?>
        <p style="margin-top: 1rem; color: #666; font-size: 0.9em;">
          Queue: <strong><?= (int) $vm['queue_depth'] ?></strong> pending,
          <strong><?= (int) $vm['dead_letters'] ?></strong> failed.
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <details class="advanced">
      <summary>Use my own Cloudflare account</summary>

      <p style="margin-top: 1rem; color: #555;">
        If your domain isn&rsquo;t covered by your hosting provider&rsquo;s Cloudflare
        account, you can connect your own Cloudflare token instead.
      </p>

      <form method="post" autocomplete="off" class="form-stack">
        <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
        <input type="hidden" name="action" value="save">

        <label>Cloudflare API token <small style="color:#888">(leave blank to keep current)</small>
          <input type="password" name="token" autocomplete="new-password"
                 placeholder="<?= $vm['token_set'] ? '••••••••' : 'cf-XXXX...' ?>">
        </label>

        <label>Zone (domain)
          <input type="text" name="zone_name" value="<?= $h($vm['zone_name']) ?>" placeholder="example.com">
        </label>

        <label style="display: block; margin-top: 0.5rem;">
          <input type="checkbox" name="defaults_proxied" <?= $vm['defaults_proxied'] ? 'checked' : '' ?>>
          Proxy A / AAAA / CNAME records by default
        </label>
        <label>
          <input type="checkbox" name="enabled" <?= ($vm['enabled'] && $vm['source'] === 'user') ? 'checked' : '' ?>>
          Enable real-time sync to Cloudflare
        </label>

        <div style="margin-top: 0.75rem;">
          <button type="submit" name="action" value="test" class="btn btn-default" formnovalidate>Test connection</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </details>

  <?php endif; ?>
</div>

<?php
print $cpanel->footer();
$cpanel->end();


// ─── helpers ─────────────────────────────────────────────────────────────

/**
 * @return list<string>
 */
function zm_list_user_domains(CPANEL $cpanel, string $user): array
{
    $out = [];

    // Primary: UAPI. The shape of $cpanel->uapi() output is not strongly
    // documented, so we walk the most common nestings defensively.
    try {
        $resp = $cpanel->uapi('DomainInfo', 'list_domains');
        $data = zm_unwrap_uapi_data($resp);
        if (is_array($data)) {
            if (!empty($data['main_domain'])) {
                $out[] = (string) $data['main_domain'];
            }
            foreach (['addon_domains', 'parked_domains', 'sub_domains'] as $k) {
                if (isset($data[$k]) && is_array($data[$k])) {
                    foreach ($data[$k] as $d) {
                        if (is_string($d) && $d !== '') {
                            $out[] = $d;
                        }
                    }
                }
            }
        }
    } catch (\Throwable) {
        // Fall through to filesystem read.
    }

    // Fallback: parse /var/cpanel/userdata/<user>/main. cPanel writes it
    // as YAML but with a known, shallow shape we can read with regex
    // instead of pulling in a YAML parser.
    if ($out === []) {
        $path = '/var/cpanel/userdata/' . $user . '/main';
        $raw = @file_get_contents($path);
        if (is_string($raw)) {
            if (preg_match('/^main_domain:\s*(\S+)/m', $raw, $m)) {
                $out[] = $m[1];
            }
            foreach (['addon_domains', 'parked_domains', 'sub_domains'] as $k) {
                if (preg_match('/^' . $k . ':\s*\n((?:\s+-\s+\S+\n?)+)/m', $raw, $m)) {
                    foreach (preg_split('/\R/', $m[1]) as $line) {
                        if (preg_match('/^\s+-\s+(\S+)/', $line, $mm)) {
                            $out[] = $mm[1];
                        }
                    }
                }
            }
        }
    }

    return array_values(array_unique($out));
}

/**
 * cpanel.php returns uapi results in one of several shapes depending on
 * the connector. Try the documented nesting first, then progressively
 * looser ones, before giving up.
 *
 * @return array<string, mixed>|null
 */
function zm_unwrap_uapi_data(mixed $resp): ?array
{
    if (!is_array($resp)) {
        return null;
    }
    foreach (
        [
            ['cpanelresult', 'result', 'data'],
            ['result', 'data'],
            ['data'],
        ] as $path
    ) {
        $cur = $resp;
        $ok = true;
        foreach ($path as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                $ok = false;
                break;
            }
            $cur = $cur[$key];
        }
        if ($ok && is_array($cur)) {
            return $cur;
        }
    }

    return null;
}
