#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WKHTML_CSS="$ROOT_DIR/scripts/pandoc-wkhtml.css"
WKHTML_FOOTER="$ROOT_DIR/scripts/pandoc-footer.html"
WKHTML_FLAGS=(
  --pdf-engine=wkhtmltopdf
  --pdf-engine-opt=--user-style-sheet --pdf-engine-opt="$WKHTML_CSS"
  --pdf-engine-opt=--margin-top --pdf-engine-opt=10mm
  --pdf-engine-opt=--margin-bottom --pdf-engine-opt=10mm
  --pdf-engine-opt=--margin-left --pdf-engine-opt=25mm
  --pdf-engine-opt=--margin-right --pdf-engine-opt=25mm
  --pdf-engine-opt=--footer-html --pdf-engine-opt="$WKHTML_FOOTER"
)

run_package() {
  local db_dump
  db_dump="$ROOT_DIR/db.sql"

  mkdir -p "$ROOT_DIR/artifacts"

  ddev drush sql-dump > "$db_dump"
  zip -9qr "$ROOT_DIR/artifacts/db.sql.zip" "$db_dump"
  rm "$db_dump"

  "$SCRIPT_DIR/package_tracked.sh"
  pandoc "$ROOT_DIR/SETUP.md" -o "$ROOT_DIR/artifacts/SETUP.pdf" "${WKHTML_FLAGS[@]}"
}

run_schema() {
  mkdir -p "$ROOT_DIR/artifacts"

  (
    cd "$ROOT_DIR"
    ddev exec php scripts/export_schema.php
    pandoc docs/schema/schema-overview.md -o artifacts/schema-overview.pdf "${WKHTML_FLAGS[@]}"
  )
}

run_uat() {
  mkdir -p "$ROOT_DIR/artifacts"

  pandoc "$ROOT_DIR/tests/playwright/uat-scenarios.md" -o "$ROOT_DIR/artifacts/uat-scenarios.pdf" "${WKHTML_FLAGS[@]}"
}

print_usage() {
  cat <<EOF
Usage: $(basename "$0") [--package] [--schema] [--uat] [--help]

Without arguments, all sections run in order:
  1. --package
  2. --schema
  3. --uat
EOF
}

run_selected=false

if [[ $# -eq 0 ]]; then
  run_package
  run_schema
  run_uat
  exit 0
fi

for arg in "$@"; do
  case "$arg" in
    --package)
      run_package
      run_selected=true
      ;;
    --schema)
      run_schema
      run_selected=true
      ;;
    --uat)
      run_uat
      run_selected=true
      ;;
    --help|-h)
      print_usage
      exit 0
      ;;
    *)
      echo "Unknown option: $arg" >&2
      print_usage >&2
      exit 1
      ;;
  esac
done

if [[ "$run_selected" != true ]]; then
  print_usage >&2
  exit 1
fi
