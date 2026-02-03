#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")"/.. && pwd)"
WRITE="false"

for arg in "$@"; do
  case "$arg" in
    --write|-w)
      WRITE="true"
      shift
      ;;
  esac
done

if ! command -v shfmt >/dev/null 2>&1; then
  echo "shfmt is required. Install e.g. 'brew install shfmt' or see https://github.com/mvdan/sh" >&2
  exit 1
fi

mapfile -t FILES < <(find "$ROOT_DIR" -type f \( -name "*.sh" -o -path "$ROOT_DIR/plugin/bin/*" -o -name "install.sh" -o -name "uninstall.sh" \) \
  -not -path "$ROOT_DIR/vendor/*" -not -path "$ROOT_DIR/node_modules/*")

if [[ ${#FILES[@]} -eq 0 ]]; then
  echo "No shell files found to format."
  exit 0
fi

format_one() {
  local file="$1"
  local width
  if grep -q $'^\t' "$file"; then
    width=0 # tabs
  else
    if grep -qE '^ {4,}' "$file"; then
      width=4
    elif grep -qE '^ {2,}' "$file"; then
      width=2
    else
      width=2
    fi
  fi

  if [[ "$WRITE" == "true" ]]; then
    shfmt -i "$width" -ci -bn -w "$file"
  else
    shfmt -i "$width" -ci -bn -d "$file" || true
  fi
}

# Process files serially for clarity; adjust to parallel if needed
for f in "${FILES[@]}"; do
  format_one "$f"
done

if [[ "$WRITE" != "true" ]]; then
  echo "Dry run complete. Re-run with --write to apply changes."
fi














