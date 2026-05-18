<?php

declare(strict_types=1);

/**
 * WHM admin LiveAPI entry. Runs as root via WHM's frontend. Lets the host
 * administrator define global defaults and an allowlist of users.
 */

use CfSync\Interface\Ui\AdminController;

$autoload = '/usr/local/cpanel/3rdparty/cloudflare-dns-sync/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo 'Plugin not installed correctly: missing vendor/autoload.php';
    exit;
}
require $autoload;

$controller = new AdminController();
$vm = $controller->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST);

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cloudflare DNS Sync — Admin</title>
  <meta http-equiv="Content-Security-Policy"
        content="default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; img-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'self'">
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; }
    .card { max-width: 820px; margin: 1.5rem auto; padding: 1rem 1.25rem; border: 1px solid #ddd; border-radius: 6px; }
    .err { color: #b00; }
    .ok { color: #060; }
    fieldset { margin-bottom: 1rem; }
    textarea { width: 100%; min-height: 120px; font-family: monospace; }
    input[type=number] { width: 6em; }
  </style>
</head>
<body>
<div class="card">
  <h2>Cloudflare DNS Sync — Administration</h2>
  <?php if ($vm['saved']): ?>
    <p class="ok">Settings saved.</p>
  <?php endif; ?>
  <?php foreach ($vm['errors'] as $err): ?>
    <p class="err"><?= $h($err) ?></p>
  <?php endforeach; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">

    <fieldset>
      <legend>Defaults</legend>
      <p><label><input type="checkbox" name="defaults_proxied" <?= $vm['defaults_proxied'] ? 'checked' : '' ?>>
        Default to proxied A/AAAA/CNAME records when users enable sync</label></p>
      <p><label>Default TTL (seconds, min 60):
        <input type="number" min="60" name="default_ttl" value="<?= (int) $vm['default_ttl'] ?>"></label></p>
    </fieldset>

    <fieldset>
      <legend>Who may use this plugin</legend>
      <p>
        <label><input type="radio" name="allowed_users_mode" value="all" <?= $vm['allowed_users_mode'] === 'all' ? 'checked' : '' ?>>
          All cPanel users</label>
      </p>
      <p>
        <label><input type="radio" name="allowed_users_mode" value="list" <?= $vm['allowed_users_mode'] === 'list' ? 'checked' : '' ?>>
          Only the users listed below (one per line)</label>
      </p>
      <textarea name="allowed_users_list" placeholder="alice&#10;bob"><?= $h($vm['allowed_users_list']) ?></textarea>
    </fieldset>

    <fieldset>
      <legend>Safety</legend>
      <p><label>Cloudflare API rate-limit budget (requests/second, 1-50):
        <input type="number" min="1" max="50" name="rate_limit_rps" value="<?= (int) $vm['rate_limit_rps'] ?>"></label></p>
      <p><label><input type="checkbox" name="dry_run" <?= $vm['dry_run'] ? 'checked' : '' ?>>
        Dry-run mode (log intended changes; do not call Cloudflare)</label></p>
    </fieldset>

    <button type="submit">Save defaults</button>
  </form>

  <h3>Enrolled users</h3>
  <?php if ($vm['enrolled'] === []): ?>
    <p>No users have enabled sync yet.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($vm['enrolled'] as $u): ?>
        <li><?= $h($u) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
</body>
</html>
