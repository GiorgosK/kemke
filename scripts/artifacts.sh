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

ddev drush sql-dump > db.sql
zip -9qr artifacts/db.sql.zip db.sql 
rm db.sql
./package_tracked.sh
ddev exec php scripts/export_schema.php
pandoc SETUP.md -o artifacts/SETUP.pdf "${WKHTML_FLAGS[@]}"
pandoc docs/schema/schema-overview.md -o artifacts/schema-overview.pdf "${WKHTML_FLAGS[@]}"
pandoc tests/playwright/uat-scenarios.md -o artifacts/uat-scenarios.pdf "${WKHTML_FLAGS[@]}"
