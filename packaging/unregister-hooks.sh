#!/usr/bin/env bash
set -euo pipefail

PLUGIN_ID="zonemirror"
PREFIX="/usr/local/cpanel/3rdparty/${PLUGIN_ID}"

unregister() {
  local event="$1"
  local script="$2"
  /usr/local/cpanel/bin/manage_hooks delete script "$script" \
    --category "Cpanel" \
    --event "$event" \
    --stage "post" \
    || true
}

# Current hooks.
unregister "UAPI::DNS::mass_edit_zone"            "$PREFIX/bin/on_mass_edit_zone"
unregister "Api2::ZoneEdit::add_zone_record"      "$PREFIX/bin/on_add_zone_record"
unregister "Api2::ZoneEdit::edit_zone_record"     "$PREFIX/bin/on_edit_zone_record"
unregister "Api2::ZoneEdit::remove_zone_record"   "$PREFIX/bin/on_remove_zone_record"
unregister "Api2::ZoneEdit::mass_edit_zone"       "$PREFIX/bin/on_mass_edit_zone"

# Legacy hooks (pre-2025 register-hooks.sh registered against the
# UAPI ZoneEdit endpoints that no longer exist, and against "Uapi::"
# capitalisation that cPanel's dispatcher silently ignored). Remove
# them too so an upgrade cleanly replaces the old registration set.
unregister "Uapi::DNS::mass_edit_zone"            "$PREFIX/bin/on_mass_edit_zone"
unregister "Uapi::ZoneEdit::add_zone_record"      "$PREFIX/bin/on_add_zone_record"
unregister "Uapi::ZoneEdit::edit_zone_record"     "$PREFIX/bin/on_edit_zone_record"
unregister "Uapi::ZoneEdit::remove_zone_record"   "$PREFIX/bin/on_remove_zone_record"
unregister "Uapi::ZoneEdit::mass_edit_zone"       "$PREFIX/bin/on_mass_edit_zone"

echo "Hooks unregistered."
