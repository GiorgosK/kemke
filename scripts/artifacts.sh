#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WKHTML_CSS="$ROOT_DIR/scripts/pandoc-wkhtml.css"
WKHTML_FOOTER="$ROOT_DIR/scripts/pandoc-footer.html"
DOCX_FILTER="$ROOT_DIR/scripts/pandoc-docx.lua"
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
OUTPUT_FORMAT="pdf"
DOCX_FLAGS=(
  --lua-filter="$DOCX_FILTER"
)

render_markdown_artifact() {
  local source_file="$1"
  local output_stem="$2"

  case "$OUTPUT_FORMAT" in
    pdf)
      pandoc "$source_file" -o "$ROOT_DIR/artifacts/${output_stem}.pdf" "${WKHTML_FLAGS[@]}"
      ;;
    docx)
      pandoc "$source_file" -o "$ROOT_DIR/artifacts/${output_stem}.docx" "${DOCX_FLAGS[@]}"
      ;;
    both)
      pandoc "$source_file" -o "$ROOT_DIR/artifacts/${output_stem}.pdf" "${WKHTML_FLAGS[@]}"
      pandoc "$source_file" -o "$ROOT_DIR/artifacts/${output_stem}.docx" "${DOCX_FLAGS[@]}"
      ;;
  esac
}

render_html_artifact() {
  local source_file="$1"
  local output_stem="$2"

  case "$OUTPUT_FORMAT" in
    pdf)
      wkhtmltopdf "${WKHTMLTOPDF_FLAGS[@]}" "$source_file" "$ROOT_DIR/artifacts/${output_stem}.pdf"
      ;;
    docx)
      pandoc "$source_file" -o "$ROOT_DIR/artifacts/${output_stem}.docx" "${DOCX_FLAGS[@]}"
      ;;
    both)
      wkhtmltopdf "${WKHTMLTOPDF_FLAGS[@]}" "$source_file" "$ROOT_DIR/artifacts/${output_stem}.pdf"
      pandoc "$source_file" -o "$ROOT_DIR/artifacts/${output_stem}.docx" "${DOCX_FLAGS[@]}"
      ;;
  esac
}

set_output_format() {
  local format="$1"

  case "$format" in
    pdf|docx|both)
      OUTPUT_FORMAT="$format"
      format_selected=true
      ;;
    *)
      echo "Unsupported format: $format" >&2
      print_usage >&2
      exit 1
      ;;
  esac
}

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
    render_markdown_artifact docs/schema/schema-overview.md schema-overview
    render_markdown_artifact docs/architecture/custom-modules-overview.md custom-modules-overview
    render_html_artifact docs/api.html api
  )
}

run_setup() {
  mkdir -p "$ROOT_DIR/artifacts"

  (
    cd "$ROOT_DIR"
    render_markdown_artifact SETUP.md SETUP
  )
}

run_uat() {
  mkdir -p "$ROOT_DIR/artifacts"

  render_markdown_artifact "$ROOT_DIR/tests/playwright/uat-scenarios.md" uat-scenarios
}

print_usage() {
  cat <<EOF
Usage: $(basename "$0") [--package] [--db] [--setup] [--schema] [--uat] [--format pdf|docx|both] [--help]

Output format:
  --pdf              Generate document artifacts as PDF (default)
  --docx             Generate document artifacts as DOCX
  --both             Generate document artifacts as both PDF and DOCX
  --format FORMAT    FORMAT must be one of: pdf, docx, both

Without arguments, all sections run in order:
  1. --package
  2. --db
  3. --setup
  4. --schema
  5. --uat

If only a format flag is provided, document sections run in order:
  1. --setup
  2. --schema
  3. --uat
EOF
}

run_package_selected=false
run_db_selected=false
run_setup_selected=false
run_schema_selected=false
run_uat_selected=false
section_selected=false
format_selected=false

if [[ $# -eq 0 ]]; then
  run_package
  run_db
  run_setup
  run_schema
  run_uat
  exit 0
fi

while [[ $# -gt 0 ]]; do
  arg="$1"
  shift

  case "$arg" in
    --package)
      run_package_selected=true
      section_selected=true
      ;;
    --db)
      run_db_selected=true
      section_selected=true
      ;;
    --schema)
      run_schema_selected=true
      section_selected=true
      ;;
    --setup)
      run_setup_selected=true
      section_selected=true
      ;;
    --uat)
      run_uat_selected=true
      section_selected=true
      ;;
    --pdf)
      set_output_format "pdf"
      ;;
    --docx)
      set_output_format "docx"
      ;;
    --both)
      set_output_format "both"
      ;;
    --format=*)
      set_output_format "${arg#--format=}"
      ;;
    --format)
      if [[ $# -eq 0 ]]; then
        echo "--format requires a value: pdf, docx, or both" >&2
        print_usage >&2
        exit 1
      fi
      set_output_format "$1"
      shift
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

if [[ "$section_selected" != true ]]; then
  if [[ "$format_selected" == true ]]; then
    run_setup_selected=true
    run_schema_selected=true
    run_uat_selected=true
  else
    print_usage >&2
    exit 1
  fi
fi

if [[ "$run_package_selected" == true ]]; then
  run_package
fi
if [[ "$run_db_selected" == true ]]; then
  run_db
fi
if [[ "$run_setup_selected" == true ]]; then
  run_setup
fi
if [[ "$run_schema_selected" == true ]]; then
  run_schema
fi
if [[ "$run_uat_selected" == true ]]; then
  run_uat
fi
