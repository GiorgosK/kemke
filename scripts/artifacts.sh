#!/usr/bin/env bash
ddev drush sql-dump > db.sql
zip -9qr artifacts/db.sql.zip db.sql 
rm db.sql
pandoc SETUP.md -o artifacts/SETUP.pdf --pdf-engine=wkhtmltopdf
ddev exec php scripts/export_schema.php
pandoc docs/schema/schema-overview.md -o artifacts/schema-overview.pdf --pdf-engine=wkhtmltopdf
