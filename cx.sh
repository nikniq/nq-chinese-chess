#!/bin/bash
#================================================================
# LOCAL command-line assistant for Chinese Chess — cx.sh
#
#   ./cx.sh             # interactive menu
#   ./cx.sh 20          # run an option directly
#   ./cx.sh 41 a@b.com  # …with a second parameter
#
# ⚠ Runs on your LOCAL machine only — never copy this to the server.
#   Its counterpart on the server is deploy.sh.
#
# Requires a vars.sh next to it (copy vars.sh.example). vars.sh is
# gitignored because it names hosts and accounts.
#
# Version 3.0 (ported from the as-website1 cx.sh v2.4)
# - dropped the options for commands this app does not have (pricing,
#   connectors, subscriptions, MCP log, queue/schedule); every option left
#   calls something that exists here
# - deploy options match deploy.sh v3: 20 deploy, 21 deploy with
#   maintenance mode, 27 pre-flight check, 28 rollback, 29 deploy status
# - 20 refuses to deploy unpushed commits without a 'yes': the server
#   deploys by pulling, so a local-only commit deploys the previous one.
#   (The Claude Code Stop hook pushes after every reply, so this is
#   normally clean.)
# - game operations: stats (40), player lookup for support (41),
#   leaderboard (42)
# - backups: back up the database now (90), list server backups (92),
#   download the newest one (93); 91 still runs bk.sh if you installed it
# - 30 health-checks the home page, /up and the leaderboard over HTTPS
# - the server-guard compares hostnames, so running cx.sh on the server
#   stops instead of ssh-ing to itself
#================================================================
clear
cd "$(dirname "$0")"

if [ ! -f ./vars.sh ]; then
  echo "vars.sh not found next to cx.sh — copy vars.sh.example to vars.sh and fill it in."
  exit 1
fi
source ./vars.sh

# Defaults so a minimal vars.sh works.
REMOTE_APP="${REMOTE_APP:-www/$PROJECT}"
APP_URL="${APP_URL:-https://$PROJECT}"
LOG_OUTPUT="${LOG_OUTPUT:-./log/remote.out}"
REMOTE_BACKUPS="${REMOTE_BACKUPS:-backups/$(basename "$REMOTE_APP")}"
APP_SSH="${APP_SSH:-ssh}"
APP_SCP="${APP_SCP:-scp}"

if [ "$(hostname -s 2>/dev/null)" = "${REMOTE_HOST%%.*}" ]; then
  echo "⚠ This looks like $REMOTE_HOST — cx.sh is the LOCAL script. Use deploy.sh here."
  exit 1
fi

#---------------------------------------------------------------
# Helpers
#---------------------------------------------------------------
log_history() {
  mkdir -p ./log
  touch ./log/history.log
  { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M')" "$1"; cat ./log/history.log; } > ./log/.history.tmp \
    && mv ./log/.history.tmp ./log/history.log
}

# Run a command on the server, in the app directory.
remote()     { $APP_SSH "$REMOTE" "cd ~/$REMOTE_APP && $*"; }
# Same, with a terminal — for anything interactive or long-running.
remote_tty() { $APP_SSH -t "$REMOTE" "cd ~/$REMOTE_APP && $*"; }

need_param() { [ -n "$PARAM2" ] || read -r -p "$1: " PARAM2; }

banner() {
  echo ===========================================================
  echo "$1"
  echo ===========================================================
}

# Gate for anything that takes the site down or is hard to undo.
confirm() {
  echo "⚠ $1"
  read -r -p "Type 'yes' to continue: " reply
  [ "$reply" = "yes" ] || { echo "Skipped $SELECTION."; exit 0; }
}

# Warn about work that will not deploy because the server deploys by pulling.
check_pushed() {
  if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
    echo "⚠ Uncommitted local changes — these will NOT deploy:"
    git status --short
    echo
  fi
  git fetch --quiet 2>/dev/null
  UNPUSHED=$(git log --oneline @{u}.. 2>/dev/null)
  if [ -n "$UNPUSHED" ]; then
    echo "⚠ Commits not pushed — these will NOT deploy:"
    echo "$UNPUSHED"
    echo
    confirm "Deploy anyway?"
  fi
  echo "Local HEAD : $(git log --oneline -1)"
  echo
}

echo =============================================================
echo "Hi $USER@$HOSTNAME — acting on $PROJECT ($REMOTE)"
echo What do you want to do?
echo -------------------------------------------------------------
echo "DEPLOY"
echo "20 : DEPLOY: pull + composer + migrate + caches + verify"
echo "21 : DEPLOY with maintenance mode during the deploy"
echo "22 : WHAT'S LIVE: compare the server's HEAD with local"
echo "23 : MIGRATIONS: status on the server"
echo "24 : CACHES: rebuild config/routes/views"
echo "25 : CACHES: clear everything (stale config after an .env edit)"
echo "26 : COMPOSER: install production dependencies"
echo "27 : DEPLOY CHECK: what would deploy (changes nothing)"
echo "28 : ROLLBACK: code back to the commit before the last deploy"
echo "29 : DEPLOY STATUS: current commit and deploy history"
echo ----------------------------------------------
echo DIAGNOSE
echo "30 : HEALTH: $APP_URL — home, /up, leaderboard"
echo "31 : LOGS: tail the Laravel log"
echo "32 : LOGS: recent errors"
echo "33 : ARTISAN: run a command (e.g. 'route:list')"
echo "34 : CONFIG: environment settings (secrets hidden)"
echo "35 : DISK: what is using the account quota"
echo "36 : LOGS: file sizes"
echo ----------------------------------------------
echo "GAME"
echo "40 : STATS: players, games and results"
echo "41 : PLAYER: look up a player by email (support)"
echo "42 : LEADERBOARD: fetch the live page"
echo ----------------------------------------------
echo MAINTENANCE
echo "70 : VERIFY: run the post-deploy checks on their own"
echo "72 : MAINTENANCE MODE: on  [site goes down]"
echo "73 : MAINTENANCE MODE: off"
echo "74 : LOGS: truncate the Laravel log  [discards log history]"
echo ----------------------------------------------
echo "BACKUP"
echo "90 : BACKUP: database now (SQLite copy or mysqldump)"
echo "91 : BACKUP: run bk.sh (database and files, if installed via 03)"
echo "92 : BACKUP: list backups on the server"
echo "93 : BACKUP: download the newest database backup to ./backup"
echo ----------------------------------------------
echo ACCESS
echo "00 : CONNECT: to $REMOTE"
echo "01 : RSYNC: ssh settings and dotfiles to remote"
echo "03 : SETUP BACKUP: copy bk.sh to remote"
echo "05 : MONITOR: remote top"
echo "06 : TERMINATE ACCESS: delete remote ssh directory"
echo "99 : LOCAL FILE UPDATE and SECURITY SCRUB"
echo "qq : Exit"
echo =============================================================
if [ -n "$1" ]; then
  SELECTION=$1
else
  read -r -n 2 SELECTION
fi
PARAM2="${2:-}"
echo
echo "Your selection is : $SELECTION."

case "$SELECTION" in

  #-------------------------------------------------------------
  # DEPLOY
  #-------------------------------------------------------------
  "20" )
  banner "DEPLOY: $PROJECT"
  check_pushed
  remote_tty "./deploy.sh" && log_history "Deployed $PROJECT ($(git rev-parse --short HEAD))" \
    || { log_history "Deploy FAILED on $PROJECT"; exit 1; }
  ;;

  "21" )
  banner "DEPLOY with maintenance mode: $PROJECT"
  check_pushed
  confirm "$APP_URL returns 503 for the length of the deploy. deploy.sh brings it back up even if a step fails."
  remote_tty "./deploy.sh --down" && log_history "Deployed $PROJECT with maintenance mode" \
    || { log_history "Deploy (--down) FAILED on $PROJECT"; exit 1; }
  ;;

  "22" )
  banner "WHAT'S LIVE on $PROJECT"
  echo "--- server ---"
  remote "git log --oneline -3 && git status --short"
  echo
  echo "--- local ---"
  git log --oneline -3
  echo
  LOCAL_HEAD=$(git rev-parse HEAD 2>/dev/null)
  SERVER_HEAD=$(remote "git rev-parse HEAD" 2>/dev/null | tr -d '\r')
  if [ "$LOCAL_HEAD" = "$SERVER_HEAD" ]; then
    echo "✓ server matches local HEAD"
  else
    echo "✗ server is NOT on your local HEAD"
    echo "  local : $LOCAL_HEAD"
    echo "  server: $SERVER_HEAD"
  fi
  ;;

  "23" )
  banner "MIGRATIONS: status on $PROJECT"
  remote "php artisan migrate:status"
  ;;

  "24" )
  banner "CACHES: rebuild on $PROJECT"
  remote "php artisan optimize"
  log_history "Rebuilt caches on $PROJECT"
  ;;

  "25" )
  banner "CACHES: clear everything on $PROJECT"
  # A stale config cache is why an .env edit can look ignored. Run 24 after.
  remote "php artisan optimize:clear"
  log_history "Cleared all caches on $PROJECT"
  ;;

  "26" )
  banner "COMPOSER: install production dependencies"
  remote_tty "composer install --no-dev --optimize-autoloader --no-interaction"
  log_history "Installed composer dependencies on $PROJECT"
  ;;

  "27" )
  banner "DEPLOY CHECK: $PROJECT"
  check_pushed
  remote "./deploy.sh --check"
  ;;

  "28" )
  banner "ROLLBACK: $PROJECT"
  # deploy.sh asks for its own confirmation and shows the target commit.
  remote_tty "./deploy.sh --rollback" && log_history "Rolled back $PROJECT"
  ;;

  "29" )
  banner "DEPLOY STATUS: $PROJECT"
  remote "./deploy.sh --status"
  ;;

  #-------------------------------------------------------------
  # DIAGNOSE
  #-------------------------------------------------------------
  "30" )
  banner "HEALTH: $APP_URL"
  # Checked from here so it covers DNS, TLS and the web server, not just PHP.
  for path in "" "/up" "/leaderboard"; do
    printf '%-14s ' "${path:-/}"
    curl -sS -o /dev/null -w '%{http_code}  %{time_total}s\n' --max-time 25 "$APP_URL$path" \
      || echo "✗ no response"
  done
  echo
  echo "Expect 200 for each. /up is Laravel's health route: 500 means the app failed to boot."
  ;;

  "31" )
  banner "LOGS: tail on $PROJECT (Ctrl-C to stop)"
  remote_tty "tail -n 40 -f storage/logs/laravel.log"
  ;;

  "32" )
  banner "LOGS: recent errors on $PROJECT"
  remote "grep -nE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' storage/logs/laravel.log | tail -n 25" \
    || echo "No matching lines (or no log file yet)."
  ;;

  "33" )
  banner "ARTISAN on $PROJECT"
  need_param "artisan command (without the word 'artisan')"
  remote_tty "php artisan $PARAM2"
  log_history "Ran artisan $PARAM2 on $PROJECT"
  ;;

  "34" )
  banner "CONFIG on $PROJECT"
  echo "--- environment ---"
  remote "grep -E '^(APP_ENV|APP_DEBUG|APP_URL|LOG_LEVEL|SESSION_DRIVER|CACHE_STORE|QUEUE_CONNECTION|MAIL_MAILER|DB_CONNECTION)=' .env | sort"
  echo
  echo "--- secrets present (values hidden) ---"
  remote "grep -oE '^[A-Z_]*(KEY|SECRET|TOKEN|PASSWORD)[A-Z_]*=.' .env | sed -E 's/=.$/ (set)/' | sort | sed 's/^/  /'"
  ;;

  "35" )
  banner "DISK: quota and the biggest directories"
  remote "du -sh . 2>/dev/null; echo; echo 'largest directories:'; du -sh */ 2>/dev/null | sort -rh | head -12; echo; echo 'backups:'; du -sh ~/$REMOTE_BACKUPS 2>/dev/null || echo '  none'"
  ;;

  "36" )
  banner "LOGS: sizes on $PROJECT"
  remote "ls -lh storage/logs/ 2>/dev/null | tail -n +2; echo; echo 'total:'; du -sh storage/logs 2>/dev/null"
  ;;

  #-------------------------------------------------------------
  # GAME
  #-------------------------------------------------------------
  "40" )
  banner "STATS: $PROJECT"
  remote "php artisan chess:stats"
  ;;

  "41" )
  banner "PLAYER lookup"
  need_param "Player email"
  remote "php artisan chess:user '$PARAM2'"
  ;;

  "42" )
  banner "LEADERBOARD: $APP_URL/leaderboard"
  # Strip tags for a terminal read; the check is that the page renders.
  curl -sS --max-time 25 "$APP_URL/leaderboard" | sed -n '/<table/,/<\/table>/p' \
    | sed -E 's/<[^>]+>/ /g; s/[[:space:]]+/ /g' | grep -v '^ *$' | head -30 \
    || echo "✗ could not fetch the leaderboard"
  ;;

  #-------------------------------------------------------------
  # MAINTENANCE
  #-------------------------------------------------------------
  "70" )
  banner "VERIFY: post-deploy checks on $PROJECT"
  remote "php artisan ops:verify-deploy"
  ;;

  "72" )
  banner "MAINTENANCE MODE: ON"
  confirm "$APP_URL will return 503 to everyone until you run 73."
  remote_tty "php artisan down --retry=60"
  log_history "Maintenance mode ON for $PROJECT"
  ;;

  "73" )
  banner "MAINTENANCE MODE: OFF"
  remote_tty "php artisan up"
  log_history "Maintenance mode OFF for $PROJECT"
  ;;

  "74" )
  banner "LOGS: truncate the Laravel log"
  confirm "This discards the Laravel log on $PROJECT. Read 32 first if you have not."
  # Truncated, not deleted, so the web user's permissions on the file survive.
  remote "test -f storage/logs/laravel.log && : > storage/logs/laravel.log && echo 'laravel.log truncated.' || echo 'No laravel.log to truncate.'"
  log_history "Truncated the Laravel log on $PROJECT"
  ;;

  #-------------------------------------------------------------
  # BACKUP
  #-------------------------------------------------------------
  "90" )
  banner "BACKUP: database on $PROJECT"
  # deploy.sh owns the backup routine (SQLite copy or mysqldump, pruned to
  # the newest KEEP_BACKUPS) and runs it before every migrating deploy.
  remote "./deploy.sh --backup" && log_history "Database backup on $PROJECT"
  ;;

  "91" )
  banner "BACKUP: database and files via bk.sh"
  need_param "Backup description"
  $APP_SSH -t "$REMOTE" "test -x ~/$REMOTE_APP/bk.sh || { echo 'bk.sh not installed — run 03 first, or use 90.'; exit 1; }; ~/$REMOTE_APP/bk.sh 01 '$PARAM2' && ~/$REMOTE_APP/bk.sh 02 '$PARAM2'"
  log_history "Backup database and files: $PARAM2"
  ;;

  "92" )
  banner "BACKUPS on the server (~/$REMOTE_BACKUPS)"
  remote "ls -lht ~/$REMOTE_BACKUPS 2>/dev/null | head -20 || echo 'No backups yet.'"
  ;;

  "93" )
  banner "BACKUP: download the newest"
  NEWEST=$($APP_SSH "$REMOTE" "ls -1t ~/$REMOTE_BACKUPS/db-* 2>/dev/null | head -1" | tr -d '\r')
  if [ -z "$NEWEST" ]; then
    echo "No backups on the server yet — run 90 first."
  else
    mkdir -p ./backup && chmod 700 ./backup
    $APP_SCP "$REMOTE:$NEWEST" ./backup/ && echo "✓ ./backup/$(basename "$NEWEST")  (gitignored — it holds player emails)"
    log_history "Downloaded backup $(basename "$NEWEST")"
  fi
  ;;

  #-------------------------------------------------------------
  # ACCESS
  #-------------------------------------------------------------
  "00" )
  banner "CONNECT: to $REMOTE"
  $APP_SSH "$REMOTE"
  log_history "Connect to remote"
  ;;

  "01" )
  banner "GAIN ACCESS: copy ssh settings and dotfiles to remote"
  SRC=~/.ssh/remote/$PROJECT/shell
  $APP_SSH "$REMOTE" 'mkdir -p ~/.ssh && chmod 700 ~/.ssh'
  $APP_SCP "$SRC/authorized_keys" "$REMOTE:~/.ssh/authorized_keys"
  $APP_SCP "$SRC/known_hosts"     "$REMOTE:~/.ssh/known_hosts"
  $APP_SSH "$REMOTE" 'chmod 600 ~/.ssh/authorized_keys; for k in ~/.ssh/id*; do [ -f "$k" ] || continue; case "$k" in *.pub) chmod 644 "$k";; *) chmod 600 "$k";; esac; done'
  for f in .vimrc .alias .bashrc .bash_logout; do
    [ -f "$SRC/$f" ] && $APP_SCP "$SRC/$f" "$REMOTE:~/$f"
  done
  # Idempotent: the old form appended a duplicate line on every run.
  $APP_SSH "$REMOTE" 'grep -qx "source ~/.alias" ~/.bash_profile 2>/dev/null || echo "source ~/.alias" >> ~/.bash_profile'
  log_history "GAIN ACCESS: ssh settings to remote"
  ;;

  "03" )
  banner "BACKUP CAPABILITY: copy bk.sh to remote"
  $APP_SSH "$REMOTE" "mkdir -p ~/$REMOTE_APP/backup && chmod 700 ~/$REMOTE_APP/backup"
  $APP_SCP ~/.ssh/remote/$PROJECT/bk.sh "$REMOTE:~/$REMOTE_APP/bk.sh"
  $APP_SSH "$REMOTE" "chmod 755 ~/$REMOTE_APP/bk.sh"
  log_history "BACKUP CAPABILITY: bk.sh to remote"
  ;;

  "05" )
  banner "MONITOR: remote top"
  $APP_SSH -t "$REMOTE" 'top'
  ;;

  "06" )
  banner "TERMINATE ACCESS: delete remote ssh directory"
  confirm "This wipes EVERY file in the remote ~/.ssh, including authorized_keys — you may lock yourself out of $REMOTE."
  $APP_SSH "$REMOTE" 'cd ~/.ssh/ && rm -f *'
  log_history "TERMINATE ACCESS on $REMOTE"
  ;;

  "99" )
  banner "LOCAL FILE UPDATE and SECURITY SCRUB"
  for d in shell log; do mkdir -p "$d"; done
  touch ./log/history.log
  if [ -d "$LOCAL_SCRIPTS_DEFAULT" ]; then
    for f in .alias .vimrc .bashrc .bash_logout authorized_keys known_hosts; do
      [ -f "$LOCAL_SCRIPTS_DEFAULT/shell/$f" ] && cp "$LOCAL_SCRIPTS_DEFAULT/shell/$f" ./shell/
    done
    [ -f "$LOCAL_SCRIPTS_DEFAULT/bk.sh" ] && cp "$LOCAL_SCRIPTS_DEFAULT/bk.sh" .
  else
    echo "⚠ LOCAL_SCRIPTS_DEFAULT ($LOCAL_SCRIPTS_DEFAULT) not found — skipped copying defaults."
  fi
  chmod 700 ~/.ssh
  for k in ~/.ssh/id*; do
    [ -f "$k" ] || continue
    case "$k" in *.pub) chmod 644 "$k" ;; *) chmod 600 "$k" ;; esac
  done
  [ -e ~/.ssh/authorized_keys ] && chmod 600 ~/.ssh/authorized_keys
  chmod 600 ./vars.sh
  log_history "Refreshed files from default"
  ;;

  "qq" | $'\e'* )
  echo Quit
  exit 0
  ;;

  * )
  echo
  echo "Not a recognized option."
  ;;

esac
