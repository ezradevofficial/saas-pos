-- Run as a superuser. app_owner owns the schema and runs migrations;
-- app is the runtime role and is always subject to row-level security (TEN-01);
-- app_platform is the platform staff role: it reaches only the platform and
-- catalogue tables, which the migrations grant to it (ADR 013).
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_owner') THEN
    CREATE ROLE app_owner LOGIN PASSWORD 'app_owner' NOSUPERUSER BYPASSRLS;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app') THEN
    CREATE ROLE app LOGIN PASSWORD 'app' NOSUPERUSER NOBYPASSRLS;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_platform') THEN
    CREATE ROLE app_platform LOGIN PASSWORD 'app_platform' NOSUPERUSER NOBYPASSRLS;
  END IF;
END $$;
