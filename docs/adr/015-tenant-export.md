# ADR 015: Full tenant data export

Status: Accepted (Phase 6, EXP-02; TEN-01, AUD-01, RBAC-10). Revised after the security review.

## Context

EXP-02 lets an Owner download a complete, machine-readable copy of their tenant's data at any time. A whole-tenant dump is the largest possible leak if it reads another tenant's rows, includes secrets, or reaches the wrong person.

## Decision

### Who can export

- Only an active Owner (OwnerGuard, RBAC-10) holding `core.tenant.export` at tenant scope.
- The permission is seeded to the Owner template only. The Admin template's `core.*` leaves it out through a new `except` key in `role-templates.php`.
- Both checks are required, so a custom role given the permission still cannot export.
- A request confirms the Owner's password (`PasswordCheck`, as for POS PINs). Wrong passwords count towards the account lockout, and a stolen session alone cannot take the whole tenant.

### How it is built

- `POST tenant-exports` creates a `tenant_exports` row (queued) and dispatches `BuildTenantExport` on the `exports` queue.
- The job runs under `TenantAware` on the runtime connection with row-level security on, never the owner connection.

### What is in the zip

- **`tables/{table}.jsonl`:** one file per table, discovered from the schema: every `public` table with a `tenant_id` under forced RLS, plus the tenant's own `tenants` row. New tables are included automatically.
  - Tables with a `tenant_id` but no RLS (`personal_access_tokens`, `verification_challenges`, ADR 002) hold only credentials and are left out.
  - PostgreSQL renders each row (`row_to_json`), so money and rates keep their exact digits.
  - **Each table is read through one server-side cursor** (`DECLARE … FETCH chunk`) in its own `REPEATABLE READ READ ONLY` transaction. That gives one consistent snapshot per table and one scan, never keyset pages (which needed a `(tenant_id, pk)` index on every table). A read-only transaction takes no transaction id, so it does not hold back device sync (ADR 004). The query also filters on the tenant as a second guard.
  - Locks are released table by table. In tests, the build runs inside the test's transaction and keeps its locks (about 650) until the test ends, so the fixture does not build exports.
- **`files/`:** everything under `tenants/{tenant}/` on the media disk, except exports.
  - On a local disk the zip reads each file in place.
  - On an object store, files go through temporary copies that are flushed into the zip every 100 files or 256 MB.
- **`manifest.json`:** tables with row counts, exported, omitted and transformed columns, files with size and SHA-256, the schema version, `generated_at`, and the tenant id and name.
- **Size cap:** `data_export.max_bytes` (5 GB before compression). A larger export fails as `too_large` and the page says so.

### Secrets

`ExportColumns` is a reviewed list:

- **`DENIED`:** never exported. Password and PIN material, TOTP secrets, device secrets, token hashes, and payment, fiscal and webhook credentials. Also:
  - webhook delivery URLs;
  - payment intents' phone numbers. These are denied rather than decrypted: the app only ever shows them masked, and decrypting would bring the application key into the export path.
- **`ALLOWED`:** secret-looking names that are safe, each with a reason.
- **`TRANSFORMS`:** exported through SQL. Automation rule actions are exported as the API shows them: webhook `url` replaced by `url_display` (no query or fragment) and `has_url`.
- **Tests:**
  - `ExportColumnsTest` fails on any column of an exported table whose name matches `password|secret|token|pin|otp|two_factor|api_key|credential|hash`, or any model attribute cast `encrypted*`, that is not classified.
  - At run time an unreviewed name match is left out (fail closed).

### Delivery

- **Storage:** the build records the final path on the row before writing anything. It uploads the zip to `{path}.part` and moves it into place once complete, so a failed or stale build always knows what to delete.
- **Links:** the list API never carries a URL; it says `can_download`.
  - `POST tenant-exports/{id}/link` (authenticated, requester only) mints a signed URL bound to the user, valid 5 minutes.
  - The download route enters the signed tenant and serves the file only to that requester, while they are an active Owner and the export is ready.
  - The route is limited to 5 downloads per export per hour (`tenant-export-download`).
- **Retention:**
  - `exports:expire` runs hourly. It finds tenants through `app_tenants_with_due_exports` (a security-definer function, ADR 002).
  - It deletes files after 7 days and keeps the rows as `expired` (TEN-06). It checks the delete: on failure the row stays (no longer downloadable) and is retried on the next run, as is a failed build whose file remains.
  - Builds go stale **from `started_at`** when running past the timeout plus an hour (a dead worker), and from `created_at` when queued for `data_export.queued_stale_hours` (24, no worker).
- **Work directories** (`storage/app/private/tenant-exports/*`, plaintext) are removed after each build, and swept when older than the timeout plus 10 minutes: at the start of every build job (worker hosts) and by `exports:expire` (scheduler host).
- **Limits:** one export queued or running per tenant (advisory lock plus a partial unique index, 409), at most `data_export.per_day` (3) in any 24 hours (429).
- **Notify and audit:**
  - The requester gets `core.tenant_export.ready` (in-app and email); the email links to the settings page.
  - Audited: `core.tenant_export.request`, `.ready`, `.download`, `.expire` and `.fail`.

### Workers

- Horizon's `supervisor-exports` works the `exports` queue on the `redis-exports` connection, whose `retry_after` (3900 s) is above the one-hour timeout.
- It runs one process locally and up to two elsewhere. Each build holds one process for up to an hour and needs disk for the work directory and the zip, so scale it by worker disk and memory (256 MB), not by queue length.
- Exports waiting behind a busy supervisor are failed after 24 hours, never left queued forever.

## Consequences

- A new tenant table is exported without code changes. A new secret-like or encrypted column fails CI until it is classified.
- The isolation suite builds tenant A's export after both tenants exist. It checks that every tenant table has A's rows, and that nothing of B, no stored secret of either tenant and no webhook token is in the zip.
- The deploy needs the `supervisor-exports` Horizon supervisor.
