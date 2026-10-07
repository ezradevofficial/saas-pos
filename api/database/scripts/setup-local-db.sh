#!/usr/bin/env bash
# Local development only. Creates the roles and (re)creates the `app` and
# `app_test` databases owned by app_owner (TEN-01). Idempotent: existing
# databases are dropped and recreated, so run migrations afterwards.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PGSUPERUSER="${PGSUPERUSER:-$(id -un)}"
PGHOST="${PGHOST:-127.0.0.1}"
PGPORT="${PGPORT:-5432}"
APP_PASSWORD="${APP_PASSWORD:-app}"
APP_OWNER_PASSWORD="${APP_OWNER_PASSWORD:-app_owner}"
export PGHOST PGPORT

psql_su() { psql -U "$PGSUPERUSER" -v ON_ERROR_STOP=1 -q "$@"; }

echo "Creating roles app_owner and app (as $PGSUPERUSER)"
psql_su -d postgres -f "$DIR/create-roles.sql"
psql_su -d postgres \
  -c "ALTER ROLE app_owner PASSWORD '${APP_OWNER_PASSWORD}'" \
  -c "ALTER ROLE app PASSWORD '${APP_PASSWORD}'"

for db in app app_test; do
  echo "Recreating database $db owned by app_owner"
  psql_su -d postgres \
    -c "DROP DATABASE IF EXISTS $db WITH (FORCE)" \
    -c "CREATE DATABASE $db OWNER app_owner"
  psql_su -d "$db" <<SQL
ALTER SCHEMA public OWNER TO app_owner;
GRANT USAGE ON SCHEMA public TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO app;
SQL
  echo "Configured database $db"
done
echo "Done. Next: composer migrate"
