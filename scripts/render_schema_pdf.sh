#!/usr/bin/env bash
set -euo pipefail

# Render docs/schema/schema-overview.md to PDF using pandoc.
# Usage: ./scripts/render_schema_pdf.sh

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT_DIR/docs/schema/schema-overview.md"
OUT="$ROOT_DIR/docs/schema/schema-overview.pdf"

if [[ ! -f "$SRC" ]]; then
  echo "Missing source markdown: $SRC" >&2
  exit 1
fi

if ! command -v pandoc >/dev/null 2>&1; then
  echo "pandoc is required but not found. Install pandoc (and a PDF engine like wkhtmltopdf/LaTeX) then rerun." >&2
  exit 1
fi

mkdir -p "$(dirname "$OUT")"
pandoc "$SRC" -o "$OUT" --pdf-engine=wkhtmltopdf
echo "PDF written to $OUT"
