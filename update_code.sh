#!/bin/bash

# Drupal 11 Deployment Script - Enhanced
# Manual Git Authentication - Token is never stored
# Usage: sudo ./update_code.sh
# Always deploys from main branch only

set -e

DRUPAL_ROOT="/var/www/drupal"
DRUPAL_USER="www-data"
DRUPAL_GROUP="www-data"
BRANCH="main"
BACKUP_DIR="/var/backups/drupal"
BACKUP_RETENTION_DAYS=7
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/backup_$TIMESTAMP.tar.gz"
LOG_FILE="/var/log/drupal_deploy_$TIMESTAMP.log"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log() { echo -e "${GREEN}[✓]${NC} $1" | tee -a "$LOG_FILE"; }
warn() { echo -e "${YELLOW}[!]${NC} $1" | tee -a "$LOG_FILE"; }
error() { echo -e "${RED}[✗]${NC} $1" | tee -a "$LOG_FILE"; exit 1; }
info() { echo -e "${BLUE}[i]${NC} $1" | tee -a "$LOG_FILE"; }

# ========== INITIALIZATION ==========
[[ $EUID -ne 0 ]] && error "Πρέπει να τρέξεις με sudo"

mkdir -p "$BACKUP_DIR"
mkdir -p "$(dirname "$LOG_FILE")"

info "Έναρξη ανάπτυξης - $TIMESTAMP"
info "Log file: $LOG_FILE"

cd "$DRUPAL_ROOT"

# ========== PRE-DEPLOYMENT CHECKS ==========
info "Έλεγχοι προ-ανάπτυξης..."

# Check if Git repo exists
[[ ! -d .git ]] && error "Δεν είναι Git repository"

# Check if composer.json exists
[[ ! -f composer.json ]] && error "Δεν βρέθηκε composer.json"

# Check if Drush exists
[[ ! -f vendor/bin/drush ]] && error "Δεν βρέθηκε Drush"

log "Όλοι οι έλεγχοι πέρασαν"

# ========== DATABASE BACKUP ==========
info "Δημιουργία database backup..."

# Export database
DUMP_FILE="/tmp/drupal_backup_$TIMESTAMP.sql"
sudo -u "$DRUPAL_USER" ./vendor/bin/drush sql:dump --gzip --result-file="$DUMP_FILE"

if [[ -f "${DUMP_FILE}.gz" ]]; then
    log "Database backup δημιουργήθηκε"
else
    error "Αποτυχία δημιουργίας database backup"
fi

# ========== CODEBASE BACKUP ==========
info "Δημιουργία codebase backup..."

tar -czf "$BACKUP_FILE" \
    --exclude='node_modules' \
    --exclude='.git' \
    --exclude='vendor' \
    --exclude='web/sites/default/files' \
    -C "$(dirname "$DRUPAL_ROOT")" \
    "$(basename "$DRUPAL_ROOT")"

log "Backup δημιουργήθηκε: $BACKUP_FILE"

# ========== MAINTENANCE MODE ==========
info "Ενεργοποίηση Maintenance Mode..."
sudo -u "$DRUPAL_USER" ./vendor/bin/drush state:set system.maintenance_mode TRUE
log "Site είναι σε Maintenance Mode"

# ========== GIT PULL ==========
info "Ενημέρωση κώδικα από main branch..."
warn "Εισάγετε τα στοιχεία GitHub όταν ζητηθούν..."

if ! sudo -u "$DRUPAL_USER" git fetch origin; then
    error "Git fetch απέτυχε"
fi

if ! sudo -u "$DRUPAL_USER" git checkout "$BRANCH"; then
    error "Git checkout απέτυχε"
fi

if ! sudo -u "$DRUPAL_USER" git pull origin "$BRANCH"; then
    error "Git pull απέτυχε"
fi

log "Κώδικας ενημερώθηκε από main"

# ========== COMPOSER INSTALL ==========
info "Composer install..."

if ! sudo -u "$DRUPAL_USER" /usr/local/bin/composer install --no-dev --optimize-autoloader 2>&1 | tee -a "$LOG_FILE"; then
    warn "Composer install είχε προειδοποιήσεις"
fi

log "Dependencies εγκατάστησαν"

# ========== DRUPAL UPDATES ==========
info "Εκτέλεση Drupal updates..."

# Database updates
log "Running database updates..."
if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush updatedb -y 2>&1 | tee -a "$LOG_FILE"; then
    error "Database updates απέτυχαν"
fi

# Config import
log "Config import..."
if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush cim -y 2>&1 | tee -a "$LOG_FILE"; then
    error "Config import απέτυχε"
fi

# Cache rebuild
log "Cache rebuild..."
if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush cr 2>&1 | tee -a "$LOG_FILE"; then
    error "Cache rebuild απέτυχε"
fi

log "Drupal updates ολοκληρώθησαν"

# ========== POST-DEPLOYMENT CHECKS ==========
info "Post-deployment έλεγχοι..."

# Check if site is accessible
if sudo -u "$DRUPAL_USER" ./vendor/bin/drush status 2>&1 | grep -q "Drupal version"; then
    log "Site είναι accessible"
else
    error "Site δεν είναι accessible μετά την ανάπτυξη"
fi

# ========== DISABLE MAINTENANCE MODE ==========
info "Απενεργοποίηση Maintenance Mode..."
sudo -u "$DRUPAL_USER" ./vendor/bin/drush state:set system.maintenance_mode FALSE
log "Site είναι Live"

# ========== FILE PERMISSIONS ==========
info "Διόρθωση δικαιωμάτων αρχείων..."
chown -R "$DRUPAL_USER:$DRUPAL_GROUP" "$DRUPAL_ROOT"
chmod -R 755 "$DRUPAL_ROOT"
chmod -R 775 "$DRUPAL_ROOT/web/sites/default/files"
chmod -R 775 "$DRUPAL_ROOT/private"
log "Δικαιώματα αρχείων διορθώθησαν"

# ========== CLEANUP OLD BACKUPS ==========
info "Καθαρισμός παλιών backups..."
find "$BACKUP_DIR" -name "backup_*.tar.gz" -mtime +$BACKUP_RETENTION_DAYS -delete
log "Παλιά backups διαγράφηκαν"

# ========== FINAL SUMMARY ==========
echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
log "Ανάπτυξη ολοκληρώθηκε επιτυχώς!"
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
info "Backup αρχείο: $BACKUP_FILE"
info "Database dump: ${DUMP_FILE}.gz"
info "Log file: $LOG_FILE"
info "Branch: main"
info "Χρόνος: $TIMESTAMP"
echo ""
