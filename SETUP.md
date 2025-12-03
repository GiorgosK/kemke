# Drupal project setup

Prerequisites: PHP 8.1+, Composer, MySQL/MariaDB, and a web server stack (or DDEV/Lando).

1) Install dependencies  
```
composer install
```

2) Create database and import snapshot  
```
mysql -u <user> -p -e "CREATE DATABASE drupal;"
mysql -u <user> -p drupal < db.sql
```

3) Configure settings  
- Copy `web/sites/default/default.settings.php` to `web/sites/default/settings.php` if not present.  
- Ensure database credentials in `settings.php` match the database you created.  
- Optional: include `settings.local.php` for overrides.

4) Run Drupal updates and config import  
```
cd web
php core/scripts/drupal quick-start || true   # optional bootstrap check
../vendor/bin/drush status
../vendor/bin/drush updb
../vendor/bin/drush cim -y
../vendor/bin/drush cr
```

5) Login  
```
../vendor/bin/drush uli
```

Notes  
- If using DDEV, ensure `.ddev/config.yaml` matches your PHP/DB versions, then `ddev start` and use `ddev exec` for the above commands.
