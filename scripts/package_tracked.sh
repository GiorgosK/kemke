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
# - top-level .ddev/ and .vscode/ directories
# - root-level db.zip
EXCLUDE_REGEX='^(\.ddev/|\.vscode/|db\.zip$|vendor/|web/core/|web/modules/contrib/|web/themes/contrib/|tests/|scripts/|docs/)'
ENABLED_MODULE_DIRS="$(
  php -r '
    require "vendor/autoload.php";
    $config = Symfony\Component\Yaml\Yaml::parseFile("config/core.extension.yml");
    $enabled = array_keys($config["module"] ?? []);
    $dirs = [];
    foreach (glob("web/modules/custom/*", GLOB_ONLYDIR) ?: [] as $dir) {
      $name = basename($dir);
      if (in_array($name, $enabled, true)) {
        $dirs[] = $dir;
      }
    }
    sort($dirs);
    echo implode("\n", $dirs);
  '
)"

DISABLED_MODULE_REGEX=''
if [[ -n "$ENABLED_MODULE_DIRS" ]]; then
  DISABLED_MODULE_REGEX="$(
    find web/modules/custom -mindepth 1 -maxdepth 1 -type d \
      | grep -Fvx -f <(printf '%s\n' "$ENABLED_MODULE_DIRS") \
      | sed 's#[][\.^$*+?(){}|]#\\&#g' \
      | paste -sd'|' -
  )"
fi

FILES=$(git ls-files | grep -Ev "$EXCLUDE_REGEX" || true)
if [[ -n "$DISABLED_MODULE_REGEX" ]]; then
  FILES=$(printf '%s\n' "$FILES" | grep -Ev "^(${DISABLED_MODULE_REGEX})/" || true)
fi
if [[ -z "$FILES" ]]; then
  echo "No files to archive." >&2
  exit 0
fi

tar -czf "$OUT" -C "$ROOT_DIR" $FILES
echo "Tracked files archived to $OUT"
