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
mkdir -p "$(dirname "$LOCK_FILE")"
if [ -f "$LOCK_FILE" ]; then
  die "Another deploy holds $LOCK_FILE (since $(cat "$LOCK_FILE")). If none is running, delete it and retry."
fi
date '+%Y-%m-%d %H:%M:%S' > "$LOCK_FILE"

WENT_DOWN=0
cleanup() {
  status=$?
  if [ "$WENT_DOWN" = 1 ]; then
    say "bringing the site back up"
    $PHP artisan up || warn "artisan up failed — run it by hand (cx.sh 73)."
  fi
  rm -f "$LOCK_FILE"
  if [ $status -ne 0 ]; then
    log_deploy "FAILED $MODE at $(git rev-parse --short HEAD) (exit $status)"
    echo "✗ deploy failed (exit $status). The code is at $(git log --oneline -1)."
    echo "  Read the output above, then fix forward or run ./deploy.sh --rollback."
  fi
}
trap cleanup EXIT

backup_database() {
  mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
  stamp=$(date '+%Y%m%d-%H%M%S')
  case "$(env_value DB_CONNECTION)" in
    sqlite|"")
      db=$(env_value DB_DATABASE); db="${db:-database/database.sqlite}"
      if [ -f "$db" ]; then
        say "backup: $db → $BACKUP_DIR/db-$stamp.sqlite"
        cp "$db" "$BACKUP_DIR/db-$stamp.sqlite"
      else
        warn "SQLite file $db not found — no backup taken."
      fi ;;
    mysql|mariadb)
      if command -v mysqldump >/dev/null 2>&1; then
        say "backup: mysqldump → $BACKUP_DIR/db-$stamp.sql.gz"
        MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces \
          -h "$(env_value DB_HOST)" -P "$(p=$(env_value DB_PORT); echo "${p:-3306}")" -u "$(env_value DB_USERNAME)" \
          "$(env_value DB_DATABASE)" | gzip > "$BACKUP_DIR/db-$stamp.sql.gz"
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
