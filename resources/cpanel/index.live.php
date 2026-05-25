<?php

declare(strict_types=1);

/**
 * cPanel LiveAPI entry point. Rendered inside cPanel's Jupiter theme via the
 * cpanelplugin manifest. Runs as the cPanel user; relies on $ENV{REMOTE_USER}
 * (cPanel) or posix_geteuid() to identify the caller.
 *
 * The page is rendered INSIDE cPanel's theme chrome (sidebar, breadcrumb,
 * branding) via $cpanel->header()/footer(). We deliberately do NOT emit our
 * own <html> document — that produced an unstyled, naked page outside the
 * cPanel UI and confused users into thinking the plugin was broken.
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

$controller = new UserController();
$vm = $controller->handle($user, $_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST);

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

print $cpanel->header('ZoneMirror');
?>

<style>
  /* Minimal layout polish; theme provides the rest. */
  .zonemirror-wrap { max-width: 760px; }
  .zonemirror-wrap fieldset { margin-bottom: 1.25rem; padding: 0.75rem 1rem; border: 1px solid #e5e5e5; border-radius: 4px; }
  .zonemirror-wrap fieldset legend { padding: 0 0.5rem; font-weight: 600; }
  .zonemirror-wrap .form-group { margin-bottom: 0.75rem; }
  .zonemirror-meta { color: #666; font-size: 0.9em; }
</style>

<div class="body-content zonemirror-wrap">
  <h2>ZoneMirror <small class="text-muted">— sync DNS to Cloudflare in real time</small></h2>
  <p>Push every change you make in <em>Zone Editor</em> to your Cloudflare zone, automatically.</p>

  <?php if (!$vm['allowed']): ?>
    <div class="callout callout-warning">
      <strong>Not enabled.</strong> This plugin has not been enabled for your account by the server administrator.
    </div>
  <?php endif; ?>

  <?php if ($vm['saved']): ?>
    <div class="callout callout-success">Settings saved.</div>
  <?php endif; ?>

  <?php foreach ($vm['errors'] as $err): ?>
    <div class="callout callout-danger"><?= $h($err) ?></div>
  <?php endforeach; ?>

  <?php if ($vm['test_result'] !== null): ?>
    <div class="callout callout-info"><?= $h($vm['test_result']) ?></div>
  <?php endif; ?>

  <?php if ($vm['allowed']): ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">

      <fieldset>
        <legend>Connection</legend>

        <div class="form-group">
          <label for="zm-token">Cloudflare API Token <small class="text-muted">(leave blank to keep current)</small></label>
          <input id="zm-token" class="form-control" type="password" name="token" autocomplete="new-password"
                 placeholder="<?= $vm['token_set'] ? '••••••••' : 'cf-XXXX...' ?>">
        </div>

        <div class="form-group">
          <label for="zm-zone">Zone (domain)</label>
          <input id="zm-zone" class="form-control" type="text" name="zone_name"
                 value="<?= $h($vm['zone_name']) ?>" placeholder="example.com">
        </div>

        <button type="submit" name="action" value="test" class="btn btn-default">Test connection</button>
      </fieldset>

      <fieldset>
        <legend>Defaults</legend>
        <div class="checkbox">
          <label>
            <input type="checkbox" name="defaults_proxied" <?= $vm['defaults_proxied'] ? 'checked' : '' ?>>
            Proxy A / AAAA / CNAME records by default
          </label>
        </div>
        <p class="help-block">_acme-challenge and _dmarc records are never proxied, regardless of this setting.</p>
      </fieldset>

      <fieldset>
        <legend>Sync</legend>
        <div class="checkbox">
          <label>
            <input type="checkbox" name="enabled" <?= $vm['enabled'] ? 'checked' : '' ?>>
            Enable real-time sync to Cloudflare
          </label>
        </div>
      </fieldset>

      <button type="submit" name="action" value="save" class="btn btn-primary">Save</button>
    </form>

    <hr>

    <h3>Queue</h3>
    <p>
      Pending: <strong><?= (int) $vm['queue_depth'] ?></strong>
      &middot;
      Dead-lettered: <strong><?= (int) $vm['dead_letters'] ?></strong>
    </p>
    <p class="zonemirror-meta">User: <code><?= $h($vm['user']) ?></code></p>
  <?php endif; ?>
</div>

<?php
print $cpanel->footer();
$cpanel->end();
