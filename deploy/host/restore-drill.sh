#!/usr/bin/env bash
# NFR-07, ADR 025: the restore drill. Restores the database into a scratch
# cluster on this host (its own data directory, port and socket; WAL
# archiving off, so it never writes to the backup bucket), waits for
# recovery to finish, renames the restored database to <db>_drill, and runs
# `php artisan backup:verify` against it from the deployed API. Then stops
# the scratch cluster and deletes its copy (it holds every tenant's data),
# unless --keep. The live cluster is only read.
#
#   restore-drill.sh [--dry-run] [--keep] [--no-verify]
#                    [--source walg | --source dir:<pg_basebackup dir>]
#                    [--backup <name>]            WAL-G backup (default LATEST)
#                    [--wal-archive <dir>]        dir source: WAL to replay
#                    [--target-time '<timestamp with zone>']
#                    [--tolerance 0.05]           backup:verify's tolerance
#
# --source walg (the default) needs BACKUPS_ENABLED=true. --source dir: is
# the WAL-G-less path (a pg_basebackup -X stream copy, optionally with a WAL
# archive for a point in time), used by the local rehearsal and for a copy
# taken before a risky change.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/host/lib.sh
. "$HERE/lib.sh"

HOST_ENV="${HOST_ENV:-/etc/app-host/host.env}"
# shellcheck disable=SC1090
[ ! -r "$HOST_ENV" ] || . "$HOST_ENV"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_PATH="${DEPLOY_PATH:-/srv/platform}"
DB_NAME="${DB_NAME:-app}"
PG_VERSION="${PG_VERSION:-16}"
PG_BINDIR="${PG_BINDIR:-/usr/lib/postgresql/$PG_VERSION/bin}"
PG_PORT="${PG_PORT:-5432}"
PG_SOCKET_DIR="${PG_SOCKET_DIR:-/var/run/postgresql}"
PG_OS_USER="${PG_OS_USER:-postgres}"
PG_SUPERUSER="${PG_SUPERUSER:-postgres}"
PG_SSL_CERT_FILE="${PG_SSL_CERT_FILE:-/etc/ssl/certs/ssl-cert-snakeoil.pem}"
PG_SSL_KEY_FILE="${PG_SSL_KEY_FILE:-/etc/ssl/private/ssl-cert-snakeoil.key}"
WALG_BIN="${WALG_BIN:-/usr/local/bin/wal-g}"
WALG_CONFIG="${WALG_CONFIG:-/etc/wal-g/walg.json}"
DRILL_ROOT="${DRILL_ROOT:-/var/lib/app-host/drill}"
DRILL_SOCKET_DIR="${DRILL_SOCKET_DIR:-}"
DRILL_PORT="${DRILL_PORT:-5433}"
DRILL_LOG="${DRILL_LOG:-/var/lib/app-host/drill-history.log}"
PHP_BIN="${PHP_BIN:-php}"
# How long recovery may take (WAL replay of a busy day can be long).
DRILL_RECOVERY_TIMEOUT="${DRILL_RECOVERY_TIMEOUT:-7200}"

source_kind=walg
source_dir=""
backup=LATEST
wal_archive=""
target_time=""
tolerance=0.05
keep=0
verify=1
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --keep) keep=1 ;;
    --no-verify) verify=0 ;;
    --source)
      case "${2:?--source needs walg or dir:<path>}" in
        walg) source_kind=walg ;;
        dir:*) source_kind=dir; source_dir="${2#dir:}" ;;
        *) die "--source is walg or dir:<path>" ;;
      esac
      shift ;;
    --backup) backup="${2:?}"; shift ;;
    --wal-archive) wal_archive="${2:?}"; shift ;;
    --target-time) target_time="${2:?}"; shift ;;
    --tolerance) tolerance="${2:?}"; shift ;;
    -h | --help) sed -n '2,22p' "$0"; exit 0 ;;
    *) die "Unknown argument: $1" ;;
  esac
  shift
done

[[ "$DRILL_PORT" =~ ^[0-9]+$ ]] && [ "$DRILL_PORT" != "$PG_PORT" ] || die "DRILL_PORT must be a port other than the live cluster's ($PG_PORT)."
[[ "$DRILL_RECOVERY_TIMEOUT" =~ ^[0-9]+$ ]] || die "DRILL_RECOVERY_TIMEOUT is a number of seconds."
[[ "$tolerance" =~ ^0?\.[0-9]+$|^0$|^1$ ]] || die "--tolerance is a fraction, e.g. 0.05."
[[ "$backup" =~ ^[A-Za-z0-9_]+$ ]] || die "Invalid --backup name."
case "$target_time" in *\'* | *\\*) die "Invalid --target-time." ;; esac
if [ "$source_kind" = walg ]; then
  [ "${BACKUPS_ENABLED:-false}" = true ] || die "Backups are off (BACKUPS_ENABLED=false): nothing to restore from WAL-G. Use --source dir:<pg_basebackup dir> for a local copy."
else
  [ -d "$source_dir" ] && [ -f "$source_dir/PG_VERSION" ] || die "$source_dir is not a pg_basebackup directory."
  [ -z "$wal_archive" ] || [ -d "$wal_archive" ] || die "No WAL archive at $wal_archive."
  [ -z "$target_time" ] || [ -n "$wal_archive" ] || die "--target-time with a dir source needs --wal-archive."
fi

as_pg() { as_user "$PG_OS_USER" "$@"; }
pg_mkdir() {
  if [ "$(id -un)" = "$PG_OS_USER" ]; then
    run mkdir -p -m 700 "$1"
  else
    run install -d -m 700 -o "$PG_OS_USER" -g "$PG_OS_USER" "$1"
  fi
}
live_psql() { as_pg "$PG_BINDIR/psql" -X -q -At -v ON_ERROR_STOP=1 -h "$PG_SOCKET_DIR" -p "$PG_PORT" -U "$PG_SUPERUSER" -d postgres "$@"; }

started="$(now_epoch)"
stamp="$(date_from_epoch "$started" +%Y%m%dT%H%M%SZ)"
drill="$DRILL_ROOT/$stamp"
data="$drill/data"
socket="${DRILL_SOCKET_DIR:-$drill}"
server_started=0
result=failed

cleanup() {
  if [ "$server_started" = 1 ]; then
    as_pg "$PG_BINDIR/pg_ctl" -D "$data" -m fast -w stop >/dev/null 2>&1 || warn "Could not stop the drill cluster in $data; stop it by hand."
  fi
  if [ "$keep" = 0 ] && { [ -f "$drill/.restore-drill" ] || is_dry_run; }; then
    # Only the directory this run created (it carries the marker).
    run rm -rf "$drill"
  elif [ -d "$drill" ]; then
    note "kept $drill (it holds every tenant's data: delete it when done)"
  fi
  if ! is_dry_run; then
    mkdir -p "$(dirname "$DRILL_LOG")"
    printf '%s source=%s backup=%s target_time=%s verify=%s result=%s seconds=%s\n' \
      "$stamp" "$source_kind${source_dir:+:$source_dir}" "$backup" "${target_time:-latest}" "$verify" "$result" "$(($(now_epoch) - started))" >>"$DRILL_LOG"
  fi
}
trap cleanup EXIT

log "Restore drill $stamp: $source_kind${source_dir:+ $source_dir}, backup $backup, target ${target_time:-latest}"

# Room for a full copy plus a fifth, on the drill's disk (the 50 GB disk
# holds the live database too).
need_kb=0
if [ "$source_kind" = walg ]; then
  if ! is_dry_run; then
    bytes="$(live_psql -c "select sum(pg_database_size(datname)) from pg_database")"
    need_kb=$((bytes * 12 / 10240))
  fi
else
  need_kb=$(($(du -sk "$source_dir" | cut -f1) * 12 / 10))
fi
run mkdir -p "$DRILL_ROOT"
if ! is_dry_run; then
  free_kb="$(df -Pk "$DRILL_ROOT" | awk 'NR == 2 { print $4 }')"
  [ "$free_kb" -gt "$need_kb" ] || die "Not enough disk for the drill: $((need_kb / 1024)) MB needed, $((free_kb / 1024)) MB free in $DRILL_ROOT."
  note "disk: $((need_kb / 1024)) MB needed, $((free_kb / 1024)) MB free"
fi

pg_mkdir "$drill"
pg_mkdir "$data"
if is_dry_run; then
  printf '[dry-run] mark %s as this drill'"'"'s directory\n' "$drill"
else
  as_pg touch "$drill/.restore-drill"
fi

t0="$(now_epoch)"
restore_command=""
if [ "$source_kind" = walg ]; then
  as_pg "$WALG_BIN" --config "$WALG_CONFIG" backup-fetch "$data" "$backup"
  restore_command="$WALG_BIN --config $WALG_CONFIG wal-fetch %f %p"
else
  as_pg cp -a "$source_dir/." "$data/"
  [ -z "$wal_archive" ] || restore_command="cp $wal_archive/%f %p"
fi
note "fetched in $(($(now_epoch) - t0)) s"

# The backup's own settings never apply here: ALTER SYSTEM values could turn
# archiving back on. Set aside, not deleted.
for f in postgresql.auto.conf standby.signal postmaster.pid; do
  if [ -e "$data/$f" ] || is_dry_run; then
    run mv -f "$data/$f" "$data/$f.from-backup" 2>/dev/null || true
  fi
done

max_connections=40
if ! is_dry_run; then
  max_connections="$(live_psql -c "show max_connections" 2>/dev/null || echo 40)"
fi
conf="$(cat <<EOF
# Restore drill $stamp (restore-drill.sh). Scratch cluster: archiving off.
hba_file = '$drill/pg_hba.conf'
ident_file = '$drill/pg_ident.conf'
listen_addresses = '127.0.0.1'
port = $DRILL_PORT
unix_socket_directories = '$socket'
max_connections = $max_connections
max_locks_per_transaction = 256
max_prepared_transactions = 0
shared_buffers = 64MB
work_mem = 4MB
maintenance_work_mem = 32MB
ssl = on
ssl_cert_file = '$PG_SSL_CERT_FILE'
ssl_key_file = '$PG_SSL_KEY_FILE'
archive_mode = off
archive_command = ''
hot_standby = off
restore_command = '$restore_command'
recovery_target_time = '$target_time'
recovery_target_action = 'promote'
logging_collector = off
EOF
)"
hba="$(cat <<EOF
local    all  $PG_SUPERUSER                 peer
host     all  $PG_SUPERUSER  0.0.0.0/0      reject
hostssl  all  all            127.0.0.1/32   scram-sha-256
host     all  all            0.0.0.0/0      reject
EOF
)"
if is_dry_run; then
  printf '[dry-run] write %s/postgresql.conf, pg_hba.conf, pg_ident.conf\n' "$drill"
else
  printf '%s\n' "$conf" | as_pg tee "$drill/postgresql.conf" >/dev/null
  printf '%s\n' "$hba" | as_pg tee "$drill/pg_hba.conf" >/dev/null
  as_pg touch "$drill/pg_ident.conf"
fi
if [ -n "$restore_command" ]; then
  if is_dry_run; then
    printf '[dry-run] touch %s/recovery.signal\n' "$data"
  else
    as_pg touch "$data/recovery.signal"
  fi
fi

t0="$(now_epoch)"
log "Recovering on port $DRILL_PORT (archiving off)"
server_started=1
as_pg "$PG_BINDIR/pg_ctl" -D "$data" -o "-c config_file=$drill/postgresql.conf -c archive_mode=off" -l "$drill/server.log" -w -t 7200 start
drill_psql() { as_pg "$PG_BINDIR/psql" -X -q -At -v ON_ERROR_STOP=1 -h "$socket" -p "$DRILL_PORT" -U "$PG_SUPERUSER" -d postgres "$@"; }
if ! is_dry_run; then
  # pg_ctl -w returns once the server runs, which may still be replaying
  # WAL (hot_standby is off, so it refuses connections until promoted).
  # Found by the local rehearsal (2026-10-22): wait for the promotion.
  deadline=$(($(date -u +%s) + DRILL_RECOVERY_TIMEOUT))
  until [ "$(drill_psql -c "select pg_is_in_recovery()" 2>/dev/null || true)" = f ]; do
    as_pg "$PG_BINDIR/pg_ctl" -D "$data" status >/dev/null 2>&1 || die "The drill cluster stopped during recovery; see $drill/server.log."
    [ "$(date -u +%s)" -lt "$deadline" ] || die "Recovery did not finish within $DRILL_RECOVERY_TIMEOUT s; see $drill/server.log."
    sleep 2
  done
  note "recovered in $(($(now_epoch) - t0)) s"
fi
drill_psql -c "ALTER DATABASE \"$DB_NAME\" RENAME TO \"${DB_NAME}_drill\""

if [ "$verify" = 1 ]; then
  log "backup:verify against ${DB_NAME}_drill on port $DRILL_PORT (tolerance $tolerance)"
  [ -f "$DEPLOY_PATH/api/artisan" ] || is_dry_run || die "No deployed API at $DEPLOY_PATH/api: run with --no-verify, or deploy first."
  (
    cd "$DEPLOY_PATH/api" 2>/dev/null || cd /
    as_user "$DEPLOY_USER" env BACKUP_VERIFY_HOST=127.0.0.1 BACKUP_VERIFY_PORT="$DRILL_PORT" \
      "$PHP_BIN" artisan backup:verify --target-database="${DB_NAME}_drill" --tolerance="$tolerance"
  )
fi

result=passed
log "Drill passed in $(($(now_epoch) - started)) s. Record it in the ops log (docs/backups.md)."
