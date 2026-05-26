#!/usr/bin/env bash
set -euo pipefail

# Uninstalls the ZoneMirror plugin. Leaves /var/cpanel/zonemirror
# in place (contains config + queues) so reinstall recovers state. Pass --purge
# to also remove that directory and per-user state.
#
# Local DMARC rewrites: if the plugin has rewritten any _dmarc record in
# /var/named the operator is prompted before removal so the change is
# explicit. --keep-local-rewrites and --revert-local-rewrites skip the
# prompt. --purge implies --revert-local-rewrites (clean slate). Pass
# --yes / -y for fully non-interactive runs (CI, automation).

PLUGIN_ID="zonemirror"
PREFIX="/usr/local/cpanel/3rdparty/${PLUGIN_ID}"
SYSTEM_DIR="/var/cpanel/${PLUGIN_ID}"
SERVICE_NAME="${PLUGIN_ID}d"
SERVICE_PATH="/etc/systemd/system/${SERVICE_NAME}.service"
UPDATER_SERVICE_PATH="/etc/systemd/system/${SERVICE_NAME}-updater.service"
UPDATER_TIMER_PATH="/etc/systemd/system/${SERVICE_NAME}-updater.timer"
LIVEAPI_DIR="/usr/local/cpanel/base/frontend/jupiter/${PLUGIN_ID}"
WHM_DIR="/usr/local/cpanel/whostmgr/docroot/cgi/${PLUGIN_ID}"
ICON_TARGET_DIR="/usr/local/cpanel/base/unprotected/${PLUGIN_ID}"
DYNAMICUI_CONF="/usr/local/cpanel/base/frontend/jupiter/dynamicui/dynamicui_${PLUGIN_ID}.conf"
JUPITER_APP_ICON="/usr/local/cpanel/base/frontend/jupiter/assets/application_icons/${PLUGIN_ID}.png"
WHM_ADDON_ICON="/usr/local/cpanel/whostmgr/docroot/addon_plugins/${PLUGIN_ID}.png"
WHM_ADDON_ICON_LIGHT="/usr/local/cpanel/whostmgr/docroot/addon_plugins/${PLUGIN_ID}-light.png"
CLI_SYMLINK="/usr/local/bin/zonemirror"
LOCAL_REWRITES_FILE="${SYSTEM_DIR}/local-rewrites.json"

PURGE=false
KEEP_REWRITES=false
REVERT_REWRITES=false
ASSUME_YES=false
for arg in "$@"; do
  case "$arg" in
    --purge) PURGE=true; REVERT_REWRITES=true ;;
    --keep-local-rewrites) KEEP_REWRITES=true ;;
    --revert-local-rewrites) REVERT_REWRITES=true ;;
    -y|--yes) ASSUME_YES=true ;;
  esac
done

if [[ $EUID -ne 0 ]]; then
  echo "This uninstaller must run as root." >&2
  exit 1
fi

# Decide what to do with /var/named rewrites BEFORE we remove the plugin
# files (the revert path needs $PREFIX/bin/local-dmarc-cli.php on disk).
handle_local_rewrites() {
  if [[ ! -s "$LOCAL_REWRITES_FILE" ]]; then
    return 0
  fi
  if [[ ! -f "$PREFIX/bin/local-dmarc-cli.php" ]]; then
    echo "WARNING: $LOCAL_REWRITES_FILE present but plugin binaries already gone." >&2
    echo "         Cannot offer to revert; leaving the local-rewrites state file in place." >&2

    return 0
  fi

  local tracked_zones tracked_records
  tracked_zones=$(/usr/local/cpanel/3rdparty/bin/php "$PREFIX/bin/local-dmarc-cli.php" status --json 2>/dev/null \
    | sed -n 's/.*"tracked_zones": \([0-9]*\).*/\1/p' | head -n1)
  tracked_records=$(/usr/local/cpanel/3rdparty/bin/php "$PREFIX/bin/local-dmarc-cli.php" status --json 2>/dev/null \
    | sed -n 's/.*"tracked_records": \([0-9]*\).*/\1/p' | head -n1)
  tracked_zones="${tracked_zones:-0}"
  tracked_records="${tracked_records:-0}"

  if [[ "$tracked_records" == "0" ]]; then
    return 0
  fi

  local choice="$KEEP_REWRITES" decided=false
  if [[ "$REVERT_REWRITES" == "true" ]]; then
    decided=true
    choice="revert"
  elif [[ "$KEEP_REWRITES" == "true" ]]; then
    decided=true
    choice="keep"
  elif [[ "$ASSUME_YES" == "true" ]]; then
    # Non-interactive default: keep, because reverting touches DNS and
    # silently mutating slave zones during an automated uninstall is the
    # surprising option. Use --revert-local-rewrites to opt in explicitly.
    decided=true
    choice="keep"
  fi

  if [[ "$decided" != "true" ]]; then
    echo
    echo "ZoneMirror has rewritten ${tracked_records} _dmarc record(s) across ${tracked_zones} zone(s)"
    echo "under /var/named. If you remove the plugin without reverting, those records"
    echo "stay as-is and your DMARC reports keep flowing to the configured rua/ruf."
    echo
    echo "  [k] Keep current values (default)"
    echo "  [r] Revert each record to its pre-plugin value (bump SOA + reload PowerDNS)"
    echo "  [l] List affected zones before deciding"
    echo
    while true; do
      read -rp "Choose [k/r/l]: " ans
      case "${ans,,}" in
        ''|k|keep)   choice="keep"; break ;;
        r|revert)    choice="revert"; break ;;
        l|list)
          /usr/local/cpanel/3rdparty/bin/php "$PREFIX/bin/local-dmarc-cli.php" status
          echo
          ;;
        *) echo "Please answer k, r, or l." ;;
      esac
    done
  fi

  if [[ "$choice" == "revert" ]]; then
    /usr/local/cpanel/3rdparty/bin/php "$PREFIX/bin/local-dmarc-cli.php" revert --yes \
      || echo "WARNING: revert reported errors (see output above)." >&2
  else
    echo "Keeping ${tracked_records} local DMARC rewrite(s) in place."
  fi
}

handle_local_rewrites

systemctl disable --now "${SERVICE_NAME}-updater.timer" 2>/dev/null || true
systemctl disable --now "$SERVICE_NAME" 2>/dev/null || true
rm -f "$SERVICE_PATH" "$UPDATER_SERVICE_PATH" "$UPDATER_TIMER_PATH"
systemctl daemon-reload

if [[ -d "$PREFIX/packaging" ]]; then
  bash "$PREFIX/packaging/unregister-hooks.sh" || true
fi

/usr/local/cpanel/bin/unregister_cpanelplugin "$PREFIX/packaging/zonemirror.cpanelplugin" 2>/dev/null || true

# Remove the WHM appconfig entry (mirrors install.sh:register_whm_appconfig).
if [[ -x /usr/local/cpanel/bin/unregister_appconfig ]]; then
  /usr/local/cpanel/bin/unregister_appconfig "$PLUGIN_ID" >/dev/null 2>&1 || true
fi

rm -f "$CLI_SYMLINK" "$DYNAMICUI_CONF" "$JUPITER_APP_ICON" "$WHM_ADDON_ICON" "$WHM_ADDON_ICON_LIGHT"
rm -rf "$PREFIX" "$LIVEAPI_DIR" "$WHM_DIR" "$ICON_TARGET_DIR"

# Rebuild the Jupiter sprite sheet so the now-stale .icon-zonemirror class
# does not linger in icon_spritemap.css/svg/png.
if [[ -x /usr/local/cpanel/bin/sprite_generator ]]; then
  /usr/local/cpanel/bin/sprite_generator --theme=jupiter >/dev/null 2>&1 || true
fi

if $PURGE; then
  rm -rf "$SYSTEM_DIR"
  for h in /home/*; do
    user="$(basename "$h")"
    rm -rf "/home/$user/.zonemirror"
  done
fi

echo "Uninstalled."
