<?php

declare(strict_types=1);

/**
 * cPanel UI entry point for ZoneMirror.
 *
 * The page is rendered inside Jupiter's chrome via $cpanel->header()/
 * footer(). The layout follows the user's lifecycle:
 *
 *  1. No domain connected yet → show the connect wizard: one row per
 *     cPanel domain with a Connect button for the ones an admin token
 *     covers, plus an "Advanced" disclosure for the user-pasted token
 *     path.
 *  2. Just connected, diff being computed → "Computing diff with
 *     Cloudflare…" with a soft auto-refresh so the user lands on the
 *     review screen without manually reloading.
 *  3. Diff ready for review → the wizard's review step: per-record
 *     table with cPanel-vs-Cloudflare comparison, per-row checkboxes,
 *     and bulk actions for "apply all differences" / "apply missing in
 *     CF" / "delete CF-only".
 *  4. Diff applied (idle) → settings view: connected-domain header
 *     with Refresh and Disconnect, plus the available-domains list to
 *     connect more.
 *  5. Diff computation failed → inline error + Retry.
 */

use ZoneMirror\Domain\DnsDiff;
use ZoneMirror\Infrastructure\Storage\UserConfigStorage;
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

// Quietly auto-refresh on the transient states so the user doesn't have
// to mash F5 while the daemon does its work. 4s is enough to let one
// full cycle finish on a quiet server without spamming the page if the
// daemon is wedged.
$autoRefresh = in_array(
    $vm['sync_state'] ?? '',
    [UserConfigStorage::STATE_PENDING_DIFF, UserConfigStorage::STATE_COMPUTING_DIFF],
    true,
);
if ($autoRefresh) {
    echo "<meta http-equiv=\"refresh\" content=\"4\">\n";
}
?>

<style>
  .zonemirror-wrap { max-width: 980px; }

  /* connected-domain banner */
  .zm-banner {
    display: flex; gap: 1rem; align-items: center; justify-content: space-between;
    border: 1px solid #d1e3f8; background: #f1f7fd; border-radius: 6px;
    padding: 0.85rem 1.1rem; margin: 0 0 1rem 0;
  }
  .zm-banner .name { font-weight: 600; font-size: 1.05em; }
  .zm-banner .meta { color: #555; font-size: 0.9em; margin-top: 0.15rem; }
  .zm-banner form { display: inline-block; margin: 0 0 0 0.4rem; }

  /* available-domains list */
  .zm-domains { border: 1px solid #e5e5e5; border-radius: 6px; overflow: hidden; margin-top: 1rem; }
  .zm-domain { display: flex; align-items: center; justify-content: space-between; padding: 0.9rem 1rem; border-bottom: 1px solid #eee; gap: 1rem; }
  .zm-domain:last-child { border-bottom: 0; }
  .zm-domain .name { font-weight: 600; word-break: break-all; }
  .zm-domain .meta { font-size: 0.85em; color: #888; margin-top: 0.15rem; }
  .zm-empty { padding: 1.5rem; text-align: center; color: #777; }

  /* status pills */
  .zm-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 0.8em; font-weight: 500; }
  .zm-pill.ok      { background: #e6f7ec; color: #0a7d2e; }
  .zm-pill.avail   { background: #eaf2fb; color: #1f5fa6; }
  .zm-pill.unavail { background: #f0f0f0; color: #777; }
  .zm-pill.warn    { background: #fff7d6; color: #7a5b00; }
  .zm-pill.danger  { background: #fbe6e6; color: #a02020; }

  /* diff table */
  .zm-summary { display: flex; gap: 0.75rem; margin: 1rem 0 0.75rem; flex-wrap: wrap; }
  .zm-summary .card {
    flex: 1 1 140px; min-width: 130px;
    border: 1px solid #e5e5e5; border-radius: 6px; padding: 0.6rem 0.85rem;
    background: #fff;
  }
  .zm-summary .card .label { font-size: 0.78em; text-transform: uppercase; letter-spacing: 0.04em; color: #777; }
  .zm-summary .card .value { font-size: 1.6em; font-weight: 600; line-height: 1.1; margin-top: 0.2rem; }
  .zm-summary .card.diff   { border-color: #fbd97a; background: #fffaeb; }
  .zm-summary .card.miss-l { border-color: #c2d4e8; background: #f1f7fd; }
  .zm-summary .card.miss-r { border-color: #e0c0c0; background: #fdf3f3; }

  .zm-actions-bar {
    display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;
    border: 1px solid #e5e5e5; border-radius: 6px; padding: 0.65rem 0.85rem;
    background: #fafafa; margin: 0.75rem 0;
  }
  .zm-actions-bar form { display: inline-block; margin: 0; }
  .zm-actions-bar .spacer { flex: 1; }
  .zm-actions-bar small { color: #666; }

  table.zm-diff { width: 100%; border-collapse: collapse; font-size: 0.92em; }
  table.zm-diff th, table.zm-diff td { text-align: left; padding: 0.5rem 0.7rem; border-bottom: 1px solid #eee; vertical-align: top; }
  table.zm-diff th { font-size: 0.75em; text-transform: uppercase; color: #666; background: #fafbfc; letter-spacing: 0.04em; }
  table.zm-diff td.name { word-break: break-all; max-width: 260px; }
  table.zm-diff td.val  { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.86em; word-break: break-all; max-width: 280px; }
  table.zm-diff tr.identical td { color: #999; }
  table.zm-diff tr.different .val,
  table.zm-diff tr.cpanel_only .val.cp,
  table.zm-diff tr.cloudflare_only .val.cf { background: #fffaeb; }
  table.zm-diff input[type=checkbox] { transform: scale(1.1); }

  /* wizard heading */
  .zm-step { color: #888; font-size: 0.8em; letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 0.25rem; }

  details.advanced { margin-top: 2rem; padding: 0.75rem 0; border-top: 1px solid #eee; }
  details.advanced > summary { cursor: pointer; color: #555; padding: 0.4rem 0; }
  .form-stack label { display: block; margin: 0.75rem 0 0.25rem; font-weight: 500; }
  .form-stack input { width: 100%; padding: 0.5rem 0.6rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
</style>

<div class="body-content zonemirror-wrap">
  <h2>ZoneMirror</h2>

  <?php if (!$vm['allowed']): ?>
    <div class="callout callout-warning">
      <strong>This plugin is not available for your account.</strong>
      Ask your hosting provider to enable it.
    </div>
    <?php
    print $cpanel->footer();
    $cpanel->end();
    exit;
    ?>
  <?php endif; ?>

  <?php if ($vm['message'] !== ''): ?>
    <div class="callout callout-success"><?= $h($vm['message']) ?></div>
  <?php endif; ?>
  <?php foreach ($vm['errors'] as $err): ?>
    <div class="callout callout-danger"><?= $h($err) ?></div>
  <?php endforeach; ?>
  <?php if ($vm['test_result'] !== null): ?>
    <div class="callout callout-info"><?= $h($vm['test_result']) ?></div>
  <?php endif; ?>

  <?php /* ─── Banner: connected-domain header + Refresh/Disconnect ─── */ ?>
  <?php if ($vm['enabled']): ?>
    <div class="zm-banner">
      <div>
        <div class="name"><?= $h($vm['zone_name']) ?></div>
        <div class="meta">
          <?php if ($vm['source'] === UserConfigStorage::SOURCE_ADMIN): ?>
            <span class="zm-pill ok">Connected</span>
            via your hosting provider&rsquo;s Cloudflare account.
          <?php else: ?>
            <span class="zm-pill ok">Connected</span>
            via your Cloudflare token.
          <?php endif; ?>
          &nbsp;Queue: <strong><?= (int) $vm['queue_depth'] ?></strong> pending,
          <strong><?= (int) $vm['dead_letters'] ?></strong> failed.
        </div>
      </div>
      <div class="zm-btn-row">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
          <input type="hidden" name="action" value="refresh_diff">
          <button type="submit" class="btn btn-default">Refresh diff</button>
        </form>
        <form method="post" onsubmit="return confirm('Stop syncing <?= $h($vm['zone_name']) ?> to Cloudflare?');">
          <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
          <input type="hidden" name="action" value="disconnect">
          <button type="submit" class="btn btn-default">Disconnect</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <?php /* ─── Wizard / state-dependent content ─── */ ?>
  <?php if ($vm['enabled'] && in_array($vm['sync_state'], [UserConfigStorage::STATE_PENDING_DIFF, UserConfigStorage::STATE_COMPUTING_DIFF], true)): ?>

    <div class="zm-step">Step 2 of 2 — Review</div>
    <h3 style="margin-top: 0.2rem;">Computing diff with Cloudflare…</h3>
    <p style="color:#666;">
      We&rsquo;re comparing your cPanel zone file against Cloudflare so you
      can pick what to sync. This page refreshes itself every few seconds.
    </p>

  <?php elseif ($vm['enabled'] && $vm['sync_state'] === UserConfigStorage::STATE_FAILED): ?>

    <div class="callout callout-danger" style="margin-top:1rem;">
      <strong>Diff failed.</strong>
      <?php if ($vm['last_error'] !== ''): ?>
        <div style="margin-top: 0.4rem; font-family: ui-monospace, monospace; font-size: 0.86em;">
          <?= $h($vm['last_error']) ?>
        </div>
      <?php endif; ?>
      <form method="post" style="margin-top: 0.6rem;">
        <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
        <input type="hidden" name="action" value="refresh_diff">
        <button type="submit" class="btn btn-primary">Retry</button>
      </form>
    </div>

  <?php elseif ($vm['enabled'] && $vm['sync_state'] === UserConfigStorage::STATE_AWAITING_REVIEW && is_array($vm['diff'])): ?>

    <?php
    $summary = is_array($vm['diff']['summary'] ?? null) ? $vm['diff']['summary'] : [];
    $entries = is_array($vm['diff']['entries'] ?? null) ? $vm['diff']['entries'] : [];
    $computedAt = isset($vm['diff']['computed_at']) ? (int) $vm['diff']['computed_at'] : 0;
    ?>

    <div class="zm-step">Step 2 of 2 — Review</div>
    <h3 style="margin-top: 0.2rem;">What&rsquo;s different on Cloudflare</h3>
    <p style="color:#666;">
      Pick exactly which changes to apply. Nothing is pushed to Cloudflare
      until you click <strong>Apply</strong>.
      <?php if ($computedAt > 0): ?>
        <span style="color:#aaa;">&nbsp;Computed <?= $h(gmdate('Y-m-d H:i \U\T\C', $computedAt)) ?>.</span>
      <?php endif; ?>
    </p>

    <div class="zm-summary">
      <div class="card">
        <div class="label">Identical</div>
        <div class="value"><?= (int) ($summary[DnsDiff::STATUS_IDENTICAL] ?? 0) ?></div>
      </div>
      <div class="card diff">
        <div class="label">Different</div>
        <div class="value"><?= (int) ($summary[DnsDiff::STATUS_DIFFERENT] ?? 0) ?></div>
      </div>
      <div class="card miss-l">
        <div class="label">Only in cPanel</div>
        <div class="value"><?= (int) ($summary[DnsDiff::STATUS_CPANEL_ONLY] ?? 0) ?></div>
      </div>
      <div class="card miss-r">
        <div class="label">Only on Cloudflare</div>
        <div class="value"><?= (int) ($summary[DnsDiff::STATUS_CLOUDFLARE_ONLY] ?? 0) ?></div>
      </div>
    </div>

    <form method="post" id="zm-diff-form">
      <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
      <input type="hidden" name="action" value="apply">

      <div class="zm-actions-bar">
        <button type="submit" class="btn btn-primary">Apply selected</button>
        <small>or in bulk:</small>
        <button type="submit" name="apply_status" value="different" class="btn btn-default">
          Push all <em>different</em>
        </button>
        <button type="submit" name="apply_status" value="cpanel_only" class="btn btn-default">
          Create all <em>missing in CF</em>
        </button>
        <button type="submit" name="apply_status" value="cloudflare_only" class="btn btn-default"
                onclick="return confirm('Delete every Cloudflare-only record this domain has? They will be removed from Cloudflare. cPanel is not affected.');">
          Delete all <em>CF-only</em>
        </button>
        <span class="spacer"></span>
        <button type="submit" name="apply_status" value="all" class="btn btn-default"
                onclick="return confirm('Push every difference and every missing record from cPanel to Cloudflare? (CF-only records are NOT deleted in this action.)');">
          Apply everything from cPanel
        </button>
      </div>

      <table class="zm-diff">
        <thead>
          <tr>
            <th style="width: 1px;">&nbsp;</th>
            <th style="width: 1px;">Status</th>
            <th style="width: 1px;">Type</th>
            <th>Name</th>
            <th>cPanel</th>
            <th>Cloudflare</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $e): if (!is_array($e)) continue;
            $status = (string) ($e['status'] ?? '');
            $key = (string) ($e['key'] ?? '');
            $type = (string) ($e['type'] ?? '');
            $name = (string) ($e['name'] ?? '');
            $localTxt = zm_format_record($e['local'] ?? null);
            $remoteTxt = zm_format_record($e['remote'] ?? null);
            $checkboxName = $status === DnsDiff::STATUS_CLOUDFLARE_ONLY ? 'delete_keys[]' : 'push_keys[]';
            $defaultChecked = false; // user decides — never pre-tick
          ?>
            <tr class="<?= $h($status) ?>">
              <td>
                <?php if ($status !== DnsDiff::STATUS_IDENTICAL): ?>
                  <input type="checkbox" name="<?= $h($checkboxName) ?>" value="<?= $h($key) ?>" <?= $defaultChecked ? 'checked' : '' ?>>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($status === DnsDiff::STATUS_IDENTICAL): ?>
                  <span class="zm-pill ok">match</span>
                <?php elseif ($status === DnsDiff::STATUS_DIFFERENT): ?>
                  <span class="zm-pill warn">diff</span>
                <?php elseif ($status === DnsDiff::STATUS_CPANEL_ONLY): ?>
                  <span class="zm-pill avail">cPanel only</span>
                <?php else: ?>
                  <span class="zm-pill danger">CF only</span>
                <?php endif; ?>
              </td>
              <td><strong><?= $h($type) ?></strong></td>
              <td class="name"><?= $h($name) ?></td>
              <td class="val cp"><?= $h($localTxt) ?></td>
              <td class="val cf"><?= $h($remoteTxt) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </form>

  <?php elseif ($vm['enabled'] && $vm['sync_state'] === UserConfigStorage::STATE_IDLE): ?>

    <div class="callout callout-success" style="margin-top:1rem;">
      <strong>All synced.</strong>
      Cloudflare matches cPanel for every record that&rsquo;s under our control.
      Future Zone-Editor edits propagate automatically.
    </div>

  <?php endif; ?>

  <?php /* ─── Wizard step 1 (no domain connected): pick a domain ─── */ ?>
  <?php if (!$vm['enabled']): ?>

    <div class="zm-step">Step 1 of 2 — Connect</div>
    <h3 style="margin-top: 0.2rem;">Pick a domain to sync to Cloudflare</h3>
    <p style="color:#666;">
      Choose any of your cPanel domains that your hosting provider has
      already linked to Cloudflare. After connecting we&rsquo;ll show you
      a side-by-side diff so you can decide what to sync.
    </p>

  <?php endif; ?>

  <?php /* ─── Domain list (always shown — also lets a connected user add more later) ─── */ ?>
  <?php if ($vm['domains'] !== [] && (!$vm['enabled'] || $vm['sync_state'] === UserConfigStorage::STATE_IDLE)): ?>
    <div class="zm-domains">
      <?php foreach ($vm['domains'] as $d): ?>
        <div class="zm-domain">
          <div>
            <div class="name"><?= $h($d['name']) ?></div>
            <?php if ($d['status'] === UserController::DOMAIN_CONNECTED_ADMIN): ?>
              <div class="meta"><span class="zm-pill ok">Connected</span> &nbsp;Syncing automatically.</div>
            <?php elseif ($d['status'] === UserController::DOMAIN_CONNECTED_USER): ?>
              <div class="meta"><span class="zm-pill ok">Connected</span> &nbsp;Using your own Cloudflare token.</div>
            <?php elseif ($d['status'] === UserController::DOMAIN_AVAILABLE): ?>
              <div class="meta"><span class="zm-pill avail">Available</span> &nbsp;Ready to connect with one click.</div>
            <?php else: ?>
              <div class="meta"><span class="zm-pill unavail">Not available</span> &nbsp;Not in any Cloudflare account this server can reach.</div>
            <?php endif; ?>
          </div>

          <div class="actions zm-btn-row">
            <?php if ($d['is_current']): ?>
              <span class="meta" style="color:#888;">&nbsp;</span>
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
  <?php endif; ?>

  <?php /* ─── Advanced (user-pasted token, optional) ─── */ ?>
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
</div>

<?php
print $cpanel->footer();
$cpanel->end();


// ─── helpers ─────────────────────────────────────────────────────────────

/**
 * Render a record payload (either the `local` block from a diff entry, or
 * the `remote` Cloudflare row) as a single human-readable string the diff
 * table cell can display. Designed for skimmability, not round-tripping:
 * we surface the data the user needs to decide ("is the content the same?
 * is proxied flipped?") without dumping the whole JSON.
 */
function zm_format_record(mixed $payload): string
{
    if (!is_array($payload)) {
        return '—';
    }
    $type = strtoupper((string) ($payload['type'] ?? ''));
    if ($type === 'SRV') {
        $d = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        return sprintf(
            '%d %d %d %s',
            (int) ($d['priority'] ?? 0),
            (int) ($d['weight'] ?? 0),
            (int) ($d['port'] ?? 0),
            (string) ($d['target'] ?? ''),
        );
    }
    if ($type === 'CAA') {
        $d = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        return sprintf(
            '%d %s "%s"',
            (int) ($d['flags'] ?? 0),
            (string) ($d['tag'] ?? ''),
            (string) ($d['value'] ?? ''),
        );
    }
    $bits = [];
    if (isset($payload['content']) && $payload['content'] !== null && $payload['content'] !== '') {
        $bits[] = (string) $payload['content'];
    }
    if ($type === 'MX' && isset($payload['priority'])) {
        array_unshift($bits, (string) $payload['priority']);
    }
    if (array_key_exists('proxied', $payload) && $payload['proxied'] !== null) {
        $bits[] = $payload['proxied'] ? '[proxied]' : '[dns-only]';
    }

    return $bits === [] ? '—' : implode(' ', $bits);
}

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
