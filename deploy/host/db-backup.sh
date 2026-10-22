#!/usr/bin/env bash
# NFR-07, ADR 025: the nightly base backup with WAL-G, then retention. Run
# by app-db-backup.service as postgres, only when BACKUPS_ENABLED=true.
# WAL itself is archived continuously by archive_command (wal-push).
#
#   db-backup.sh [--dry-run] [--retention-only]
#
# Retention (owner decisions, /etc/app-host/host.env): BACKUP_PITR_DAYS of
# point-in-time recovery, BACKUP_DAILY_DAYS daily and BACKUP_MONTHLY_MONTHS
# monthly base backups (walg-retention.jq).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/host/lib.sh
. "$HERE/lib.sh"

HOST_ENV="${HOST_ENV:-/etc/app-host/host.env}"
# shellcheck disable=SC1090
[ ! -r "$HOST_ENV" ] || . "$HOST_ENV"
WALG_BIN="${WALG_BIN:-/usr/local/bin/wal-g}"
WALG_CONFIG="${WALG_CONFIG:-/etc/wal-g/walg.json}"
PG_DATA="${PG_DATA:-/var/lib/postgresql/16/main}"
STATE_DIR="${STATE_DIRECTORY:-/var/lib/app-host/db-backup}"
BACKUP_PITR_DAYS="${BACKUP_PITR_DAYS:-7}"
BACKUP_DAILY_DAYS="${BACKUP_DAILY_DAYS:-30}"
BACKUP_MONTHLY_MONTHS="${BACKUP_MONTHLY_MONTHS:-12}"

retention_only=0
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --retention-only) retention_only=1 ;;
    *) die "Unknown argument: $1" ;;
  esac
  shift
done

[ "${BACKUPS_ENABLED:-false}" = true ] || die "Backups are off (BACKUPS_ENABLED=false in $HOST_ENV)."

walg() { run "$WALG_BIN" --config "$WALG_CONFIG" "$@"; }

started="$(now_epoch)"
if [ "$retention_only" = 0 ]; then
  log "Base backup of $PG_DATA"
  walg backup-push "$PG_DATA"
fi

log "Retention: ${BACKUP_PITR_DAYS} days point in time, $BACKUP_DAILY_DAYS dailies, $BACKUP_MONTHLY_MONTHS monthlies"
# The list is read even in a dry run: it changes nothing.
list="$("$WALG_BIN" --config "$WALG_CONFIG" backup-list --json --detail)"
plan="$(printf '%s' "$list" | jq -r --arg now "$(now_epoch)" --arg pitr "$BACKUP_PITR_DAYS" \
  --arg daily "$BACKUP_DAILY_DAYS" --arg monthly "$BACKUP_MONTHLY_MONTHS" -f "$HERE/walg-retention.jq")"
# Marks first, so the delete never removes a backup that is kept.
printf '%s\n' "$plan" | while read -r action name; do
  case "$action" in
    mark) walg backup-mark "$name" ;;
    unmark) walg backup-mark -i "$name" ;;
  esac
done
printf '%s\n' "$plan" | while read -r action name; do
  [ "$action" != delete-before ] || walg delete before FIND_FULL "$name" --confirm
done

if [ "$retention_only" = 0 ] && ! is_dry_run; then
  mkdir -p "$STATE_DIR"
  printf '%s\n' "$started" >"$STATE_DIR/last-success"
fi
log "Done."
