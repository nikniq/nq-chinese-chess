#!/usr/bin/env bash
#================================================================
# Production deploy for Chinese Chess. Run ON the server, in the app root:
#
#   ./deploy.sh                 # pull + composer (if lock moved) + migrate + caches + verify
#   ./deploy.sh --down          # …same, with maintenance mode during the deploy
#   ./deploy.sh --check         # pre-flight only: what would deploy, nothing changes
#   ./deploy.sh --rollback      # return code to the commit before the last deploy
#   ./deploy.sh --status        # current commit, last deploys, pending migrations
#   ./deploy.sh --backup        # back up the database now (cx.sh 90)
#   ./deploy.sh --list-backups  # backups, newest first, with age and size (cx.sh 92)
#   ./deploy.sh --restore FILE  # restore a backup (backs up the current DB first) (cx.sh 94)
#   ./deploy.sh --install-cron  # daily 03:15 database backup via crontab (cx.sh 95)
#
# Its local counterpart is cx.sh (option 20 calls this over ssh).
#
# Version 3.0 (ported from the as-website1 deploy.sh v2)
# - one deploy at a time: a lock file stops two deploys racing each other
# - pre-flight refuses to deploy over local edits on the server, since
#   `git pull` would either fail half-way or merge them silently
# - the database is backed up before migrating (SQLite file copy, or
#   mysqldump when DB_CONNECTION=mysql and mysqldump exists)
# - --down puts the site in maintenance mode, and a trap brings it back up
#   even if a step fails, so a failed deploy never leaves the site at 503
# - every deploy is recorded in storage/logs/deploy.log with the commits
#   before and after, which is what --rollback and --status read
# - verification is a real command here (php artisan ops:verify-deploy)
#   and a failure makes the script exit non-zero
#
# Version 3.1
# - --restore: puts a backup back, with maintenance mode on, the current
#   database saved first, and migrations re-run so an older backup still
#   matches newer code. Backups existed before, but nothing could use them
# - SQLite backups use `sqlite3 .backup` when available (a plain cp taken
#   while a player is saving a score can copy a half-written file) and are
#   integrity-checked; a backup that fails the check is deleted, not kept
# - --list-backups and --install-cron (daily backup; until now backups only
#   happened when a deploy brought migrations)
# - the full output of every changing run is kept in
#   storage/logs/deploy-last.out, so a failed deploy can be read afterwards
#   (cx.sh 38) instead of only in the terminal that ran it
#
# Version 3.2
# - fixed: a run killed part-way (closed terminal, dropped ssh) left
#   storage/framework/deploy.lock behind and blocked every later deploy.
#   The lock now records its pid and a dead run's lock is cleared
#   automatically; a dropped connection no longer stops a deploy half-way;
#   the cleanup that releases the lock and lifts maintenance mode can no
#   longer be killed by writing to a terminal that has gone
#================================================================
set -euo pipefail
cd "$(dirname "$0")"

DEPLOY_LOG="storage/logs/deploy.log"
LOCK_FILE="storage/framework/deploy.lock"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/$(basename "$PWD")}"
KEEP_BACKUPS="${KEEP_BACKUPS:-10}"
PHP="${PHP:-php}"
MODE="${1:-deploy}"

say()  { echo "→ $*"; }
warn() { echo "⚠ $*"; }
die()  { echo "✗ $*" >&2; exit 1; }
log_deploy() { mkdir -p "$(dirname "$DEPLOY_LOG")"; printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$DEPLOY_LOG"; }
# Read one key from .env. Never fails: a missing key is an empty string, not
# a pipefail exit in the middle of a deploy.
env_value() { { grep -E "^$1=" .env 2>/dev/null || true; } | tail -1 | cut -d= -f2- | tr -d '"'"'"; }

[ -f artisan ] || die "No artisan here — run deploy.sh from the app root."
[ -f .env ]    || die "No .env — copy .env.example, set APP_KEY and the database, then retry."

#---------------------------------------------------------------
# Read-only modes
#---------------------------------------------------------------
if [ "$MODE" = "--status" ]; then
  echo "Current : $(git log --oneline -1)"
  echo "Branch  : $(git rev-parse --abbrev-ref HEAD)"
  echo
  echo "Last deploys:"
  tail -n 8 "$DEPLOY_LOG" 2>/dev/null || echo "  (none recorded yet)"
  echo
  $PHP artisan migrate:status --pending 2>/dev/null || true
  [ -f storage/framework/down ] && warn "Maintenance mode is ON."
  exit 0
fi

if [ "$MODE" = "--list-backups" ]; then
  if ! ls "$BACKUP_DIR"/db-* >/dev/null 2>&1; then echo "No backups in $BACKUP_DIR yet."; exit 0; fi
  echo "Backups in $BACKUP_DIR (newest first):"
  now=$(date +%s)
  ls -1t "$BACKUP_DIR"/db-* | while read -r f; do
    m=$(stat -c %Y "$f" 2>/dev/null || stat -f %m "$f")
    printf '  %-34s %8s   %s ago\n' "$(basename "$f")" "$(du -h "$f" | cut -f1)" \
      "$(( (now - m) / 86400 ))d $(( (now - m) % 86400 / 3600 ))h"
  done
  newest=$(ls -1t "$BACKUP_DIR"/db-* | head -1)
  age=$(( (now - $(stat -c %Y "$newest" 2>/dev/null || stat -f %m "$newest")) / 86400 ))
  [ "$age" -ge 2 ] && warn "Newest backup is ${age} days old. ./deploy.sh --install-cron takes one daily."
  exit 0
fi

if [ "$MODE" = "--install-cron" ]; then
  # --backup needs no PHP, only cp/sqlite3/mysqldump, so cron's thin PATH is enough
  # once the usual shared-hosting bin directories are added.
  LINE="15 3 * * * cd $PWD && PATH=/usr/local/bin:/usr/bin:/bin ./deploy.sh --backup >> storage/logs/backup-cron.log 2>&1"
  if crontab -l 2>/dev/null | grep -qF "./deploy.sh --backup"; then
    echo "A backup cron line already exists:"; crontab -l | grep -F "./deploy.sh --backup"
  else
    { crontab -l 2>/dev/null || true; echo "$LINE"; } | crontab -
    echo "✓ installed: $LINE"
  fi
  exit 0
fi

require_clean() {
  if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git status --short --untracked-files=no
    die "Tracked files are modified on the server. Commit them upstream or 'git checkout -- <file>', then retry."
  fi
}

preflight() {
  say "pre-flight"
  require_clean
  git fetch --quiet
  BEHIND=$(git rev-list --count HEAD..@{u})
  echo "  current : $(git log --oneline -1)"
  echo "  incoming: $BEHIND commit(s)"
  [ "$BEHIND" -gt 0 ] && git log --oneline HEAD..@{u} | sed 's/^/    /'
  if git diff --quiet HEAD @{u} -- composer.lock; then
    echo "  composer: lockfile unchanged"
  else
    echo "  composer: lockfile changed — dependencies will be installed"
  fi
  if git diff --name-only HEAD @{u} | grep -q '^database/migrations/'; then
    echo "  database: new migrations incoming — a backup will be taken first"
  fi
}

if [ "$MODE" = "--check" ]; then
  preflight
  echo
  echo "Nothing changed (--check)."
  exit 0
fi

#---------------------------------------------------------------
# Changing modes: take the lock
#---------------------------------------------------------------
mkdir -p "$(dirname "$LOCK_FILE")" storage/logs
WENT_DOWN=0

# The lock holds "<pid> <start time>". A lock whose process is gone is left
# over from a run that was killed (closed terminal, dropped ssh, kill -9) and
# is cleared automatically. Locks written before v3.2 hold only a time; those
# count as stale once they are 30 minutes old, far longer than any deploy.
if [ -f "$LOCK_FILE" ]; then
  read -r LPID LREST < "$LOCK_FILE" || true
  if [[ "$LPID" =~ ^[0-9]+$ ]]; then
    if kill -0 "$LPID" 2>/dev/null && ps -p "$LPID" -o args= 2>/dev/null | grep -q 'deploy\.sh'; then
      die "A deploy is running right now (pid $LPID, started $LREST). Wait for it, or stop it with: kill $LPID"
    fi
  elif [ -n "$(find "$LOCK_FILE" -mmin -30 2>/dev/null)" ]; then
    die "A deploy started at $(cat "$LOCK_FILE") may still be running. Check with: ps -u \$USER -o pid,args | grep '[d]eploy.sh' — if nothing shows, delete $LOCK_FILE and retry."
  fi
  warn "Clearing a stale lock from a run that did not finish (started $(cat "$LOCK_FILE"))."
  warn "What it was doing: tail storage/logs/deploy.log storage/logs/deploy-last.out"
  if [ -f storage/framework/down ]; then
    warn "The site is still in maintenance mode, probably left by that run; it is brought back up at the end of this one."
    WENT_DOWN=1
  fi
  log_deploy "STALE LOCK cleared ($(cat "$LOCK_FILE"))"
  rm -f "$LOCK_FILE"
fi
# noclobber makes taking the lock atomic: if two deploys start at the same
# moment, exactly one of them creates the file.
( set -o noclobber; echo "$$ $(date '+%Y-%m-%d %H:%M:%S')" > "$LOCK_FILE" ) 2>/dev/null \
  || die "Another deploy took the lock a moment ago."

cleanup() {
  status=$?
  # Ignore broken pipes from here on: if the terminal has gone, printing must
  # not kill the cleanup before the lock is released and the site is back up.
  trap '' PIPE HUP INT TERM
  rm -f "$LOCK_FILE"
  if [ "$WENT_DOWN" = 1 ]; then
    { say "bringing the site back up"; } 2>/dev/null || true
    $PHP artisan up >> "$TRANSCRIPT" 2>&1 || { warn "artisan up failed — run it by hand (cx.sh 73)."; } 2>/dev/null || true
  fi
  if [ $status -ne 0 ]; then
    log_deploy "FAILED $MODE at $(git rev-parse --short HEAD) (exit $status)"
    { echo "✗ deploy failed (exit $status). The code is at $(git log --oneline -1)."
      echo "  Read the output above, then fix forward or run ./deploy.sh --rollback."; } 2>/dev/null || true
  fi
}
# Installed straight after taking the lock, so no exit path can skip it.
TRANSCRIPT="storage/logs/deploy-last.out"
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
# A dropped ssh connection sends SIGHUP. Finishing the deploy is safer than
# stopping half-way through a pull or migration, so carry on.
trap '' HUP

# Keep everything this run prints, for reading after the terminal is gone.
# tee -p keeps writing the file if the terminal goes away (GNU coreutils 8.24+).
echo "=== $(date '+%Y-%m-%d %H:%M:%S') ./deploy.sh $* (pid $$) ===" > "$TRANSCRIPT"
if tee -p /dev/null </dev/null >/dev/null 2>&1; then TEE="tee -p"; else TEE="tee"; fi
exec > >(trap '' HUP PIPE; $TEE -a "$TRANSCRIPT" 2>/dev/null) 2>&1

backup_database() {
  mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
  stamp=$(date '+%Y%m%d-%H%M%S')
  case "$(env_value DB_CONNECTION)" in
    sqlite|"")
      db=$(env_value DB_DATABASE); db="${db:-database/database.sqlite}"
      if [ -f "$db" ]; then
        dest="$BACKUP_DIR/db-$stamp.sqlite"
        say "backup: $db → $dest"
        if command -v sqlite3 >/dev/null 2>&1; then
          sqlite3 "$db" ".backup '$dest'"
          if [ "$(sqlite3 "$dest" 'PRAGMA integrity_check;')" != "ok" ]; then
            rm -f "$dest"; die "Backup failed its integrity check and was deleted. Nothing else was changed."
          fi
        else
          cp "$db" "$dest"
        fi
        LAST_BACKUP="$dest"
      else
        warn "SQLite file $db not found — no backup taken."
      fi ;;
    mysql|mariadb)
      if command -v mysqldump >/dev/null 2>&1; then
        say "backup: mysqldump → $BACKUP_DIR/db-$stamp.sql.gz"
        MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces \
          -h "$(env_value DB_HOST)" -P "$(p=$(env_value DB_PORT); echo "${p:-3306}")" -u "$(env_value DB_USERNAME)" \
          "$(env_value DB_DATABASE)" | gzip > "$BACKUP_DIR/db-$stamp.sql.gz"
        LAST_BACKUP="$BACKUP_DIR/db-$stamp.sql.gz"
      else
        warn "mysqldump not on PATH — no backup taken. Use cx.sh 91 before risky migrations."
      fi ;;
    *) warn "No backup routine for DB_CONNECTION=$(env_value DB_CONNECTION)." ;;
  esac
  # Keep the newest $KEEP_BACKUPS; shared hosting quotas fill up quietly.
  ls -1t "$BACKUP_DIR"/db-* 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f
}

composer_install() {
  if command -v composer >/dev/null 2>&1; then
    say "composer install"
    composer install --no-dev --optimize-autoloader --no-interaction
  else
    warn "composer.lock changed but composer is not on PATH. Install dependencies (cx.sh 26) before trusting this deploy."
  fi
}

finish() {
  say "cache config/routes/views"
  $PHP artisan optimize
  say "verify"
  $PHP artisan ops:verify-deploy
}

if [ "$MODE" = "--backup" ]; then
  backup_database
  ls -lht "$BACKUP_DIR" | sed -n '2,4p'
  log_deploy "BACKUP"
  exit 0
fi

#---------------------------------------------------------------
# Restore
#---------------------------------------------------------------
if [ "$MODE" = "--restore" ]; then
  FILE="${2:-}"
  [ -n "$FILE" ] || die "Usage: ./deploy.sh --restore <backup file>  (see ./deploy.sh --list-backups)"
  [ -f "$FILE" ] || FILE="$BACKUP_DIR/$FILE"   # accept a bare file name from --list-backups
  [ -f "$FILE" ] || die "No such backup: ${2}"
  CONN="$(env_value DB_CONNECTION)"; CONN="${CONN:-sqlite}"
  case "$CONN:$FILE" in
    sqlite:*.sqlite|mysql:*.sql.gz|mariadb:*.sql.gz) ;;
    *) die "$(basename "$FILE") does not match DB_CONNECTION=$CONN." ;;
  esac
  echo "Restore $(basename "$FILE") ($(du -h "$FILE" | cut -f1)) over the live $CONN database?"
  warn "Every score and account created since that backup is lost. The current database is backed up first."
  read -r -p "Type 'yes' to continue: " reply
  [ "$reply" = "yes" ] || { echo "Skipped."; exit 0; }

  LAST_BACKUP=""
  backup_database
  [ -n "$LAST_BACKUP" ] || die "Could not back up the current database, so it was not overwritten."
  say "maintenance mode on"; $PHP artisan down --retry=60; WENT_DOWN=1
  if [ "$CONN" = sqlite ]; then
    db=$(env_value DB_DATABASE); db="${db:-database/database.sqlite}"
    say "restore: $FILE → $db"
    # Write next to the target and rename, so the live file is never half-copied;
    # drop the WAL files of the old database so they are not replayed onto the new one.
    cp "$FILE" "$db.restoring" && mv "$db.restoring" "$db" && rm -f "$db-wal" "$db-shm"
  else
    say "restore: $FILE → $(env_value DB_DATABASE)"
    gunzip -c "$FILE" | MYSQL_PWD="$(env_value DB_PASSWORD)" mysql -h "$(env_value DB_HOST)" \
      -P "$(p=$(env_value DB_PORT); echo "${p:-3306}")" -u "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)"
  fi
  say "migrate (bring an older backup up to the current schema)"
  $PHP artisan migrate --force
  $PHP artisan cache:clear >/dev/null 2>&1 || true   # leaderboard/home stats were cached from the old data
  finish
  log_deploy "RESTORE $(basename "$FILE") (previous DB saved as $(basename "$LAST_BACKUP"))"
  echo "✓ restored $(basename "$FILE"). The database it replaced is $LAST_BACKUP."
  exit 0
fi

#---------------------------------------------------------------
# Rollback
#---------------------------------------------------------------
if [ "$MODE" = "--rollback" ]; then
  # The commit before the last deploy that actually changed the code; a
  # deploy with nothing incoming is logged as X..X and must be skipped.
  PREV=$(awk '$3 == "DEPLOYED" { split($4, r, /\.\./); if (r[1] != r[2]) prev = r[1] } END { print prev }' "$DEPLOY_LOG" 2>/dev/null)
  [ -n "$PREV" ] || die "No previous deploy recorded in $DEPLOY_LOG."
  CUR=$(git rev-parse --short HEAD)
  [ "$PREV" != "$CUR" ] || die "Already at $PREV."
  echo "Roll code back from $CUR to $(git log --oneline -1 "$PREV")?"
  warn "Migrations are NOT reversed. If the last deploy changed the schema, restore the"
  warn "backup in $BACKUP_DIR instead of, or as well as, rolling back."
  read -r -p "Type 'yes' to continue: " reply
  [ "$reply" = "yes" ] || { echo "Skipped."; exit 0; }
  LOCK_BEFORE=$(git rev-parse HEAD:composer.lock 2>/dev/null || echo none)
  # Detached checkout: the branch still tracks upstream, and the next normal
  # deploy (after the fix is pushed) returns to it with `git checkout -`.
  git checkout --quiet "$PREV"
  [ "$LOCK_BEFORE" = "$(git rev-parse HEAD:composer.lock 2>/dev/null || echo none)" ] || composer_install
  finish
  log_deploy "ROLLBACK $CUR..$PREV"
  echo "✓ rolled back to $(git log --oneline -1). The checkout is detached; ./deploy.sh returns to the branch."
  exit 0
fi

#---------------------------------------------------------------
# Deploy
#---------------------------------------------------------------
case "$MODE" in deploy|--down) ;; *) die "Unknown option '$MODE'. See the header of deploy.sh." ;; esac

# After a rollback the checkout is detached; get back on the branch first.
# Check for local edits before switching, or the checkout carries them along.
require_clean
if ! git symbolic-ref -q HEAD >/dev/null; then
  say "leaving detached rollback checkout"
  git checkout --quiet -
fi

preflight
BEFORE=$(git rev-parse --short HEAD)
LOCK_BEFORE=$(git rev-parse HEAD:composer.lock 2>/dev/null || echo none)
NEW_MIGRATIONS=$(git diff --name-only HEAD @{u} -- database/migrations | wc -l | tr -d ' ')

if [ "$MODE" = "--down" ]; then
  say "maintenance mode on"
  $PHP artisan down --retry=60
  WENT_DOWN=1
fi

say "git pull"
git pull --ff-only

[ "$LOCK_BEFORE" = "$(git rev-parse HEAD:composer.lock 2>/dev/null || echo none)" ] || composer_install

if [ "$NEW_MIGRATIONS" -gt 0 ]; then
  backup_database
fi
say "migrate"
$PHP artisan migrate --force

finish
AFTER=$(git rev-parse --short HEAD)
log_deploy "DEPLOYED $BEFORE..$AFTER"
echo "✓ deployed $(git log --oneline -1)"
