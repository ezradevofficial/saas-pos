#!/usr/bin/env bash
# NFR-01, NFR-07, ADR 025: the host watch, every 5 minutes (app-host-watch.timer).
# No third-party uptime service: it checks from the host itself and emails
# the owner (HEALTH_ALERT_EMAIL) through the app's own SMTP settings
# (MAIL_* in the API's .env), once when the set of problems changes and
# once when everything recovers. A failed email is tried again next run.
#
# Checks (thresholds in /etc/app-host/host.env):
#   ready        GET https://<API_DOMAIN>/health/ready answers 200
#   disk:<path>  the root and database file systems below WATCH_DISK_PCT used
#                (one 50 GB disk holds the database, logs and the app)
#   connections  client connections below WATCH_CONNECTIONS_PCT of max_connections
#   long-tx      no transaction open longer than WATCH_LONG_TX_SECONDS (it
#                also holds back POS sync, ADR 004)
#   replication  replica replay lag under 5 minutes (only once a replica exists)
#   archive      with backups on: WAL archiving not failing, under 20 files waiting
#   base-backup  with backups on: the last base backup under
#                WATCH_BACKUP_MAX_AGE_HOURS old
#
#   host-watch.sh [--dry-run]   (--dry-run: check and print, send nothing, keep no state)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/host/lib.sh
. "$HERE/lib.sh"
[ "${1:-}" != --dry-run ] || DRY_RUN=1

HOST_ENV="${HOST_ENV:-/etc/app-host/host.env}"
# shellcheck disable=SC1090
[ ! -r "$HOST_ENV" ] || . "$HOST_ENV"
DEPLOY_PATH="${DEPLOY_PATH:-/srv/platform}"
APP_ENV_FILE="${APP_ENV_FILE:-$DEPLOY_PATH/api/.env}"
STATE_DIR="${STATE_DIRECTORY:-/var/lib/app-host/watch}"
BACKUP_STATE="${BACKUP_STATE:-/var/lib/app-host/db-backup/last-success}"
PG_DATA="${PG_DATA:-/var/lib/postgresql/16/main}"
PG_PORT="${PG_PORT:-5432}"
PG_SOCKET_DIR="${PG_SOCKET_DIR:-/var/run/postgresql}"
WATCH_READY_URL="${WATCH_READY_URL:-https://${API_DOMAIN:-localhost}/health/ready}"
WATCH_DISK_PCT="${WATCH_DISK_PCT:-80}"
WATCH_CONNECTIONS_PCT="${WATCH_CONNECTIONS_PCT:-80}"
WATCH_LONG_TX_SECONDS="${WATCH_LONG_TX_SECONDS:-600}"
WATCH_BACKUP_MAX_AGE_HOURS="${WATCH_BACKUP_MAX_AGE_HOURS:-26}"
# Overridable for the tests.
CURL_BIN="${CURL_BIN:-curl}"
WATCH_DF="${WATCH_DF:-df}"

# psql as the postgres superuser through the local socket, or WATCH_PSQL.
pg() {
  if [ -n "${WATCH_PSQL:-}" ]; then
    "$WATCH_PSQL" -c "$1"
  else
    runuser -u postgres -- psql -X -q -At -h "$PG_SOCKET_DIR" -p "$PG_PORT" -d postgres -c "$1"
  fi
}

keys=""
details=""
problem() {
  keys="$keys$1"$'\n'
  details="$details- $2"$'\n'
}

# 1. Readiness, through nginx and TLS like any client.
code="$("$CURL_BIN" -s -o /dev/null -m 20 -w '%{http_code}' "$WATCH_READY_URL" || true)"
[ "$code" = 200 ] || problem ready "readiness: $WATCH_READY_URL answered ${code:-nothing} (expected 200)"

# 2. Disk.
for path in / "$PG_DATA"; do
  [ -e "$path" ] || continue
  used="$("$WATCH_DF" -P "$path" | awk 'NR == 2 { sub(/%/, "", $5); print $5 }')"
  mount="$("$WATCH_DF" -P "$path" | awk 'NR == 2 { print $6 }')"
  case "$keys" in *"disk:$mount"*) continue ;; esac
  if [ -n "$used" ] && [ "$used" -ge "$WATCH_DISK_PCT" ]; then
    problem "disk:$mount" "disk: $mount is ${used}% full (alert at ${WATCH_DISK_PCT}%); see docs/deploy-linode.md, Disk"
  fi
done

# 3 to 6. The database.
if ! pg_out="$(pg "select
    (select count(*) from pg_stat_activity where backend_type = 'client backend'),
    current_setting('max_connections')::int,
    coalesce((select extract(epoch from max(now() - xact_start))::int from pg_stat_activity
              where backend_type = 'client backend' and state <> 'idle' and xact_start is not null and pid <> pg_backend_pid()), 0),
    coalesce((select extract(epoch from max(replay_lag))::int from pg_stat_replication), 0),
    coalesce((select (last_failed_time > coalesce(last_archived_time, 'epoch'))::int from pg_stat_archiver), 0),
    (select count(*) from pg_ls_archive_statusdir() where name like '%.ready')" 2>&1)"; then
  problem database "database: cannot query PostgreSQL ($(printf '%s' "$pg_out" | head -1))"
else
  IFS='|' read -r conns max_conns longest lag archive_failing pending <<EOF
$pg_out
EOF
  if [ $((conns * 100)) -ge $((max_conns * WATCH_CONNECTIONS_PCT)) ]; then
    problem connections "database: $conns of $max_conns connections in use (alert at ${WATCH_CONNECTIONS_PCT}%)"
  fi
  if [ "$longest" -ge "$WATCH_LONG_TX_SECONDS" ]; then
    problem long-tx "database: a transaction has been open for ${longest} s (alert at ${WATCH_LONG_TX_SECONDS} s; it also holds back POS sync)"
  fi
  if [ "$lag" -ge 300 ]; then
    problem replication "database: a replica is ${lag} s behind"
  fi
  if [ "${BACKUPS_ENABLED:-false}" = true ]; then
    if [ "$archive_failing" = 1 ] || [ "$pending" -ge 20 ]; then
      problem archive "backups: WAL archiving is failing or behind ($pending files waiting); point-in-time recovery is at risk"
    fi
  fi
fi

# 7. The base backup's age.
if [ "${BACKUPS_ENABLED:-false}" = true ]; then
  last="$(cat "$BACKUP_STATE" 2>/dev/null || echo 0)"
  age_h=$((($(now_epoch) - last) / 3600))
  if [ "$age_h" -ge "$WATCH_BACKUP_MAX_AGE_HOURS" ]; then
    problem base-backup "backups: the last base backup is ${age_h} h old (alert at ${WATCH_BACKUP_MAX_AGE_HOURS} h); see journalctl -u app-db-backup"
  fi
fi

state="$(printf '%s' "$keys" | sort -u | sed '/^$/d')"
previous="$(cat "$STATE_DIR/state" 2>/dev/null || true)"

if [ -n "$state" ]; then
  printf 'Problems:\n%s' "$details"
else
  echo "All checks pass."
fi
is_dry_run && exit 0
[ "$state" != "$previous" ] || exit 0

# --- The state changed: email the owner --------------------------------

dotenv() {
  local value
  value="$(sed -n "s/^$1=//p" "$APP_ENV_FILE" 2>/dev/null | tail -1)"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  [ "$value" != null ] || value=""
  printf '%s' "$value"
}

label="${API_DOMAIN:-$(hostname)}"
if [ -n "$state" ]; then
  subject="[$label] Problem: $(printf '%s' "$state" | tr '\n' ' ' | sed 's/ $//')"
  body="These checks on $label are failing:

$details
Checked at $(date -u '+%Y-%m-%d %H:%M UTC'). You get one email when this changes and one when it recovers."
else
  subject="[$label] Recovered: every check passes"
  body="Every check on $label passes again (previously failing: $(printf '%s' "$previous" | tr '\n' ' ')).

Checked at $(date -u '+%Y-%m-%d %H:%M UTC')."
fi

to="${HEALTH_ALERT_EMAIL:-}"
host="$(dotenv MAIL_HOST)"
port="$(dotenv MAIL_PORT)"
scheme="$(dotenv MAIL_SCHEME)"
user="$(dotenv MAIL_USERNAME)"
from="$(dotenv MAIL_FROM_ADDRESS)"
if [ -z "$to" ] || [ -z "$host" ] || [ -z "$from" ] || [ "$(dotenv MAIL_MAILER)" != smtp ]; then
  warn "Cannot email: set HEALTH_ALERT_EMAIL in $HOST_ENV and MAIL_MAILER=smtp with MAIL_HOST and MAIL_FROM_ADDRESS in $APP_ENV_FILE. $subject"
  exit 1
fi
if [ "$scheme" = smtps ] || [ "${port:-587}" = 465 ]; then
  url="smtps://$host:${port:-465}"
  tls=()
else
  url="smtp://$host:${port:-587}"
  tls=(--ssl-reqd)
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
chmod 700 "$tmp"
# The mailbox password goes in a private netrc file, never in arguments.
if [ -n "$user" ]; then
  MAIL_PASSWORD_VALUE="$(dotenv MAIL_PASSWORD)"
  printf 'machine %s login %s password %s\n' "$host" "$user" "$MAIL_PASSWORD_VALUE" >"$tmp/netrc"
  unset MAIL_PASSWORD_VALUE
  auth=(--netrc-file "$tmp/netrc")
else
  auth=()
fi
{
  printf 'From: %s\r\n' "$from"
  printf 'To: %s\r\n' "$to"
  printf 'Subject: %s\r\n' "$subject"
  printf 'Date: %s\r\n' "$(LC_ALL=C date -u '+%a, %d %b %Y %H:%M:%S +0000')"
  printf 'Content-Type: text/plain; charset=utf-8\r\n\r\n'
  printf '%s\r\n' "$body"
} >"$tmp/message"

if "$CURL_BIN" -sS -m 30 ${tls[@]+"${tls[@]}"} ${auth[@]+"${auth[@]}"} --url "$url" --mail-from "$from" --mail-rcpt "$to" --upload-file "$tmp/message"; then
  mkdir -p "$STATE_DIR"
  printf '%s' "$state" >"$STATE_DIR/state"
  echo "Emailed $to: $subject"
else
  warn "Could not send the alert email; trying again in 5 minutes. $subject"
  exit 1
fi
