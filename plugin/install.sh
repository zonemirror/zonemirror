#!/usr/bin/env bash
set -euo pipefail

PLUGIN_NAME="Cloudflare DNS Sync"
PLUGIN_ID="cloudflare-dns-sync"
PREFIX="/usr/local/cpanel/3rdparty/${PLUGIN_ID}"
THEME_PATH="/usr/local/cpanel/base/frontend/jupiter/${PLUGIN_ID}"
SERVICE_PATH="/etc/systemd/system/${PLUGIN_ID}d.service"
LOGDIR="/var/log/${PLUGIN_ID}"

echo "🔧 Installing ${PLUGIN_NAME}..."

# 1️⃣ Create required directories
mkdir -p "$PREFIX" "$LOGDIR" "$THEME_PATH"
chmod 700 "$LOGDIR"

# 2️⃣ Copy plugin files
rsync -a --delete --exclude='.git' --exclude='.github' --exclude='tests' "$(dirname "$0")/" "$PREFIX/"

# 3️⃣ Link UI for cPanel (Jupiter theme)
ln -sf "$PREFIX/ui/index.php" "$THEME_PATH/index.php"

# 4️⃣ Register cPanel hooks
if bash "$PREFIX/hooks/register_hooks.sh"; then
  echo "✅ Registered cPanel hooks."
else
  echo "⚠️ Hook registration failed — check hooks/register_hooks.sh" >&2
fi

# 5️⃣ Install and enable systemd service
cp "$PREFIX/etc/systemd/${PLUGIN_ID}d.service" "$SERVICE_PATH"
systemctl daemon-reload
systemctl enable --now "${PLUGIN_ID}d"
echo "✅ Service ${PLUGIN_ID}d enabled and started."

# 6️⃣ Register cPanel plugin
/usr/local/cpanel/bin/register_cpanelplugin "$PREFIX/cloudflare_sync.cpanelplugin" || true
echo "✅ Registered plugin with cPanel."

# 7️⃣ Set correct ownership & permissions
chown -R root:root "$PREFIX"
find "$PREFIX" -type f -exec chmod 600 {} \;
find "$PREFIX" -type d -exec chmod 700 {} \;
chmod 755 "$PREFIX/install.sh" || true

echo "✅ ${PLUGIN_NAME} installed successfully."
echo "📍 UI available at: cPanel → Domains → ${PLUGIN_NAME}"
echo "📜 Logs: ${LOGDIR}/agent.log"
