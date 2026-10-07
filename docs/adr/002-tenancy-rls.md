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
- **Reset at every request.** `ResetTenantContext` is the first global middleware. It clears three things:
  - the tenant
  - the authentication guards
  - the request's `AuditContext` (device, location, actor; AUD-02)

  Nothing leaks from a previous request on a reused worker or connection. The tenant is then set **only** from the resolved bearer token. Task 14's isolation suite found that `AuditContext` was not being reset and fixed it. Any new request-state singleton must be reset here as well.

### Global tables, with no row-level security, and why

| Table | Why it is global |
| --- | --- |
| `migrations` | Framework bookkeeping, owned by `app_owner` |
| `cache`, `cache_locks` | Framework cache and lock store. Keys are namespaced by the code. The permission cache key carries the tenant id (ADR 006). |
| `jobs`, `job_batches`, `failed_jobs` | Queue tables, written before any tenant is set. Each job re-enters its tenant through `TenantAware`. |
| `personal_access_tokens` | A bearer token is looked up **before** the tenant is known. `PersonalAccessToken::findToken` then sets the context from the token's own `tenant_id`. Code always reaches tokens through their tokenable. |
| `verification_challenges` | Sign-up, sign-in, 2FA and password-reset codes are checked before a session exists. Only `Identity\Services\Challenges` touches the table, and codes are stored as HMACs. |
| `permissions` | The permission catalogue is the same for every tenant (RBAC-01). Roles and their links are tenant tables. |

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
- The owner credentials (`DB_OWNER_*`) are needed only by migrations. In production, keep them out of the web and worker processes where possible.
