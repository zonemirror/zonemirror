#!/usr/bin/env bash
set -euo pipefail

# Register standardized hooks for ZoneEdit
/usr/local/cpanel/bin/manage_hooks add script /usr/local/cpanel/3rdparty/cf-sync/hooks/on_add_zone_record.php \
  --manual --category "Cpanel" --event "API" --stage "post" \
  --hookpoint "uapi=ZoneEdit::add_zone_record"

/usr/local/cpanel/bin/manage_hooks add script /usr/local/cpanel/3rdparty/cf-sync/hooks/on_edit_zone_record.php \
  --manual --category "Cpanel" --event "API" --stage "post" \
  --hookpoint "uapi=ZoneEdit::edit_zone_record"

/usr/local/cpanel/bin/manage_hooks add script /usr/local/cpanel/3rdparty/cf-sync/hooks/on_remove_zone_record.php \
  --manual --category "Cpanel" --event "API" --stage "post" \
  --hookpoint "uapi=ZoneEdit::remove_zone_record"

echo "cPanel hooks registered for cf-sync."
