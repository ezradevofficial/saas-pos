# ADR 015: Full tenant data export

Status: Accepted (Phase 6, EXP-02; TEN-01, AUD-01, RBAC-10)

## Context

EXP-02 lets an Owner download a complete, machine-readable copy of their tenant's data at any time. A whole-tenant dump is the largest possible leak if it reads another tenant's rows, includes secrets, or reaches the wrong person.

## Decision

- **Who.** Only an active Owner (OwnerGuard, RBAC-10) holding `core.tenant.export` at tenant scope. The permission is seeded to the Owner template only; the Admin template's `core.*` leaves it out through a new `except` key in `role-templates.php`. Both checks are required, so a custom role given the permission still cannot export.
- **How it is built.** `POST tenant-exports` creates a `tenant_exports` row (queued) and dispatches `BuildTenantExport` on the `exports` queue. The job runs under `TenantAware` on the runtime connection with row-level security on, never the owner connection. Horizon's `supervisor-exports` works the queue on the `redis-exports` connection, whose `retry_after` (3900 s) is above the one-hour timeout.
- **What is in the zip.**
  - `tables/{table}.jsonl`: one file per table, discovered from the schema. That is every `public` table with a `tenant_id` under forced RLS, plus the tenant's own `tenants` row, so new tables are included automatically.
  - Tables with a `tenant_id` but no RLS (`personal_access_tokens`, `verification_challenges`, ADR 002) hold only credentials and are left out.
  - Rows are rendered by PostgreSQL (`row_to_json`), so money and rates keep their exact digits. They are read in primary-key order, `data_export.chunk` rows per query (keyset paging), and every query also filters on the tenant as a second guard. Each chunk is its own statement: no long transaction holds back device sync (ADR 004). The cost is that the export is consistent per row, not one snapshot.
  - `files/`: everything under `tenants/{tenant}/` on the media disk, except earlier exports.
  - `manifest.json`: tables with row counts, exported and omitted columns, files with size and SHA-256, the schema version (the last migration), `generated_at`, and the tenant id and name.
- **Secrets.** `ExportColumns` is a reviewed list. `DENIED` columns are never exported: password and PIN material, TOTP secrets, device secrets, token hashes, payment, fiscal and webhook credentials. `ALLOWED` lists secret-looking names that are safe, with a reason (for example audit chain hashes). `ExportColumnsTest` fails when a column whose name matches `password|secret|token|pin|otp|two_factor|api_key|credential|hash` is in neither list. At run time an unreviewed match is left out (fail closed).
- **Delivery.**
  - The zip is stored at `tenants/{tenant}/exports/{id}.zip`; its size and SHA-256 are kept on the row.
  - The list API gives the requester only a temporary signed URL (`tenant` and `user` signed in), valid 24 hours and never past the file's expiry.
  - The download route enters the signed tenant and serves the file only to that requester, while they are an active Owner and the export is ready.
  - `exports:expire` runs hourly. It finds tenants through the security-definer function `app_tenants_with_due_exports` (ADR 002), deletes files after 7 days and keeps the rows as `expired` (TEN-06). It also fails builds left queued or running for 6 hours.
- **Limits.** One export queued or running per tenant (advisory lock plus a partial unique index, 409), at most `data_export.per_day` (3) in any 24 hours (429).
- **Notify and audit.** The requester gets `core.tenant_export.ready` (in-app and email) through the Notifier; the email links to the settings page, never to the signed URL. Audited: `core.tenant_export.request`, `.ready`, `.download`, `.expire`, `.fail`.

## Consequences

- A new tenant table is exported without code changes. A new secret-like column fails CI until it is classified.
- The isolation suite builds tenant A's export after both tenants exist. It checks that every tenant table has A's rows, and that nothing of B and no stored secret of either tenant is in the zip.
- Exports cost one worker for up to an hour. The deploy needs the `supervisor-exports` Horizon supervisor.
