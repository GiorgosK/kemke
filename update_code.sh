#!/bin/bash

# Drupal 11 Deployment Script - Production Ready
# Safe Git Authentication - Uses credentials from input
# Usage: sudo ./update_code.sh

set -euo pipefail

# ========== GIT CREDENTIALS ==========
read -p "Enter GitHub username: " GIT_USERNAME
read -sp "Enter GitHub password/token: " GIT_PASSWORD
echo ""

# ========== CONFIGURATION ==========
DRUPAL_ROOT="${DRUPAL_ROOT:-/var/www/drupal}"
DRUPAL_USER="${DRUPAL_USER:-www-data}"
DRUPAL_GROUP="${DRUPAL_GROUP:-www-data}"
BRANCH="${GIT_BRANCH:-main}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/drupal}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/backup_$TIMESTAMP.tar.gz"
DB_BACKUP_FILE="$BACKUP_DIR/db_backup_$TIMESTAMP.sql"
LOG_FILE="/var/log/drupal_deploy_$TIMESTAMP.log"
DEPLOY_FAILED=false

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m'

# ========== LOGGING ==========
log() { echo -e "${GREEN}[✓]${NC} $1" | tee -a "$LOG_FILE"; }
warn() { echo -e "${YELLOW}[!]${NC} $1" | tee -a "$LOG_FILE"; }
error() { echo -e "${RED}[✗]${NC} $1" | tee -a "$LOG_FILE"; }
info() { echo -e "${BLUE}[i]${NC} $1" | tee -a "$LOG_FILE"; }
section() { echo -e "\n${MAGENTA}========== $1 ==========${NC}" | tee -a "$LOG_FILE"; }

cleanup() {
    local exit_code=$?
    if [[ $exit_code -ne 0 || "$DEPLOY_FAILED" == "true" ]]; then
        warn "Deployment failed or interrupted with code $exit_code"
        if [[ -f "$DRUPAL_ROOT/vendor/bin/drush" ]]; then
            warn "Attempting to disable maintenance mode..."
            sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" state:set system.maintenance_mode FALSE 2>/dev/null || true
        fi
    fi
    exit $exit_code
}
trap cleanup EXIT

validate_environment() {
    section "Environment Validation"
    if [[ $EUID -ne 0 ]]; then error "Πρέπει να τρέξεις με sudo"; fi
    log "Running as root"
    [[ ! -d "$DRUPAL_ROOT" ]] && error "Drupal root δεν υπάρχει: $DRUPAL_ROOT"
    log "Drupal root exists: $DRUPAL_ROOT"
    [[ ! -d "$DRUPAL_ROOT/.git" ]] && error "Δεν είναι Git repository"
    log "Git repository found"
    [[ ! -f "$DRUPAL_ROOT/composer.json" ]] && error "Δεν βρέθηκε composer.json"
    log "composer.json found"
    [[ ! -f "$DRUPAL_ROOT/vendor/bin/drush" ]] && error "Δεν βρέθηκε Drush"
    log "Drush found"
    ! id "$DRUPAL_USER" &>/dev/null && error "User does not exist: $DRUPAL_USER"
    log "Drupal user exists: $DRUPAL_USER"
    cd "$DRUPAL_ROOT"
    ! sudo -u "$DRUPAL_USER" git rev-parse --verify "origin/$BRANCH" &>/dev/null && error "Branch δεν υπάρχει: $BRANCH"
    log "Git branch exists: $BRANCH"
    log "Όλοι οι έλεγχοι πέρασαν"
}

backup_database() {
    section "Database Backup"
    info "Δημιουργία database backup..."
    mkdir -p "$BACKUP_DIR"
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" sql:dump --gzip --result-file="${DB_BACKUP_FILE%.gz}" 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Αποτυχία δημιουργίας database backup"
    fi
    [[ ! -f "${DB_BACKUP_FILE}.gz" ]] && DEPLOY_FAILED=true && error "Database backup file not created"
    local size=$(du -h "${DB_BACKUP_FILE}.gz" | cut -f1)
    log "Database backup δημιουργήθηκε: ${DB_BACKUP_FILE}.gz ($size)"
}

backup_codebase() {
    section "Codebase Backup"
    info "Δημιουργία codebase backup..."
    mkdir -p "$BACKUP_DIR"
    if ! tar -czf "$BACKUP_FILE" --exclude='node_modules' --exclude='.git' --exclude='vendor' --exclude='web/sites/default/files' --exclude='private' -C "$(dirname "$DRUPAL_ROOT")" "$(basename "$DRUPAL_ROOT")" 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Αποτυχία δημιουργίας codebase backup"
    fi
    local size=$(du -h "$BACKUP_FILE" | cut -f1)
    log "Backup δημιουργήθηκε: $BACKUP_FILE ($size)"
}

git_update() {
    section "Git Operations"
    cd "$DRUPAL_ROOT"
    info "Current branch: $(git rev-parse --abbrev-ref HEAD)"
    info "Έλεγχος για νέες αλλαγές..."
    
    # Git with credentials
    git -c "credential.https://github.com.username=$GIT_USERNAME" \
        -c "credential.https://github.com.password=$GIT_PASSWORD" \
        fetch origin 2>&1 | tee -a "$LOG_FILE" || true
    
    local current_commit=$(git rev-parse HEAD)
    local remote_commit=$(git rev-parse origin/$BRANCH)
    
    if [[ "$current_commit" == "$remote_commit" ]]; then
        warn "Δεν υπάρχουν νέες αλλαγές - Deployment ακυρώθηκε"
        exit 0
    fi
    
    log "Νέες αλλαγές ανιχνεύθηκαν"
    git checkout "$BRANCH" 2>&1 | tee -a "$LOG_FILE" || true
    git -c "credential.https://github.com.username=$GIT_USERNAME" \
        -c "credential.https://github.com.password=$GIT_PASSWORD" \
        pull origin "$BRANCH" 2>&1 | tee -a "$LOG_FILE" || true
    log "Κώδικας ενημερώθηκε: $current_commit → $remote_commit"
}

composer_install() {
    section "Composer Install"
    cd "$DRUPAL_ROOT"
    info "Running: composer install --no-dev --optimize-autoloader"
    if ! sudo -u "$DRUPAL_USER" /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tee -a "$LOG_FILE"; then
        warn "Composer install finished with warnings/errors"
    fi
    log "Dependencies εγκατάστησαν"
}

enable_maintenance() {
    section "Maintenance Mode"
    info "Ενεργοποίηση Maintenance Mode..."
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" state:set system.maintenance_mode TRUE 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Failed to enable maintenance mode"
    fi
    log "Site είναι σε Maintenance Mode"
}

disable_maintenance() {
    section "Disable Maintenance Mode"
    info "Απενεργοποίηση Maintenance Mode..."
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" state:set system.maintenance_mode FALSE 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Failed to disable maintenance mode"
    fi
    log "Site είναι Live"
}

run_drupal_updates() {
    section "Drupal Updates & Cache Rebuild"
    cd "$DRUPAL_ROOT"
    info "Running database updates..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush updatedb -y 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Database updates απέτυχαν"
    fi
    log "Database updates ολοκληρώθησαν"
    info "Importing configuration..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush cim -y 2>&1 | tee -a "$LOG_FILE"; then
        warn "Config import had issues (may be expected)"
    fi
    log "Configuration imported"
    info "Rebuilding caches..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush cr 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Cache rebuild απέτυχε"
    fi
    log "Cache rebuilt"
}

fix_permissions() {
    section "File Permissions"
    info "Διόρθωση δικαιωμάτων αρχείων..."
    chown -R "$DRUPAL_USER:$DRUPAL_GROUP" "$DRUPAL_ROOT"
    find "$DRUPAL_ROOT" -type d -exec chmod 750 {} \;
    find "$DRUPAL_ROOT" -type f -exec chmod 640 {} \;
    chmod 770 "$DRUPAL_ROOT/web/sites/default/files" 2>/dev/null || true
    chmod 770 "$DRUPAL_ROOT/private" 2>/dev/null || true
    chmod 755 "$DRUPAL_ROOT/web"
    chmod 440 "$DRUPAL_ROOT/web/sites/default/settings.php" 2>/dev/null || true
    log "Δικαιώματα αρχείων διορθώθησαν"
}

health_check() {
    section "Health Checks"
    cd "$DRUPAL_ROOT"
    info "Checking Drupal status..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush status 2>&1 | tee -a "$LOG_FILE" | grep -q "Drupal version"; then
        DEPLOY_FAILED=true
        error "Site δεν είναι accessible μετά την ανάπτυξη"
    fi
    log "Site είναι accessible"
    info "Checking database connection..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush sqlq "SELECT 1;" &>/dev/null; then
        DEPLOY_FAILED=true
        error "Database connection failed"
    fi
    log "Database connection OK"
}

cleanup_old_backups() {
    section "Backup Cleanup"
    info "Καθαρισμός παλιών backups (retention: $BACKUP_RETENTION_DAYS days)..."
    local deleted_count=$(find "$BACKUP_DIR" -name "backup_*.tar.gz" -mtime +$BACKUP_RETENTION_DAYS -delete -print | wc -l)
    local db_deleted=$(find "$BACKUP_DIR" -name "db_backup_*.sql.gz" -mtime +$BACKUP_RETENTION_DAYS -delete -print | wc -l)
    total_deleted=$((deleted_count + db_deleted))
    [[ $total_deleted -gt 0 ]] && log "Deleted $total_deleted old backups" || info "No old backups to delete"
}

main() {
    section "Drupal 11 Deployment Started"
    info "Timestamp: $TIMESTAMP"
    info "Log file: $LOG_FILE"
    info "Branch: $BRANCH"
    info "Drupal root: $DRUPAL_ROOT"
    
    mkdir -p "$BACKUP_DIR"
    mkdir -p "$(dirname "$LOG_FILE")"
    
    validate_environment
    git_update
    backup_database
    backup_codebase
    enable_maintenance
    composer_install
    run_drupal_updates
    fix_permissions
    health_check
    disable_maintenance
    cleanup_old_backups
    
    section "Deployment Summary"
    if [[ "$DEPLOY_FAILED" == "true" ]]; then
        error "DEPLOYMENT COMPLETED WITH ERRORS"
        return 1
    else
        log "Ανάπτυξη ολοκληρώθηκε επιτυχώς!"
        info "Backup αρχείο: $BACKUP_FILE"
        info "Database dump: ${DB_BACKUP_FILE}.gz"
        info "Log file: $LOG_FILE"
        return 0
    fi
}

main
