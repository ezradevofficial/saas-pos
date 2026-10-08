# ADR 002: Tenant isolation with PostgreSQL row-level security

Status: Accepted (Sprint 1, TEN-01 to TEN-06)

## Context

Many tenants share one database. A missing `where tenant_id = ?` anywhere would leak data: in a controller, a report, a queued job, an export or search. The sprint exit criterion is that a user of tenant A provably cannot see anything of tenant B through the UI, the API, exports or search. Filtering in application code alone cannot prove that. The database has to refuse cross-tenant rows by itself.

## Decision

### Two database roles

`api/database/scripts/create-roles.sql` creates two roles. `setup-local-db.sh` runs it locally and in CI.

| Role | Attributes | Used for | Laravel connection |
| --- | --- | --- | --- |
| `app_owner` | `NOSUPERUSER BYPASSRLS` | Owns the `public` schema and every table. Runs migrations (`composer migrate`, `migrate:fresh`, the deploy's `migrate --database=pgsql_owner`). | `pgsql_owner` |
| `app` | `NOSUPERUSER NOBYPASSRLS` | Every request, job and command at runtime. | `pgsql` |

- Default privileges give `app` `SELECT, INSERT, UPDATE, DELETE` on tables, sequence usage and `EXECUTE` on functions created by `app_owner`. Nothing more.
- Some tables take privileges back from `app`:
  - `permissions` and `migrations`: `SELECT` only (migration `2026_10_08_000700_restrict_catalogue_writes`). Deleting a permission cascades to `role_has_permissions` in every tenant, so only the owner writes the catalogue (`permissions:sync` runs on `pgsql_owner`, ADR 006).
  - `currencies`: `SELECT` only (revoked in migration `2026_10_09_000100_create_currency_tables`). The ISO 4217 catalogue is shared by every tenant; `currencies:sync` writes it as the owner. `CurrencyCatalogueTest` proves `app` is refused.
  - `audit_logs`: no `UPDATE`, `DELETE` or `TRUNCATE`; a trigger refuses edits by any role, the owner included (AUD-03).
  - `audit_chain_heads`: no `DELETE` or `TRUNCATE`. The trigger `audit_chain_heads_forward_only` (migration `2026_10_08_000710`) refuses, for every role, an update that lowers `seq` or changes `tenant_id`, and any delete or truncate. A lowered head would hide the entries above it. `Auditor::verify()` also reports entries above the head (re-read in the same statement as the check, so concurrent appends never raise a false alarm).
- `HealthTest` asserts at runtime that the current role is neither a superuser nor `BYPASSRLS`. The isolation suite asserts the same.
- `database.default` must stay `pgsql`. `TenantContext::CONNECTION` is hard-coded to `pgsql`, and only that connection receives the tenant setting.
- Never point the runtime connection at the owner or at a superuser. Either one bypasses every policy below.

### Every tenant table is forced under a policy

- `Blueprint::tenantId()` adds `tenant_id uuid not null`. Its default is `NULLIF(current_setting('app.tenant_id', true), '')::uuid`, and it has an index and a restricting FK to `tenants`.
- `Rls::enable($table)` runs three statements:
  - `ENABLE ROW LEVEL SECURITY`
  - `FORCE ROW LEVEL SECURITY`, so the table owner is bound too, except through `BYPASSRLS`
  - creates the policy `tenant_isolation`, with `USING` and `WITH CHECK` both comparing `tenant_id` to the session tenant
- `tenants` uses `Rls::enableOnKey('tenants')`: a tenant sees only its own row.
- Without a tenant context, the setting is empty, so `NULLIF` yields `NULL`. Every tenant table then reads as empty, and every insert fails its check.
- The `BelongsToTenant` trait also fills `tenant_id` from the context, and throws `TenantContextMissing` when there is none. A forgotten context fails loudly in PHP before the database refuses it.
- `RlsCoverageTest` enumerates every `public` table with a `tenant_id` column and requires `relrowsecurity`, `relforcerowsecurity` and a policy. The only exceptions are the global tables listed below.

### The tenant context: a session-level setting kept in sync

`App\Core\Tenancy\TenantContext` holds the current tenant in PHP. It mirrors the value into the session with `set_config('app.tenant_id', $id, false)`. PHP is the source of truth.

- **Session-level, not transaction-local.** Requests are not wrapped in a transaction, so the setting must outlive individual statements.
  - Consequence: the API must connect **directly or through session pooling**. pgbouncer in transaction mode would hand the session, and its tenant, to another client. Do not use transaction pooling.
- **Re-synced after rollback and reconnect** (`CoreServiceProvider`):
  - `set_config` is transactional, so a rollback can restore an older value. A `TransactionRolledBack` listener re-applies the PHP value, including on rollback to a savepoint.
  - A `ConnectionEstablished` listener applies it on every reconnect.
  - If re-applying fails outside a transaction, the connection is dropped, so the next query reconnects and re-applies.
- **`TenantContext::run($tenantId, $fn)`** switches the tenant and restores the previous one, even when `$fn` throws.
  - Queued jobs use it through the `TenantAware` job middleware. Each job carries `$tenantId`.
  - Listeners registered with `onChange()` follow every switch. The per-tenant permission cache is one of them (ADR 006).
- **Reset between queued jobs.** A queue worker is one long process. `CoreServiceProvider` registers `Queue::before`, `Queue::after`, `Queue::exceptionOccurred` and `Queue::looping` hooks that clear the tenant (`TenantContext::set(null)`) and reset `AuditContext`. A job that set a tenant directly therefore cannot leak it into the next job; `TenantAware` jobs enter their own tenant. Jobs on the `sync` connection run inline in the caller and keep its context (`TenantAware` restores it). `QueueContextResetTest` runs two jobs through a real worker.
- **Reset at every request.** `ResetTenantContext` is the first global middleware. It clears three things:
  - the tenant
  - the authentication guards
  - the request's `AuditContext` (device, location, actor; AUD-02)

  Nothing leaks from a previous request on a reused worker or connection. The tenant is then set **only** from the resolved bearer token. Task 14's isolation suite found that `AuditContext` was not being reset and fixed it. Any new request-state singleton must be reset here as well.

### Global tables, with no row-level security, and why

| Table | Why it is global |
| --- | --- |
| `migrations` | Framework bookkeeping, owned by `app_owner`. `app` may only read it. |
| `cache`, `cache_locks` | Framework cache and lock store. Keys are namespaced by the code. The permission cache key carries the tenant id (ADR 006). |
| `jobs`, `job_batches`, `failed_jobs` | Queue tables, written before any tenant is set. Each job re-enters its tenant through `TenantAware`. |
| `personal_access_tokens` | A bearer token is looked up **before** the tenant is known. `PersonalAccessToken::findToken` then sets the context from the token's own `tenant_id`. Code always reaches tokens through their tokenable. |
| `verification_challenges` | Sign-up, sign-in, 2FA and password-reset codes are checked before a session exists. Only `Identity\Services\Challenges` touches the table, and codes are stored as HMACs. |
| `permissions` | The permission catalogue is the same for every tenant (RBAC-01). Roles and their links are tenant tables. `app` may only read it; `permissions:sync` writes it as the owner. |
| `currencies` | The ISO 4217 catalogue (CUR-01), the same for every tenant and with no `tenant_id`. `app` may only read it; `currencies:sync` writes it as the owner. The currencies a tenant uses (`tenant_currencies`) and a company's reporting currencies (`company_reporting_currencies`) are tenant tables. |

- `personal_access_tokens` and `verification_challenges` carry a `tenant_id` column. They are listed in `api/tests/Support/GlobalTables.php`.
- `RlsCoverageTest` fails if any other table with a `tenant_id` lacks forced RLS.
- The isolation suite checks that these two tables are reached only through the signed-in user's own rows.

### Lookups before the tenant is known: three security-definer functions

Some lookups have to find a tenant before it is known: signing in by email or phone, opening an invitation link, pairing a device with a code. Each one calls a function owned by `app_owner`. The function runs with the owner's `BYPASSRLS` and returns **only the tenant id**, never a row.

| Function | Looks up |
| --- | --- |
| `auth_tenant_for_login(p_login text)` | `users` by email (citext) or phone |
| `auth_tenant_for_invitation(p_token_hash text)` | `invitations` by token hash |
| `auth_tenant_for_pairing(p_code_hash text)` | `devices` by pairing-code hash, unexpired codes only |

Each function is `language sql stable security definer`:

- `set search_path = pg_catalog, public` is pinned, so a caller cannot redirect name resolution.
- Every name is schema-qualified.
- `revoke all ... from public` is followed by `grant execute` to the runtime role only.
- The caller then sets the context to that tenant and does the real work under RLS.

### Scheduled fan-out: tenant ids through security-definer functions

Amended in Phase 3. The scheduled commands must find the tenants that have work due before any tenant is set. They used to read those ids through the owner connection, which meant the scheduler host held the owner's `BYPASSRLS` credentials. They now call functions owned by `app_owner` (migrations `2026_10_18_000100_create_scheduler_tenant_functions` and `2026_10_18_000400_create_workflow_scheduler_tenant_functions`), on the runtime connection, through `App\Core\Tenancy\DueTenants`:

| Function | Used by | Returns the tenants with |
| --- | --- | --- |
| `app_tenants_with_due_approval_timers(p_at timestamptz)` | `approvals:process-timers` (APR-05) | a pending approval whose reminder or escalation is due |
| `app_tenants_with_due_automation(p_kind text, p_at timestamptz)` | `automation:scan schedules` / `dates` (AUTO-01) | live `schedule` rules due at `p_at`, or live `date` rules |
| `app_tenants_with_stuck_automation(p_stale_before timestamptz)` | `automation:scan reap` (AUTO-05) | runs or webhook deliveries untouched since `p_stale_before` |
| `app_tenants_with_pending_digests()` | `notifications:send-digests` (NOT-05) | emails held for a digest |
| `app_active_tenant_ids()` | `exchange-rates:fetch` (CUR-03) | status `active` |
| `app_tenants_with_due_stage_timers(p_at timestamptz)` | `workflow:process-stage-timers` (WF-09) | an active stage position whose reminder, overdue notice or escalation is due |
| `app_tenants_with_unsettled_credit_changes(p_before timestamptz)` | `credit-limits:reconcile` (WF-10, WF-11) | a pending credit limit change whose flow completed or was cancelled before `p_before` |

They follow the same rules as the lookups above: `security definer`, `search_path` pinned, schema-qualified names, revoked from `PUBLIC`, granted to the runtime role, and they return `setof uuid`, never a row. `DueTenantsTest` checks each of these properties. Each command then dispatches one job per tenant, and the job does the work in that tenant's context under row-level security.

**Only migrations and deploy commands need the owner's credentials.** Those are `migrate --database=pgsql_owner`, `permissions:sync`, `currencies:sync`, `country-packs:publish`, `country-packs:holidays`, `uoms:seed-defaults`, `payment-methods:seed-defaults` and `app:preflight`, all run by the deploy on the API host. Web requests, queue workers (Horizon) and the scheduler never open the owner connection. Their `.env` can, and in production should, leave `DB_OWNER_*` out. The command tests prove it by making the owner connection unusable before running each command.

### Nobody but the owner creates objects in `public`

Migration `2026_10_08_000050_harden_public_schema` revokes `CREATE` on schema `public` from `PUBLIC` and from the runtime role. `app` therefore cannot plant a table or function that a security-definer function or a `search_path` lookup might pick up. `HealthTest` asserts it.

### Proof: the isolation suite

`api/tests/Feature/Isolation/TenantIsolationTest.php` runs in the CI job `api (isolation)` with `php artisan test --testsuite=Isolation`. It seeds two tenants and discovers tables and routes at run time, so a new table or route is covered, or fails loudly, without editing the suite. It asserts that:

- it runs as `app`
- in tenant A's context, no table shows a row of tenant B
- without a context, every tenant table is empty
- every route is authenticated or allow-listed as public, with a reason
- every route with ids refuses tenant B's ids and changes nothing of B, including ids sent in request bodies
- lists show nothing of B to an Owner, a branch manager or a device
- the access-review CSV of A contains nothing of B
- global tables are reached only through the user's own rows

## Consequences

- Isolation holds even when application code forgets a filter. Reads see nothing, and writes fail the policy check.
- Every new tenant table needs `tenantId()` and `Rls::enable()` in its migration. `RlsCoverageTest` blocks the PR otherwise.
- Jobs, commands and reports must enter a tenant explicitly (`TenantContext::run`, `TenantAware`). There is no "all tenants" mode at runtime. Cross-tenant maintenance, such as the system-role refresh in ADR 006, must loop over tenants and enter each one.
- No transaction-mode pooling. Connection counts must be sized for direct or session-pooled connections.
- `ResetTenantContext` adds one database round trip per request, including `/up`. This is accepted.
- `database.default` and the `pgsql` connection name are load-bearing. Renaming them breaks tenant sync.
- The owner credentials (`DB_OWNER_*`) are needed only by migrations and the deploy commands listed above. Workers and the scheduler never use them, so a queue host's `.env` leaves them out. A new scheduled command that needs tenant ids adds a security-definer function, never an owner query.
