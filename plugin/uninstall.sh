#!/usr/bin/env bash
set -euo pipefail

PREFIX="/usr/local/cpanel/3rdparty/cf-sync"

# Unregister plugin UI link
rm -f /usr/local/cpanel/base/frontend/jupiter/cloudflare-sync/index.php || true
rmdir --ignore-fail-on-non-empty /usr/local/cpanel/base/frontend/jupiter/cloudflare-sync || true

# Unregister hooks
/usr/local/cpanel/bin/manage_hooks list | awk '/cf-sync/ && /script/ {print $1}' | while read -r HOOKID; do
  /usr/local/cpanel/bin/manage_hooks delete id "$HOOKID" || true
done

# Stop and disable service
systemctl disable --now cf-syncd || true
rm -f /etc/systemd/system/cf-syncd.service || true
systemctl daemon-reload || true

# Remove installed files (keep user configs unless --purge)
if [[ "${1:-}" == "--purge" ]]; then
  rm -rf "$PREFIX" /var/log/cf-sync || true
else
  rm -rf "$PREFIX" || true
fi

echo "Uninstalled Cloudflare DNS Sync plugin."
