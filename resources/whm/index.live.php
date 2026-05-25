<?php

declare(strict_types=1);

/**
 * WHM admin entry. Runs as root; WHM frames this script through the
 * resources/whm/index.cgi Perl wrapper that prints WHM's chrome around
 * an iframe pointing back here. The body below renders the iframe
 * content as a standalone document on purpose.
 *
 * The page funnels the operator into a guided "Connect Cloudflare"
 * flow that mirrors a third-party OAuth dance: we open Cloudflare's
 * token creation page with the right permissions pre-filled
 * (Zone:DNS:Edit + Zone:Zone:Read), the operator approves it there,
 * pastes the token back, and we verify + index. No token paste form
 * is the primary CTA on first install — the primary CTA is "Connect
 * Cloudflare" with the deep link.
 */

use ZoneMirror\Interface\Ui\AdminController;
use ZoneMirror\Interface\Ui\AdminTokensController;

$autoload = '/usr/local/cpanel/3rdparty/zonemirror/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo 'Plugin not installed correctly: missing vendor/autoload.php';
    exit;
}
require $autoload;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    http_response_code(403);
    echo 'ZoneMirror admin must run as root.';
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$post = $_POST;
$form = (string) ($post['form'] ?? '');

$admin = new AdminController();
$tokens = new AdminTokensController();

$adminVm = $admin->handle($form === 'admin' ? $method : 'GET', $form === 'admin' ? $post : []);
$tokensVm = $tokens->handle($form === 'tokens' ? $method : 'GET', $form === 'tokens' ? $post : []);

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$statusBadge = static function (string $status): string {
    $map = [
        'ok' => ['#0a7d2e', '#e6f7ec', 'Active'],
        'unverified' => ['#7a5b00', '#fff7d6', 'Not verified yet'],
        'unauthorized' => ['#a02020', '#fbe6e6', 'Token rejected'],
        'expired' => ['#a02020', '#fbe6e6', 'Expired'],
        'partial-scope' => ['#7a5b00', '#fff7d6', 'Limited scope'],
    ];
    [$fg, $bg, $label] = $map[$status] ?? ['#666', '#f0f0f0', $status];

    return sprintf(
        '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:%s;color:%s;font-size:0.85em;font-weight:500;">%s</span>',
        htmlspecialchars($bg, ENT_QUOTES),
        htmlspecialchars($fg, ENT_QUOTES),
        htmlspecialchars($label, ENT_QUOTES),
    );
};

// Cloudflare deep link with permissions pre-filled. Cloudflare reads the
// permissionGroupKeys query string to seed the "Create Token" form. We
// ask for the two scopes the plugin actually uses: DNS edit (CRUDL on
// zone DNS records) and Zone read (so we can enumerate the zones the
// token can see). The operator still has to click "Create Token" in
// the Cloudflare UI — Cloudflare does not allow third parties to
// bypass that confirmation.
$cfPermissionGroups = [
    ['key' => 'dns', 'type' => 'edit'],
    ['key' => 'zone', 'type' => 'read'],
];
$cfDeepLink = 'https://dash.cloudflare.com/profile/api-tokens?'
    . http_build_query([
        'permissionGroupKeys' => json_encode($cfPermissionGroups, JSON_UNESCAPED_SLASHES),
        'accountId' => '*',
        'zoneId' => 'all',
        'name' => 'ZoneMirror — DNS sync (cPanel)',
    ]);

$hasTokens = $tokensVm['allowed'] && $tokensVm['tokens'] !== [];
$justConnected = $tokensVm['message'] !== '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ZoneMirror — Admin</title>
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #fafafa; color: #222; }
    .card { max-width: 920px; margin: 1.5rem auto; padding: 1.25rem 1.5rem; background: #fff; border: 1px solid #ddd; border-radius: 6px; }
    .card.hero { text-align: center; padding: 2rem 1.5rem 2.5rem; }
    .card h2 { margin-top: 0; display: flex; align-items: center; gap: 0.75rem; }
    .card.hero h2 { justify-content: center; }
    .card h2 img.brand { height: 44px; width: auto; }
    .card.hero img.brand { height: 64px; margin-bottom: 0.5rem; }
    .hero-pitch { font-size: 1.05em; color: #555; max-width: 540px; margin: 0.5rem auto 1.5rem; }

    .err { color: #b00; }
    .ok { color: #060; }
    .muted { color: #666; font-size: 0.9em; }
    .note { background: #f6f8fa; border: 1px solid #e1e4e8; border-radius: 4px; padding: 0.75rem 1rem; font-size: 0.92em; }

    fieldset { margin-bottom: 1rem; border: 1px solid #e5e5e5; border-radius: 4px; padding: 0.75rem 1rem; }
    legend { font-weight: 600; padding: 0 0.5rem; }

    label { display: block; font-weight: 500; margin-bottom: 0.25rem; }
    textarea { width: 100%; min-height: 120px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9em; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    input[type=text], input[type=password] { width: 100%; padding: 0.55rem 0.6rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 1em; }
    input[type=number] { width: 6em; padding: 0.4rem 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; }
    input:focus, textarea:focus {
      outline: none; border-color: #2a73c4; box-shadow: 0 0 0 3px rgba(42,115,196,0.18);
    }
    input[type=checkbox], input[type=radio] { vertical-align: middle; margin-right: 0.4rem; }

    /* buttons */
    .btn, button {
      display: inline-block;
      padding: 0.55rem 1.1rem;
      font-size: 0.95em;
      font-weight: 500;
      line-height: 1.2;
      border: 1px solid #2a73c4;
      border-radius: 4px;
      background: #2a73c4;
      color: #fff;
      cursor: pointer;
      text-decoration: none;
      transition: background-color 0.12s, border-color 0.12s, box-shadow 0.12s;
    }
    .btn:hover, button:hover    { background: #1f5fa6; border-color: #1f5fa6; color: #fff; }
    .btn:active, button:active  { background: #194e89; border-color: #194e89; }
    .btn:focus, button:focus    { outline: none; box-shadow: 0 0 0 3px rgba(42,115,196,0.32); }
    .btn-lg { padding: 0.9rem 1.8rem; font-size: 1.05em; font-weight: 600; }
    .btn-cf-icon::before { content: "↗"; font-weight: 600; margin-left: 0.4rem; }
    button.secondary,
    button[name="action"][value="verify"] {
      background: #fff; color: #2a73c4; border-color: #c2d4e8;
    }
    button.secondary:hover,
    button[name="action"][value="verify"]:hover {
      background: #f1f7fd; border-color: #2a73c4;
    }
    button.danger,
    button[name="action"][value="remove"] {
      background: #fff; color: #b53a3a; border-color: #e0c0c0;
    }
    button.danger:hover,
    button[name="action"][value="remove"]:hover {
      background: #fdf3f3; border-color: #b53a3a;
    }

    /* tables */
    table.tokens { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
    table.tokens th, table.tokens td { text-align: left; padding: 0.7rem 0.75rem; border-bottom: 1px solid #eee; vertical-align: middle; }
    table.tokens th { font-size: 0.78em; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; background: #fafbfc; }
    table.tokens td.actions { text-align: right; white-space: nowrap; }
    table.tokens td.actions form { display: inline-block; margin-left: 0.4rem; }

    /* guided connect flow */
    .step-list { counter-reset: step; padding-left: 0; list-style: none; margin: 1rem 0; }
    .step-list li { counter-increment: step; padding: 0.25rem 0 0.25rem 2.2rem; position: relative; }
    .step-list li::before {
      content: counter(step);
      position: absolute; left: 0; top: 0.15rem;
      width: 1.6rem; height: 1.6rem;
      background: #2a73c4; color: #fff;
      border-radius: 999px;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 0.85em; font-weight: 600;
    }

    details.connect-more { margin-top: 1rem; }
    details.connect-more > summary {
      cursor: pointer;
      color: #2a73c4;
      font-weight: 500;
      list-style: none;
      padding: 0.5rem 0;
    }
    details.connect-more > summary::before { content: "＋ "; }
    details.connect-more[open] > summary::before { content: "− "; }
    details.connect-more > .body { padding: 1rem 0 0 0; }

    details.advanced { margin-top: 1rem; }
    details.advanced > summary { cursor: pointer; color: #555; padding: 0.5rem 0; }

    .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #eef; color: #336; font-size: 0.85em; }
    .row { display: flex; gap: 1rem; align-items: end; }
    .row > div { flex: 1; }
  </style>
</head>
<body>

<?php if ($justConnected): ?>
<div class="card">
  <p class="ok" style="margin: 0; font-weight: 500;">✓ <?= $h($tokensVm['message']) ?></p>
</div>
<?php endif; ?>
<?php foreach ($tokensVm['errors'] as $err): ?>
<div class="card">
  <p class="err" style="margin: 0;"><?= $h($err) ?></p>
</div>
<?php endforeach; ?>

<?php if (!$tokensVm['allowed']): ?>

  <div class="card">
    <p class="err">Admin tokens can only be managed as root.</p>
  </div>

<?php elseif (!$hasTokens): ?>

  <!-- ─── First-time hero: "Connect Cloudflare" ─────────────────────────── -->
  <div class="card hero">
    <img class="brand" src="../../addon_plugins/zonemirror-light.png" alt="">
    <h2 style="margin-top: 0;">Connect your Cloudflare account</h2>
    <p class="hero-pitch">
      Sync DNS Zone Editor changes to Cloudflare for every cPanel user
      on this server, with no per-user setup. Once connected, your
      customers connect their domains with a single click.
    </p>

    <a class="btn btn-lg btn-cf-icon" href="<?= $h($cfDeepLink) ?>" target="_blank" rel="noopener">
      Connect Cloudflare
    </a>

    <p class="muted" style="margin-top: 1rem;">
      We&rsquo;ll open Cloudflare with the right permissions already selected.
    </p>

    <details class="connect-more" style="max-width: 560px; margin: 1.5rem auto 0; text-align: left;">
      <summary>I already created a token in Cloudflare</summary>
      <div class="body">
        <?php include __DIR__ . '/_paste_form.partial.php'; ?>
      </div>
    </details>
  </div>

<?php else: ?>

  <!-- ─── Recurring state: list + "Connect another" ─────────────────────── -->
  <div class="card">
    <h2>
      <img class="brand" src="../../addon_plugins/zonemirror-light.png" alt="">
      <span>ZoneMirror</span>
      <span class="pill" title="Installed plugin version">v<?= $h($adminVm['installed_version']) ?></span>
    </h2>
    <p class="muted">
      Cloudflare accounts connected to this server. Each one covers every
      cPanel domain that lives inside its zones.
    </p>

    <table class="tokens">
      <thead>
        <tr>
          <th>Cloudflare account</th>
          <th>Status</th>
          <th>Zones</th>
          <th>Last verified</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tokensVm['tokens'] as $t): ?>
          <tr>
            <td>
              <strong><?= $h($t->name) ?></strong>
              <div class="muted" style="font-size: 0.78em; margin-top: 0.15rem;">id <code><?= $h($t->id) ?></code></div>
            </td>
            <td><?= $statusBadge($t->status) ?></td>
            <td><?= (int) $t->zonesIndexed ?></td>
            <td class="muted">
              <?= $t->lastVerifiedAt > 0 ? $h(gmdate('Y-m-d H:i \U\T\C', $t->lastVerifiedAt)) : 'never' ?>
            </td>
            <td class="actions">
              <form method="post" style="display:inline">
                <input type="hidden" name="form" value="tokens">
                <input type="hidden" name="csrf" value="<?= $h($tokensVm['csrf']) ?>">
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="id" value="<?= $h($t->id) ?>">
                <button type="submit">Verify now</button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove &quot;<?= $h($t->name) ?>&quot;? cPanel users on its zones will fall back to manual setup.');">
                <input type="hidden" name="form" value="tokens">
                <input type="hidden" name="csrf" value="<?= $h($tokensVm['csrf']) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id" value="<?= $h($t->id) ?>">
                <button type="submit">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <details class="connect-more">
      <summary>Connect another Cloudflare account</summary>
      <div class="body">
        <p class="muted">
          Add a second token if you manage multiple Cloudflare accounts
          (for example, one per reseller brand).
        </p>
        <a class="btn btn-cf-icon" href="<?= $h($cfDeepLink) ?>" target="_blank" rel="noopener">
          Open Cloudflare
        </a>

        <details class="connect-more" style="margin-top: 1rem;">
          <summary>I already have a token, paste it here</summary>
          <div class="body">
            <?php include __DIR__ . '/_paste_form.partial.php'; ?>
          </div>
        </details>
      </div>
    </details>
  </div>

<?php endif; ?>

<!-- ─── Advanced (collapsed by default) ─────────────────────────────────── -->
<div class="card">
  <details class="advanced">
    <summary>Advanced settings</summary>

    <?php if ($adminVm['saved']): ?>
      <p class="ok">Settings saved.</p>
    <?php endif; ?>
    <?php foreach ($adminVm['errors'] as $err): ?>
      <p class="err"><?= $h($err) ?></p>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="form" value="admin">
      <input type="hidden" name="csrf" value="<?= $h($adminVm['csrf']) ?>">

      <fieldset>
        <legend>Defaults</legend>
        <p><label><input type="checkbox" name="defaults_proxied" <?= $adminVm['defaults_proxied'] ? 'checked' : '' ?>>
          Default to proxied A/AAAA/CNAME records when users enable sync</label></p>
        <p><label>Default TTL (seconds, min 60):
          <input type="number" min="60" name="default_ttl" value="<?= (int) $adminVm['default_ttl'] ?>"></label></p>
      </fieldset>

      <fieldset>
        <legend>Who may use this plugin</legend>
        <p>
          <label><input type="radio" name="allowed_users_mode" value="all" <?= $adminVm['allowed_users_mode'] === 'all' ? 'checked' : '' ?>>
            All cPanel users</label>
        </p>
        <p>
          <label><input type="radio" name="allowed_users_mode" value="list" <?= $adminVm['allowed_users_mode'] === 'list' ? 'checked' : '' ?>>
            Only the users listed below (one per line)</label>
        </p>
        <textarea name="allowed_users_list" placeholder="alice&#10;bob"><?= $h($adminVm['allowed_users_list']) ?></textarea>
      </fieldset>

      <fieldset>
        <legend>Safety</legend>
        <p><label>Cloudflare API rate-limit budget (requests/second, 1-50):
          <input type="number" min="1" max="50" name="rate_limit_rps" value="<?= (int) $adminVm['rate_limit_rps'] ?>"></label></p>
        <p><label><input type="checkbox" name="dry_run" <?= $adminVm['dry_run'] ? 'checked' : '' ?>>
          Dry-run mode (log intended changes; do not call Cloudflare)</label></p>
      </fieldset>

      <button type="submit">Save</button>
    </form>

    <h3 style="margin-top: 1.5rem;">Enrolled cPanel users</h3>
    <?php if ($adminVm['enrolled'] === []): ?>
      <p class="muted">No users have connected a domain yet.</p>
    <?php else: ?>
      <ul>
        <?php foreach ($adminVm['enrolled'] as $u): ?>
          <li><code><?= $h($u) ?></code></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </details>
</div>

</body>
</html>
