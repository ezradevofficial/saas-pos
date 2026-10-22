#!/usr/bin/env bash
# Runs the migrations as the schema owner, retrying when one gave up waiting
# for a lock (Phase 8 review L9, docs/deploy-linode.md "Migrations and locks").
#
# The owner connection sets `lock_timeout` (DB_OWNER_LOCK_TIMEOUT, default 5s),
# so a migration that needs a table a running transaction holds (a queue
# worker, a long export) fails fast with SQLSTATE 55P03 instead of queueing
# behind it and blocking every other query on that table. Each migration runs
# in its own transaction, so the failed one is rolled back and running the
# command again is safe. Any other failure stops at once.
#
# Environment (all optional):
#   MIGRATE_ATTEMPTS   how many times to try (default 3)
#   MIGRATE_RETRY_WAIT seconds between attempts (default 15)
#   MIGRATE_COMMAND    the command to run (default: php artisan migrate
#                      --database=pgsql_owner --force); the tests set it
set -uo pipefail

attempts="${MIGRATE_ATTEMPTS:-3}"
wait_seconds="${MIGRATE_RETRY_WAIT:-15}"
command="${MIGRATE_COMMAND:-php artisan migrate --database=pgsql_owner --force}"
log="$(mktemp)"
trap 'rm -f "$log"' EXIT

attempt=1
while true; do
  # shellcheck disable=SC2086 # the command is a word list on purpose
  $command 2>&1 | tee "$log"
  status=${PIPESTATUS[0]}

  if [ "$status" -eq 0 ]; then
    exit 0
  fi

  if ! grep -qE '55P03|lock timeout' "$log"; then
    echo "Migrations failed (not a lock timeout); not retrying." >&2
    exit "$status"
  fi

  if [ "$attempt" -ge "$attempts" ]; then
    echo "A migration still waited too long for a lock after $attempt attempts. Find the blocking session (pg_stat_activity) and run the deploy again." >&2
    exit "$status"
  fi

  echo "A migration waited too long for a lock; trying again in ${wait_seconds}s (attempt $((attempt + 1)) of $attempts)." >&2
  sleep "$wait_seconds"
  attempt=$((attempt + 1))
done
