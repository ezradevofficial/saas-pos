-- ADR 002, ADR 013: the schema and default privileges of one database,
-- run inside that database by setup-local-db.sh (local, CI) and
-- provision-db.sh (dev, staging, production). Idempotent.
-- The caller must be a superuser or a member of app_owner.
ALTER SCHEMA public OWNER TO app_owner;
GRANT USAGE ON SCHEMA public TO app;
-- ADR 013: no default privileges; the platform migrations grant its tables.
GRANT USAGE ON SCHEMA public TO app_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO app;
