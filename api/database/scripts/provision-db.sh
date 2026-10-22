#!/usr/bin/env bash
# Creates the three roles (ADR 002, ADR 013) and one database owned by
# app_owner on a real environment (dev, staging, production), as the
# cluster's admin user. Unlike setup-local-db.sh it never drops anything:
# running it again only resets the passwords and re-applies the grants.
#
# Works as a superuser or as a managed database's admin (NOSUPERUSER with
# CREATEROLE and CREATEDB, e.g. Linode's linpostgres). The admin must have
# BYPASSRLS itself (PostgreSQL 16+ allows only such a role to create
# app_owner, which needs it). A non-superuser admin is made a member of
# app_owner for the CREATE DATABASE and the default privileges, then the
# membership is revoked again (NFR-07, TEN-01).
#
# Environment:
#   PGHOST, PGPORT, PGUSER, PGPASSWORD   the admin connection (read by psql);
#   PGSSLMODE=require for a managed cluster
#   DB_NAME                              the database (required)
#   APP_PASSWORD, APP_OWNER_PASSWORD, APP_PLATFORM_PASSWORD   required; at
#                                        least 16 characters each
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
: "${DB_NAME:?Set DB_NAME, the database to create}"
for var in APP_PASSWORD APP_OWNER_PASSWORD APP_PLATFORM_PASSWORD; do
  value="${!var:-}"
  if [ "${#value}" -lt 16 ]; then
    echo "Set $var to a generated password of at least 16 characters." >&2
    exit 1
  fi
done
if [[ ! "$DB_NAME" =~ ^[a-z_][a-z0-9_]*$ ]]; then
  echo "Invalid database name: $DB_NAME (use lowercase letters, digits and _)" >&2
  exit 1
fi

q() { psql -v ON_ERROR_STOP=1 -X -q "$@"; }

read -r admin super bypass < <(q -d postgres -At -F ' ' -c "select current_user, rolsuper, rolbypassrls from pg_roles where rolname = current_user")
if [ "$super" != t ] && [ "$bypass" != t ]; then
  echo "$admin has neither SUPERUSER nor BYPASSRLS, so it cannot create app_owner (NOSUPERUSER BYPASSRLS)." >&2
  echo "Ask the database provider for an admin with BYPASSRLS; see docs/deploy-linode.md, Database." >&2
  exit 1
fi

echo "Creating roles app_owner, app and app_platform (as $admin)"
q -d postgres -f "$DIR/create-roles.sql"
q -d postgres -v owner_pw="$APP_OWNER_PASSWORD" -v app_pw="$APP_PASSWORD" -v platform_pw="$APP_PLATFORM_PASSWORD" <<'SQL'
ALTER ROLE app_owner PASSWORD :'owner_pw';
ALTER ROLE app PASSWORD :'app_pw';
ALTER ROLE app_platform PASSWORD :'platform_pw';
SQL

granted=no
if [ "$super" != t ] && [ "$(q -d postgres -At -c "select pg_has_role(current_user, 'app_owner', 'SET')")" != t ]; then
  q -d postgres -c "GRANT app_owner TO CURRENT_USER"
  granted=yes
fi

if [ "$(q -d postgres -At -c "select 1 from pg_database where datname = '$DB_NAME'")" = 1 ]; then
  echo "Database $DB_NAME exists: keeping it"
else
  echo "Creating database $DB_NAME owned by app_owner"
  q -d postgres -c "CREATE DATABASE $DB_NAME OWNER app_owner"
fi
q -d "$DB_NAME" -f "$DIR/database-grants.sql"

if [ "$granted" = yes ]; then
  q -d postgres -c "REVOKE app_owner FROM CURRENT_USER" || echo "Could not revoke app_owner from $admin; revoke it by hand." >&2
fi

locks="$(q -d postgres -At -c "show max_locks_per_transaction")"
prepared="$(q -d postgres -At -c "show max_prepared_transactions")"
[ "$locks" -ge 256 ] || echo "Warning: max_locks_per_transaction is $locks; full exports need 256 (docs/deploy-linode.md)." >&2
[ "$prepared" = 0 ] || echo "Warning: max_prepared_transactions is $prepared; keep it 0 (ADR 004)." >&2
q -d postgres -At -F ' ' -c "select rolname, case when rolsuper then 'SUPERUSER' else 'nosuperuser' end, case when rolbypassrls then 'BYPASSRLS' else 'nobypassrls' end from pg_roles where rolname in ('app', 'app_owner', 'app_platform') order by 1"
echo "Done. Next: the first deploy migrates as app_owner (php artisan migrate --database=pgsql_owner --force)."
