#!/bin/bash

# PHP (force login shell)
PHP_LINE="$(bash -lc 'php -v' 2>/dev/null | head -n1)"

if [ -n "$PHP_LINE" ]; then
  echo "PHP: ${PHP_LINE#PHP }"
else
  echo "PHP: not installed"
fi

# Apache
if command -v apache2 >/dev/null 2>&1; then
  echo "Apache: $(apache2 -v | awk -F': ' '/Server version/ {print $2}')"
elif command -v httpd >/dev/null 2>&1; then
  echo "Apache: $(httpd -v | awk -F': ' '/Server version/ {print $2}')"
else
  echo "Apache: not installed"
fi

# Nginx
if command -v nginx >/dev/null 2>&1; then
  echo "Nginx: $(nginx -v 2>&1 | awk '{print $3}')"
else
  echo "Nginx: not installed"
fi

# MySQL / MariaDB
if command -v mysql >/dev/null 2>&1; then
  echo "MySQL/MariaDB: $(mysql --version | sed 's/.*Distrib \(.*\),.*/\1/')"
else
  echo "MySQL/MariaDB: not installed"
fi
