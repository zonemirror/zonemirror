#!/usr/local/cpanel/3rdparty/bin/php
<?php

declare(strict_types=1);

/**
 * `zonemirror local-dmarc` worker — invoked from the bash dispatcher in
 * bin/zonemirror. Operator-facing CLI for the local-zone DMARC rewrite:
 *
 *   local-dmarc status   show the policy + how many zones the plugin tracks
 *   local-dmarc preview  dry-run; print per-zone "would_apply / skipped"
 *   local-dmarc apply    actually write /var/named, bump SOA, reload PDNS
 *   local-dmarc revert   restore every rewritten record to its pre-plugin value
 *   local-dmarc enable   flip system.json local_rewrite.enabled = true
 *   local-dmarc disable  flip system.json local_rewrite.enabled = false
 *
 * Operates as root only — we read /var/named, write /var/cpanel/zonemirror,
 * and call whmapi1; an unprivileged caller can't accomplish any of that.
 * The bash wrapper enforces the EUID check before dispatching here.
 */

use ZoneMirror\Application\ApplyLocalDmarc;
use ZoneMirror\Infrastructure\Storage\LocalRewriteState;
use ZoneMirror\Infrastructure\Storage\SystemConfigStorage;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — plugin not properly installed\n");
    exit(2);
}
require $autoload;

$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // script name
$sub = array_shift($argv) ?? 'status';

$systemStorage = new SystemConfigStorage();
$state = new LocalRewriteState();
$apply = new ApplyLocalDmarc($systemStorage);

$json = in_array('--json', $argv, true);
$assumeYes = in_array('--yes', $argv, true) || in_array('-y', $argv, true);

switch ($sub) {
    case 'status':
        cmd_status($systemStorage, $state, $json);

        break;
    case 'preview':
        cmd_preview($apply, $json);

        break;
    case 'apply':
        cmd_apply($apply, $assumeYes, $json);

        break;
    case 'revert':
        cmd_revert($apply, $state, $assumeYes, $json);

        break;
    case 'enable':
        cmd_toggle($systemStorage, true);

        break;
    case 'disable':
        cmd_toggle($systemStorage, false);

        break;
    default:
        fwrite(STDERR, "usage: zonemirror local-dmarc {status|preview|apply|revert|enable|disable} [--yes] [--json]\n");
        exit(2);
}

function cmd_status(SystemConfigStorage $systemStorage, LocalRewriteState $state, bool $json): void
{
    $cfg = $systemStorage->load();
    $template = (string) $cfg['email_normalization']['dmarc_template'];
    $local = $cfg['local_rewrite'];
    $payload = [
        'feature_enabled'          => $local['enabled'],
        'template'                 => $template,
        'overwrite_custom_rua'     => $local['overwrite_custom_rua'],
        'respect_has_custom_dmarc' => $local['respect_has_custom_dmarc'],
        'respect_user_locks'       => $local['respect_user_locks'],
        'exclude_zones'            => $local['exclude_zones'],
        'tracked_zones'            => $state->countZones(),
        'tracked_records'          => $state->countRecords(),
    ];
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

        return;
    }
    echo "Local DMARC rewrite status\n";
    echo "  feature enabled       : ", $local['enabled'] ? 'yes' : 'no', "\n";
    echo "  template              : ", $template !== '' ? $template : '(none — set it in WHM > ZoneMirror)', "\n";
    echo "  overwrite custom rua  : ", $local['overwrite_custom_rua'] ? 'yes' : 'no', "\n";
    echo "  respect has_custom    : ", $local['respect_has_custom_dmarc'] ? 'yes' : 'no', "\n";
    echo "  respect user locks    : ", $local['respect_user_locks'] ? 'yes' : 'no', "\n";
    echo "  excluded zones        : ", count($local['exclude_zones']) === 0 ? '(none)' : implode(', ', $local['exclude_zones']), "\n";
    echo "  tracked zones / recs  : ", $state->countZones(), ' zones / ', $state->countRecords(), " records\n";
}

function cmd_preview(ApplyLocalDmarc $apply, bool $json): void
{
    $plan = $apply->preview();
    if ($json) {
        echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

        return;
    }
    render_plan_table($plan, false);
}

function cmd_apply(ApplyLocalDmarc $apply, bool $assumeYes, bool $json): void
{
    $plan = $apply->preview();
    if (!$json) {
        render_plan_table($plan, false);
        $wouldApply = (int) ($plan['summary']['would_apply'] ?? 0);
        if ($wouldApply === 0) {
            echo "\nNothing to apply.\n";

            return;
        }
        if (!$assumeYes) {
            echo "\nApply the {$wouldApply} change(s) above to /var/named (writes + SOA bump + reload)? [y/N] ";
            $resp = trim((string) fgets(STDIN));
            if (strtolower($resp) !== 'y' && strtolower($resp) !== 'yes') {
                echo "Aborted.\n";

                return;
            }
        }
    }
    $result = $apply->apply('cli');
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

        return;
    }
    echo "\n=== APPLIED ===\n";
    render_plan_table($result, true);
    echo sprintf(
        "\nResult: %d applied, %d skipped, %d error.\n",
        $result['summary']['applied'] ?? 0,
        $result['summary']['skipped'] ?? 0,
        $result['summary']['error'] ?? 0,
    );
}

function cmd_revert(ApplyLocalDmarc $apply, LocalRewriteState $state, bool $assumeYes, bool $json): void
{
    if ($state->isEmpty()) {
        if ($json) {
            echo json_encode(['summary' => ['reverted' => 0, 'error' => 0, 'total' => 0], 'records' => []], JSON_PRETTY_PRINT), "\n";

            return;
        }
        echo "Nothing to revert (no recorded rewrites).\n";

        return;
    }
    if (!$json && !$assumeYes) {
        echo sprintf(
            "About to revert %d record(s) across %d zone(s) back to their pre-plugin values, bump SOA and reload PowerDNS.\n",
            $state->countRecords(),
            $state->countZones(),
        );
        echo "Proceed? [y/N] ";
        $resp = trim((string) fgets(STDIN));
        if (strtolower($resp) !== 'y' && strtolower($resp) !== 'yes') {
            echo "Aborted.\n";

            return;
        }
    }
    $result = $apply->revert();
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

        return;
    }
    foreach ($result['records'] as $r) {
        $line = sprintf(
            "  %-45s %-15s %s",
            $r['zone'],
            $r['owner'],
            $r['error'] === null ? 'reverted (reload=' . ($r['reload_method'] ?: 'n/a') . ')' : 'ERROR: ' . $r['error'],
        );
        echo $line, "\n";
    }
    echo sprintf(
        "\nResult: %d reverted, %d error, %d total.\n",
        $result['summary']['reverted'],
        $result['summary']['error'],
        $result['summary']['total'],
    );
}

function cmd_toggle(SystemConfigStorage $systemStorage, bool $on): void
{
    $cfg = $systemStorage->load();
    $cfg['local_rewrite']['enabled'] = $on;
    $systemStorage->save($cfg);
    echo "local_rewrite.enabled = ", $on ? 'true' : 'false', "\n";
}

/**
 * @param array{template: string, summary: array<string, int>, zones: list<array<string, mixed>>} $plan
 */
function render_plan_table(array $plan, bool $applied): void
{
    $tpl = (string) ($plan['template'] ?? '');
    echo "Template: ", $tpl !== '' ? $tpl : '(no template set)', "\n\n";
    if (!isset($plan['zones']) || !is_array($plan['zones']) || $plan['zones'] === []) {
        echo "No zones discovered under /var/named.\n";

        return;
    }
    $wantHeader = $applied ? 'OUTCOME' : 'PLAN';
    $col1 = 'ZONE';
    $col2 = 'OWNER';
    $col3 = 'RECORD';
    $col4 = $wantHeader;
    echo sprintf("  %-40s %-14s %-22s %s\n", $col1, $col2, $col3, $col4);
    echo sprintf("  %-40s %-14s %-22s %s\n", str_repeat('-', 40), str_repeat('-', 14), str_repeat('-', 22), str_repeat('-', 20));
    foreach ($plan['zones'] as $row) {
        if (($row['zone'] ?? '') === '*') {
            echo "  (global) ", $row['reason'] ?? '', "\n";

            continue;
        }
        $reason = (string) ($row['reason'] ?? '');
        $owner = (string) ($row['owner'] ?? '-');
        $recOwner = (string) ($row['record_owner'] ?? '-');
        $tag = match ($reason) {
            ApplyLocalDmarc::REASON_WOULD_APPLY      => 'WOULD APPLY',
            ApplyLocalDmarc::REASON_APPLIED          => 'APPLIED' . (($row['reload_method'] ?? '') !== '' ? ' (reload=' . $row['reload_method'] . ')' : ''),
            ApplyLocalDmarc::REASON_ALREADY          => 'skip: already matches',
            ApplyLocalDmarc::REASON_LOCKED           => 'skip: locked by user',
            ApplyLocalDmarc::REASON_CUSTOM_RUA       => 'skip: has custom rua/ruf',
            ApplyLocalDmarc::REASON_HAS_CUSTOM_DMARC => 'skip: cPanel flagged custom',
            ApplyLocalDmarc::REASON_EXCLUDED_ZONE    => 'skip: excluded zone',
            ApplyLocalDmarc::REASON_NO_DMARC_RECORD  => 'skip: no _dmarc in zone',
            ApplyLocalDmarc::REASON_APPLY_ERROR      => 'ERROR: ' . ($row['error'] ?? 'unknown'),
            default                                  => $reason,
        };
        echo sprintf("  %-40s %-14s %-22s %s\n", $row['zone'], $owner !== '' ? $owner : '-', $recOwner !== '' ? $recOwner : '-', $tag);
    }
    echo "\n";
    if (isset($plan['summary']) && is_array($plan['summary'])) {
        $bits = [];
        foreach ($plan['summary'] as $k => $v) {
            $bits[] = $k . '=' . $v;
        }
        echo 'Summary: ', implode(', ', $bits), "\n";
    }
}
