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

// Private-Use Area sentinels for the Cloudflare-style cloud icons. Top-
// level `const` is not hoisted like function declarations are, so they
// have to be declared before the template (which calls helpers that
// reference them) executes. See zm_cloud_swap() at the bottom of file.
const ZM_PROXIED_MARK = "\u{F8E1}";
const ZM_DNSONLY_MARK = "\u{F8E2}";

include '/usr/local/cpanel/php/cpanel.php';
$cpanel = new CPANEL();

$autoload = '/usr/local/cpanel/3rdparty/zonemirror/vendor/autoload.php';
if (!is_file($autoload)) {
    print $cpanel->header('ZoneMirror');
    echo '<div class="body-content"><div class="alert alert-danger" role="alert">'
        . '<span class="glyphicon glyphicon-remove-sign" aria-hidden="true"></span>'
        . '<div class="alert-message"><strong class="alert-title">Plugin not installed correctly.</strong> '
        . '<span class="alert-body">Missing vendor/autoload.php — re-run packaging/install.sh as root.</span>'
        . '</div></div></div>';
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

// JSON read-only endpoint used by the live progress poll. Short-circuits
// before any cPanel chrome is written so the response is pure JSON. The
// cPanel session itself guards REMOTE_USER, so no extra CSRF is needed
// for a read that only ever sees the calling user's own queue.
if (($_GET['action'] ?? '') === 'queue_status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($controller->queueStatus($user));
    exit;
}

$vm = $controller->handle(
    $user,
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_POST,
    $allDomains,
);

// AJAX POST contract: the new in-page Apply/Refresh flow sends an
// X-Requested-With header so we know to return a compact JSON envelope
// instead of re-rendering the whole page. Falls back to the classic
// full-page POST when the header is absent (no-JS, or a hard reload).
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strcasecmp((string) $_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;
if ($isAjax && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => $vm['errors'] === [],
        'errors' => $vm['errors'],
        'message' => $vm['message'],
        'sync_state' => $vm['sync_state'],
        'queue_depth' => $vm['queue_depth'],
        'dead_letters' => $vm['dead_letters'],
        'csrf' => $vm['csrf'],
        'apply' => $controller->lastApplyMeta(),
    ]);
    exit;
}

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

print $cpanel->header('ZoneMirror');

// No-JS fallback only. When JS is on, the page polls queue_status and
// patches the DOM inline; the meta-refresh would fight that by reloading
// out from under the user. We still want a sane fallback for the rare
// no-JS session, so emit the refresh inside <noscript>.
$autoRefresh = in_array(
    $vm['sync_state'] ?? '',
    [UserConfigStorage::STATE_PENDING_DIFF, UserConfigStorage::STATE_COMPUTING_DIFF],
    true,
) || (
    ($vm['sync_state'] ?? '') === UserConfigStorage::STATE_AWAITING_REVIEW
    && (int) $vm['queue_depth'] > 0
);
if ($autoRefresh) {
    echo "<noscript><meta http-equiv=\"refresh\" content=\"4\"></noscript>\n";
}
?>

<style>
  .zonemirror-wrap { max-width: none; }
  .zonemirror-wrap .narrow { max-width: 720px; }

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

  /* action pills (used by cards) */
  .zm-pill.create  { background: #d9f1e1; color: #0a5f1f; }
  .zm-pill.replace { background: #fff1c2; color: #7a5b00; }
  .zm-pill.delete  { background: #fbd5d5; color: #a02020; }
  .zm-pill.noop    { background: #f0f0f0; color: #999; }

  /* filter chips */
  .zm-filter { display: flex; gap: 0.4rem; margin: 0.75rem 0 0.4rem; flex-wrap: wrap; }
  .zm-filter button {
    background: #fff; border: 1px solid #d4d4d4; border-radius: 999px;
    padding: 0.3rem 0.85rem; font-size: 0.85em; cursor: pointer; color: #555;
  }
  .zm-filter button.active { background: #1f5fa6; border-color: #1f5fa6; color: #fff; }
  .zm-filter button:hover:not(.active) { background: #f5f5f5; }

  /* selection toolbar — link-style buttons so it reads as a row of
     actions, not a second set of filter chips */
  .zm-select-row {
    display: flex; gap: 0.4rem; margin: 0 0 1rem; flex-wrap: wrap;
    align-items: center; font-size: 0.85em; color: #777;
  }
  .zm-select-label { color: #777; margin-right: 0.2rem; }
  .zm-select-row .zm-link {
    background: none; border: 0; padding: 0.15rem 0.4rem; cursor: pointer;
    color: #1f5fa6; font-size: 0.92em; text-decoration: underline;
    text-underline-offset: 2px; font-family: inherit;
  }
  .zm-select-row .zm-link:hover { color: #154a85; }
  .zm-select-row .zm-link-muted { color: #999; }
  .zm-select-row .zm-link-muted:hover { color: #555; }
  .zm-select-sep { color: #d4d4d4; padding: 0 0.2rem; }

  /* PR-style diff cards */
  .zm-cards { display: flex; flex-direction: column; gap: 0.75rem; }
  .zm-card {
    border: 1px solid #d4d4d4; border-left-width: 4px; border-radius: 6px;
    background: #fff; overflow: hidden;
  }
  .zm-card.status-different     { border-left-color: #d9a700; }
  .zm-card.status-cpanel_only   { border-left-color: #1f8a3e; }
  .zm-card.status-cloudflare_only { border-left-color: #c53030; }
  .zm-card.status-identical     { border-left-color: #ccc; opacity: 0.75; }

  .zm-card-head {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.55rem 0.85rem; background: #fafbfc;
    user-select: none; border-bottom: 1px solid #eee;
    cursor: pointer;
  }
  .zm-card-head input[type=checkbox] { transform: scale(1.15); margin: 0; }
  .zm-card-head .zm-rtype { font-weight: 600; color: #444; font-size: 0.92em; }
  .zm-card-head .zm-rname {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.9em; word-break: break-all; flex: 1; color: #222;
  }

  .zm-card-body { padding: 0.6rem 0.85rem; }
  .zm-line {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.86em;
    padding: 0.35rem 0.6rem; border-radius: 4px; margin: 0.15rem 0;
    word-break: break-all; white-space: pre-wrap;
  }
  .zm-line-del { background: #fdf2f2; color: #842029; }
  .zm-line-add { background: #f0faf3; color: #0a5f1f; }
  .zm-line-noop { background: #fafafa; color: #555; }
  .zm-line ins { background: #b6ecc2; text-decoration: none; padding: 0 2px; border-radius: 2px; font-weight: 500; }
  .zm-line del { background: #ffc6c6; text-decoration: line-through; padding: 0 2px; border-radius: 2px; }

  /* Per-record proxy toggle. Shown inside the card body for any A/AAAA/CNAME
     record (no matter its diff status). Clicking it flips the proxied flag
     and turns the card into an override push — the card visually transitions
     from "No change" to a yellow Update with a pending mark. */
  .zm-actions { margin-top: 0.5rem; display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
  .zm-toggle-proxy {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background: #fff; border: 1px solid #d4d4d4; border-radius: 999px;
    padding: 0.25rem 0.7rem; font-size: 0.82em; color: #555;
    cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem;
    font-family: inherit;
  }
  .zm-toggle-proxy:hover { background: #f5f5f5; border-color: #b4b4b4; }
  .zm-toggle-proxy[data-state="1"] { color: #b35900; border-color: #f4ad6a; background: #fff7ec; }
  .zm-toggle-proxy[data-state="1"]:hover { background: #ffeede; }
  .zm-toggle-proxy .zm-toggle-cloud { color: #aaa; line-height: 0; }
  .zm-toggle-proxy[data-state="1"] .zm-toggle-cloud { color: #f48120; }
  .zm-toggle-proxy[data-overridden="1"] {
    border-color: #d9a700; background: #fff8e1; color: #6e5300;
    box-shadow: 0 0 0 2px rgba(217,167,0,0.18);
  }
  .zm-override-hint {
    font-size: 0.78em; color: #b35900; font-style: italic;
    display: none;
  }
  .zm-card[data-pending-override="1"] .zm-override-hint { display: inline; }
  .zm-card[data-pending-override="1"] { border-left-color: #d9a700 !important; opacity: 1 !important; }
  .zm-meta {
    font-size: 0.82em; color: #666; margin-top: 0.5rem;
    padding: 0.25rem 0.5rem;
  }
  .zm-meta strong { color: #333; font-weight: 600; }
  .zm-meta .arrow { color: #888; padding: 0 0.3rem; }
  .zm-meta .v-old { color: #842029; }
  .zm-meta .v-new { color: #0a5f1f; }

  /* CF-only delete cards get an explicit warning banner above the - line */
  .zm-warn {
    font-size: 0.85em; color: #842029; background: #fff5f5;
    border: 1px solid #f5c6c6; border-radius: 4px;
    padding: 0.5rem 0.7rem; margin-bottom: 0.5rem; line-height: 1.4;
  }
  .zm-warn strong { color: #842029; }

  /* Cloudflare-style cloud icon — orange = proxied, grey = DNS-only. The
     SVG is inlined as text by zm_cloud_swap() so the icon survives the
     LCS-based word diff (which would corrupt embedded HTML). */
  .zm-cloud {
    display: inline-block; vertical-align: -2px; margin-left: 0.35rem;
    line-height: 1; cursor: help;
  }
  .zm-cloud svg { display: block; }
  .zm-cloud-proxied { color: #f48120; }   /* Cloudflare orange */
  .zm-cloud-dnsonly { color: #aaa; }      /* muted grey */

  /* sticky apply bar */
  .zm-sticky {
    position: sticky; bottom: 0;
    background: #fff; border-top: 2px solid #1f5fa6;
    padding: 0.7rem 0.85rem; margin: 1rem 0 0;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: 0 -2px 8px rgba(0,0,0,0.06);
    z-index: 10; flex-wrap: wrap; gap: 0.5rem;
  }
  .zm-sticky .info { color: #444; font-size: 0.95em; }
  .zm-sticky #zm-selected-count { font-weight: 700; color: #1f5fa6; font-size: 1.15em; }
  .zm-sticky .actions { display: flex; gap: 0.5rem; align-items: center; }
  .zm-sticky button[disabled] { opacity: 0.5; cursor: not-allowed; }
  details.zm-bulk { position: relative; }
  details.zm-bulk > summary {
    list-style: none; cursor: pointer;
  }
  details.zm-bulk > summary::-webkit-details-marker { display: none; }
  details.zm-bulk[open] .zm-bulk-menu {
    position: absolute; right: 0; bottom: 100%;
    background: #fff; border: 1px solid #d4d4d4; border-radius: 6px;
    padding: 0.5rem; min-width: 300px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    display: flex; flex-direction: column; gap: 0.35rem; margin-bottom: 0.4rem;
  }
  .zm-bulk-menu button { text-align: left; }

  /* wizard heading */
  .zm-step { color: #888; font-size: 0.8em; letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 0.25rem; }

  /* "Use my own Cloudflare account" — the card boxes the whole disclosure
     so it visually matches the other sections (banner, domain list) instead
     of floating as a bare link under the page. */
  details.advanced {
    margin-top: 1.5rem;
    background: #fff; border: 1px solid #e5e5e5; border-radius: 6px;
    padding: 0;
  }
  details.advanced > summary {
    cursor: pointer; color: #444; font-weight: 500;
    padding: 0.85rem 1rem;
    list-style: none; user-select: none;
    display: flex; align-items: center; gap: 0.5rem;
    border-radius: 6px;
  }
  details.advanced[open] > summary {
    border-bottom: 1px solid #eee; border-radius: 6px 6px 0 0;
  }
  details.advanced > summary::-webkit-details-marker { display: none; }
  details.advanced > summary::before { content: "▶"; font-size: 0.7em; color: #888; }
  details.advanced[open] > summary::before { content: "▼"; }
  details.advanced > summary:hover { background: #fafafa; }
  details.advanced > .adv-body { padding: 0.5rem 1rem 1rem; }
  .form-stack label { display: block; margin: 0.75rem 0 0.25rem; font-weight: 500; }
  /* Only text-shaped fields stretch to full width. Checkboxes and radios
     stay at their intrinsic size so the label text sits next to the box. */
  .form-stack input[type=text],
  .form-stack input[type=password],
  .form-stack input[type=email],
  .form-stack input[type=number],
  .form-stack select,
  .form-stack textarea {
    width: 100%; padding: 0.5rem 0.6rem;
    border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;
    font-size: 1em;
  }
  .form-stack label.inline {
    display: flex; align-items: center; gap: 0.5rem;
    font-weight: 400; margin: 0.6rem 0 0;
  }
  .form-stack label.inline input[type=checkbox] { margin: 0; flex: 0 0 auto; }

  /* ─── Live progress banner (Apply → drain → done) ───
     Built on cPanel's .alert markup, so the box/colour/icon already
     come from the chrome. We only add: a spinning glyphicon while in
     flight, the progress bar, and a right-aligned actions slot. */
  .zm-progress { margin-bottom: 1rem; }
  .zm-progress .zm-progress-icon {
    margin-right: 0.4rem;
    animation: zm-spin 1.2s linear infinite;
    transform-origin: 50% 50%; display: inline-block;
  }
  .zm-progress.alert-success .zm-progress-icon,
  .zm-progress.alert-danger  .zm-progress-icon { animation: none; }
  .zm-progress .zm-progress-actions {
    display: inline-flex; gap: 0.5rem; flex-wrap: wrap;
    margin-left: 0.6rem; vertical-align: middle;
  }
  .zm-progress .zm-progress-bar-outer {
    margin-top: 0.55rem; height: 6px; background: rgba(0,0,0,0.08);
    border-radius: 3px; overflow: hidden;
  }
  .zm-progress .zm-progress-bar-inner {
    height: 100%; width: 0%; background: currentColor; opacity: 0.55;
    transition: width 0.4s ease-out;
  }
  .zm-progress.alert-success .zm-progress-bar-inner { width: 100%; }
  @keyframes zm-spin { to { transform: rotate(360deg); } }

  /* Per-card live state — overlayed on top of the existing status classes
     so the diff colour still shows through on the left border. */
  .zm-card[data-apply-state="applying"] {
    box-shadow: 0 0 0 2px rgba(217,167,0,0.35);
  }
  .zm-card[data-apply-state="applying"] .zm-card-head::after {
    content: ""; display: inline-block; width: 12px; height: 12px;
    margin-left: 0.5rem;
    border: 2px solid rgba(217,167,0,0.25); border-top-color: #d9a700;
    border-radius: 50%; animation: zm-spin 0.85s linear infinite;
  }
  .zm-card[data-apply-state="applied"] {
    border-left-color: #1f8a3e !important; opacity: 0.55;
  }
  .zm-card[data-apply-state="applied"] .zm-card-head::after {
    content: "✓ applied"; margin-left: 0.5rem;
    color: #1f8a3e; font-size: 0.8em; font-weight: 600;
  }
  .zm-card[data-apply-state="failed"] {
    border-left-color: #c53030 !important;
    box-shadow: 0 0 0 2px rgba(197,48,48,0.35);
  }
  .zm-card[data-apply-state="failed"] .zm-card-head::after {
    content: "✗ failed"; margin-left: 0.5rem;
    color: #c53030; font-size: 0.8em; font-weight: 600;
  }

  /* Manage Locks panel — slides open under the connected-domain banner.
     Lists every active lock and exposes a form to add a new one with an
     explicit scope. Designed as a disclosure: starts hidden, the button
     in the banner toggles it. */
  .zm-locks-count {
    display: inline-block; min-width: 1.4em; padding: 0 0.4em;
    border-radius: 999px; background: rgba(0,0,0,0.1); color: #666;
    font-size: 0.78em; font-weight: 600; margin-left: 0.35rem;
  }
  .zm-locks-count.has-locks { background: #c8a73b; color: #fff; }
  .zm-locks-panel {
    background: #fffaeb; border: 1px solid #f4ce6e; border-radius: 6px;
    padding: 0.9rem 1.1rem; margin-bottom: 1rem;
  }
  .zm-locks-panel-head { margin-bottom: 0.5rem; }
  .zm-locks-panel-head .zm-muted { color: #777; font-size: 0.88em; margin-left: 0.4rem; }
  .zm-muted { color: #777; }
  .zm-locks-list { list-style: none; padding: 0; margin: 0 0 0.75rem; }
  .zm-lock-row {
    display: flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0;
    border-bottom: 1px dashed #f0d990; font-size: 0.92em;
  }
  .zm-lock-row:last-child { border-bottom: 0; }
  .zm-lock-row code {
    background: rgba(0,0,0,0.05); padding: 0.05em 0.4em;
    border-radius: 3px; font-size: 0.92em;
  }
  .zm-lock-criteria { flex: 1 1 auto; color: #444; }
  .zm-pill-scope {
    background: #f4e3a8; color: #6b4c00; text-transform: uppercase;
    letter-spacing: 0.04em; font-size: 0.72em;
  }
  .zm-pill-scope-zone     { background: #d9534f; color: #fff; }
  .zm-pill-scope-subtree  { background: #f0ad4e; color: #fff; }
  .zm-pill-scope-name     { background: #f4ce6e; color: #6b4c00; }
  .zm-pill-scope-type_name{ background: #f4e3a8; color: #6b4c00; }
  .zm-pill-scope-exact    { background: #b6ecc2; color: #0a5f1f; }
  .zm-lock-add-form { margin-top: 0.5rem; }
  .zm-lock-add-row {
    display: flex; gap: 0.6rem; align-items: flex-end;
    flex-wrap: wrap; margin-top: 0.5rem;
  }
  .zm-lock-add-row label { display: flex; flex-direction: column; font-size: 0.85em; }
  .zm-lock-add-row label .zm-muted { margin-bottom: 0.15rem; }
  .zm-lock-add-row input[type="text"],
  .zm-lock-add-row input[type="number"],
  .zm-lock-add-row select {
    padding: 0.4rem 0.5rem; border: 1px solid #ccc; border-radius: 4px;
    font-size: 0.95em;
  }
  .zm-lock-add-row label[data-scope-field][hidden] { display: none; }

  /* Lock affordance per card. Click to flip; the JS controller fires
     an AJAX toggle_lock and rewrites data-locked on success. Locked
     cards get a slate border, faded body and their checkbox suppressed
     so the user can't tick them by accident. */
  .zm-lock-btn {
    margin-left: auto;
    background: none; border: 0; padding: 0.15rem 0.5rem; cursor: pointer;
    color: #888; font-size: 0.82em; display: inline-flex; align-items: center; gap: 0.3rem;
    border-radius: 4px; font-family: inherit;
  }
  .zm-lock-btn:hover { background: rgba(0,0,0,0.05); color: #555; }
  .zm-lock-btn .zm-lock-icon { display: inline-flex; line-height: 0; }
  .zm-lock-btn .zm-lock-svg { width: 14px; height: 14px; display: none; }
  /* Show the open lock when unlocked, the closed lock when locked.
     On hover/focus, swap to the icon that previews what the click
     will do — open lock on a locked row, closed on an unlocked one. */
  .zm-lock-btn[data-locked="0"] .zm-lock-svg-open   { display: inline-block; }
  .zm-lock-btn[data-locked="0"]:hover .zm-lock-svg-open,
  .zm-lock-btn[data-locked="0"]:focus-visible .zm-lock-svg-open { display: none; }
  .zm-lock-btn[data-locked="0"]:hover .zm-lock-svg-closed,
  .zm-lock-btn[data-locked="0"]:focus-visible .zm-lock-svg-closed { display: inline-block; }
  .zm-lock-btn[data-locked="1"] .zm-lock-svg-closed { display: inline-block; }
  .zm-lock-btn[data-locked="1"]:hover .zm-lock-svg-closed,
  .zm-lock-btn[data-locked="1"]:focus-visible .zm-lock-svg-closed { display: none; }
  .zm-lock-btn[data-locked="1"]:hover .zm-lock-svg-open,
  .zm-lock-btn[data-locked="1"]:focus-visible .zm-lock-svg-open { display: inline-block; }
  .zm-lock-btn[data-locked="1"] {
    color: #6b4c00; background: #fff8e1; border: 1px solid #f4ce6e;
  }
  .zm-lock-btn[data-locked="1"] .zm-lock-label { font-weight: 600; }
  .zm-card[data-locked="1"] {
    background: #fbf9f0; border-left-color: #c8a73b !important;
  }
  .zm-card[data-locked="1"] .zm-card-body { opacity: 0.65; }
  .zm-card[data-locked="1"] .zm-toggle-proxy,
  .zm-card[data-locked="1"] .zm-actions { pointer-events: none; opacity: 0.55; }

  /* Custom confirm dialog (replaces native browser confirm() for bulk
     destructive actions — the native one looked alien inside cPanel). */
  .zm-dialog {
    border: 0; border-radius: 8px; padding: 0;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25); max-width: 480px; width: 90%;
  }
  .zm-dialog::backdrop { background: rgba(0,0,0,0.35); }
  .zm-dialog .zm-dialog-body { padding: 1.1rem 1.25rem 0.4rem; }
  .zm-dialog .zm-dialog-title { font-weight: 600; font-size: 1.05em; margin-bottom: 0.4rem; }
  .zm-dialog .zm-dialog-msg { color: #444; line-height: 1.45; }
  .zm-dialog .zm-dialog-actions {
    display: flex; gap: 0.5rem; justify-content: flex-end;
    padding: 0.85rem 1.25rem 1rem;
  }
</style>

<div class="body-content zonemirror-wrap">
  <?php if (!$vm['allowed']): ?>
    <?= zm_alert('warning', 'Ask your hosting provider to enable it.', 'This plugin is not available for your account.') ?>
    <?php
    print $cpanel->footer();
    $cpanel->end();
    exit;
    ?>
  <?php endif; ?>

  <?php if ($vm['message'] !== ''): ?>
    <?= zm_alert('success', $h($vm['message']), '') ?>
  <?php endif; ?>
  <?php foreach ($vm['errors'] as $err): ?>
    <?= zm_alert('danger', $h($err)) ?>
  <?php endforeach; ?>
  <?php if ($vm['test_result'] !== null): ?>
    <?= zm_alert('info', $h($vm['test_result']), '') ?>
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
        </div>
      </div>
      <div class="zm-btn-row">
        <button type="button" class="btn btn-default" id="zm-locks-toggle"
                aria-controls="zm-locks-panel" aria-expanded="false">
          <span class="glyphicon glyphicon-lock" aria-hidden="true"></span>
          Manage locks
          <span class="zm-locks-count<?= $vm['locks_count'] > 0 ? ' has-locks' : '' ?>"
                id="zm-locks-count"><?= (int) $vm['locks_count'] ?></span>
        </button>
        <form method="post" data-zm-form="refresh">
          <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
          <input type="hidden" name="action" value="refresh_diff">
          <button type="submit" class="btn btn-default">Refresh diff</button>
        </form>
        <form method="post" data-zm-confirm-title="Disconnect"
              data-zm-confirm-msg="Stop syncing <?= $h($vm['zone_name']) ?> to Cloudflare? Your local zone keeps unchanged.">
          <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
          <input type="hidden" name="action" value="disconnect">
          <button type="submit" class="btn btn-default">Disconnect</button>
        </form>
      </div>
    </div>

    <?php /* ─── Manage Locks panel ────────────────────────────────────
            Slides open below the banner. Lists every active lock with
            its scope/criteria + a remove button, plus a tiny form to
            add a new lock by picking scope from a dropdown. Scope
            controls which fields are required (the JS hides what
            doesn't apply when scope changes). */ ?>
    <div id="zm-locks-panel" class="zm-locks-panel" hidden>
      <div class="zm-locks-panel-head">
        <strong>Locked records</strong>
        <span class="zm-muted">ZoneMirror skips every push or delete that matches one of these.</span>
      </div>
      <?php if ($vm['locks_count'] === 0): ?>
        <p class="zm-muted" id="zm-locks-empty" style="margin: 0.5rem 0 0.75rem;">
          No locks yet. Add one below or click the padlock on any diff card.
        </p>
      <?php endif; ?>
      <ul class="zm-locks-list" id="zm-locks-list">
        <?php foreach ($vm['locks'] as $lockId => $lock): ?>
          <li class="zm-lock-row" data-lock-id="<?= $h($lockId) ?>">
            <span class="zm-pill zm-pill-scope zm-pill-scope-<?= $h($lock['scope']) ?>"><?= $h($lock['scope']) ?></span>
            <span class="zm-lock-criteria">
              <?php if ($lock['scope'] === 'zone'): ?>
                <em>entire zone</em>
              <?php elseif ($lock['scope'] === 'subtree'): ?>
                everything under <code><?= $h($lock['name']) ?></code>
              <?php elseif ($lock['scope'] === 'name'): ?>
                any record at <code><?= $h($lock['name']) ?></code>
              <?php elseif ($lock['scope'] === 'type_name'): ?>
                <code><?= $h($lock['type']) ?></code> at <code><?= $h($lock['name']) ?></code>
              <?php elseif ($lock['scope'] === 'exact'): ?>
                <code><?= $h($lock['type']) ?></code> at <code><?= $h($lock['name']) ?></code>
                = <code><?= $h((string) $lock['content']) ?></code>
                <?php if ($lock['priority'] !== null): ?>
                  (priority <?= (int) $lock['priority'] ?>)
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($lock['reason'] !== ''): ?>
                — <span class="zm-muted"><?= $h($lock['reason']) ?></span>
              <?php endif; ?>
            </span>
            <button type="button" class="zm-link zm-link-muted zm-lock-remove" data-lock-id="<?= $h($lockId) ?>" title="Remove this lock">remove</button>
          </li>
        <?php endforeach; ?>
      </ul>

      <form id="zm-lock-add-form" class="zm-lock-add-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
        <input type="hidden" name="action" value="add_lock">
        <div class="zm-lock-add-row">
          <label>
            <span class="zm-muted">Scope</span>
            <select name="scope" id="zm-lock-scope">
              <option value="type_name">type + name (one record type at a name)</option>
              <option value="name">name (any record type at this name)</option>
              <option value="subtree">subtree (name + everything under it)</option>
              <option value="exact">exact (type + name + content)</option>
              <option value="zone">whole zone</option>
            </select>
          </label>
          <label data-scope-field="type">
            <span class="zm-muted">Type</span>
            <select name="type">
              <?php foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'] as $t): ?>
                <option value="<?= $t ?>"><?= $t ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label data-scope-field="name" style="flex: 1 1 220px;">
            <span class="zm-muted">Name (FQDN)</span>
            <input type="text" name="name" placeholder="<?= $h($vm['zone_name']) ?> or sub.<?= $h($vm['zone_name']) ?>" />
          </label>
          <label data-scope-field="content" style="flex: 1 1 220px;">
            <span class="zm-muted">Content</span>
            <input type="text" name="content" placeholder="(only for exact)" />
          </label>
          <label data-scope-field="priority" style="flex: 0 0 90px;">
            <span class="zm-muted">Priority</span>
            <input type="number" name="priority" placeholder="(MX only)" min="0" />
          </label>
        </div>
        <div class="zm-lock-add-row">
          <label style="flex: 1;">
            <span class="zm-muted">Reason (optional)</span>
            <input type="text" name="reason" placeholder="why is this locked" />
          </label>
          <button type="submit" class="btn btn-primary">Add lock</button>
        </div>
      </form>
    </div>

    <?php /* Live progress banner. Built on cPanel's native .alert markup so
            it visually matches the chrome's own alertService; the JS at the
            bottom flips the alert-info → alert-success/warning/danger class
            depending on queue state. Hidden by default; the JS reveals it
            whenever there are queued events. */ ?>
    <div id="zm-progress" class="alert alert-info zm-progress" role="status" aria-live="polite"
         style="display:none;"
         data-initial-depth="<?= (int) $vm['queue_depth'] ?>"
         data-initial-dead="<?= (int) $vm['dead_letters'] ?>"
         data-sync-state="<?= $h($vm['sync_state']) ?>">
      <span class="glyphicon glyphicon-refresh zm-progress-icon" aria-hidden="true"></span>
      <div class="alert-message">
        <strong class="alert-title zm-progress-title">Applying changes…</strong>
        <span class="alert-body">
          <span id="zm-progress-detail">Waiting for the daemon…</span>
        </span>
        <div class="zm-progress-actions" id="zm-progress-actions"></div>
        <div class="zm-progress-bar-outer"><div class="zm-progress-bar-inner"></div></div>
      </div>
    </div>
  <?php endif; ?>

  <?php /* Reusable confirm dialog. Bound by JS to any form carrying
          data-zm-confirm-* attributes. Replaces the native browser
          confirm() which looked alien inside cPanel chrome. */ ?>
  <dialog id="zm-confirm" class="zm-dialog">
    <div class="zm-dialog-body">
      <div class="zm-dialog-title" id="zm-confirm-title">Confirm</div>
      <div class="zm-dialog-msg"  id="zm-confirm-msg"></div>
    </div>
    <div class="zm-dialog-actions">
      <button type="button" class="btn btn-default" id="zm-confirm-cancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="zm-confirm-ok">Confirm</button>
    </div>
  </dialog>

  <?php /* ─── Wizard / state-dependent content ─── */ ?>
  <?php if ($vm['enabled'] && in_array($vm['sync_state'], [UserConfigStorage::STATE_PENDING_DIFF, UserConfigStorage::STATE_COMPUTING_DIFF], true)): ?>

    <div class="zm-step">Step 2 of 2 — Review</div>
    <h3 style="margin-top: 0.2rem;">Computing diff with Cloudflare…</h3>
    <p style="color:#666;">
      We&rsquo;re comparing your cPanel zone file against Cloudflare so you
      can pick what to sync. This page refreshes itself every few seconds.
    </p>

  <?php elseif ($vm['enabled'] && $vm['sync_state'] === UserConfigStorage::STATE_FAILED): ?>

    <?php
    ob_start();
    if ($vm['last_error'] !== '') {
        echo '<div style="margin-top: 0.4rem; font-family: ui-monospace, monospace; font-size: 0.86em;">'
            . $h($vm['last_error']) . '</div>';
    }
    ?>
    <form method="post" style="margin-top: 0.6rem;" data-zm-form="refresh">
      <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
      <input type="hidden" name="action" value="refresh_diff">
      <button type="submit" class="btn btn-primary">Retry</button>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= zm_alert('danger', $body, 'Diff failed.', 'margin-top:1rem;') ?>

  <?php elseif ($vm['enabled'] && $vm['sync_state'] === UserConfigStorage::STATE_AWAITING_REVIEW && is_array($vm['diff'])): ?>

    <?php
    $entries = is_array($vm['diff']['entries'] ?? null) ? $vm['diff']['entries'] : [];
    $computedAt = isset($vm['diff']['computed_at']) ? (int) $vm['diff']['computed_at'] : 0;

    // Bucket by status so the page can render one section per action
    // (creates, updates, deletes), keep "no change" rows collapsed in a
    // separate disclosure, and feed live counts to the sticky footer.
    $byStatus = [
        DnsDiff::STATUS_DIFFERENT => [],
        DnsDiff::STATUS_CPANEL_ONLY => [],
        DnsDiff::STATUS_CLOUDFLARE_ONLY => [],
        DnsDiff::STATUS_IDENTICAL => [],
    ];
    foreach ($entries as $e) {
        if (!is_array($e)) {
            continue;
        }
        $s = (string) ($e['status'] ?? '');
        if (isset($byStatus[$s])) {
            $byStatus[$s][] = $e;
        }
    }
    $cCreate = count($byStatus[DnsDiff::STATUS_CPANEL_ONLY]);
    $cUpdate = count($byStatus[DnsDiff::STATUS_DIFFERENT]);
    $cDelete = count($byStatus[DnsDiff::STATUS_CLOUDFLARE_ONLY]);
    $cIdent  = count($byStatus[DnsDiff::STATUS_IDENTICAL]);
    ?>

    <div class="zm-step">Step 2 of 2 — Review</div>
    <h3 style="margin-top: 0.2rem;">Review changes before syncing</h3>
    <p style="color:#666;">
      Each card shows what will happen on Cloudflare if you apply it.
      Nothing is pushed until you tick the box and confirm.
      <?php if ($computedAt > 0): ?>
        <span style="color:#aaa;">&nbsp;Computed <?= $h(gmdate('Y-m-d H:i \U\T\C', $computedAt)) ?>.</span>
      <?php endif; ?>
    </p>

    <div class="zm-summary">
      <div class="card miss-l">
        <div class="label">Create</div>
        <div class="value"><?= $cCreate ?></div>
      </div>
      <div class="card diff">
        <div class="label">Update</div>
        <div class="value"><?= $cUpdate ?></div>
      </div>
      <div class="card miss-r">
        <div class="label">Delete</div>
        <div class="value"><?= $cDelete ?></div>
      </div>
      <div class="card">
        <div class="label">No change</div>
        <div class="value"><?= $cIdent ?></div>
      </div>
    </div>

    <div class="zm-filter" role="tablist">
      <button type="button" class="active" data-filter="actionable">Actionable (<?= $cCreate + $cUpdate + $cDelete ?>)</button>
      <button type="button" data-filter="cpanel_only">Create (<?= $cCreate ?>)</button>
      <button type="button" data-filter="different">Update (<?= $cUpdate ?>)</button>
      <button type="button" data-filter="cloudflare_only">Delete (<?= $cDelete ?>)</button>
      <button type="button" data-filter="identical">No change (<?= $cIdent ?>)</button>
      <button type="button" data-filter="all">All</button>
    </div>

    <div class="zm-select-row">
      <span class="zm-select-label">Selection:</span>
      <button type="button" class="zm-link" data-select="visible">Select shown</button>
      <button type="button" class="zm-link" data-select="all">Select all (<?= $cCreate + $cUpdate + $cDelete ?>)</button>
      <button type="button" class="zm-link" data-select="cpanel_only">Creates only</button>
      <button type="button" class="zm-link" data-select="different">Updates only</button>
      <button type="button" class="zm-link" data-select="cloudflare_only">Deletes only</button>
      <span class="zm-select-sep">|</span>
      <button type="button" class="zm-link zm-link-muted" data-select="none">Clear</button>
    </div>

    <form method="post" id="zm-diff-form" data-zm-form="apply">
      <input type="hidden" name="csrf" value="<?= $h($vm['csrf']) ?>">
      <input type="hidden" name="action" value="apply">

      <div class="zm-cards">
        <?php
        // Order: updates first (yellow), then creates (green), deletes (red),
        // then identical (grey). Visibility is filter-driven, not collapsed.
        foreach ([
            DnsDiff::STATUS_DIFFERENT,
            DnsDiff::STATUS_CPANEL_ONLY,
            DnsDiff::STATUS_CLOUDFLARE_ONLY,
            DnsDiff::STATUS_IDENTICAL,
        ] as $st) {
            foreach ($byStatus[$st] as $e) {
                echo zm_render_card($e, $h);
            }
        }
        ?>
      </div>

      <div class="zm-sticky">
        <div class="info">
          <span id="zm-selected-count">0</span> change(s) selected
        </div>
        <div class="actions">
          <button type="submit" id="zm-apply-btn" class="btn btn-primary" disabled>
            Apply selected changes
          </button>
          <details class="zm-bulk">
            <summary class="btn btn-default">Apply all&hellip;</summary>
            <div class="zm-bulk-menu">
              <?php if ($cCreate > 0): ?>
                <button type="submit" name="apply_status" value="cpanel_only" class="btn btn-default"
                        data-zm-confirm-title="Create on Cloudflare"
                        data-zm-confirm-msg="Create <?= $cCreate ?> record(s) on Cloudflare that exist on cPanel?">
                  Create all <?= $cCreate ?> missing on CF
                </button>
              <?php endif; ?>
              <?php if ($cUpdate > 0): ?>
                <button type="submit" name="apply_status" value="different" class="btn btn-default"
                        data-zm-confirm-title="Overwrite on Cloudflare"
                        data-zm-confirm-msg="Overwrite <?= $cUpdate ?> Cloudflare record(s) with the cPanel version?">
                  Update all <?= $cUpdate ?> differing
                </button>
              <?php endif; ?>
              <?php if ($cDelete > 0): ?>
                <button type="submit" name="apply_status" value="cloudflare_only" class="btn btn-default"
                        data-zm-confirm-title="Delete from Cloudflare"
                        data-zm-confirm-msg="DELETE <?= $cDelete ?> record(s) from Cloudflare that do not exist on cPanel? This is destructive.">
                  Delete all <?= $cDelete ?> CF-only
                </button>
              <?php endif; ?>
              <?php if ($cCreate + $cUpdate > 0): ?>
                <button type="submit" name="apply_status" value="all" class="btn btn-default"
                        data-zm-confirm-title="Apply all"
                        data-zm-confirm-msg="Push every create + update from cPanel to Cloudflare? (CF-only records are NOT deleted.)">
                  Apply all creates + updates
                </button>
              <?php endif; ?>
            </div>
          </details>
        </div>
      </div>
    </form>

    <script>
    (function() {
      var form     = document.getElementById('zm-diff-form');
      var counter  = document.getElementById('zm-selected-count');
      var applyBtn = document.getElementById('zm-apply-btn');

      function selected() {
        return form.querySelectorAll(
          'input[name="push_keys[]"]:checked, input[name="delete_keys[]"]:checked'
        );
      }
      function refresh() {
        var n = selected().length;
        counter.textContent = n;
        applyBtn.disabled = n === 0;
      }
      form.addEventListener('change', refresh);
      refresh();

      // Submit handling (per-row apply + bulk apply) lives in the global
      // ZoneMirror controller at the bottom of the page — it handles the
      // AJAX POST, the progress banner, the per-card live state, and the
      // <dialog>-based confirm flow. We just keep the form here as the
      // canonical source of truth for the selected keys.

      // Filter chips. "actionable" hides identical; other filters show the
      // matching status. Identical rows are normal cards in the same flow,
      // toggled like the rest.
      var chips = document.querySelectorAll('.zm-filter button');
      function applyFilter(f) {
        chips.forEach(function(x) {
          x.classList.toggle('active', x.dataset.filter === f);
        });
        document.querySelectorAll('.zm-card').forEach(function(card) {
          var s = card.dataset.status;
          var show =
            (f === 'all') ||
            (f === 'actionable' && s !== 'identical') ||
            (f === s);
          card.style.display = show ? '' : 'none';
        });
      }
      chips.forEach(function(b) {
        b.addEventListener('click', function() { applyFilter(b.dataset.filter); });
      });
      applyFilter('actionable');

      // Bulk selection. Each button targets a different subset of cards;
      // identical rows are skipped (their checkbox is hidden anyway).
      // "Clear" also resets pending proxy overrides so the user has one
      // single Undo affordance instead of two.
      document.querySelectorAll('.zm-select-row .zm-link').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var mode = btn.dataset.select;
          if (mode === 'none') {
            form.querySelectorAll('input[name="push_keys[]"], input[name="delete_keys[]"]').forEach(function(cb) {
              cb.checked = false;
              cb.dataset.userChecked = '0';
            });
            // Reset proxy toggles back to their original state.
            document.querySelectorAll('.zm-toggle-proxy[data-overridden="1"]').forEach(function(t) {
              if (t.dataset.state !== t.dataset.original) {
                t.click();
              }
            });
            refresh();

            return;
          }

          document.querySelectorAll('.zm-card').forEach(function(card) {
            var hidden = card.style.display === 'none';
            var status = card.dataset.status;
            var pickThis =
              (mode === 'visible' && !hidden && status !== 'identical') ||
              (mode === 'all' && status !== 'identical') ||
              (mode === status);
            if (!pickThis) {
              return;
            }
            var cb = card.querySelector('input[name="push_keys[]"], input[name="delete_keys[]"]');
            if (cb) {
              cb.checked = true;
              cb.dataset.userChecked = '1';
            }
          });
          refresh();
        });
      });

      // Per-record proxy toggle. The button's data-state holds the
      // currently-displayed proxied flag (0/1); data-original is what came
      // from the diff. When they diverge, we mark the card as override-
      // pending: the hidden proxy_override[KEY] field gets the new value,
      // the push checkbox is force-ticked, and the card itself flips to
      // a yellow Update-ish look so the user knows it'll be pushed.
      document.querySelectorAll('.zm-toggle-proxy').forEach(function(btn) {
        var key = btn.dataset.key;
        var card = btn.closest('.zm-card');
        var hidden = card.querySelector('input[name="proxy_override[' + CSS.escape(key) + ']"]');
        var pushCb = card.querySelector('input[name="push_keys[]"]');
        var label  = btn.querySelector('.zm-toggle-label');
        var cloud  = btn.querySelector('.zm-toggle-cloud');

        // Pre-rendered SVGs we swap into the cloud span on toggle. Keep
        // them in sync with zm_cloud_swap() so they look identical.
        var SVG_ON  = '<span class="zm-cloud zm-cloud-proxied" title="Proxied through Cloudflare">'
                    + cloud.innerHTML.replace(/zm-cloud-(proxied|dnsonly)/, 'zm-cloud-proxied') + '</span>';
        var SVG_OFF = '<span class="zm-cloud zm-cloud-dnsonly" title="DNS only — not proxied">'
                    + cloud.innerHTML.replace(/zm-cloud-(proxied|dnsonly)/, 'zm-cloud-dnsonly') + '</span>';

        btn.addEventListener('click', function() {
          var next = btn.dataset.state === '1' ? '0' : '1';
          btn.dataset.state = next;
          label.textContent = next === '1' ? 'Proxied' : 'DNS only';
          cloud.outerHTML = next === '1'
            ? '<span class="zm-toggle-cloud">' + SVG_ON  + '</span>'
            : '<span class="zm-toggle-cloud">' + SVG_OFF + '</span>';
          // Re-query the cloud node since outerHTML replaced it.
          cloud = btn.querySelector('.zm-toggle-cloud');

          var overridden = next !== btn.dataset.original;
          btn.dataset.overridden = overridden ? '1' : '0';
          card.dataset.pendingOverride = overridden ? '1' : '0';
          if (hidden) { hidden.value = overridden ? next : ''; }
          if (pushCb) {
            pushCb.checked = overridden ? true : pushCb.dataset.userChecked === '1';
            // Track user-initiated checkbox state so toggling back to the
            // original doesn't un-tick a row the user explicitly selected.
          }
          refresh();
        });
      });
      // Remember any push checkbox the user ticks manually so the
      // override-toggle doesn't accidentally undo it on return-to-original.
      form.querySelectorAll('input[name="push_keys[]"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
          if (cb.checked) cb.dataset.userChecked = '1';
          else if (cb.dataset.userChecked === '1') cb.dataset.userChecked = '0';
        });
      });
    })();
    </script>

  <?php elseif ($vm['enabled'] && $vm['sync_state'] === UserConfigStorage::STATE_IDLE): ?>

    <?= zm_alert(
        'success',
        'Cloudflare matches cPanel for every record that&rsquo;s under our control. '
        . 'Future Zone-Editor edits propagate automatically.',
        'All synced.',
        'margin-top:1rem;'
    ) ?>

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
    <div class="adv-body">
    <p style="margin: 0 0 0.6rem; color: #555;">
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
        <select name="zone_name">
          <option value="">— select one of your domains —</option>
          <?php foreach ($vm['domains'] as $d): ?>
            <option value="<?= $h($d['name']) ?>" <?= $vm['zone_name'] === $d['name'] ? 'selected' : '' ?>>
              <?= $h($d['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="inline">
        <input type="checkbox" name="defaults_proxied" <?= $vm['defaults_proxied'] ? 'checked' : '' ?>>
        <span>Proxy A / AAAA / CNAME records by default</span>
      </label>
      <label class="inline">
        <input type="checkbox" name="enabled" <?= ($vm['enabled'] && $vm['source'] === 'user') ? 'checked' : '' ?>>
        <span>Enable real-time sync to Cloudflare</span>
      </label>
      <div style="margin-top: 0.75rem;">
        <button type="submit" name="action" value="test" class="btn btn-default" formnovalidate>Test connection</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
    </div>
  </details>
</div>

<script>
/*
 * ZoneMirror live controller.
 *
 * Replaces the historical click → full-page POST → silent 30s wait flow
 * with: AJAX Apply, dialog-based confirms, a live progress banner that
 * polls /index.live.php?action=queue_status every 1.5s, and per-card
 * applying → applied/failed states.
 *
 * Designed to gracefully no-op on a no-JS session — the underlying forms
 * still submit normally and the <noscript> meta-refresh keeps the page
 * up to date.
 */
(function () {
  var POLL_MS = 1500;
  var STUCK_MS = 30000;
  var BATCH_KEY = 'zm-apply-batch';

  var progress = document.getElementById('zm-progress');
  var detail   = progress ? document.getElementById('zm-progress-detail')  : null;
  var actions  = progress ? document.getElementById('zm-progress-actions') : null;
  var bar      = progress ? progress.querySelector('.zm-progress-bar-inner') : null;
  var title    = progress ? progress.querySelector('.zm-progress-title')   : null;

  var dlg      = document.getElementById('zm-confirm');
  var dlgTitle = document.getElementById('zm-confirm-title');
  var dlgMsg   = document.getElementById('zm-confirm-msg');
  var dlgOk    = document.getElementById('zm-confirm-ok');
  var dlgNo    = document.getElementById('zm-confirm-cancel');

  // Latest CSRF. The classic forms render with this; we rotate it on every
  // AJAX response since Csrf::verify() invalidates the token on use.
  var csrf = '<?= $h($vm['csrf']) ?>';

  // ──── Custom <dialog> confirm ──────────────────────────────────────
  function confirmDialog(t, m) {
    if (!dlg || typeof dlg.showModal !== 'function') {
      return Promise.resolve(window.confirm(m || t || 'Confirm?'));
    }
    dlgTitle.textContent = t || 'Confirm';
    dlgMsg.textContent   = m || '';
    return new Promise(function (resolve) {
      var done = false;
      function finish(ans) {
        if (done) return; done = true;
        dlgOk.removeEventListener('click', onOk);
        dlgNo.removeEventListener('click', onNo);
        dlg.removeEventListener('close', onClose);
        if (dlg.open) { try { dlg.close(); } catch (_) {} }
        resolve(ans);
      }
      function onOk()    { finish(true); }
      function onNo()    { finish(false); }
      function onClose() { finish(false); }
      dlgOk.addEventListener('click', onOk);
      dlgNo.addEventListener('click', onNo);
      dlg.addEventListener('close', onClose);
      try { dlg.showModal(); } catch (_) { resolve(window.confirm(m)); }
    });
  }

  // ──── Idempotency key parser ───────────────────────────────────────
  // Format: apply:TS:push:CARDKEY  or  apply:TS:del:CARDKEY
  function parseIK(k) {
    if (!k || k.slice(0, 6) !== 'apply:') return null;
    var rest = k.slice(6);
    var i = rest.indexOf(':');
    if (i < 0) return null;
    var afterTs = rest.slice(i + 1);
    if (afterTs.slice(0, 5) === 'push:') return { action: 'push', cardKey: afterTs.slice(5) };
    if (afterTs.slice(0, 4) === 'del:')  return { action: 'del',  cardKey: afterTs.slice(4) };
    return null;
  }

  // ──── Card markers ─────────────────────────────────────────────────
  function escAttr(v) {
    return (window.CSS && CSS.escape) ? CSS.escape(v) : String(v).replace(/"/g, '\\"');
  }
  function markCards(state, cardKeys) {
    cardKeys.forEach(function (k) {
      var card = document.querySelector('.zm-card[data-key="' + escAttr(k) + '"]');
      if (card) {
        if (state) card.dataset.applyState = state;
        else       delete card.dataset.applyState;
      }
    });
  }

  // ──── Progress banner ──────────────────────────────────────────────
  // Tone is mapped to cPanel's native .alert-* class so we inherit the
  // chrome's colour palette, icon emphasis and dark-mode tweaks instead
  // of redefining all of it.
  var TONE_CLASS = {
    '':        'alert-info',
    'info':    'alert-info',
    'success': 'alert-success',
    'warn':    'alert-warning',
    'error':   'alert-danger',
  };
  var TONE_ICON = {
    '':        'glyphicon-refresh',
    'info':    'glyphicon-refresh',
    'success': 'glyphicon-ok-sign',
    'warn':    'glyphicon-exclamation-sign',
    'error':   'glyphicon-remove-sign',
  };
  function show(tone) {
    if (!progress) return;
    var cls = TONE_CLASS[tone || ''] || 'alert-info';
    progress.classList.remove('alert-info', 'alert-success', 'alert-warning', 'alert-danger');
    progress.classList.add(cls);
    var icon = progress.querySelector('.zm-progress-icon');
    if (icon) {
      icon.classList.remove('glyphicon-refresh', 'glyphicon-ok-sign', 'glyphicon-exclamation-sign', 'glyphicon-remove-sign');
      icon.classList.add(TONE_ICON[tone || ''] || 'glyphicon-refresh');
    }
    progress.style.display = '';
  }
  function hide() { if (progress) progress.style.display = 'none'; }
  function setBar(p)        { if (bar) bar.style.width = Math.max(0, Math.min(100, p)) + '%'; }
  function setText(t, d)    { if (title) title.textContent = t; if (detail) detail.textContent = d; }
  function setActions(html) { if (actions) actions.innerHTML = html; }

  // ──── Batch state in sessionStorage ────────────────────────────────
  function loadBatch() {
    try {
      var raw = sessionStorage.getItem(BATCH_KEY);
      if (!raw) return null;
      var b = JSON.parse(raw);
      if (!b.ts || (Date.now() - b.ts) > 600000) { sessionStorage.removeItem(BATCH_KEY); return null; }
      return b;
    } catch (_) { return null; }
  }
  function saveBatch(b)  { try { sessionStorage.setItem(BATCH_KEY, JSON.stringify(b)); } catch (_) {} }
  function clearBatch()  { try { sessionStorage.removeItem(BATCH_KEY); } catch (_) {} }

  // ──── Poll loop ────────────────────────────────────────────────────
  var poll = {
    timer: null,
    maxDepth: 0,
    lastDepth: -1,
    lastChange: 0,
    deadBaseline: 0,
    cardKeys: [],
    autoRefresh: true,
    syncStateStart: '',
    stuckShown: false,
  };

  function startPolling(opts) {
    opts = opts || {};
    if (poll.timer) clearInterval(poll.timer);
    poll.maxDepth       = opts.initialDepth || 0;
    poll.lastDepth      = -1;
    poll.lastChange     = Date.now();
    poll.deadBaseline   = opts.initialDead || 0;
    poll.cardKeys       = (opts.cardKeys || []).slice();
    poll.autoRefresh    = opts.autoRefresh !== false;
    poll.syncStateStart = opts.syncStateStart || '';
    poll.stuckShown     = false;
    if (poll.cardKeys.length) markCards('applying', poll.cardKeys);
    tick();
    poll.timer = setInterval(tick, POLL_MS);
  }

  function stopPolling() {
    if (poll.timer) { clearInterval(poll.timer); poll.timer = null; }
  }

  function tick() {
    fetch(window.location.pathname + '?action=queue_status', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store',
    })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
      .then(onTick)
      .catch(function (e) {
        setText('Connection issue', 'Could not reach the server (' + (e.message || e) + '). Will retry.');
      });
  }

  function onTick(data) {
    if (!data) return;
    var depth = (typeof data.queue_depth === 'number') ? data.queue_depth : 0;
    var dead  = (typeof data.dead_letters === 'number') ? data.dead_letters : 0;
    var pending = data.pending_keys || [];
    var syncState = data.sync_state || '';

    // Diff-computation mode: reload when the sync_state changes (i.e. the
    // daemon finished pending_diff/computing_diff and produced a fresh
    // diff or failed).
    if (poll.syncStateStart) {
      if (syncState && syncState !== poll.syncStateStart) {
        stopPolling();
        window.location.reload();
      }
      return;
    }

    if (depth > poll.maxDepth) poll.maxDepth = depth;
    if (depth !== poll.lastDepth) {
      poll.lastDepth = depth;
      poll.lastChange = Date.now();
      poll.stuckShown = false;
    }

    // Per-card update — only for keys belonging to our current batch.
    if (poll.cardKeys.length) {
      var stillPending = {};
      pending.forEach(function (k) { var p = parseIK(k); if (p) stillPending[p.cardKey] = true; });
      var newlyDone = [];
      var stillApplying = [];
      poll.cardKeys.forEach(function (k) {
        if (stillPending[k]) stillApplying.push(k); else newlyDone.push(k);
      });
      if (dead > poll.deadBaseline) {
        // We can't tell exactly which keys failed; mark the most-recently-
        // finished ones as failed and bump the baseline so we don't re-mark.
        markCards('failed', newlyDone);
        poll.deadBaseline = dead;
      } else {
        markCards('applied', newlyDone);
      }
      markCards('applying', stillApplying);
      // Stop tracking finished keys so transitions don't reset every tick.
      poll.cardKeys = stillApplying;
    }

    // Drained → success / error final state.
    if (depth === 0) {
      stopPolling();
      clearBatch();
      if (dead > 0) {
        show('error');
        setText('Applied with errors', dead + ' event(s) in dead-letter — open the user log to inspect, then Retry.');
        setBar(100);
        setActions('<button type="button" class="btn btn-default" id="zm-prog-refresh">Refresh diff</button>');
        attachProgressActions();
      } else {
        show('success');
        setText('All changes applied', 'Cloudflare is in sync. Refreshing the diff…');
        setBar(100);
        setActions('');
        if (poll.autoRefresh) {
          setTimeout(triggerRefreshDiff, 1500);
        } else {
          setActions('<button type="button" class="btn btn-default" id="zm-prog-refresh">Refresh diff</button>');
          attachProgressActions();
        }
      }
      return;
    }

    // Still draining.
    var applied = Math.max(0, poll.maxDepth - depth);
    show('');
    setText(
      'Applying changes…',
      'Applied ' + applied + ' of ' + poll.maxDepth + ' — ' + depth + ' pending'
        + (dead > poll.deadBaseline ? ' · ' + (dead - poll.deadBaseline) + ' failed' : '') + '.'
    );
    setBar(poll.maxDepth > 0 ? (applied / poll.maxDepth) * 100 : 0);

    if (!poll.stuckShown && Date.now() - poll.lastChange > STUCK_MS) {
      show('warn');
      setText(
        'Queue not draining',
        depth + ' event(s) stuck for 30s+. The daemon may be slow or stopped — check `systemctl status zonemirrord`.'
      );
      poll.stuckShown = true;
    }
  }

  function attachProgressActions() {
    var b = document.getElementById('zm-prog-refresh');
    if (b) b.addEventListener('click', triggerRefreshDiff);
  }

  // ──── AJAX submit helper ───────────────────────────────────────────
  function ajaxSubmit(form, extras) {
    var fd = new FormData(form);
    if (extras) Object.keys(extras).forEach(function (k) { fd.append(k, extras[k]); });
    fd.set('csrf', csrf);
    return fetch(window.location.pathname, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: fd,
      cache: 'no-store',
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (data) {
        if (data && data.csrf) {
          csrf = data.csrf;
          document.querySelectorAll('input[type="hidden"][name="csrf"]').forEach(function (i) { i.value = csrf; });
        }
        if (!r.ok) {
          throw new Error((data && data.errors && data.errors.join('; ')) || ('HTTP ' + r.status));
        }
        if (data && data.ok === false) {
          throw new Error((data.errors && data.errors.join('; ')) || 'Request failed');
        }
        return data || {};
      });
    });
  }

  function triggerRefreshDiff() {
    var f = document.querySelector('form[data-zm-form="refresh"]');
    if (!f) { window.location.reload(); return; }
    ajaxSubmit(f).then(function () { window.location.reload(); })
                  .catch(function () { window.location.reload(); });
  }

  // ──── Apply form (AJAX + per-card state) ───────────────────────────
  var applyForm = document.querySelector('form[data-zm-form="apply"]');
  if (applyForm) {
    applyForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var submitter = e.submitter;
      var extras = null;
      var cTitle = '', cMsg = '';
      var needConfirm = false;

      if (submitter && submitter.name === 'apply_status') {
        extras = { apply_status: submitter.value };
        cTitle = submitter.getAttribute('data-zm-confirm-title') || '';
        cMsg   = submitter.getAttribute('data-zm-confirm-msg')   || '';
        needConfirm = !!cMsg;
      } else {
        var checked = applyForm.querySelectorAll(
          'input[name="push_keys[]"]:checked, input[name="delete_keys[]"]:checked'
        ).length;
        if (checked === 0) return;
      }

      (needConfirm ? confirmDialog(cTitle, cMsg) : Promise.resolve(true)).then(function (ok) {
        if (ok) runApply(applyForm, extras);
      });
    });
  }

  function runApply(form, extras) {
    var applyBtn = document.getElementById('zm-apply-btn');
    if (applyBtn) { applyBtn.disabled = true; applyBtn.textContent = 'Applying…'; }

    show('');
    setText('Sending to ZoneMirror…', 'Enqueueing your changes.');
    setBar(2);
    setActions('');

    ajaxSubmit(form, extras).then(function (data) {
      var meta       = data.apply || {};
      var pushKeys   = meta.push_keys   || [];
      var deleteKeys = meta.delete_keys || [];
      var allKeys    = pushKeys.concat(deleteKeys);
      var enqueued   = allKeys.length;
      var depth      = data.queue_depth  || 0;
      var dead       = data.dead_letters || 0;

      if (enqueued === 0) {
        show('warn');
        setText('Nothing enqueued', data.message || 'No changes were applied.');
        setBar(0);
        if (applyBtn) { applyBtn.disabled = false; applyBtn.textContent = 'Apply selected changes'; }
        return;
      }

      saveBatch({ ts: Date.now(), cardKeys: allKeys, enqueued: enqueued });

      // Clean the submitted checkboxes from the form so the user can keep
      // working with the remaining rows.
      form.querySelectorAll(
        'input[name="push_keys[]"]:checked, input[name="delete_keys[]"]:checked'
      ).forEach(function (cb) {
        if (allKeys.indexOf(cb.value) !== -1) {
          cb.checked = false;
          cb.dataset.userChecked = '0';
        }
      });
      var counter = document.getElementById('zm-selected-count');
      if (counter) counter.textContent = '0';
      if (applyBtn) { applyBtn.disabled = true; applyBtn.textContent = 'Apply selected changes'; }

      startPolling({
        initialDepth: depth,
        initialDead:  dead,
        cardKeys:     allKeys,
        autoRefresh:  true,
      });
    }).catch(function (err) {
      show('error');
      setText('Apply failed', err.message || String(err));
      setBar(0);
      if (applyBtn) { applyBtn.disabled = false; applyBtn.textContent = 'Apply selected changes'; }
    });
  }

  // ──── Refresh form (AJAX) ──────────────────────────────────────────
  var refreshForm = document.querySelector('form[data-zm-form="refresh"]');
  if (refreshForm) {
    refreshForm.addEventListener('submit', function (e) {
      e.preventDefault();
      ajaxSubmit(refreshForm).then(function () { window.location.reload(); })
                              .catch(function () { window.location.reload(); });
    });
  }

  // ──── Confirm-only forms (Disconnect, and any future destructive
  // single-shot action). These keep their classic full-page POST after
  // the dialog answers OK — that's exactly what we want for a
  // navigation-changing action.
  document.querySelectorAll('form[data-zm-confirm-msg]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (f.dataset.zmConfirmed === '1') { f.dataset.zmConfirmed = ''; return; }
      e.preventDefault();
      confirmDialog(
        f.getAttribute('data-zm-confirm-title') || 'Confirm',
        f.getAttribute('data-zm-confirm-msg')   || ''
      ).then(function (ok) {
        if (!ok) return;
        f.dataset.zmConfirmed = '1';
        if (typeof f.requestSubmit === 'function') f.requestSubmit();
        else f.submit();
      });
    });
  });

  // ──── Manage Locks panel ───────────────────────────────────────────
  // Toggle, scope-driven field visibility, add/remove via AJAX. The
  // panel mirrors LockStorage's scopes: the scope dropdown decides
  // which of {type, name, content, priority} the form requires.
  var locksToggle = document.getElementById('zm-locks-toggle');
  var locksPanel  = document.getElementById('zm-locks-panel');
  var locksList   = document.getElementById('zm-locks-list');
  var locksCount  = document.getElementById('zm-locks-count');
  var lockAddForm = document.getElementById('zm-lock-add-form');
  var lockScopeEl = document.getElementById('zm-lock-scope');

  function updateLocksCount(n) {
    if (!locksCount) return;
    locksCount.textContent = String(n);
    locksCount.classList.toggle('has-locks', n > 0);
  }

  function updateScopeFields() {
    if (!lockAddForm || !lockScopeEl) return;
    var s = lockScopeEl.value;
    // Required field set per scope. Anything not in this set gets
    // hidden so the form is honest about what's actually required.
    var requires = {
      zone:      [],
      subtree:   ['name'],
      name:      ['name'],
      type_name: ['type', 'name'],
      exact:     ['type', 'name', 'content'], // priority is optional MX-only
    }[s] || [];
    lockAddForm.querySelectorAll('[data-scope-field]').forEach(function (lab) {
      var f = lab.getAttribute('data-scope-field');
      var visible = requires.indexOf(f) !== -1
        || (s === 'exact' && f === 'priority');
      lab.hidden = !visible;
    });
  }

  if (locksToggle && locksPanel) {
    locksToggle.addEventListener('click', function () {
      var open = !locksPanel.hidden;
      locksPanel.hidden = open;
      locksToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
      if (!open) updateScopeFields();
    });
  }
  if (lockScopeEl) {
    lockScopeEl.addEventListener('change', updateScopeFields);
    updateScopeFields();
  }

  // Remove lock (delegated, so newly-added rows respond too).
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.zm-lock-remove');
    if (!btn || !locksList || !locksList.contains(btn)) return;
    e.preventDefault();
    var lockId = btn.getAttribute('data-lock-id') || '';
    if (!lockId) return;
    var row = btn.closest('.zm-lock-row');

    var fd = new FormData();
    fd.set('action', 'remove_lock');
    fd.set('lock_id', lockId);
    fd.set('csrf', csrf);
    fetch(window.location.pathname, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: fd,
      cache: 'no-store',
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (data) {
        if (data && data.csrf) {
          csrf = data.csrf;
          document.querySelectorAll('input[type="hidden"][name="csrf"]').forEach(function (i) { i.value = csrf; });
        }
        if (!r.ok || (data && data.ok === false)) {
          throw new Error((data && data.errors && data.errors.join('; ')) || ('HTTP ' + r.status));
        }
        if (row) row.remove();
        var remaining = locksList.querySelectorAll('.zm-lock-row').length;
        updateLocksCount(remaining);
        // Reload so the diff cards lose their padlock affordance. We
        // could patch the DOM but lock matching is non-trivial (zone
        // / subtree etc.) and a reload is the simplest correct path.
        window.location.reload();
      });
    }).catch(function (err) {
      show('error');
      setText('Could not remove lock', err.message || String(err));
      setBar(0);
    });
  });

  // Add lock.
  if (lockAddForm) {
    lockAddForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(lockAddForm);
      fd.set('csrf', csrf);
      fetch(window.location.pathname, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: fd,
        cache: 'no-store',
      }).then(function (r) {
        return r.json().catch(function () { return null; }).then(function (data) {
          if (data && data.csrf) {
            csrf = data.csrf;
            document.querySelectorAll('input[type="hidden"][name="csrf"]').forEach(function (i) { i.value = csrf; });
          }
          if (!r.ok || (data && data.ok === false)) {
            throw new Error((data && data.errors && data.errors.join('; ')) || ('HTTP ' + r.status));
          }
          window.location.reload();
        });
      }).catch(function (err) {
        show('error');
        setText('Could not add lock', err.message || String(err));
        setBar(0);
      });
    });
  }

  // ──── Per-card lock toggle ─────────────────────────────────────────
  // The padlock button on each card POSTs action=toggle_lock with the
  // card's data-key. We optimistically swap data-locked + the icon
  // class, then revert on error. The matching apply checkbox is hidden
  // (and unticked) while locked so a bulk-select cannot drag the row
  // into the next push.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.zm-lock-btn');
    if (!btn) return;
    e.preventDefault();
    var card = btn.closest('.zm-card');
    if (!card) return;
    var key = btn.getAttribute('data-key') || '';
    var wasLocked = btn.getAttribute('data-locked') === '1';
    var nowLocked = !wasLocked;

    // Optimistic update. The icon glyph itself is driven by CSS off
    // data-locked, so we only need to flip the attribute.
    btn.setAttribute('data-locked', nowLocked ? '1' : '0');
    card.setAttribute('data-locked', nowLocked ? '1' : '0');
    var label = btn.querySelector('.zm-lock-label');
    if (label) label.textContent = nowLocked ? 'Locked' : '';
    // Hide / show the matching apply checkbox; identical cards already
    // hide it for a different reason, leave them alone.
    var cb = card.querySelector('input[name="push_keys[]"], input[name="delete_keys[]"]');
    if (cb && card.dataset.status !== 'identical') {
      if (nowLocked) {
        cb.checked = false;
        cb.dataset.userChecked = '0';
        cb.style.visibility = 'hidden';
      } else {
        cb.style.visibility = '';
      }
    }

    var fd = new FormData();
    fd.set('action', 'toggle_lock');
    fd.set('lock_key', key);
    fd.set('csrf', csrf);
    fetch(window.location.pathname, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: fd,
      cache: 'no-store',
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (data) {
        if (data && data.csrf) {
          csrf = data.csrf;
          document.querySelectorAll('input[type="hidden"][name="csrf"]').forEach(function (i) { i.value = csrf; });
        }
        if (!r.ok || (data && data.ok === false)) {
          throw new Error((data && data.errors && data.errors.join('; ')) || ('HTTP ' + r.status));
        }
      });
    }).catch(function (err) {
      // Revert on failure.
      btn.setAttribute('data-locked', wasLocked ? '1' : '0');
      card.setAttribute('data-locked', wasLocked ? '1' : '0');
      if (label) label.textContent = wasLocked ? 'Locked' : '';
      if (cb && card.dataset.status !== 'identical') {
        cb.style.visibility = '';
      }
      show('error');
      setText('Lock toggle failed', err.message || String(err));
      setBar(0);
    });
  });

  // Bulk-select must skip locked rows. The existing handler picks
  // every visible card; we intercept the selection just before it
  // ticks the checkbox.
  document.querySelectorAll('.zm-select-row .zm-link').forEach(function (btn) {
    btn.addEventListener('click', function () {
      // After the original handler runs, untick anything locked.
      setTimeout(function () {
        document.querySelectorAll('.zm-card[data-locked="1"]').forEach(function (card) {
          var cb = card.querySelector('input[name="push_keys[]"], input[name="delete_keys[]"]');
          if (cb) cb.checked = false;
        });
        var counter = document.getElementById('zm-selected-count');
        if (counter) {
          counter.textContent = document.querySelectorAll(
            'input[name="push_keys[]"]:checked, input[name="delete_keys[]"]:checked'
          ).length;
        }
        var applyBtn = document.getElementById('zm-apply-btn');
        if (applyBtn) {
          applyBtn.disabled = counter && parseInt(counter.textContent, 10) === 0;
        }
      }, 0);
    });
  });

  // ──── Bootstrap on page load ───────────────────────────────────────
  if (progress) {
    var initialDepth = parseInt(progress.dataset.initialDepth || '0', 10) || 0;
    var initialDead  = parseInt(progress.dataset.initialDead  || '0', 10) || 0;
    var initialState = progress.dataset.syncState || '';
    var saved = loadBatch();

    if (initialState === 'pending_diff' || initialState === 'computing_diff') {
      // Diff-computation poll: cheap, just watches sync_state. The
      // existing "Computing diff…" callout already explains what's
      // happening visually; we just need to reload when it's done.
      startPolling({ syncStateStart: initialState });
    } else if (saved && saved.cardKeys && saved.cardKeys.length) {
      startPolling({
        initialDepth: Math.max(initialDepth, saved.enqueued || 0),
        initialDead:  initialDead,
        cardKeys:     saved.cardKeys,
        autoRefresh:  true,
      });
    } else if (initialDepth > 0) {
      // Background drain we didn't initiate — show progress but don't
      // auto-refresh out from under whatever the user is doing.
      startPolling({
        initialDepth: initialDepth,
        initialDead:  initialDead,
        cardKeys:     [],
        autoRefresh:  false,
      });
    } else if (initialDead > 0) {
      show('error');
      setText('Previous failures', initialDead + ' event(s) in dead-letter. Check the daemon logs and consider re-running Apply.');
      setBar(100);
    }
  }
})();
</script>

<?php
print $cpanel->footer();
$cpanel->end();


// ─── helpers ─────────────────────────────────────────────────────────────

/**
 * Render a cPanel-style alert. Matches the Bootstrap 3 markup the
 * Jupiter chrome uses for its own Angular alertService, so our static
 * messages blend in instead of looking like a third-party widget. Title
 * is optional; when omitted the standard "Error:/Warning:/etc." label
 * for the given type is used.
 *
 * The body string is treated as already-safe HTML — callers that take
 * user input must pre-escape via $h().
 */
function zm_alert(string $type, string $bodyHtml, ?string $title = null, string $extraStyle = ''): string
{
    [$cls, $icon, $defaultTitle] = match ($type) {
        'success' => ['alert-success', 'glyphicon-ok-sign',         'Success:'],
        'warning' => ['alert-warning', 'glyphicon-exclamation-sign','Warning:'],
        'danger', 'error' => ['alert-danger',  'glyphicon-remove-sign',     'Error:'],
        default   => ['alert-info',    'glyphicon-info-sign',       'Info:'],
    };
    $titleHtml = $title === null
        ? '<strong class="alert-title">' . htmlspecialchars($defaultTitle, ENT_QUOTES) . '</strong> '
        : ($title === '' ? '' : '<strong class="alert-title">' . $title . '</strong> ');
    $style = $extraStyle === '' ? '' : ' style="' . htmlspecialchars($extraStyle, ENT_QUOTES) . '"';

    return '<div class="alert ' . $cls . '" role="alert"' . $style . '>'
        . '<span class="glyphicon ' . $icon . '" aria-hidden="true"></span>'
        . '<div class="alert-message">' . $titleHtml
        . '<span class="alert-body">' . $bodyHtml . '</span>'
        . '</div></div>';
}

/**
 * Render a single diff card. Picks the layout based on status:
 *   - cpanel_only   → single + line in green ("Create on Cloudflare")
 *   - cloudflare_only → single - line in red ("Delete from Cloudflare")
 *   - different     → two stacked lines with inline word-level highlighting
 *                     of the changed tokens, plus a field-level meta line
 *                     summarising TTL/proxied/priority/etc. changes
 *
 * The output is a full <div class="zm-card"> ready to be inserted into the
 * cards container. All untrusted strings go through $h() before printing.
 *
 * @param array<string, mixed> $e Diff entry from the persisted diff.json.
 */
function zm_render_card(array $e, callable $h): string
{
    $status = (string) ($e['status'] ?? '');
    $key    = (string) ($e['key'] ?? '');
    $type   = (string) ($e['type'] ?? '');
    $name   = (string) ($e['name'] ?? '');
    $local  = is_array($e['local']  ?? null) ? $e['local']  : null;
    $remote = is_array($e['remote'] ?? null) ? $e['remote'] : null;

    [$labelText, $labelClass] = match ($status) {
        DnsDiff::STATUS_DIFFERENT       => ['Update', 'replace'],
        DnsDiff::STATUS_CPANEL_ONLY     => ['Create', 'create'],
        DnsDiff::STATUS_CLOUDFLARE_ONLY => ['Delete', 'delete'],
        default                          => ['No change', 'noop'],
    };
    $checkboxName = $status === DnsDiff::STATUS_CLOUDFLARE_ONLY ? 'delete_keys[]' : 'push_keys[]';

    if ($status === DnsDiff::STATUS_DIFFERENT && $local !== null && $remote !== null) {
        $body = zm_render_update_body($local, $remote, $h);
    } elseif ($status === DnsDiff::STATUS_CPANEL_ONLY && $local !== null) {
        $body = zm_render_create_body($local, $h);
    } elseif ($status === DnsDiff::STATUS_CLOUDFLARE_ONLY && $remote !== null) {
        $body = zm_render_delete_body($remote, $h);
    } elseif ($status === DnsDiff::STATUS_IDENTICAL && $local !== null) {
        $body = zm_render_noop_body($local, $h);
    } else {
        $body = '';
    }

    // Proxy override row — only for A/AAAA/CNAME records (the rrtypes
    // Cloudflare can actually proxy) and never on delete cards (the
    // record is going away). On identical cards the toggle is the only
    // way to interact with the row, which is the whole point of showing
    // them in the same card layout.
    $proxySource = $local ?? $remote;
    $upperType   = strtoupper($type);
    $canProxy    = in_array($upperType, ['A', 'AAAA', 'CNAME'], true)
        && $status !== DnsDiff::STATUS_CLOUDFLARE_ONLY
        && is_array($proxySource)
        && array_key_exists('proxied', $proxySource)
        && $proxySource['proxied'] !== null;
    $actions = '';
    if ($canProxy) {
        $currentlyProxied = (bool) $proxySource['proxied'];
        $actions = sprintf(
            '<div class="zm-actions">'
            . '<button type="button" class="zm-toggle-proxy" data-key="%s" data-state="%s" data-original="%s" title="Click to toggle proxy">'
            . '<span class="zm-toggle-cloud">%s</span><span class="zm-toggle-label">%s</span>'
            . '</button>'
            . '<input type="hidden" name="proxy_override[%s]" value="" data-key="%s">'
            . '<span class="zm-override-hint">override pending — will be pushed</span>'
            . '</div>',
            $h($key),
            $currentlyProxied ? '1' : '0',
            $currentlyProxied ? '1' : '0',
            zm_cloud_swap($currentlyProxied ? ZM_PROXIED_MARK : ZM_DNSONLY_MARK),
            $currentlyProxied ? 'Proxied' : 'DNS only',
            $h($key),
            $h($key),
        );
    }

    $locked      = !empty($e['locked']);
    $lockReason  = (string) ($e['lock_reason'] ?? '');
    $hideCheckbox = $status === DnsDiff::STATUS_IDENTICAL || $locked;
    $checkbox = sprintf(
        '<input type="checkbox" name="%s" value="%s"%s>',
        $h($checkboxName),
        $h($key),
        $hideCheckbox ? ' style="visibility:hidden"' : '',
    );

    // Lock affordance. Lives in the card head next to the type pill so
    // it's visible without expanding the body. The JS controller wires
    // up the click to an AJAX toggle_lock POST; on success the card
    // gains/loses data-locked and the checkbox is suppressed.
    // Two inline SVGs — closed lock and open lock — and CSS decides
    // which one is shown based on data-locked + :hover. Hand-drawn
    // Heroicons-style outline, currentColor so it inherits the
    // button's text colour. Jupiter's glyphicon set is missing an
    // open-padlock glyph, hence inline SVG instead.
    $svgClosed = '<svg class="zm-lock-svg zm-lock-svg-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<rect x="5" y="11" width="14" height="9" rx="2"/>'
        . '<path d="M8 11V7a4 4 0 0 1 8 0v4"/>'
        . '</svg>';
    $svgOpen = '<svg class="zm-lock-svg zm-lock-svg-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<rect x="5" y="11" width="14" height="9" rx="2"/>'
        . '<path d="M8 11V7a4 4 0 0 1 7.5-2"/>'
        . '</svg>';
    $lockBtn = sprintf(
        '<button type="button" class="zm-lock-btn" data-key="%s" data-locked="%s" title="%s">'
        . '<span class="zm-lock-icon" aria-hidden="true">%s%s</span>'
        . '<span class="zm-lock-label">%s</span>'
        . '</button>',
        $h($key),
        $locked ? '1' : '0',
        $h($locked
            ? ($lockReason !== '' ? 'Locked: ' . $lockReason : 'Locked — ZoneMirror will not sync this row')
            : 'Click to lock this row (ZoneMirror will skip it on every apply)'),
        $svgClosed,
        $svgOpen,
        $locked ? 'Locked' : '',
    );

    return sprintf(
        '<div class="zm-card status-%s" data-status="%s" data-key="%s" data-locked="%s">'
        . '<label class="zm-card-head">%s'
        . '<span class="zm-pill %s">%s</span>'
        . '<span class="zm-rtype">%s</span>'
        . '<span class="zm-rname">%s</span>'
        . '%s'
        . '</label>'
        . '<div class="zm-card-body">%s%s</div>'
        . '</div>',
        $h($status),
        $h($status),
        $h($key),
        $locked ? '1' : '0',
        $checkbox,
        $h($labelClass),
        $h($labelText),
        $h($type),
        $h($name),
        $lockBtn,
        $body,
        $actions,
    );
}

/**
 * Body for an identical card. Just the matching content plus the same
 * TTL / proxied / priority strip the create + delete bodies use, so the
 * user can confirm at a glance that the row really matches in every
 * dimension (not just content).
 *
 * @param array<string, mixed> $local
 */
function zm_render_noop_body(array $local, callable $h): string
{
    $txt = zm_format_record($local);
    $out = '<div class="zm-line zm-line-noop">' . zm_cloud_swap($h($txt)) . '</div>';
    $out .= zm_render_meta_strip($local, $h);

    return $out;
}

/**
 * Shared meta-line for create / delete / noop cards. Update has its own
 * change-aware strip in zm_render_update_body(). We list whichever
 * fields are present and non-null so the line stays short for record
 * types that don't carry a given attribute (e.g. TXT has no priority).
 *
 * @param array<string, mixed> $rec
 */
function zm_render_meta_strip(array $rec, callable $h): string
{
    $bits = [];
    $ttl = isset($rec['ttl']) ? (int) $rec['ttl'] : 0;
    if ($ttl > 0) {
        $bits[] = '<strong>TTL</strong>: ' . $h(zm_fmt_ttl($ttl));
    }
    if (array_key_exists('proxied', $rec) && $rec['proxied'] !== null) {
        $bits[] = '<strong>Proxy</strong>: ' . zm_fmt_field_value('proxied', $rec['proxied']);
    }
    if (array_key_exists('priority', $rec) && $rec['priority'] !== null) {
        $bits[] = '<strong>Priority</strong>: ' . $h((string) (int) $rec['priority']);
    }
    if ($bits === []) {
        return '';
    }

    return '<div class="zm-meta">' . zm_cloud_swap(implode(' &nbsp;·&nbsp; ', $bits)) . '</div>';
}

/**
 * Stacked "- old / + new" lines with per-field meta. Inline word-level
 * highlighting on the content row makes the actual change pop (especially
 * useful for SPF strings where a single mechanism may be added in the
 * middle, or DMARC where the rua/ruf chunk is appended).
 *
 * @param array<string, mixed> $local
 * @param array<string, mixed> $remote
 */
function zm_render_update_body(array $local, array $remote, callable $h): string
{
    $localTxt  = zm_format_record($local);
    $remoteTxt = zm_format_record($remote);

    $inline = zm_inline_diff($remoteTxt, $localTxt, $h);

    $out  = '<div class="zm-line zm-line-del">- ' . zm_cloud_swap($inline['before']) . '</div>';
    $out .= '<div class="zm-line zm-line-add">+ ' . zm_cloud_swap($inline['after'])  . '</div>';

    // Field-level annotations for everything that isn't the main content
    // line — TTL, proxied, priority, structured data. The user usually
    // skim-reads this strip to confirm "yes, just the TTL changed".
    $changes = zm_field_changes($local, $remote);
    unset($changes['content']); // already visualised inline above
    $bits = [];
    foreach ($changes as $field => $bf) {
        $bits[] = sprintf(
            '<strong>%s</strong>: <span class="v-old">%s</span><span class="arrow">→</span><span class="v-new">%s</span>',
            $h($field),
            $h(zm_fmt_field_value($field, $bf['before'])),
            $h(zm_fmt_field_value($field, $bf['after'])),
        );
    }
    if ($bits !== []) {
        $out .= '<div class="zm-meta">' . zm_cloud_swap(implode(' &nbsp;·&nbsp; ', $bits)) . '</div>';
    }

    return $out;
}

/**
 * @param array<string, mixed> $local
 */
function zm_render_create_body(array $local, callable $h): string
{
    $txt = zm_format_record($local);
    $out = '<div class="zm-line zm-line-add">+ ' . zm_cloud_swap($h($txt)) . '</div>';
    $out .= zm_render_meta_strip($local, $h);

    return $out;
}

/**
 * @param array<string, mixed> $remote
 */
function zm_render_delete_body(array $remote, callable $h): string
{
    $txt = zm_format_record($remote);
    // Delete rows are the only destructive action in the diff. Make the
    // intent loud: a banner above the - line says what the user is doing
    // and where the record came from (it wasn't in cPanel, so it must
    // have been created directly in the Cloudflare dashboard at some
    // point — exactly the sort of change a user wants to double-check
    // before nuking it).
    $out  = '<div class="zm-warn">'
        . 'This record exists on <strong>Cloudflare only</strong> — it&rsquo;s not in your cPanel zone. '
        . 'It was likely added by hand in the Cloudflare dashboard. Ticking the box will remove it.'
        . '</div>';
    $out .= '<div class="zm-line zm-line-del">- ' . zm_cloud_swap($h($txt)) . '</div>';
    $out .= zm_render_meta_strip($remote, $h);

    return $out;
}

/**
 * Word-level diff of two strings. Tokens are split on whitespace (keeping
 * the whitespace as its own token so we can faithfully reconstruct the
 * line). Renders the result twice: a "before" string with deletions wrapped
 * in <del> and a corresponding "after" with insertions wrapped in <ins>;
 * equal tokens appear plain in both.
 *
 * For very long strings (m*n > 100k) we fall back to a coarse whole-string
 * mark — the LCS table would blow memory and the user can't read it
 * anyway. SPF/DMARC sizes are tiny so this never trips in practice.
 *
 * @return array{before: string, after: string}
 */
function zm_inline_diff(string $before, string $after, callable $h): array
{
    if ($before === $after) {
        $esc = $h($before);

        return ['before' => $esc, 'after' => $esc];
    }
    $a = $before === '' ? [] : (preg_split('/(\s+)/', $before, -1, PREG_SPLIT_DELIM_CAPTURE) ?: []);
    $b = $after  === '' ? [] : (preg_split('/(\s+)/', $after,  -1, PREG_SPLIT_DELIM_CAPTURE) ?: []);
    $m = count($a);
    $n = count($b);

    if ($m * $n > 100000) {
        return [
            'before' => '<del>' . $h($before) . '</del>',
            'after'  => '<ins>' . $h($after)  . '</ins>',
        ];
    }

    $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    for ($i = 1; $i <= $m; $i++) {
        for ($j = 1; $j <= $n; $j++) {
            if ($a[$i - 1] === $b[$j - 1]) {
                $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
            } else {
                $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }
    }

    $ops = [];
    $i = $m;
    $j = $n;
    while ($i > 0 || $j > 0) {
        if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
            $ops[] = ['eq', $a[$i - 1]];
            $i--;
            $j--;
        } elseif ($j > 0 && ($i === 0 || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
            $ops[] = ['add', $b[$j - 1]];
            $j--;
        } else {
            $ops[] = ['del', $a[$i - 1]];
            $i--;
        }
    }
    $ops = array_reverse($ops);

    $beforeHtml = '';
    $afterHtml  = '';
    foreach ($ops as $op) {
        $esc = $h($op[1]);
        if ($op[0] === 'eq') {
            $beforeHtml .= $esc;
            $afterHtml  .= $esc;
        } elseif ($op[0] === 'del') {
            $beforeHtml .= '<del>' . $esc . '</del>';
        } else { // add
            $afterHtml .= '<ins>' . $esc . '</ins>';
        }
    }

    return ['before' => $beforeHtml, 'after' => $afterHtml];
}

/**
 * Per-field diff between a cPanel record and the Cloudflare row. Returns
 * a map of changed-field-name → {before, after}. Excludes equal fields
 * entirely so the caller only iterates over what actually moved.
 *
 * Mirrors ComputeDiff::recordsMatch() in what counts as "different":
 *   - content (skipped for SRV/CAA which use `data` instead)
 *   - ttl (informational — ComputeDiff intentionally ignores TTL for
 *          equality, but we still surface it to the operator)
 *   - proxied
 *   - priority (mostly MX)
 *   - data.priority / data.weight / data.port / data.target (SRV)
 *   - data.flags / data.tag / data.value (CAA)
 *
 * @param array<string, mixed> $local
 * @param array<string, mixed> $remote
 * @return array<string, array{before: mixed, after: mixed}>
 */
function zm_field_changes(array $local, array $remote): array
{
    $changes = [];
    $type = strtoupper((string) ($local['type'] ?? $remote['type'] ?? ''));

    if (!in_array($type, ['SRV', 'CAA'], true)) {
        $l = isset($local['content'])  ? (string) $local['content']  : null;
        $r = isset($remote['content']) ? (string) $remote['content'] : null;
        if ($l !== $r) {
            $changes['content'] = ['before' => $r, 'after' => $l];
        }
    }

    $lt = isset($local['ttl'])  ? (int) $local['ttl']  : 0;
    $rt = isset($remote['ttl']) ? (int) $remote['ttl'] : 0;
    // Skip the TTL row when Cloudflare is on "Auto" (ttl=1) — pushing the
    // record will set it to cPanel's value, but practically nothing in DNS
    // resolution changes, so the diff treats this as noise instead of a
    // real difference. ComputeDiff already excludes TTL from the match
    // check for the same reason. We only surface TTL when both sides have
    // a meaningful explicit value AND they disagree.
    if ($lt > 0 && $rt > 1 && $lt !== $rt) {
        $changes['ttl'] = ['before' => $rt, 'after' => $lt];
    }

    if (
        array_key_exists('proxied', $local) && array_key_exists('proxied', $remote)
        && $local['proxied'] !== null && $remote['proxied'] !== null
        && (bool) $local['proxied'] !== (bool) $remote['proxied']
    ) {
        $changes['proxied'] = ['before' => $remote['proxied'], 'after' => $local['proxied']];
    }

    if (
        isset($local['priority']) && isset($remote['priority'])
        && (int) $local['priority'] !== (int) $remote['priority']
    ) {
        $changes['priority'] = ['before' => (int) $remote['priority'], 'after' => (int) $local['priority']];
    }

    if (in_array($type, ['SRV', 'CAA'], true)) {
        $ld = is_array($local['data']  ?? null) ? $local['data']  : [];
        $rd = is_array($remote['data'] ?? null) ? $remote['data'] : [];
        $keys = $type === 'SRV' ? ['priority', 'weight', 'port', 'target'] : ['flags', 'tag', 'value'];
        foreach ($keys as $k) {
            $a = $ld[$k] ?? null;
            $b = $rd[$k] ?? null;
            if ((string) $a !== (string) $b) {
                $changes['data.' . $k] = ['before' => $b, 'after' => $a];
            }
        }
    }

    return $changes;
}

function zm_fmt_field_value(string $field, mixed $v): string
{
    if ($field === 'ttl') {
        return zm_fmt_ttl((int) $v);
    }
    if ($field === 'proxied') {
        // Sentinel; zm_cloud_swap() rewrites to a cloud SVG. Using the
        // marker char keeps the label compact in the meta line.
        return ((bool) $v) ? ZM_PROXIED_MARK : ZM_DNSONLY_MARK;
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if ($v === null) {
        return '—';
    }

    return (string) $v;
}

function zm_fmt_ttl(int $ttl): string
{
    return $ttl === 1 ? 'Auto' : (string) $ttl;
}

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
    // Proxy hint only makes sense for the three rrtypes Cloudflare can
    // actually proxy. cPanel/CF still ship a `proxied: false` field on TXT
    // and friends; tagging a TXT just clutters the diff. We emit a private-
    // use sentinel char here, not the SVG, so the LCS word diff still sees
    // a single stable token. zm_cloud_swap() rewrites it after escaping.
    if (
        in_array($type, ['A', 'AAAA', 'CNAME'], true)
        && array_key_exists('proxied', $payload)
        && $payload['proxied'] !== null
    ) {
        $bits[] = $payload['proxied'] ? ZM_PROXIED_MARK : ZM_DNSONLY_MARK;
    }

    return $bits === [] ? '—' : implode(' ', $bits);
}

/**
 * Replace the private-use sentinel chars planted by zm_format_record() /
 * zm_fmt_field_value() with inline Cloudflare-style cloud SVGs. Run this
 * on a string that has ALREADY been passed through htmlspecialchars().
 * The sentinel codepoints (ZM_PROXIED_MARK / ZM_DNSONLY_MARK) are defined
 * at the top of this file. Idempotent and safe to call on strings with
 * no sentinels.
 */
function zm_cloud_swap(string $html): string
{
    static $proxiedSvg = null;
    static $dnsOnlySvg = null;
    if ($proxiedSvg === null) {
        // Material Design "cloud" outline filled — visually close to what
        // Cloudflare uses in its dashboard for the proxy toggle.
        $path = 'M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04'
            . 'A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24'
            . ' 5-5 0-2.64-2.05-4.78-4.65-4.96z';
        $svg = '<svg viewBox="0 0 24 24" width="20" height="14" fill="currentColor" aria-hidden="true"><path d="'
            . $path . '"/></svg>';
        $proxiedSvg = '<span class="zm-cloud zm-cloud-proxied" title="Proxied through Cloudflare">' . $svg . '</span>';
        $dnsOnlySvg = '<span class="zm-cloud zm-cloud-dnsonly" title="DNS only — not proxied">' . $svg . '</span>';
    }

    return strtr($html, [
        ZM_PROXIED_MARK => $proxiedSvg,
        ZM_DNSONLY_MARK => $dnsOnlySvg,
    ]);
}

/**
 * Return the cPanel domains that map 1:1 onto Cloudflare zones — i.e. the
 * apex domains the user owns (main + addon + parked). Subdomains are
 * intentionally excluded: in Cloudflare a subdomain is a record inside
 * its parent zone, not a zone of its own, and exposing them in the picker
 * just leads users to "connect" something that can't be a zone.
 *
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
            // Addons and parked aliases are independent apex domains in their
            // own right. sub_domains are NOT — they live inside one of the
            // above as DNS records and must not be offered as zone choices.
            foreach (['addon_domains', 'parked_domains'] as $k) {
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
            foreach (['addon_domains', 'parked_domains'] as $k) {
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
