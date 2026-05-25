<?php

declare(strict_types=1);

/**
 * cPanel LiveAPI entry point. Rendered inside cPanel's Jupiter theme via the
 * cpanelplugin manifest. Runs as the cPanel user; relies on $ENV{REMOTE_USER}
 * (cPanel) or posix_geteuid() to identify the caller.
 */

use ZoneMirror\Interface\Ui\UserController;

// cPanel LIVEAPI handshake. The CPANEL wrapper reads the session token from
// STDIN and writes the acknowledgement, populating $_ENV['REMOTE_USER'] and
// the rest of the LIVEAPI environment. Without this (or an equivalent raw
// handshake) cpsrvd dies with "Child failed to make LIVEAPI connection to
// cPanel". The wrapper handles SAPI differences (STDIN is not defined as a
// constant under cpsrvd's PHP); a raw fgets(STDIN) crashes there.
// https://api.docs.cpanel.net/guides/liveapi/getting-started/
include '/usr/local/cpanel/php/cpanel.php';
$cpanel = new CPANEL();

$autoload = '/usr/local/cpanel/3rdparty/zonemirror/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo 'Plugin not installed correctly: missing vendor/autoload.php';
    exit;
}
require $autoload;

$user = (string) ($_ENV['REMOTE_USER'] ?? ($_SERVER['REMOTE_USER'] ?? ''));
if ($user === '') {
    $pw = posix_getpwuid(posix_geteuid());
    $user = is_array($pw) ? (string) ($pw['name'] ?? '') : '';
}

$controller = new UserController();
$vm = $controller->handle($user, $_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST);

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ZoneMirror</title>
  <meta http-equiv="Content-Security-Policy"
        content="default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; img-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'self'">
  <link rel="stylesheet" href="/cPanel_magic_revision_0/unprotected/cjt/css/cjt.css">
  <style>
    .zonemirror-card { max-width: 720px; margin: 1.5rem auto; padding: 1rem 1.25rem; border: 1px solid #ddd; border-radius: 6px; }
    .zonemirror-card h2 { margin-top: 0; }
    .zonemirror-row { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; margin: 0.5rem 0; }
    .zonemirror-row label { flex: 1 1 240px; }
    .zonemirror-error { color: #b00; }
    .zonemirror-ok { color: #060; }
    .zonemirror-muted { color: #666; font-size: 0.9em; }
    input[type=text], input[type=password] { width: 100%; padding: 0.4rem 0.5rem; }
    .zonemirror-actions { display: flex; gap: 0.5rem; margin-top: 1rem; }
  </style>
</head>
<body>
<div class="zonemirror-card">
  <h2>ZoneMirror</h2>

  <?php if (!$vm['allowed']): ?>
    <p class="zonemirror-error">This plugin is not enabled for your account by the server administrator.</p>
  <?php endif; ?>

  <?php if ($vm['saved']): ?>
    <p class="zonemirror-ok">Settings saved.</p>
  <?php endif; ?>

  <?php foreach ($vm['errors'] as $err): ?>
    <p class="zonemirror-error"><?= $h($err) ?></p>
  <?php endforeach; ?>

  <?php if ($vm['test_result'] !== null): ?>
    <p><?= $h($vm['test_result']) ?></p>
  <?php endif; ?>

  <?php if ($vm['allowed']): ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">

      <fieldset>
        <legend>Connection</legend>
        <div class="zonemirror-row">
          <label>Cloudflare API Token (leave blank to keep current)
            <input type="password" name="token" autocomplete="new-password"
                   placeholder="<?= $vm['token_set'] ? '••••••••' : 'cf-XXXX...' ?>">
          </label>
        </div>
        <div class="zonemirror-row">
          <label>Zone (domain)
            <input type="text" name="zone_name" value="<?= $h($vm['zone_name']) ?>" placeholder="example.com">
          </label>
        </div>
        <div class="zonemirror-row">
          <button type="submit" name="action" value="test">Test connection</button>
        </div>
      </fieldset>

      <fieldset>
        <legend>Defaults</legend>
        <div class="zonemirror-row">
          <label>
            <input type="checkbox" name="defaults_proxied" <?= $vm['defaults_proxied'] ? 'checked' : '' ?>>
            Proxy A / AAAA / CNAME records by default
          </label>
        </div>
        <p class="zonemirror-muted">_acme-challenge and _dmarc records are never proxied.</p>
      </fieldset>

      <fieldset>
        <legend>Sync</legend>
        <div class="zonemirror-row">
          <label>
            <input type="checkbox" name="enabled" <?= $vm['enabled'] ? 'checked' : '' ?>>
            Enable real-time sync to Cloudflare
          </label>
        </div>
      </fieldset>

      <div class="zonemirror-actions">
        <button type="submit" name="action" value="save">Save</button>
      </div>
    </form>

    <h3>Queue</h3>
    <p>Pending: <strong><?= (int) $vm['queue_depth'] ?></strong> &middot;
       Dead-lettered: <strong><?= (int) $vm['dead_letters'] ?></strong></p>
    <p class="zonemirror-muted">User: <?= $h($vm['user']) ?></p>
  <?php endif; ?>
</div>
</body>
</html>
<?php $cpanel->end(); ?>
