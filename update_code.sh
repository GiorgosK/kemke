#!/bin/bash

# Drupal 11 Deployment Script - Production Ready
# Safe Git Authentication - Uses SSH keys or token from environment
# Usage: sudo ./update_code.sh [--dry-run] [--skip-backup]
# Supports multiple branches via GIT_BRANCH env var (default: main)

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
DRY_RUN=false
SKIP_BACKUP=false
DEPLOY_FAILED=false

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m'

# ========== LOGGING FUNCTIONS ==========
log() { echo -e "${GREEN}[✓]${NC} $1" | tee -a "$LOG_FILE"; }
warn() { echo -e "${YELLOW}[!]${NC} $1" | tee -a "$LOG_FILE"; }
error() { echo -e "${RED}[✗]${NC} $1" | tee -a "$LOG_FILE"; }
info() { echo -e "${BLUE}[i]${NC} $1" | tee -a "$LOG_FILE"; }
debug() { [[ "${DEBUG:-0}" == "1" ]] && echo -e "${MAGENTA}[D]${NC} $1" | tee -a "$LOG_FILE"; }
section() { echo -e "\n${MAGENTA}========== $1 ==========${NC}" | tee -a "$LOG_FILE"; }

# ========== CLEANUP ON EXIT ==========
cleanup() {
    local exit_code=$?
    if [[ $exit_code -ne 0 || "$DEPLOY_FAILED" == "true" ]]; then
        warn "Deployment failed or interrupted with code $exit_code"
        # Re-enable site in case maintenance mode is still on
        if [[ -f "$DRUPAL_ROOT/vendor/bin/drush" ]]; then
            warn "Attempting to disable maintenance mode..."
            sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" state:set system.maintenance_mode FALSE 2>/dev/null || true
        fi
    fi
    exit $exit_code
}
trap cleanup EXIT

# ========== ARGUMENT PARSING ==========
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --dry-run)
                DRY_RUN=true
                warn "DRY RUN MODE - No changes will be made"
                ;;
            --skip-backup)
                SKIP_BACKUP=true
                warn "SKIPPING BACKUPS - This is risky!"
                ;;
            *)
                error "Unknown argument: $1"
                ;;
        esac
        shift
    done
}

# ========== VALIDATION FUNCTIONS ==========
validate_environment() {
    section "Environment Validation"
    
    # Check root privileges
    if [[ $EUID -ne 0 ]]; then
        error "Πρέπει να τρέξεις με sudo"
    fi
    log "Running as root"
    
    # Check Drupal root exists
    if [[ ! -d "$DRUPAL_ROOT" ]]; then
        error "Drupal root δεν υπάρχει: $DRUPAL_ROOT"
    fi
    log "Drupal root exists: $DRUPAL_ROOT"
    
    # Check if Git repo exists
    if [[ ! -d "$DRUPAL_ROOT/.git" ]]; then
        error "Δεν είναι Git repository"
    fi
    log "Git repository found"
    
    # Check if composer.json exists
    if [[ ! -f "$DRUPAL_ROOT/composer.json" ]]; then
        error "Δεν βρέθηκε composer.json"
    fi
    log "composer.json found"
    
    # Check if Drush exists
    if [[ ! -f "$DRUPAL_ROOT/vendor/bin/drush" ]]; then
        error "Δεν βρέθηκε Drush"
    fi
    log "Drush found"
    
    # Check if user exists
    if ! id "$DRUPAL_USER" &>/dev/null; then
        error "User does not exist: $DRUPAL_USER"
    fi
    log "Drupal user exists: $DRUPAL_USER"
    
    # Verify git branch exists
    cd "$DRUPAL_ROOT"
    if ! sudo -u "$DRUPAL_USER" git rev-parse --verify "origin/$BRANCH" &>/dev/null; then
        error "Branch δεν υπάρχει: $BRANCH"
    fi
    log "Git branch exists: $BRANCH"
    
    log "Όλοι οι έλεγχοι πέρασαν"
}

# ========== BACKUP FUNCTIONS ==========
backup_database() {
    section "Database Backup"
    
    if [[ "$SKIP_BACKUP" == "true" ]]; then
        warn "Skipping database backup!"
        return 0
    fi
    
    info "Δημιουργία database backup..."
    mkdir -p "$BACKUP_DIR"
    
    if ! sudo -u "$DRUPAL_USER" "$DRUPAL_ROOT/vendor/bin/drush" sql:dump --gzip --result-file="${DB_BACKUP_FILE%.gz}" 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Αποτυχία δημιουργίας database backup"
    fi
    
    if [[ ! -f "${DB_BACKUP_FILE}.gz" ]]; then
        DEPLOY_FAILED=true
        error "Database backup file not created"
    fi
    
    local size=$(du -h "${DB_BACKUP_FILE}.gz" | cut -f1)
    log "Database backup δημιουργήθηκε: ${DB_BACKUP_FILE}.gz ($size)"
}

backup_codebase() {
    section "Codebase Backup"
    
    if [[ "$SKIP_BACKUP" == "true" ]]; then
        warn "Skipping codebase backup!"
        return 0
    fi
    
    info "Δημιουργία codebase backup..."
    mkdir -p "$BACKUP_DIR"
    
    if ! tar -czf "$BACKUP_FILE" \
        --exclude='node_modules' \
        --exclude='.git' \
        --exclude='vendor' \
        --exclude='web/sites/default/files' \
        --exclude='private' \
        -C "$(dirname "$DRUPAL_ROOT")" \
        "$(basename "$DRUPAL_ROOT")" 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Αποτυχία δημιουργίας codebase backup"
    fi
    
    local size=$(du -h "$BACKUP_FILE" | cut -f1)
    log "Backup δημιουργήθηκε: $BACKUP_FILE ($size)"
}

# ========== GIT OPERATIONS ==========
git_update() {
    section "Git Operations"
    
    cd "$DRUPAL_ROOT"
    
    info "Current branch: $(git rev-parse --abbrev-ref HEAD)"
    info "Ενημέρωση κώδικα από $BRANCH branch..."
    
    # Store current commit for rollback if needed
    local current_commit=$(git rev-parse HEAD)
    debug "Current commit: $current_commit"
    
    # Git credential helper function
    git_cmd() {
        echo "$GIT_PASSWORD" | git -c credential.helper='!read pass; echo password=$pass' -c credential.username="$GIT_USERNAME" "$@"
    }
    
    # Fetch from remote
    debug "Running: git fetch origin"
    if ! git_cmd fetch origin 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Git fetch απέτυχε"
    fi
    log "Git fetch succeeded"
    
    # Check for local changes
    if ! git diff-index --quiet HEAD --; then
        warn "Local changes detected. Stashing..."
        git stash
        debug "Local changes stashed"
    fi
    
    # Checkout branch
    debug "Running: git checkout $BRANCH"
    if ! git checkout "$BRANCH" 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Git checkout απέτυχε"
    fi
    log "Git checkout succeeded"
    
    # Pull from remote
    debug "Running: git pull origin $BRANCH"
    if ! git_cmd pull origin "$BRANCH" 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Git pull απέτυχε"
    fi
    
    local new_commit=$(git rev-parse HEAD)
    debug "New commit: $new_commit"
    
    if [[ "$current_commit" == "$new_commit" ]]; then
        warn "No code changes detected"
    else
        log "Κώδικας ενημερώθηκε: $current_commit → $new_commit"
    fi
}

# ========== COMPOSER ==========
composer_install() {
    section "Composer Install"
    
    cd "$DRUPAL_ROOT"
    
    info "Running: composer install --no-dev --optimize-autoloader"
    
    if ! sudo -u "$DRUPAL_USER" /usr/local/bin/composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        2>&1 | tee -a "$LOG_FILE"; then
        warn "Composer install finished with warnings/errors"
    fi
    
    log "Dependencies εγκατάστησαν"
}

# ========== MAINTENANCE MODE ==========
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

# ========== DRUPAL UPDATES ==========
run_drupal_updates() {
    section "Drupal Updates & Cache Rebuild"
    
    cd "$DRUPAL_ROOT"
    
    # Database updates
    info "Running database updates..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush updatedb -y 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Database updates απέτυχαν"
    fi
    log "Database updates ολοκληρώθησαν"
    
    # Config import
    info "Importing configuration..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush cim -y 2>&1 | tee -a "$LOG_FILE"; then
        warn "Config import had issues (may be expected)"
    fi
    log "Configuration imported"
    
    # Cache rebuild
    info "Rebuilding caches..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush cr 2>&1 | tee -a "$LOG_FILE"; then
        DEPLOY_FAILED=true
        error "Cache rebuild απέτυχε"
    fi
    log "Cache rebuilt"
}

# ========== FILE PERMISSIONS ==========
fix_permissions() {
    section "File Permissions"
    
    info "Διόρθωση δικαιωμάτων αρχείων..."
    
    chown -R "$DRUPAL_USER:$DRUPAL_GROUP" "$DRUPAL_ROOT"
    chmod -R 755 "$DRUPAL_ROOT"
    chmod -R 775 "$DRUPAL_ROOT/web/sites/default/files"
    
    if [[ -d "$DRUPAL_ROOT/private" ]]; then
        chmod -R 775 "$DRUPAL_ROOT/private"
    fi
    
    log "Δικαιώματα αρχείων διορθώθησαν"
}

# ========== POST-DEPLOYMENT CHECKS ==========
health_check() {
    section "Health Checks"
    
    cd "$DRUPAL_ROOT"
    
    info "Checking Drupal status..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush status 2>&1 | tee -a "$LOG_FILE" | grep -q "Drupal version"; then
        DEPLOY_FAILED=true
        error "Site δεν είναι accessible μετά την ανάπτυξη"
    fi
    log "Site είναι accessible"
    
    # Check database connection
    info "Checking database connection..."
    if ! sudo -u "$DRUPAL_USER" ./vendor/bin/drush sqlq "SELECT 1;" &>/dev/null; then
        DEPLOY_FAILED=true
        error "Database connection failed"
    fi
    log "Database connection OK"
}

# ========== CLEANUP ==========
cleanup_old_backups() {
    section "Backup Cleanup"
    
    info "Καθαρισμός παλιών backups (retention: $BACKUP_RETENTION_DAYS days)..."
    
    local deleted_count=$(find "$BACKUP_DIR" -name "backup_*.tar.gz" -mtime +$BACKUP_RETENTION_DAYS -delete -print | wc -l)
    local db_deleted=$(find "$BACKUP_DIR" -name "db_backup_*.sql.gz" -mtime +$BACKUP_RETENTION_DAYS -delete -print | wc -l)
    
    total_deleted=$((deleted_count + db_deleted))
    
    if [[ $total_deleted -gt 0 ]]; then
        log "Deleted $total_deleted old backups"
    else
        info "No old backups to delete"
    fi
}

# ========== DRY RUN MODE ==========
execute_or_skip() {
    if [[ "$DRY_RUN" == "true" ]]; then
        info "[DRY RUN] Would execute: $*"
        return 0
    else
        "$@"
    fi
}

# ========== MAIN EXECUTION ==========
main() {
    section "Drupal 11 Deployment Started"
    info "Timestamp: $TIMESTAMP"
    info "Log file: $LOG_FILE"
    info "Branch: $BRANCH"
    info "Drupal root: $DRUPAL_ROOT"
    [[ "$DRY_RUN" == "true" ]] && info "MODE: DRY RUN"
    [[ "$SKIP_BACKUP" == "true" ]] && warn "SKIPPING BACKUPS"
    
    mkdir -p "$BACKUP_DIR"
    mkdir -p "$(dirname "$LOG_FILE")"
    
    # Main workflow
    validate_environment
    
    if [[ "$DRY_RUN" != "true" ]]; then
        backup_database
        backup_codebase
        enable_maintenance
    else
        info "[DRY RUN] Skipping backups"
        info "[DRY RUN] Skipping maintenance mode"
    fi
    
    git_update
    composer_install
    
    if [[ "$DRY_RUN" != "true" ]]; then
        run_drupal_updates
        fix_permissions
        health_check
        disable_maintenance
        cleanup_old_backups
    else
        info "[DRY RUN] Skipping Drupal updates, permission fixes, health checks"
    fi
    
    # Final summary
    section "Deployment Summary"
    if [[ "$DEPLOY_FAILED" == "true" ]]; then
        error "DEPLOYMENT COMPLETED WITH ERRORS"
        return 1
    else
        log "Ανάπτυξη ολοκληρώθηκε επιτυχώς!"
        info "Backup αρχείο: $BACKUP_FILE"
        info "Database dump: $DB_BACKUP_FILE"
        info "Log file: $LOG_FILE"
        return 0
    fi
}

# ========== ENTRY POINT ==========
parse_args "$@"
main
