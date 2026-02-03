#!/usr/bin/env bash
set -euo pipefail

PLUGIN_ID="cloudflare-dns-sync"
PREFIX="/usr/local/cpanel/3rdparty/${PLUGIN_ID}"
THEME_PATH="/usr/local/cpanel/base/frontend/jupiter/${PLUGIN_ID}"
SERVICE_PATH="/etc/systemd/system/${PLUGIN_ID}d.service"

echo "🧹 Uninstalling Cloudflare DNS Sync..."

systemctl disable --now "${PLUGIN_ID}d" || true
/usr/local/cpanel/bin/unregister_cpanelplugin "${PREFIX}/cloudflare_dns_sync.cpanelplugin" || true

rm -rf "$PREFIX" "$THEME_PATH" "$SERVICE_PATH"
systemctl daemon-reload

echo "✅ Uninstallation complete."
