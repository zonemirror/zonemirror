#!/usr/bin/env bash
set -euo pipefail

# Registers the standardized hooks against cPanel's hook system.
#
# For Cpanel-category hooks, manage_hooks expects an --event value of the
# form Api1::Module::function, Api2::Module::function, or
# Uapi::Module::function — not a --hookpoint argument. Modern cPanel UIs call
# UAPI for ZoneEdit, but we also register Api2 because legacy clients (and
# some scripted callers) still go through that path.

PLUGIN_ID="zonemirror"
PREFIX="/usr/local/cpanel/3rdparty/${PLUGIN_ID}"

register() {
  local module_func="$1"
  local script="$2"
  /usr/local/cpanel/bin/manage_hooks add script "$script" \
    --manual \
    --category "Cpanel" \
    --event "Uapi::$module_func" \
    --stage "post" \
    || true
  /usr/local/cpanel/bin/manage_hooks add script "$script" \
    --manual \
    --category "Cpanel" \
    --event "Api2::$module_func" \
    --stage "post" \
    || true
}

register "ZoneEdit::add_zone_record"     "$PREFIX/bin/on_add_zone_record"
register "ZoneEdit::edit_zone_record"    "$PREFIX/bin/on_edit_zone_record"
register "ZoneEdit::remove_zone_record"  "$PREFIX/bin/on_remove_zone_record"
register "ZoneEdit::mass_edit_zone"      "$PREFIX/bin/on_mass_edit_zone"

echo "Hooks registered (Uapi + Api2)."
