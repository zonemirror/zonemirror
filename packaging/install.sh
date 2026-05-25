#!/usr/bin/env bash
set -euo pipefail

# Installs (or upgrades) the ZoneMirror plugin on a cPanel/WHM
# server. Requires: root, cPanel >= 108, PHP >= 8.1. Safe to re-run.

PLUGIN_ID="zonemirror"
PLUGIN_NAME="ZoneMirror"
PREFIX="/usr/local/cpanel/3rdparty/${PLUGIN_ID}"
SYSTEM_DIR="/var/cpanel/${PLUGIN_ID}"
SERVICE_NAME="${PLUGIN_ID}d"
SERVICE_PATH="/etc/systemd/system/${SERVICE_NAME}.service"
UPDATER_SERVICE_PATH="/etc/systemd/system/${SERVICE_NAME}-updater.service"
UPDATER_TIMER_PATH="/etc/systemd/system/${SERVICE_NAME}-updater.timer"
LIVEAPI_DIR="/usr/local/cpanel/base/frontend/jupiter/${PLUGIN_ID}"
WHM_DIR="/usr/local/cpanel/whostmgr/docroot/cgi/${PLUGIN_ID}"
ICON_TARGET_DIR="/usr/local/cpanel/base/unprotected/${PLUGIN_ID}"
DYNAMICUI_DIR="/usr/local/cpanel/base/frontend/jupiter/dynamicui"
CLI_SYMLINK="/usr/local/bin/zonemirror"

require_root() {
  if [[ $EUID -ne 0 ]]; then
    echo "This installer must run as root." >&2
    exit 1
  fi
}

require_cpanel() {
  if ! command -v /usr/local/cpanel/bin/manage_hooks >/dev/null 2>&1; then
    echo "cPanel/WHM does not appear to be installed (manage_hooks not found)." >&2
    exit 1
  fi
}

require_php() {
  local v
  v="$(/usr/local/cpanel/3rdparty/bin/php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  if [[ -z "$v" ]]; then
    v="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  fi
  if [[ -z "$v" ]]; then
    echo "PHP not found on PATH or in /usr/local/cpanel/3rdparty/bin/." >&2
    exit 1
  fi
  if [[ "$(printf '%s\n8.1\n' "$v" | sort -V | head -n1)" != "8.1" ]]; then
    echo "PHP >= 8.1 is required (found $v)." >&2
    exit 1
  fi
}

stage_files() {
  local src_root
  src_root="$(cd "$(dirname "${BASH_SOURCE[0]}")"/.. && pwd)"
  mkdir -p "$PREFIX" "$SYSTEM_DIR" "$SYSTEM_DIR/logs" "$LIVEAPI_DIR" "$WHM_DIR" "$ICON_TARGET_DIR"
  chmod 0700 "$SYSTEM_DIR" "$SYSTEM_DIR/logs"
  rsync -a --delete \
    --exclude='.git/' --exclude='.github/' --exclude='tests/' \
    --exclude='node_modules/' --exclude='*.log' \
    "$src_root/" "$PREFIX/"
  ln -sfn "$PREFIX/resources/cpanel/index.live.php" "$LIVEAPI_DIR/index.live.php"
  ln -sfn "$PREFIX/resources/whm/index.live.php" "$WHM_DIR/index.live.php"
  if [[ -f "$src_root/resources/assets/icon.png" ]]; then
    install -m 0644 "$src_root/resources/assets/icon.png" "$ICON_TARGET_DIR/icon.png"
    # Jupiter looks up the per-plugin icon at $LIVEAPI_DIR/icon.png when the
    # dynamicui conf has imgtype=icon. $ICON_TARGET_DIR (base/unprotected) is
    # only used by some templated paths; ship both for portability.
    install -m 0644 "$src_root/resources/assets/icon.png" "$LIVEAPI_DIR/icon.png"
  fi
  # Drop the dynamicui conf so Jupiter actually renders the plugin tile in
  # the sidebar. register_cpanelplugin installs the manifest and the feature
  # but NOT this file, so without it the plugin is invisible in the UI even
  # though hooks, daemon, and feature manager are all wired up correctly.
  if [[ -d "$DYNAMICUI_DIR" ]]; then
    cat >"$DYNAMICUI_DIR/dynamicui_${PLUGIN_ID}.conf" <<EOF
description=>\$LANG{'${PLUGIN_NAME}'},feature=>${PLUGIN_ID},file=>${PLUGIN_ID},group=>domains,height=>48,imgtype=>icon,itemdesc=>\$LANG{'${PLUGIN_NAME}'},itemorder=>50,searchtext=>${PLUGIN_ID} cloudflare dns sync zone editor,subtype=>img,type=>image,url=>${PLUGIN_ID}/index.live.php,width=>48
EOF
    chmod 0644 "$DYNAMICUI_DIR/dynamicui_${PLUGIN_ID}.conf"
  fi
}

install_composer_deps() {
  pushd "$PREFIX" >/dev/null
  if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader
  else
    echo "Composer not found; relying on vendored autoloader (must be staged)." >&2
    if [[ ! -f "$PREFIX/vendor/autoload.php" ]]; then
      echo "vendor/autoload.php missing. Install composer or vendor the tree before installing." >&2
      exit 1
    fi
  fi
  popd >/dev/null
}

register_hooks() {
  bash "$PREFIX/packaging/register-hooks.sh"
}

install_service() {
  install -m 0644 "$PREFIX/packaging/systemd/${SERVICE_NAME}.service" "$SERVICE_PATH"
  install -m 0644 "$PREFIX/packaging/systemd/${SERVICE_NAME}-updater.service" "$UPDATER_SERVICE_PATH"
  install -m 0644 "$PREFIX/packaging/systemd/${SERVICE_NAME}-updater.timer" "$UPDATER_TIMER_PATH"
  systemctl daemon-reload
  systemctl enable --now "$SERVICE_NAME"
  # Updater timer is NOT enabled by default — operators opt in via:
  #   sudo zonemirror auto-update on
  # If it was previously enabled, keep it running across upgrades.
  if systemctl --quiet is-enabled "${SERVICE_NAME}-updater.timer" 2>/dev/null; then
    systemctl restart "${SERVICE_NAME}-updater.timer" || true
  fi
}

register_plugin() {
  /usr/local/cpanel/bin/register_cpanelplugin "$PREFIX/packaging/zonemirror.cpanelplugin" || true
}

install_cli() {
  ln -sfn "$PREFIX/bin/zonemirror" "$CLI_SYMLINK"
}

fix_permissions() {
  chown -R root:root "$PREFIX" "$SYSTEM_DIR"
  find "$PREFIX" -type d -exec chmod 0755 {} \;
  find "$PREFIX" -type f -exec chmod 0644 {} \;
  chmod 0755 "$PREFIX"/bin/* "$PREFIX"/packaging/*.sh
  chmod 0700 "$SYSTEM_DIR" "$SYSTEM_DIR/logs"
}

print_summary() {
  local ver="unknown"
  [[ -f "$PREFIX/VERSION" ]] && ver="$(tr -d '[:space:]' < "$PREFIX/VERSION")"
  echo
  echo "Installed ZoneMirror v${ver}."
  echo " - cPanel UI       : Domains -> ${PLUGIN_NAME}"
  echo " - WHM UI          : Plugins -> ${PLUGIN_NAME}"
  echo " - Daemon          : systemctl status ${SERVICE_NAME}"
  echo " - Logs            : ${SYSTEM_DIR}/logs/zonemirror.log"
  echo " - CLI             : zonemirror help"
  echo " - Auto-update     : sudo zonemirror auto-update on   (off by default)"
  echo " - Manual update   : sudo zonemirror update"
}

main() {
  require_root
  require_cpanel
  require_php

  echo "==> Installing ${PLUGIN_NAME}"
  stage_files
  install_composer_deps
  fix_permissions
  register_hooks
  install_service
  install_cli
  register_plugin
  print_summary
}

main "$@"
