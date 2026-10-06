#!/usr/bin/env bash
# ============================================================================
# Everafter Planner — automated MySQL backup with retention (cPanel-safe)
# ============================================================================
#
# WHAT IT DOES
#   1. Reads DB credentials from the app's .env (same file as the app —
#      one source of truth; real environment variables win if set).
#   2. Dumps the database with mysqldump (single-transaction, utf8mb4,
#      --no-tablespaces so it works with limited shared-hosting privileges).
#   3. Gzips it to  ~/backups/everafter/  — OUTSIDE public_html, so backups
#      are never web-downloadable. Files are chmod 600.
#   4. Verifies the archive (size + gzip integrity) before counting it.
#   5. Prunes backups older than RETAIN_DAYS and trims its own log.
#   6. Locks itself against overlapping runs (stale locks > 60 min ignored).
#
# CPANEL SETUP (Cron Jobs → Add New Cron Job):
#   Schedule:   every day 02:15   →  15 2 * * *
#   Command:    /bin/bash /home/USERNAME/public_html/scripts/db_backup.sh --quiet
#
#   --quiet  : print nothing on success; cron then only emails you on failure.
#   (without it: one summary line per run)
#
# MANUAL RUN (cPanel Terminal or SSH, if your plan has it):
#   /bin/bash /home/USERNAME/public_html/scripts/db_backup.sh
#
# RESTORE
#   CLI:    gunzip -c ~/backups/everafter/DBNAME_FILE.sql.gz | mysql -u DBUSER -p DBNAME
#   phpMyAdmin: download the .sql.gz, extract, then Import the .sql
#   (The dump contains DROP+CREATE for every table, so a restore into the
#    existing DB rebuilds it exactly; for a fully clean slate, drop and
#    recreate the DB in cPanel first, then import.)
#
# Override any default via environment: BACKUP_DIR, BACKUP_KEEP_DAYS,
# PROJECT_DIR, MYSQLDUMP_BIN, WPMS_DB_*.
# ============================================================================

set -o pipefail
umask 077

QUIET=0
[ "${1:-}" = "--quiet" ] && QUIET=1

RETAIN_DAYS="${BACKUP_KEEP_DAYS:-14}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/everafter}"
PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"

# --- tiny helpers -----------------------------------------------------------
mkdir -p "$BACKUP_DIR" || { echo "FATAL: cannot create $BACKUP_DIR" >&2; exit 1; }
LOG="$BACKUP_DIR/backup.log"

say()  { [ $QUIET -eq 0 ] && echo "$*"; return 0; }
log()  { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }
die()  { log "FAIL: $*"; echo "FAIL: $*" >&2; exit 1; }

# --- single-flight lock (stale locks older than 60 min are ignored) ---------
LOCK="$BACKUP_DIR/.lock"
if [ -d "$LOCK" ]; then
    if [ -n "$(find "$LOCK" -maxdepth 0 -mmin +60 2>/dev/null)" ]; then
        rm -rf "$LOCK"
    else
        exit 0   # another run is in progress
    fi
fi
mkdir "$LOCK" || exit 0
trap 'rm -rf "$LOCK"' EXIT

# --- credentials: environment first, then .env files ------------------------
env_val() {
    local f line
    for f in "$PROJECT_DIR/.env" "$HOME/.env"; do
        [ -r "$f" ] || continue
        line=$(grep -E "^${1}=" "$f" 2>/dev/null | tail -n1 | cut -d= -f2-)
        if [ -n "$line" ]; then
            line="${line%\"}"; line="${line#\"}"; line="${line%\'}"; line="${line#\'}"
            printf '%s' "$line"
            return 0
        fi
    done
    return 1
}

DB_USER="${WPMS_DB_USER:-$(env_val WPMS_DB_USER)}"
DB_PASS="${WPMS_DB_PASS:-$(env_val WPMS_DB_PASS)}"
DB_NAME="${WPMS_DB_NAME:-$(env_val WPMS_DB_NAME)}"
DB_HOST="${WPMS_DB_HOST:-$(env_val WPMS_DB_HOST)}"
DB_HOST="${DB_HOST:-localhost}"

[ -n "$DB_USER" ] && [ -n "$DB_NAME" ] || die "DB credentials not found (set WPMS_DB_USER/WPMS_DB_NAME in environment or .env)"

# --- locate mysqldump --------------------------------------------------------
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-}"
if [ -z "$MYSQLDUMP_BIN" ]; then
    for c in mysqldump /usr/bin/mysqldump /usr/local/bin/mysqldump /usr/local/mysql/bin/mysqldump; do
        if command -v "$c" >/dev/null 2>&1; then MYSQLDUMP_BIN="$c"; break; fi
    done
fi
[ -n "$MYSQLDUMP_BIN" ] || die "mysqldump binary not found in PATH"

# --- dump → gzip (temp file first: a partial dump never becomes "a backup") --
STAMP=$(date '+%Y-%m-%d_%H%M%S')
FINAL="$BACKUP_DIR/${DB_NAME}_${STAMP}.sql.gz"
TMP="$FINAL.partial"

if ! MYSQL_PWD="$DB_PASS" "$MYSQLDUMP_BIN" \
        --single-transaction --quick --routines --events --triggers \
        --hex-blob --no-tablespaces --default-character-set=utf8mb4 \
        --user="$DB_USER" --host="$DB_HOST" "$DB_NAME" 2>>"$LOG" | gzip > "$TMP"; then
    rm -f "$TMP"
    die "mysqldump failed for $DB_NAME — see $LOG"
fi

# --- verify before it counts: non-trivial size + gzip integrity -------------
SIZE=$(wc -c < "$TMP")
[ "$SIZE" -gt 1000 ] || { rm -f "$TMP"; die "dump suspiciously small (${SIZE} bytes) — removed"; }
gzip -t "$TMP" 2>>"$LOG" || { rm -f "$TMP"; die "gzip integrity check failed — removed"; }
mv "$TMP" "$FINAL"

# --- retention ---------------------------------------------------------------
find "$BACKUP_DIR" -name "${DB_NAME}_*.sql.gz" -mtime +"$RETAIN_DAYS" -delete 2>/dev/null
KEEP=$(find "$BACKUP_DIR" -name "${DB_NAME}_*.sql.gz" | wc -l)
tail -n 2000 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"

log "OK: $FINAL (${SIZE} bytes) — ${KEEP} backup(s) kept, retention ${RETAIN_DAYS}d"
say "OK: $FINAL (${SIZE} bytes) — ${KEEP} backup(s) kept"
exit 0
