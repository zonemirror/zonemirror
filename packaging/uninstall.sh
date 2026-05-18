#!/usr/bin/env bash
set -euo pipefail

# Uninstalls the Cloudflare DNS Sync plugin. Leaves /var/cpanel/cloudflare-dns-sync
# in place (contains config + queues) so reinstall recovers state. Pass --purge
# to also remove that directory.

PLUGIN_ID="cloudflare-dns-sync"
PREFIX="/usr/local/cpanel/3rdparty/${PLUGIN_ID}"
SYSTEM_DIR="/var/cpanel/${PLUGIN_ID}"
SERVICE_NAME="${PLUGIN_ID}d"
SERVICE_PATH="/etc/systemd/system/${SERVICE_NAME}.service"
LIVEAPI_DIR="/usr/local/cpanel/base/frontend/jupiter/${PLUGIN_ID}"
WHM_DIR="/usr/local/cpanel/whostmgr/docroot/cgi/${PLUGIN_ID}"

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

systemctl disable --now "$SERVICE_NAME" 2>/dev/null || true
rm -f "$SERVICE_PATH"
systemctl daemon-reload

if [[ -d "$PREFIX/packaging" ]]; then
  bash "$PREFIX/packaging/unregister-hooks.sh" || true
fi

/usr/local/cpanel/bin/unregister_cpanelplugin "$PREFIX/packaging/cloudflare_dns_sync.cpanelplugin" 2>/dev/null || true

rm -rf "$PREFIX" "$LIVEAPI_DIR" "$WHM_DIR"

if $PURGE; then
  rm -rf "$SYSTEM_DIR"
  # Per-user queues live under ~/.cloudflare-dns-sync; leave alone unless --purge.
  for h in /home/*; do
    user="$(basename "$h")"
    rm -rf "/home/$user/.cloudflare-dns-sync"
  done
fi

echo "Uninstalled."
