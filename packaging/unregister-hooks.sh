#!/usr/bin/env bash
set -euo pipefail

PLUGIN_ID="zonemirror"
PREFIX="/usr/local/cpanel/3rdparty/${PLUGIN_ID}"

unregister() {
  local module_func="$1"
  local script="$2"
  /usr/local/cpanel/bin/manage_hooks delete script "$script" \
    --category "Cpanel" \
    --event "Uapi::$module_func" \
    --stage "post" \
    || true
  /usr/local/cpanel/bin/manage_hooks delete script "$script" \
    --category "Cpanel" \
    --event "Api2::$module_func" \
    --stage "post" \
    || true
}

unregister "ZoneEdit::add_zone_record"     "$PREFIX/bin/on_add_zone_record"
unregister "ZoneEdit::edit_zone_record"    "$PREFIX/bin/on_edit_zone_record"
unregister "ZoneEdit::remove_zone_record"  "$PREFIX/bin/on_remove_zone_record"
unregister "ZoneEdit::mass_edit_zone"      "$PREFIX/bin/on_mass_edit_zone"

echo "Hooks unregistered."
