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
WKHTMLTOPDF_FLAGS=(
  --user-style-sheet "$WKHTML_CSS"
  --margin-top 10mm
  --margin-bottom 10mm
  --margin-left 25mm
  --margin-right 25mm
  --footer-html "$WKHTML_FOOTER"
)

run_package() {
  mkdir -p "$ROOT_DIR/artifacts"

  "$SCRIPT_DIR/package_tracked.sh"
}

run_db() {
  local db_dump
  local db_zip
  db_dump="$ROOT_DIR/artifacts/db.sql"
  db_zip="$ROOT_DIR/artifacts/db.zip"

  mkdir -p "$ROOT_DIR/artifacts"
  rm -f "$db_zip"
  ddev drush sql-dump > "$db_dump"
  (
    cd "$ROOT_DIR/artifacts"
    zip -9q db.zip db.sql
  )
  rm "$db_dump"
}

run_schema() {
  mkdir -p "$ROOT_DIR/artifacts"

  (
    cd "$ROOT_DIR"
    ddev exec php scripts/export_schema.php
    php scripts/export_custom_modules_overview.php
    pandoc SETUP.md -o artifacts/SETUP.pdf "${WKHTML_FLAGS[@]}"
    pandoc docs/schema/schema-overview.md -o artifacts/schema-overview.pdf "${WKHTML_FLAGS[@]}"
    pandoc docs/architecture/custom-modules-overview.md -o artifacts/custom-modules-overview.pdf "${WKHTML_FLAGS[@]}"
    wkhtmltopdf "${WKHTMLTOPDF_FLAGS[@]}" docs/api.html artifacts/api.pdf
  )
}

run_uat() {
  mkdir -p "$ROOT_DIR/artifacts"

  pandoc "$ROOT_DIR/tests/playwright/uat-scenarios.md" -o "$ROOT_DIR/artifacts/uat-scenarios.pdf" "${WKHTML_FLAGS[@]}"
}

print_usage() {
  cat <<EOF
Usage: $(basename "$0") [--package] [--db] [--schema] [--uat] [--help]

Without arguments, all sections run in order:
  1. --package
  2. --db
  3. --schema
  4. --uat
EOF
}

run_selected=false

if [[ $# -eq 0 ]]; then
  run_package
  run_db
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
    --db)
      run_db
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
