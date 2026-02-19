#!/usr/bin/env bash
# =============================================================================
# FDB History — Install / Update Script
#
# Usage:
#   sudo ./install.sh                     # default LibreNMS path (/opt/librenms)
#   sudo ./install.sh /custom/path        # custom LibreNMS path
#
# Safe to re-run for updates — skips steps that are already in place.
# =============================================================================

set -euo pipefail

# ---- Config -----------------------------------------------------------------

LIBRENMS_PATH="${1:-/opt/librenms}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CRON_FILE="/etc/cron.d/librenms-fdb-history"
LOG_FILE="/var/log/librenms/fdb-history-sync.log"

# ---- Helpers ----------------------------------------------------------------

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
info()  { echo -e "${GREEN}[+]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*" >&2; }
die()   { error "$*"; exit 1; }
step()  { echo -e "\n${CYAN}--- $* ---${NC}"; }

# ---- Pre-flight checks ------------------------------------------------------

[[ $EUID -eq 0 ]] || die "Please run as root:  sudo $0"
[[ -d "$LIBRENMS_PATH" ]] || die "LibreNMS directory not found: $LIBRENMS_PATH"
[[ -f "$LIBRENMS_PATH/.env" ]] || warn ".env not found at $LIBRENMS_PATH — DB bootstrap may fail"

echo -e "${CYAN}"
echo "  ___  ___  ___   _  _ _    _           "
echo " | __||   \| _ ) | || (_)__| |_ ___ _ _ _  _ "
echo " | _| | |) | _ \ | __ | (_-<  _/ _ \ '_| || |"
echo " |_|  |___/|___/ |_||_|_/__/\__\___/_|  \_, |"
echo "                                         |__/ "
echo -e "${NC}"
info "LibreNMS path : $LIBRENMS_PATH"
info "Repo path     : $SCRIPT_DIR"
echo ""

# ---- Step 1: Database table -------------------------------------------------

step "Database"

DB_NAME=$(grep '^DB_DATABASE=' "$LIBRENMS_PATH/.env" 2>/dev/null | cut -d= -f2 | tr -d '"' || echo "librenms")
SQL_FILE="$SCRIPT_DIR/sql/fdb_history_setup.sql"
[[ -f "$SQL_FILE" ]] || die "SQL file not found: $SQL_FILE"

# Try to detect a working mysql root connection method
mysql_root() {
    if sudo mysql "$DB_NAME" -e "" 2>/dev/null; then
        sudo mysql "$DB_NAME" "$@"
    elif [[ -f /etc/mysql/debian.cnf ]]; then
        mysql --defaults-file=/etc/mysql/debian.cnf "$DB_NAME" "$@"
    else
        return 1
    fi
}

if mysql_root -e "SHOW TABLES LIKE 'fdb_history'" 2>/dev/null | grep -q fdb_history; then
    warn "fdb_history table already exists — skipping"
else
    if mysql_root < "$SQL_FILE" 2>/dev/null; then
        info "fdb_history table created"
    else
        warn "Could not connect to MySQL as root automatically."
        echo ""
        echo "  Please run this manually:"
        echo "    mysql -u root -p $DB_NAME < $SQL_FILE"
        echo ""
        read -rp "  Press Enter once the table is created, or Ctrl+C to abort..."
    fi
fi

# ---- Step 2: Sync script ----------------------------------------------------

step "Sync script"

cp "$SCRIPT_DIR/scripts/fdb-history-sync.php" "$LIBRENMS_PATH/scripts/fdb-history-sync.php"
chown librenms:librenms "$LIBRENMS_PATH/scripts/fdb-history-sync.php"
chmod 750 "$LIBRENMS_PATH/scripts/fdb-history-sync.php"
info "Sync script installed: $LIBRENMS_PATH/scripts/fdb-history-sync.php"

# ---- Step 3: Plugin files ---------------------------------------------------

step "Plugin"

mkdir -p "$LIBRENMS_PATH/app/Plugins"
# Remove old directory first so deleted/moved files don't linger
rm -rf "$LIBRENMS_PATH/app/Plugins/FdbHistory"
cp -r "$SCRIPT_DIR/plugin/FdbHistory" "$LIBRENMS_PATH/app/Plugins/"
chown -R librenms:librenms "$LIBRENMS_PATH/app/Plugins/FdbHistory"
info "Plugin installed: $LIBRENMS_PATH/app/Plugins/FdbHistory"

# ---- Step 4: Cron job -------------------------------------------------------

step "Cron job"

if [[ -f "$CRON_FILE" ]]; then
    warn "Cron job already exists at $CRON_FILE — skipping"
else
    cat > "$CRON_FILE" <<EOF
# LibreNMS FDB History — sync every minute
* * * * * librenms /usr/bin/php $LIBRENMS_PATH/scripts/fdb-history-sync.php >> $LOG_FILE 2>&1
EOF
    chmod 644 "$CRON_FILE"
    info "Cron job installed: $CRON_FILE"
fi

# ---- Step 5: Test sync ------------------------------------------------------

step "Smoke test"

if sudo -u librenms /usr/bin/php "$LIBRENMS_PATH/scripts/fdb-history-sync.php"; then
    info "Sync script ran successfully"
else
    warn "Sync script exited with an error — check output above"
fi

# ---- Done -------------------------------------------------------------------

echo ""
echo -e "${GREEN}Installation complete.${NC}"
echo ""
echo "  Next step: enable the plugin in LibreNMS"
echo "    Admin → Plugins → FdbHistory → Enable"
echo ""
echo "  Search UI:  https://<your-librenms>/plugin/FdbHistory"
echo "  Sync log:   tail -f $LOG_FILE"
echo ""
