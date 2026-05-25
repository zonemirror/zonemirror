<?php

declare(strict_types=1);

/**
 * WHM admin entry. Runs as root; WHM frames this script directly without
 * a LIVEAPI handshake (see /usr/local/cpanel/whostmgr/docroot/cgi/softaculous/
 * for the upstream pattern). Hosts:
 *
 *   - Defaults / allowlist / dry-run kill switch  (AdminController)
 *   - Cloudflare admin API tokens                 (AdminTokensController)
 *
 * The chrome (WHM sidebar, banner) is provided by the iframe that wraps
 * this page; the body here renders a standalone document on purpose.
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

// Defence in depth. WHM only routes this to root in the first place,
// but the admin-token storage holds material that must never leak.
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    http_response_code(403);
    echo 'ZoneMirror admin must run as root.';
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$post = $_POST;

// Route the POST to whichever controller submitted it so we don't have
// two forms cross-talking. Each form carries a hidden `form` field.
$form = (string) ($post['form'] ?? '');

$admin = new AdminController();
$tokens = new AdminTokensController();

$adminVm = $admin->handle($form === 'admin' ? $method : 'GET', $form === 'admin' ? $post : []);
$tokensVm = $tokens->handle($form === 'tokens' ? $method : 'GET', $form === 'tokens' ? $post : []);

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$statusBadge = static function (string $status): string {
    $map = [
        'ok' => ['#0a7d2e', '#e6f7ec', 'OK'],
        'unverified' => ['#7a5b00', '#fff7d6', 'Not verified yet'],
        'unauthorized' => ['#a02020', '#fbe6e6', 'Token rejected'],
        'expired' => ['#a02020', '#fbe6e6', 'Expired'],
        'partial-scope' => ['#7a5b00', '#fff7d6', 'Limited scope'],
    ];
    [$fg, $bg, $label] = $map[$status] ?? ['#666', '#f0f0f0', $status];

    return sprintf(
        '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:%s;color:%s;font-size:0.85em;">%s</span>',
        htmlspecialchars($bg, ENT_QUOTES),
        htmlspecialchars($fg, ENT_QUOTES),
        htmlspecialchars($label, ENT_QUOTES),
    );
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ZoneMirror — Admin</title>
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #fafafa; }
    .card { max-width: 920px; margin: 1.5rem auto; padding: 1rem 1.25rem; background: #fff; border: 1px solid #ddd; border-radius: 6px; }
    .card h2 { margin-top: 0; display: flex; align-items: center; gap: 0.75rem; }
    .card h2 img.brand { height: 44px; width: auto; }
    .err { color: #b00; }
    .ok { color: #060; }
    .info { color: #336; }
    .muted { color: #666; font-size: 0.9em; }
    fieldset { margin-bottom: 1rem; border: 1px solid #e5e5e5; border-radius: 4px; padding: 0.75rem 1rem; }
    legend { font-weight: 600; padding: 0 0.5rem; }
    textarea { width: 100%; min-height: 120px; font-family: monospace; }
    input[type=text], input[type=password] { width: 100%; padding: 0.4rem 0.5rem; box-sizing: border-box; }
    input[type=number] { width: 6em; padding: 0.4rem 0.5rem; }
    table.tokens { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
    table.tokens th, table.tokens td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #eee; vertical-align: middle; }
    table.tokens th { font-size: 0.85em; color: #666; font-weight: 600; }
    table.tokens td.actions { text-align: right; }
    button { padding: 0.4rem 0.9rem; cursor: pointer; }
    button.link { background: none; border: none; color: #336; padding: 0; cursor: pointer; text-decoration: underline; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #eef; color: #336; font-size: 0.85em; }
    .row { display: flex; gap: 1rem; align-items: end; }
    .row > div { flex: 1; }
  </style>
</head>
<body>
<div class="card">
  <h2>
    <img class="brand" src="../../addon_plugins/zonemirror-light.png" alt="">
    <span>ZoneMirror</span>
    <span class="pill" title="Installed plugin version">v<?= $h($adminVm['installed_version']) ?></span>
  </h2>
  <p class="muted">
    Configure Cloudflare API tokens once. Your cPanel users connect their
    domains with a single click — no token paste required from them.
  </p>
</div>

<!-- ─── Cloudflare admin tokens ─────────────────────────────────────────── -->
<div class="card">
  <h2>Cloudflare API tokens</h2>
  <p class="muted">
    Tokens entered here cover Cloudflare zones on behalf of every cPanel user
    whose domain falls inside them.
  </p>

  <?php if (!$tokensVm['allowed']): ?>
    <p class="err">Admin tokens can only be managed as root.</p>
  <?php else: ?>

    <?php if ($tokensVm['message'] !== ''): ?>
      <p class="ok"><?= $h($tokensVm['message']) ?></p>
    <?php endif; ?>
    <?php foreach ($tokensVm['errors'] as $err): ?>
      <p class="err"><?= $h($err) ?></p>
    <?php endforeach; ?>

    <?php if ($tokensVm['tokens'] === []): ?>
      <p>No tokens configured yet. Add one below to start.</p>
    <?php else: ?>
      <table class="tokens">
        <thead>
          <tr>
            <th>Name</th>
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
                <div class="muted"><code><?= $h($t->id) ?></code></div>
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
                <form method="post" style="display:inline" onsubmit="return confirm('Remove token \&quot;<?= $h($t->name) ?>\&quot;? cPanel users currently using it will fall back to manual setup until another covering token is added.');">
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
    <?php endif; ?>

    <h3>Add token</h3>
    <form method="post" autocomplete="off">
      <input type="hidden" name="form" value="tokens">
      <input type="hidden" name="csrf" value="<?= $h($tokensVm['csrf']) ?>">
      <input type="hidden" name="action" value="add">

      <div class="row">
        <div>
          <label>Name <small class="muted">(internal, e.g. "Main CF account")</small>
            <input type="text" name="name" placeholder="Main CF account" required>
          </label>
        </div>
      </div>
      <div class="row" style="margin-top: 0.75rem;">
        <div>
          <label>Cloudflare API token
            <input type="password" name="token" placeholder="cf-XXXX..." required autocomplete="new-password">
          </label>
          <p class="muted">
            Required scopes: <strong>Zone:DNS:Edit</strong> and <strong>Zone:Zone:Read</strong>.
          </p>
        </div>
      </div>
      <button type="submit" style="margin-top: 0.75rem;">Add token</button>
    </form>
  <?php endif; ?>
</div>

<!-- ─── Defaults / allowlist / dry-run ──────────────────────────────────── -->
<div class="card">
  <h2>Defaults &amp; safety</h2>

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

    <button type="submit">Save defaults</button>
  </form>

  <h3>Enrolled users</h3>
  <?php if ($adminVm['enrolled'] === []): ?>
    <p>No users have enabled sync yet.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($adminVm['enrolled'] as $u): ?>
        <li><code><?= $h($u) ?></code></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

</body>
</html>
