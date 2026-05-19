#!/usr/bin/env bash
# One-line installer for Cloudflare DNS Sync for cPanel.
#
#   curl -fsSL https://raw.githubusercontent.com/BusiRocket/cpanel-cloudflare-dns-sync/main/packaging/bootstrap.sh | sudo bash
#
# Or with a pinned version:
#
#   curl -fsSL https://.../bootstrap.sh | sudo VERSION=v0.1.0 bash
#
# What it does:
#  1. Validates: root + cPanel + PHP >= 8.1.
#  2. Resolves the latest GitHub release (or honors $VERSION).
#  3. Downloads the release tarball and its .sha256 sidecar, verifies.
#  4. Extracts to /opt/cloudflare-dns-sync/releases/<ver>/ and atomically
#     swaps the symlink /opt/cloudflare-dns-sync/current -> that release.
#  5. Hands off to packaging/install.sh inside that release.
#
# Safe to re-run; will upgrade in place.

set -euo pipefail

GH_OWNER="${GH_OWNER:-BusiRocket}"
GH_REPO="${GH_REPO:-cpanel-cloudflare-dns-sync}"
ROOT="/opt/cloudflare-dns-sync"
RELEASES="$ROOT/releases"
CURRENT_LINK="$ROOT/current"
LOG_TAG="[cfsync-bootstrap]"

log()  { echo "$LOG_TAG $*"; }
fail() { echo "$LOG_TAG ERROR: $*" >&2; exit 1; }

require_root() {
  if [[ $EUID -ne 0 ]]; then
    fail "Run as root (sudo)."
  fi
}

require_cpanel() {
  command -v /usr/local/cpanel/bin/manage_hooks >/dev/null 2>&1 \
    || fail "cPanel/WHM not detected (manage_hooks missing)."
}

require_php() {
  local v
  if command -v /usr/local/cpanel/3rdparty/bin/php >/dev/null 2>&1; then
    v="$(/usr/local/cpanel/3rdparty/bin/php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  elif command -v php >/dev/null 2>&1; then
    v="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  else
    fail "PHP not found."
  fi
  if [[ "$(printf '%s\n8.1\n' "$v" | sort -V | head -n1)" != "8.1" ]]; then
    fail "PHP >= 8.1 required (found $v)."
  fi
  log "PHP $v OK."
}

resolve_version() {
  if [[ -n "${VERSION:-}" ]]; then
    echo "$VERSION"
    return
  fi
  local tag
  tag="$(curl -fsSL "https://api.github.com/repos/$GH_OWNER/$GH_REPO/releases/latest" \
    | grep -oE '"tag_name"[[:space:]]*:[[:space:]]*"[^"]+' \
    | sed -E 's/.*"([^"]+)$/\1/' \
    | head -n1 || true)"
  if [[ -z "$tag" ]]; then
    fail "Could not resolve latest release from GitHub. Set VERSION=v0.1.0 manually."
  fi
  echo "$tag"
}

download_and_verify() {
  local version="$1" ver_noprefix="${1#v}"
  local tarball="cloudflare-dns-sync-${ver_noprefix}.tar.gz"
  local base="https://github.com/$GH_OWNER/$GH_REPO/releases/download/$version"
  local tmp
  tmp="$(mktemp -d /tmp/cfsync-bootstrap.XXXXXX)"

  log "Downloading $version ..."
  curl -fsSL -o "$tmp/$tarball" "$base/$tarball" \
    || fail "Failed to download $base/$tarball"
  curl -fsSL -o "$tmp/$tarball.sha256" "$base/$tarball.sha256" \
    || fail "Failed to download checksum"

  log "Verifying checksum ..."
  (cd "$tmp" && sha256sum --check --status "$tarball.sha256") \
    || fail "Checksum verification failed."
  echo "$tmp/$tarball"
}

extract_and_link() {
  local tarball="$1" version="$2"
  local ver_noprefix="${version#v}"
  local target="$RELEASES/$ver_noprefix"

  mkdir -p "$RELEASES"
  if [[ -e "$target" ]]; then
    log "Release $ver_noprefix already staged, replacing."
    rm -rf "$target"
  fi
  mkdir -p "$target"

  # The tarball top-level dir is cloudflare-dns-sync-<ver>; strip it.
  tar -xzf "$tarball" -C "$target" --strip-components=1

  ln -sfn "$target" "$CURRENT_LINK"
  log "Linked $CURRENT_LINK -> $target"
}

prune_old_releases() {
  # Keep the 3 most recent releases for rollback.
  local kept=3
  mapfile -t dirs < <(ls -1dt "$RELEASES"/*/ 2>/dev/null | sed 's:/$::')
  if (( ${#dirs[@]} <= kept )); then return; fi
  for ((i=kept; i<${#dirs[@]}; i++)); do
    log "Pruning old release: ${dirs[$i]}"
    rm -rf "${dirs[$i]}"
  done
}

main() {
  require_root
  require_cpanel
  require_php

  local version tarball
  version="$(resolve_version)"
  log "Target version: $version"

  tarball="$(download_and_verify "$version")"
  extract_and_link "$tarball" "$version"
  prune_old_releases

  log "Handing off to packaging/install.sh ..."
  bash "$CURRENT_LINK/packaging/install.sh"
}

main "$@"
