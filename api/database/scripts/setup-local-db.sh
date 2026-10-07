#!/usr/bin/env bash
# Creates the roles and (re)creates the databases owned by app_owner (TEN-01).
# Used for local development and by CI (.github/workflows/ci.yml).
# Idempotent: existing databases are dropped and recreated, so run
# migrations afterwards (composer migrate).
#
# Environment (all optional):
#   PGSUPERUSER   superuser to connect as (default: $PGUSER, else your OS user)
#   PGPASSWORD    the superuser's password, read by psql itself
#   PGHOST        default 127.0.0.1
#   PGPORT        default 5432
#   DATABASES     space-separated database names (default: "app app_test")
#   APP_PASSWORD, APP_OWNER_PASSWORD   role passwords (default: app, app_owner)
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PGSUPERUSER="${PGSUPERUSER:-${PGUSER:-$(id -un)}}"
PGHOST="${PGHOST:-127.0.0.1}"
PGPORT="${PGPORT:-5432}"
DATABASES="${DATABASES:-app app_test}"
APP_PASSWORD="${APP_PASSWORD:-app}"
APP_OWNER_PASSWORD="${APP_OWNER_PASSWORD:-app_owner}"
export PGHOST PGPORT

psql_su() { psql -U "$PGSUPERUSER" -v ON_ERROR_STOP=1 -q "$@"; }

# SQL string literal: double any single quote.
literal() { printf "'%s'" "${1//\'/\'\'}"; }

# Database names are interpolated as identifiers: allow plain names only.
for db in $DATABASES; do
  if [[ ! "$db" =~ ^[a-z_][a-z0-9_]*$ ]]; then
    echo "Invalid database name: $db (use lowercase letters, digits and _)" >&2
    exit 1
  fi
done

echo "Creating roles app_owner and app (as $PGSUPERUSER on $PGHOST:$PGPORT)"
psql_su -d postgres -f "$DIR/create-roles.sql"
psql_su -d postgres \
  -c "ALTER ROLE app_owner PASSWORD $(literal "$APP_OWNER_PASSWORD")" \
  -c "ALTER ROLE app PASSWORD $(literal "$APP_PASSWORD")"

for db in $DATABASES; do
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
