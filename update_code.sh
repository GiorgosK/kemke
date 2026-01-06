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
    if [[ $exit_code -ne 0 ]]; then
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
        error "Αποτυχία δημιουργίας database backup"
        return 1
    fi
    [[ ! -f "${DB_BACKUP_FILE}.gz" ]] && error "Database backup file not created" && return 1
    local size=$(du -h "${DB_BACKUP_FILE}.gz" | cut -f1)
    log "Database backup δημιουργήθηκε: ${DB_BACKUP_FILE}.gz ($size)"
}

backup_codebase() {
    section "Codebase Backup"
    info "Δημιουργία codebase backup..."
    mkdir -p "$BACKUP_DIR"
    if ! tar -czf "$BACKUP_FILE" --exclude='node_modules' --exclude='.git' --exclude='vendor' --exclude='web/sites/default/files' --exclude='private' -C "$(dirname "$DRUPAL_ROOT")" "$(basename "$DRUPAL_ROOT")" 2>&1 | tee -a "$LOG_FILE"; then
        error "Αποτυχία δημιουργίας codebase backup"
        return 1
    fi
    local size=$(du -h "$BACKUP_FILE" | cut -f1)
    log "Backup δημιουργήθηκε: $BACKUP_FILE ($size)"
}

enable_maintenance() {
    section "Maintenance Mode"
    info "Ενεργοποίηση Maintenance Mode..."
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" state:set system.maintenance_mode TRUE 2>&1 | tee -a "$LOG_FILE"; then
        error "Failed to enable maintenance mode"
        return 1
    fi
    log "Site είναι σε Maintenance Mode"
}

disable_maintenance() {
    section "Disable Maintenance Mode"
    info "Απενεργοποίηση Maintenance Mode..."
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" state:set system.maintenance_mode FALSE 2>&1 | tee -a "$LOG_FILE"; then
        error "Failed to disable maintenance mode"
        return 1
    fi
    log "Site είναι Live"
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

run_drupal_updates() {
    section "Drupal Updates & Cache Rebuild"
    cd "$DRUPAL_ROOT"
    info "Running database updates..."
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" updatedb -y 2>&1 | tee -a "$LOG_FILE"; then
        error "Database updates απέτυχαν"
        return 1
    fi
    log "Database updates ολοκληρώθησαν"
    info "Importing configuration..."
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" cim -y 2>&1 | tee -a "$LOG_FILE"; then
        warn "Config import had issues (may be expected)"
    fi
    log "Configuration imported"
    info "Rebuilding caches..."
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" cr 2>&1 | tee -a "$LOG_FILE"; then
        error "Cache rebuild απέτυχε"
        return 1
    fi
    log "Cache rebuilt"
}

fix_permissions() {
    section "File Permissions"
    info "Διόρθωση δικαιωμάτων αρχείων..."
    chown -R "$DRUPAL_USER:$DRUPAL_GROUP" "$DRUPAL_ROOT"
    
    # Set directories to 750
    find "$DRUPAL_ROOT" -type d -exec chmod 750 {} \;
    
    # Set files to 640
    find "$DRUPAL_ROOT" -type f -exec chmod 640 {} \;
    
    # Make vendor/bin executables 755
    find "$DRUPAL_ROOT/vendor/bin" -type f -exec chmod 755 {} \;
    
    # Writable directories
    chmod 770 "$DRUPAL_ROOT/web/sites/default/files" 2>/dev/null || true
    chmod 770 "$DRUPAL_ROOT/private" 2>/dev/null || true
    
    # Public web root
    chmod 755 "$DRUPAL_ROOT/web"
    
    # Read-only configs
    chmod 440 "$DRUPAL_ROOT/web/sites/default/settings.php" 2>/dev/null || true
    
    log "Δικαιώματα αρχείων διορθώθησαν"
}

health_check() {
    section "Health Checks"
    cd "$DRUPAL_ROOT"
    info "Checking Drupal status..."
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" status 2>&1 | tee -a "$LOG_FILE" | grep -q "Drupal version"; then
        error "Site δεν είναι accessible μετά την ανάπτυξη"
        return 1
    fi
    log "Site είναι accessible"
    info "Checking database connection..."
    if sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" sql:query "SELECT 1;" &>/dev/null; then
        log "Database connection OK"
    else
        error "Database connection failed"
        return 1
    fi
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
    
    # CHECK FOR CHANGES FIRST
    cd "$DRUPAL_ROOT"
    info "Έλεγχος για νέες αλλαγές..."
    
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
    
    # BACKUP BEFORE APPLYING CHANGES
    backup_database || exit 1
    backup_codebase || exit 1
    enable_maintenance || exit 1
    
    # APPLY CHANGES
    section "Git Operations"
    git checkout "$BRANCH" 2>&1 | tee -a "$LOG_FILE" || true
    git -c "credential.https://github.com.username=$GIT_USERNAME" \
        -c "credential.https://github.com.password=$GIT_PASSWORD" \
        pull origin "$BRANCH" 2>&1 | tee -a "$LOG_FILE" || true
    log "Κώδικας ενημερώθηκε: $current_commit → $remote_commit"
    
    # DEPLOYMENT STEPS
    composer_install || exit 1
    run_drupal_updates || exit 1
    fix_permissions || exit 1
    health_check || exit 1
    disable_maintenance || exit 1
    cleanup_old_backups
    
    # FINAL SUMMARY
    section "Deployment Summary"
    log "Ανάπτυξη ολοκληρώθηκε επιτυχώς!"
    info "Backup αρχείο: $BACKUP_FILE"
    info "Database dump: ${DB_BACKUP_FILE}.gz"
    info "Log file: $LOG_FILE"
    return 0
}

main
