#!/usr/bin/env bash
set -euo pipefail

# Registers the standardized hooks against cPanel's hook system.
#
# Reality of the cPanel surface area for DNS edits, late 2025+:
#
#  - Jupiter's Zone Editor calls UAPI DNS::mass_edit_zone for every
#    add / edit / remove (one batched call per save). The legacy
#    UAPI ZoneEdit::add_zone_record / edit_zone_record / remove_zone_record
#    endpoints are gone in current cPanel — Cpanel/API/ZoneEdit.pm does
#    not exist any more — so hooks registered against them never fire.
#  - cPanel's hook dispatcher (Cpanel/EventHandler.pm) builds event
#    names as "UAPI::<module>::<func>" (all uppercase) but manage_hooks
#    accepts and stores whatever casing you pass — so a hook registered
#    as "Uapi::..." silently never matches. Register with UPPERCASE
#    "UAPI::..." to actually fire.
#  - Api2 ZoneEdit::* still exists on some installs and is occasionally
#    hit by older scripts / WHM tooling; we register it best-effort so
#    we don't lose those events on a mixed-version server.
#  - Api2 mass_edit_zone never existed; only the per-record endpoints
#    did, so we don't bother to register a non-DNS mass_edit hook.

PLUGIN_ID="zonemirror"
PREFIX="/usr/local/cpanel/3rdparty/${PLUGIN_ID}"
MANAGE="/usr/local/cpanel/bin/manage_hooks"

register() {
  local event="$1"
  local script="$2"
  "$MANAGE" add script "$script" \
    --manual \
    --category "Cpanel" \
    --event "$event" \
    --stage "post" \
    || true
}

# Modern cPanel (the only path that actually fires from the Jupiter UI).
# Note the uppercase "UAPI"; see comment block above.
register "UAPI::DNS::mass_edit_zone"     "$PREFIX/bin/on_mass_edit_zone"

# Email Deliverability (UAPI EmailAuth::*). These endpoints bypass
# DNS::mass_edit_zone entirely — EmailAuth's admin module talks
# straight to Cpanel::DnsUtils::Install, which writes the zone file
# without firing any DNS hook. Hook the UAPI dispatch so we still see
# the SPF / DMARC / DKIM mutations that the "Add the suggested
# records" button triggers.
register "UAPI::EmailAuth::install_spf_records"      "$PREFIX/bin/on_email_auth"
register "UAPI::EmailAuth::apply_dmarc"              "$PREFIX/bin/on_email_auth"
register "UAPI::EmailAuth::enable_dkim"              "$PREFIX/bin/on_email_auth"
register "UAPI::EmailAuth::install_dkim_private_keys" "$PREFIX/bin/on_email_auth"
register "UAPI::EmailAuth::ensure_dkim_keys_exist"   "$PREFIX/bin/on_email_auth"
register "UAPI::EmailAuth::disable_dkim"             "$PREFIX/bin/on_email_auth"
register "UAPI::EmailAuth::remove_dmarc"             "$PREFIX/bin/on_email_auth"

# Legacy Api2 surface, best-effort.
register "Api2::ZoneEdit::add_zone_record"     "$PREFIX/bin/on_add_zone_record"
register "Api2::ZoneEdit::edit_zone_record"    "$PREFIX/bin/on_edit_zone_record"
register "Api2::ZoneEdit::remove_zone_record"  "$PREFIX/bin/on_remove_zone_record"
register "Api2::ZoneEdit::mass_edit_zone"      "$PREFIX/bin/on_mass_edit_zone"

echo "Hooks registered (Uapi DNS + Api2 ZoneEdit fallback)."
