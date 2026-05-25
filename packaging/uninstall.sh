#!/usr/bin/env bash
set -euo pipefail

# Uninstalls the ZoneMirror plugin. Leaves /var/cpanel/zonemirror
# in place (contains config + queues) so reinstall recovers state. Pass --purge
# to also remove that directory and per-user state.

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
CLI_SYMLINK="/usr/local/bin/zonemirror"

PURGE=false
for arg in "$@"; do
  case "$arg" in
    --purge) PURGE=true ;;
  esac
done

if [[ $EUID -ne 0 ]]; then
  echo "This uninstaller must run as root." >&2
  exit 1
fi

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

rm -f "$CLI_SYMLINK" "$DYNAMICUI_CONF" "$JUPITER_APP_ICON" "$WHM_ADDON_ICON"
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
