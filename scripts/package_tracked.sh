#!/usr/bin/env bash
set -euo pipefail

# Create an archive of tracked files (committed/added) without .git or composer-installed artifacts.
# Usage: ./scripts/package_tracked.sh [output-path]

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT_DIR/artifacts"
mkdir -p "$OUT_DIR"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
OUT="${1:-$OUT_DIR/tracked-$STAMP.tar.gz}"

cd "$ROOT_DIR"

if ! command -v git >/dev/null 2>&1; then
  echo "git is required to list tracked files." >&2
  exit 1
fi

# Collect tracked files and filter out excluded paths.
# Excludes:
# - composer-installed paths
# - top-level tests/ and scripts/ directories
EXCLUDE_REGEX='^(vendor/|web/core/|web/modules/contrib/|web/themes/contrib/|tests/|scripts/|docs/)'
FILES=$(git ls-files | grep -Ev "$EXCLUDE_REGEX" || true)
if [[ -z "$FILES" ]]; then
  echo "No files to archive." >&2
  exit 0
fi

tar -czf "$OUT" -C "$ROOT_DIR" $FILES
echo "Tracked files archived to $OUT"
