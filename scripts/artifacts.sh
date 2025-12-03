#!/usr/bin/env bash
ddev drush sql-dump > db.sql
zip -9qr artifacts/db.sql.zip db.sql 
rm db.sql
pandoc SETUP.md -o artifacts/SETUP.pdf --pdf-engine=wkhtmltopdf
cp docs/schema/schema-overview.pdf artifacts/schema-overview.pdf
