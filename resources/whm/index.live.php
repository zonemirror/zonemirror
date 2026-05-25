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

// The server's main outbound IP — surfaced in the "Hardening" disclosure
// under Advanced settings so the operator can copy/paste it into the
// Cloudflare token's "Client IP Address Filtering" field. Cloudflare's
// token-template URL does not support pre-filling this, so it has to be
// added by hand after the fact. cPanel writes the main IP to
// /var/cpanel/mainip; fall back to /etc/wwwacct.conf.
$serverIp = '';
foreach (['/var/cpanel/mainip'] as $p) {
    $raw = @file_get_contents($p);
    if (is_string($raw) && preg_match('/(\d{1,3}\.){3}\d{1,3}/', trim($raw), $m) === 1) {
        $serverIp = $m[0];
        break;
    }
}
if ($serverIp === '') {
    $conf = @file_get_contents('/etc/wwwacct.conf');
    if (is_string($conf) && preg_match('/^ADDR\s+((?:\d{1,3}\.){3}\d{1,3})/m', $conf, $m) === 1) {
        $serverIp = $m[1];
    }
}

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

    /* IP-restriction hint shown in the "Connect Cloudflare" flow */
    .ip-hint {
      max-width: 560px; margin: 1.25rem auto 0;
      padding: 0.85rem 1rem;
      background: #f6f8fa; border: 1px solid #e1e4e8; border-radius: 6px;
      text-align: left;
    }
    .ip-hint-label { color: #444; font-size: 0.92em; margin-bottom: 0.3rem; }
    .ip-hint-value { font-size: 1.15em; font-weight: 600; letter-spacing: 0.5px; }
    .ip-hint-value code {
      background: #fff; border: 1px solid #d0d7de; padding: 2px 8px;
      border-radius: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }

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

<?php /* Advanced-form save feedback. The form lives inside a collapsed
        <details>, so after a POST round-trip we render this banner at
        the top of the page where it's actually visible — and keep the
        Advanced panel open so the user can see the field they just
        edited still has the new value. */ ?>
<?php if ($adminVm['saved']): ?>
<div class="card">
  <p class="ok" style="margin: 0; font-weight: 500;">✓ Settings saved.</p>
</div>
<?php endif; ?>
<?php foreach ($adminVm['errors'] as $err): ?>
<div class="card">
  <p class="err" style="margin: 0;">⚠ <?= $h($err) ?></p>
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
          <th>Cloudflare connection</th>
          <th>Status</th>
          <th>Accounts</th>
          <th>Zones</th>
          <th>Last verified</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tokensVm['tokens'] as $t): ?>
          <?php $accountsCount = $tokensVm['accounts_count_by_token'][$t->id] ?? 0; ?>
          <tr>
            <td>
              <strong><?= $h($t->name) ?></strong>
              <div class="muted" style="font-size: 0.78em; margin-top: 0.15rem;">id <code><?= $h($t->id) ?></code></div>
            </td>
            <td><?= $statusBadge($t->status) ?></td>
            <td><?= $accountsCount > 0 ? (int) $accountsCount : '—' ?></td>
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
          (for example, one per reseller brand) and the existing tokens
          don&rsquo;t cover all of them.
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

<!-- ─── Advanced (collapsed by default, re-opens after a save attempt) ─── -->
<div class="card">
  <details class="advanced" <?= ($adminVm['saved'] || $adminVm['errors'] !== []) ? 'open' : '' ?>>
    <summary>Advanced settings</summary>

    <?php /* Save feedback is already shown at the top of the page; we
            keep nothing inline so the user doesn't see the same message
            twice when the disclosure is open. */ ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="form" value="admin">
      <input type="hidden" name="csrf" value="<?= $h($adminVm['csrf']) ?>">

      <fieldset>
        <legend>Defaults</legend>
        <p><label><input type="checkbox" name="defaults_proxied" <?= $adminVm['defaults_proxied'] ? 'checked' : '' ?>>
          Default to proxied A/AAAA/CNAME records when users enable sync</label></p>

        <p style="margin-top: 0.75rem;">
          <label><input type="checkbox" name="auto_ttl" id="zm-auto-ttl" <?= $adminVm['auto_ttl'] ? 'checked' : '' ?>>
            Use Cloudflare <strong>Auto</strong> TTL on every record (recommended)</label>
          <span class="muted" style="font-size: 0.85em; display: block; margin-left: 1.65rem;">
            Cloudflare picks the right TTL based on whether the record is proxied or not.
            cPanel zone files usually carry stale 14400-second TTLs that nobody curated;
            this collapses them all to Auto.
          </span>
        </p>

        <p id="zm-manual-ttl-row" style="margin-left: 1.65rem; <?= $adminVm['auto_ttl'] ? 'display:none;' : '' ?>">
          <label>Manual TTL (seconds, min 60):
            <input type="number" min="60" name="default_ttl" value="<?= (int) $adminVm['default_ttl'] ?>"></label>
          <span class="muted" style="font-size: 0.85em;">
            Only used when the record being pushed has no TTL of its own.
          </span>
        </p>
        <script>
          (function(){
            var cb = document.getElementById('zm-auto-ttl');
            var row = document.getElementById('zm-manual-ttl-row');
            if (cb && row) {
              cb.addEventListener('change', function(){
                row.style.display = cb.checked ? 'none' : '';
              });
            }
          })();
        </script>
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

      <fieldset>
        <legend>Email DNS normalisation</legend>
        <p class="muted" style="margin: 0 0 1rem;">
          Applied to every domain&rsquo;s diff before it is shown to the
          user. The local cPanel zone file is never modified — these
          transforms only affect what gets pushed to Cloudflare.
          Use <code>{domain}</code> as a placeholder for the zone name.
        </p>

        <!-- ─── DMARC builder ─── -->
        <h4 style="margin: 0.5rem 0 0.4rem; font-size: 1em;">DMARC reporting</h4>
        <p class="muted" style="font-size: 0.88em; margin: 0 0 0.6rem;">
          cPanel ships a placeholder <code>_dmarc</code> TXT with no
          <code>rua</code>/<code>ruf</code>, so failure reports never reach
          anyone. Use the builder below to centralise them on your sysadmin
          inbox. <a href="https://dmarc.org/" target="_blank" rel="noopener">What&rsquo;s DMARC?</a>
        </p>

        <p>
          <label>
            <input type="checkbox" name="dmarc_enable" id="zm-dmarc-enable"
                   <?= $adminVm['dmarc']['enabled'] ? 'checked' : '' ?>>
            Override <code>_dmarc</code> on every domain
          </label>
        </p>

        <div id="zm-dmarc-options" style="margin-left: 1.6rem; <?= $adminVm['dmarc']['enabled'] ? '' : 'display:none;' ?>">
          <p style="margin-top: 0.3rem;">
            <span style="font-weight: 500;">Policy (<code>p=</code>):</span><br>
            <label style="display:inline-block; margin-right: 1rem; font-weight: normal;">
              <input type="radio" name="dmarc_policy" value="none"
                     <?= $adminVm['dmarc']['policy'] === 'none' ? 'checked' : '' ?>>
              <code>none</code> &mdash; monitor only
            </label>
            <label style="display:inline-block; margin-right: 1rem; font-weight: normal;">
              <input type="radio" name="dmarc_policy" value="quarantine"
                     <?= $adminVm['dmarc']['policy'] === 'quarantine' ? 'checked' : '' ?>>
              <code>quarantine</code> &mdash; send to spam
            </label>
            <label style="display:inline-block; font-weight: normal;">
              <input type="radio" name="dmarc_policy" value="reject"
                     <?= $adminVm['dmarc']['policy'] === 'reject' ? 'checked' : '' ?>>
              <code>reject</code> &mdash; drop
            </label>
            <span class="muted" style="font-size: 0.85em; display: block; margin-top: 0.25rem;">
              Start with <code>none</code>. Monitor incoming reports for a
              week or two, fix any legitimate senders that are failing
              SPF/DKIM, then tighten to <code>quarantine</code> or
              <code>reject</code>. Going straight to <code>reject</code>
              on a domain that&rsquo;s never been audited will silently
              drop legitimate mail.
            </span>
          </p>

          <p>
            <label>Reports go to:
              <input type="text" name="dmarc_email"
                     value="<?= $h($adminVm['dmarc']['email']) ?>"
                     placeholder="sysadmin@your-host.tld"
                     style="font-family: ui-monospace, monospace;">
            </label>
            <span style="display:inline-block; margin-top: 0.3rem;">
              <label style="display:inline-block; margin-right: 1rem; font-weight: normal;">
                <input type="checkbox" name="dmarc_rua"
                       <?= $adminVm['dmarc']['rua'] ? 'checked' : '' ?>>
                Aggregate (<code>rua</code>) &mdash; daily summary
              </label>
              <label style="display:inline-block; font-weight: normal;">
                <input type="checkbox" name="dmarc_ruf"
                       <?= $adminVm['dmarc']['ruf'] ? 'checked' : '' ?>>
                Forensic (<code>ruf</code>) &mdash; per-message
              </label>
            </span>
            <span class="muted" style="font-size: 0.85em; display: block; margin-top: 0.25rem;">
              Most providers (Google, Microsoft, Yahoo) only send <code>rua</code>;
              <code>ruf</code> is rare in practice and noisy when it does fire.
            </span>
          </p>

          <p>
            <label>Subdomain policy (<code>sp=</code>) <small style="color:#888">optional</small>
              <select name="dmarc_sp">
                <option value=""           <?= $adminVm['dmarc']['sp'] === '' ? 'selected' : '' ?>>Same as policy above</option>
                <option value="none"       <?= $adminVm['dmarc']['sp'] === 'none' ? 'selected' : '' ?>>none</option>
                <option value="quarantine" <?= $adminVm['dmarc']['sp'] === 'quarantine' ? 'selected' : '' ?>>quarantine</option>
                <option value="reject"     <?= $adminVm['dmarc']['sp'] === 'reject' ? 'selected' : '' ?>>reject</option>
              </select>
            </label>
            <span class="muted" style="font-size: 0.85em; display: block; margin-top: 0.25rem;">
              If you don&rsquo;t send mail from <em>subdomains</em>, locking these to
              <code>reject</code> while the main policy stays at <code>none</code>
              is a safe way to stop subdomain-spoofing without risking your real mail.
            </span>
          </p>

          <p>
            <label>Percentage (<code>pct=</code>) <small style="color:#888">optional, 1&ndash;99</small>
              <input type="number" name="dmarc_pct" min="1" max="99"
                     value="<?= $adminVm['dmarc']['pct'] !== null ? (int) $adminVm['dmarc']['pct'] : '' ?>"
                     placeholder="100">
            </label>
            <span class="muted" style="font-size: 0.85em; display: block; margin-top: 0.25rem;">
              Only enforce the policy on this share of failing messages.
              Useful for a staged rollout (e.g. <code>pct=10</code> first).
              Leave blank for 100%.
            </span>
          </p>

          <p>
            <label>Or paste a complete record (advanced):
              <input type="text" name="dmarc_custom"
                     value="<?= $h($adminVm['dmarc']['custom']) ?>"
                     placeholder="v=DMARC1; p=reject; rua=mailto:...; adkim=s; aspf=s; fo=1"
                     style="font-family: ui-monospace, monospace;">
            </label>
            <span class="muted" style="font-size: 0.85em; display: block; margin-top: 0.25rem;">
              If set, overrides everything above. Use this for tags the
              builder doesn&rsquo;t expose (<code>adkim</code>, <code>aspf</code>,
              <code>fo</code>, <code>rf</code>, <code>ri</code>).
            </span>
          </p>

          <p style="background:#f6f8fa; border:1px solid #e1e4e8; border-radius:4px; padding:0.5rem 0.7rem; font-size:0.85em;">
            <strong>Resulting record:</strong>
            <code id="zm-dmarc-preview" style="display: block; margin-top: 0.3rem; word-break: break-all;">
              <?= $h($adminVm['dmarc_template'] !== '' ? $adminVm['dmarc_template'] : '(disabled — cPanel placeholder will be left alone)') ?>
            </code>
          </p>
        </div>

        <!-- ─── SPF builder ─── -->
        <h4 style="margin: 1.2rem 0 0.4rem; font-size: 1em;">SPF extras</h4>
        <p class="muted" style="font-size: 0.88em; margin: 0 0 0.6rem;">
          cPanel&rsquo;s <code>v=spf1</code> mentions the server&rsquo;s IPv4 plus the
          local A/MX records but leaves out the IPv6 and any third-party senders.
          Tick the ones you actually use; each becomes one mechanism spliced into
          the SPF before the terminal <code>~all</code>/<code>-all</code>.
          Duplicates are skipped, so re-applying is safe.
        </p>

        <p style="margin-bottom: 0.3rem;"><strong>Server presets</strong></p>
        <?php foreach (['a_mail', 'server_ipv6'] as $slug):
            $opt = $adminVm['spf_preset_options'][$slug];
            if ($slug === 'server_ipv6' && $adminVm['server_ipv6'] === '') continue;
            $mech = $opt['mechanism'];
            if ($slug === 'server_ipv6') $mech = str_replace('{server_ipv6}', $adminVm['server_ipv6'], $mech);
            ?>
          <p style="margin: 0.15rem 0;">
            <label style="font-weight: normal;">
              <input type="checkbox" name="spf_preset[]" value="<?= $h($slug) ?>"
                     <?= in_array($slug, $adminVm['spf_presets'], true) ? 'checked' : '' ?>>
              <code><?= $h($mech) ?></code> &mdash; <?= $h($opt['label']) ?>
            </label>
          </p>
        <?php endforeach; ?>

        <p style="margin: 0.8rem 0 0.3rem;"><strong>Third-party senders</strong>
          <span class="muted" style="font-weight: normal; font-size: 0.85em;">
            (tick only the ones you actually use; every extra <code>include</code>
            counts against the SPF 10-lookup limit)
          </span>
        </p>
        <?php foreach (['google', 'outlook', 'mailgun', 'sendgrid', 'mailjet', 'amazon_ses', 'salesforce', 'mailchimp', 'zoho'] as $slug):
            $opt = $adminVm['spf_preset_options'][$slug] ?? null;
            if ($opt === null) continue;
            ?>
          <p style="margin: 0.15rem 0;">
            <label style="font-weight: normal;">
              <input type="checkbox" name="spf_preset[]" value="<?= $h($slug) ?>"
                     <?= in_array($slug, $adminVm['spf_presets'], true) ? 'checked' : '' ?>>
              <code><?= $h($opt['mechanism']) ?></code> &mdash; <?= $h($opt['label']) ?>
            </label>
          </p>
        <?php endforeach; ?>

        <p style="margin-top: 0.8rem;">
          <label>Custom mechanisms (one per line)
            <textarea name="spf_custom" rows="3" style="font-family: ui-monospace, monospace; font-size: 0.9em;"
              placeholder="+a:newsletters.example.com&#10;+ip4:198.51.100.7"><?= $h($adminVm['spf_custom']) ?></textarea>
          </label>
          <span class="muted" style="font-size: 0.85em;">
            Anything that doesn&rsquo;t fit a preset above. Use the explicit
            qualifier (<code>+</code>, <code>-</code>, <code>~</code>, <code>?</code>).
          </span>
        </p>

        <p style="background:#f6f8fa; border:1px solid #e1e4e8; border-radius:4px; padding:0.5rem 0.7rem; font-size:0.85em;">
          <strong>Tokens that will be injected:</strong>
          <code style="display: block; margin-top: 0.3rem; word-break: break-all;">
            <?= $adminVm['spf_extras'] !== '' ? $h(implode(' ', preg_split('/\R+/', $adminVm['spf_extras']) ?: [])) : '<span style="color:#888">(none)</span>' ?>
          </code>
        </p>

        <script>
          (function(){
            var cb = document.getElementById('zm-dmarc-enable');
            var block = document.getElementById('zm-dmarc-options');
            if (cb && block) {
              cb.addEventListener('change', function(){
                block.style.display = cb.checked ? '' : 'none';
              });
            }
          })();
        </script>
      </fieldset>

      <button type="submit">Save</button>
    </form>

    <?php if ($serverIp !== ''): ?>
      <details style="margin-top: 1.5rem;">
        <summary style="cursor: pointer; font-weight: 500;">Hardening — restrict tokens to this server&rsquo;s IP</summary>
        <div class="ip-hint" style="margin-top: 0.75rem;">
          <div class="ip-hint-label">This server&rsquo;s outbound IP:</div>
          <div class="ip-hint-value"><code><?= $h($serverIp) ?></code></div>
          <div class="muted" style="margin-top: 0.4rem;">
            Cloudflare does not let us pre-fill this from the connect link.
            To add it after the fact: Cloudflare dashboard &rarr; <em>My Profile</em>
            &rarr; <em>API Tokens</em> &rarr; edit the token &rarr; <strong>Client
            IP Address Filtering</strong> &rarr; <em>Is in</em> =
            <code><?= $h($serverIp) ?></code>. Optional, but recommended.
          </div>
        </div>
      </details>
    <?php endif; ?>

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
