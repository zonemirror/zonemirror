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
JUPITER_APP_ICONS_DIR="/usr/local/cpanel/base/frontend/jupiter/assets/application_icons"
WHM_ADDON_PLUGINS_DIR="/usr/local/cpanel/whostmgr/docroot/addon_plugins"
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
  # $SYSTEM_DIR is readable by cPanel-user PHP (hooks + UI both load
  # system.json from here). The log subdir stays root-only — the daemon
  # writes the global log, hooks/UI write per-user logs under
  # ~user/.zonemirror/log.txt instead.
  chmod 0755 "$SYSTEM_DIR"
  chmod 0700 "$SYSTEM_DIR/logs"
  rsync -a --delete \
    --exclude='.git/' --exclude='.github/' --exclude='tests/' \
    --exclude='node_modules/' --exclude='*.log' \
    "$src_root/" "$PREFIX/"
  ln -sfn "$PREFIX/resources/cpanel/index.live.php" "$LIVEAPI_DIR/index.live.php"
  ln -sfn "$PREFIX/resources/whm/index.live.php" "$WHM_DIR/index.live.php"
  # The WHM entry point is a Perl wrapper that prints the WHM chrome
  # (defheader/deffooter) around an iframe pointing back at the PHP
  # script above. PHP cannot call Whostmgr::HTMLInterface itself, so
  # without this wrapper the admin page renders without sidebar or
  # banner. The AppConfig manifest points at index.cgi as the primary
  # entryurl; index.live.php is kept as url2 for the iframe target.
  ln -sfn "$PREFIX/resources/whm/index.cgi" "$WHM_DIR/index.cgi"
  # Make sure the Jupiter app-icons and WHM addon-plugins dirs exist
  # (they are owned by cPanel and almost always present, but skipping the
  # check here would leave half-installed servers).
  [[ -d "$JUPITER_APP_ICONS_DIR" ]] || mkdir -p "$JUPITER_APP_ICONS_DIR"
  [[ -d "$WHM_ADDON_PLUGINS_DIR" ]] || mkdir -p "$WHM_ADDON_PLUGINS_DIR"

  # cPanel UI tile icon. Jupiter ingests <file>.png/.svg from
  # $JUPITER_APP_ICONS_DIR/ into a single sprite sheet at theme build time
  # AND at runtime via /usr/local/cpanel/bin/sprite_generator. dynamicui's
  # imgtype=icon then resolves to ".icon-<file>" against the sprite CSS,
  # not to a standalone <img>. If the icon is not in the sprite the tile
  # renders blank — which is what shipped with v0.1.0 before this fix.
  if [[ -f "$src_root/resources/assets/zonemirror-icon.png" ]]; then
    install -m 0644 "$src_root/resources/assets/zonemirror-icon.png" \
      "$JUPITER_APP_ICONS_DIR/${PLUGIN_ID}.png"
  elif [[ -f "$src_root/resources/assets/icon.png" ]]; then
    install -m 0644 "$src_root/resources/assets/icon.png" \
      "$JUPITER_APP_ICONS_DIR/${PLUGIN_ID}.png"
  fi

  # WHM addon-plugin listing icon. The "inverted" variant has colors that
  # contrast with WHM's dark sidebar.
  if [[ -f "$src_root/resources/assets/zonemirror-icon-inverted.png" ]]; then
    install -m 0644 "$src_root/resources/assets/zonemirror-icon-inverted.png" \
      "$WHM_ADDON_PLUGINS_DIR/${PLUGIN_ID}.png"
  fi

  # Legacy paths some templated themes still hit. Cheap to ship.
  if [[ -f "$src_root/resources/assets/zonemirror-icon.png" ]]; then
    install -m 0644 "$src_root/resources/assets/zonemirror-icon.png" "$ICON_TARGET_DIR/icon.png"
    install -m 0644 "$src_root/resources/assets/zonemirror-icon.png" "$LIVEAPI_DIR/icon.png"
  elif [[ -f "$src_root/resources/assets/icon.png" ]]; then
    install -m 0644 "$src_root/resources/assets/icon.png" "$ICON_TARGET_DIR/icon.png"
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

regenerate_sprites() {
  # Roll our newly-staged icon into the Jupiter sprite sheet so the tile
  # actually renders. Without this the .png sits next to the others on disk
  # but the CSS class .icon-<plugin> has no background-image rule.
  if [[ -x /usr/local/cpanel/bin/sprite_generator ]]; then
    /usr/local/cpanel/bin/sprite_generator --theme=jupiter >/dev/null 2>&1 || true
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

register_whm_appconfig() {
  # WHM > Plugins reads /var/cpanel/apps/<name>.conf. The conf is installed
  # via /usr/local/cpanel/bin/register_appconfig; it validates the manifest
  # and writes the apps/<name>.conf file (plus the menu hooks). Idempotent.
  if [[ -x /usr/local/cpanel/bin/register_appconfig ]]; then
    /usr/local/cpanel/bin/register_appconfig \
      "$PREFIX/packaging/${PLUGIN_ID}_whostmgr.conf" >/dev/null 2>&1 || true
  fi
}

register_plugin() {
  # register_cpanelplugin only understands the legacy "key:value" manifest
  # with the icon embedded as base64 in an `image:` line. We keep the
  # manifest in the repo as a small template (no icon) and append the
  # base64-encoded inverted asset here at install time — that way the
  # 1+ MB icon stays out of git history and can be swapped by replacing
  # resources/assets/zonemirror-icon-inverted.png without rewriting the
  # manifest.
  local tmp_cpp
  tmp_cpp="$(mktemp --suffix=.cpanelplugin)"
  {
    cat "$PREFIX/packaging/zonemirror.cpanelplugin"
    printf 'image:'
    base64 -w 76 "$PREFIX/resources/assets/zonemirror-icon-inverted.png"
  } > "$tmp_cpp"
  /usr/local/cpanel/bin/register_cpanelplugin "$tmp_cpp" || true
  rm -f "$tmp_cpp"
}

install_cli() {
  ln -sfn "$PREFIX/bin/zonemirror" "$CLI_SYMLINK"
}

fix_permissions() {
  chown -R root:root "$PREFIX" "$SYSTEM_DIR"
  find "$PREFIX" -type d -exec chmod 0755 {} \;
  find "$PREFIX" -type f -exec chmod 0644 {} \;
  chmod 0755 "$PREFIX"/bin/* "$PREFIX"/packaging/*.sh "$PREFIX"/resources/whm/index.cgi
  # See stage_files() for why $SYSTEM_DIR is 0755 and logs/ is 0700.
  chmod 0755 "$SYSTEM_DIR"
  chmod 0700 "$SYSTEM_DIR/logs"
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
  register_whm_appconfig
  regenerate_sprites
  print_summary
}

main "$@"
