#!/usr/bin/env bash
set -euo pipefail

PREFIX="/usr/local/cpanel/3rdparty/cf-sync"
LOGDIR="/var/log/cf-sync"

mkdir -p "$PREFIX" "$LOGDIR"
chmod 700 "$LOGDIR"

cp -R "$(dirname "$0")"/* "$PREFIX"/

# UI link
mkdir -p /usr/local/cpanel/base/frontend/jupiter/cloudflare-sync
ln -sf "$PREFIX/ui/index.php" /usr/local/cpanel/base/frontend/jupiter/cloudflare-sync/index.php

# Hooks
bash "$PREFIX/hooks/register_hooks.sh"

# Systemd service
cp "$PREFIX/etc/systemd/cf-syncd.service" /etc/systemd/system/cf-syncd.service
systemctl daemon-reload
systemctl enable --now cf-syncd

# Register plugin
/usr/local/cpanel/bin/register_cpanelplugin "$PREFIX/cloudflare_sync.cpanelplugin"

echo "Installed Cloudflare DNS Sync plugin."
