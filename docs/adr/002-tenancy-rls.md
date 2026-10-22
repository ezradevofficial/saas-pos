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
| `platform_*`, `catalogue_*` | Platform staff, their tokens and audit log, and the plan catalogue (ADR 013). No `tenant_id`; staff belong to no tenant and the catalogue is the same for every tenant. Listed in `GlobalTables::PLATFORM`. Grants keep them apart instead of RLS: only role `app_platform` reaches the staff tables and writes the catalogue; `app` only reads the catalogue. |
| `country_pack_releases` | Country pack releases loaded and published by platform staff (ADM-04, ADR 022): reference data, the same for every tenant. The platform role writes it; `app` reads only published releases (a row-level security policy on status). Listed in `GlobalTables::PLATFORM`. |
| `marketplace_developers`, `marketplace_apps`, `marketplace_app_versions`, `oauth_clients` | Developer-owned marketplace data published to every tenant (MKT-01, ADR 024). No `tenant_id`; forced row-level security keyed on `developer_tenant_id` instead: a tenant reads and writes its own rows and reads what is listed; the platform role reads all and changes only review columns. Nobody deletes. Listed in `GlobalTables::MARKETPLACE`; `RlsCoverageTest` checks the policies and grants. |

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
| `api_key_tenant(p_public_id text, p_sandbox boolean)` | `api_keys` by key id, when the tenant's sandbox flag matches the key's `sk_test_` or `sk_live_` prefix (ADR 020) |
| `crm_lead_form_tenant(p_token_hash text)` | `crm_lead_forms` by token hash, archived forms included (the controller refuses them; CRM-06) |
| `oauth_token_tenant(p_kind text, p_id_hash text)` | `oauth_auth_codes`, `oauth_access_tokens` or `oauth_refresh_tokens` by the sha256 of a marketplace app's code or token identifier (ADR 024) |
| `ecom_store_tenant(p_slug text, p_host text, p_handle text)` | the active tenant whose slug or verified custom domain the storefront's host names and that has a live, not archived store with that handle (ECOM-06) |
| `ecom_whatsapp_tenant(p_token_hash text)` | `ecom_stores` by WhatsApp webhook token hash, archived stores included (the controller refuses them; ECOM-24) |
| `sso_tenant_for_email_domain(p_domain text)` | `sso_domains`: the active tenant with that verified, not archived email domain (AUTH-11 email discovery and sign-up's enforced-domain check; ADR 023) |
| `sso_tenant_for_host(p_slug text, p_host text)` | the active tenant whose subdomain slug or verified custom domain the sign-in page's host names (AUTH-11 on the tenant's host; ADR 023) |

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
| `app_active_tenant_ids()` | `exchange-rates:fetch` (CUR-03), `usage:meter` (LIM-01, ADR 018) | status `active` |
| `app_tenants_with_due_stage_timers(p_at timestamptz)` | `workflow:process-stage-timers` (WF-09) | an active stage position whose reminder, overdue notice or escalation is due |
| `app_tenants_with_unsettled_credit_changes(p_before timestamptz)` | `credit-limits:reconcile` (WF-10, WF-11) | a pending credit limit change whose flow completed or was cancelled before `p_before` |
| `app_tenants_with_pending_domains()` | `DueTenants::withPendingDomains()` (BR-05) | active tenants with a custom domain waiting for its TXT record |
| `app_tenants_with_domain_checks_due(p_at timestamptz)` | `domains:verify` (BR-05) | active tenants with a pending claim, a verified domain due for its daily re-check, or a claim failed for 7 days |
| `app_tenants_with_sso_domain_checks_due(p_at timestamptz)` | `domains:verify` (AUTH-11, ADR 023) | the same, for SSO email domains (`sso_domains`) |
| `app_tenants_with_sso_metadata_urls()` | `sso:refresh-metadata` (AUTH-11, ADR 023) | active tenants with a SAML connection whose metadata was fetched by URL |
| `app_tenants_with_billing_due(p_at timestamptz)` | `billing:cycle` (SUB-03, SUB-05, ADR 016) | active customers whose trial or period ends within 7 days, or that are past due or read-only, or have an open invoice already due |
| `app_tenants_with_modules_to_purge(p_before timestamptz)` | `billing:purge-report` (SUB-07) | switched-off modules, switched off before `p_before`, not yet flagged for purge |
| `app_tenants_with_webhook_work(p_stale_before timestamptz, p_prune_before timestamptz)` | `webhooks:sweep` (HOOK-01, ADR 021) | webhook deliveries left pending or sending, or overdue for a retry, since before `p_stale_before`, or created before `p_prune_before` (30-day retention) |
| `app_operator_tenant_id()` | billing (ADR 016) | the operator tenant, the platform's seller (at most one) |
| `app_tenants_with_due_recurring_journals(p_on date)` | `accounting:recurring` (ACC-06; Accounting migration `2026_11_05_000100`) | an active recurring journal due on or before `p_on` |
| `app_tenants_with_loyalty_sales_to_reprocess(p_at timestamptz, p_pending_before timestamptz)` | `loyalty:reprocess` (LOY-03; Loyalty migration `2026_11_06_000200`) | a Loyalty sale `failed` whose next attempt is due at `p_at` (backoff 5 min, 15 min, 1 h, 6 h, 24 h, then `failed_permanently`), or left `pending` since before `p_pending_before` |
| `app_tenants_with_due_crm_reminders(p_at timestamptz)` | `crm:remind` (CRM-27; CRM migration `2026_11_25_000100`) | an open CRM activity whose reminder is due at `p_at` and not yet sent |
| `app_tenants_with_expired_ecom_reservations(p_at timestamptz)` | `ecommerce:expire` (ECOM-14; E-commerce migration `2026_11_28_000100`) | an unpaid online order (`placed`, payment `pending` or `failed`) whose stock reservation ended at or before `p_at` |

A module's scheduler function lives in that module's migrations, and its command calls it through `DueTenants` like the core ones. Modules register their schedules in their own service providers (`callAfterResolving(Schedule::class)`), never in `routes/console.php`.

They follow the same rules as the lookups above: `security definer`, `search_path` pinned, schema-qualified names, revoked from `PUBLIC`, granted to the runtime role, and they return `setof uuid`, never a row. `DueTenantsTest` checks each of these properties. Each command then dispatches one job per tenant, and the job does the work in that tenant's context under row-level security.

### Public branding before sign-in (Phase 5, BR-04, BR-05)

Two more functions answer for hosts, before any tenant is known:

- `app_tenant_for_verified_domain(p_host text)` returns the tenant id of a verified, not archived custom domain of an active tenant. The TLS ask endpoint only checks that one exists.
- `app_public_branding(p_slug text, p_host text)` returns a table of public fields, not ids: the tenant's name, its published tenant-wide theme's look (`scope_id is null`, live, not archived version), the welcome text, the storage paths of its logos, favicon and background, and the "hide platform" flag. It never returns the tenant id, document or asset ids.

**The storefront (ECOM-06) uses the same hosts.** `ecom_store_tenant` returns only a tenant id, and only for a live store: the host is not a credential (any client can send any host), so it only chooses whose public catalogue is read, under that tenant's row-level security, and the tenant is left at the end of the request (`ResolveShop`).

**Tenant names behind a slug are public by design.** Anyone who knows or guesses `{slug}.{APP_BASE_DOMAIN}` (or a verified domain) sees that business's name and look on its sign-in page, as on any branded login page. Nothing else about the tenant is reachable this way. Subdomains are chosen by the business; it should not pick one it wants kept secret.

**Only migrations and deploy commands need the owner's credentials.** Those are `migrate --database=pgsql_owner`, `permissions:sync`, `currencies:sync`, `country-packs:publish`, `country-packs:holidays`, `uoms:seed-defaults`, `payment-methods:seed-defaults` and `app:preflight`, all run by the deploy on the API host. Web requests, queue workers (Horizon) and the scheduler never open the owner connection. Their `.env` can, and in production should, leave `DB_OWNER_*` out. The command tests prove it by making the owner connection unusable before running each command.

### Every security-definer function (stage 7B closing review)

Every `security definer` function in `public`, with what it returns. `SecurityDefinerFunctionsTest` lists them from `pg_proc` and fails when one is missing here, lacks a pinned `search_path`, is executable by `PUBLIC`, or returns anything but `uuid` or `setof uuid`. The documented exceptions are `app_public_branding` (public fields of a sign-in page, above) and `app_platform_usage_totals` (ADM-06: totals per unit across tenants for the platform's reports, with no tenant id; the test pins its result type), and `app_prune_api_idempotency_keys` (ADR 020: deletes expired stored API responses of every tenant and returns only how many, below).

The marketplace's secret functions are exceptions too (ADR 024, security review). An app's client-secret digest and webhook key live in `marketplace_app_secrets`, which neither the runtime nor the platform role can read; only these functions reach it, and none returns a stored secret:

- `oauth_client_secret_ok` returns a `boolean`: whether the sha256 of a presented client secret matches. The digest never leaves the database.
- `app_install_webhook_key` returns `text`: one install's own signing key, `HMAC-SHA256(app webhook key, 'install:' || install id)` as `whsec_` + base64, and only for an active install of the session's tenant (null for another tenant's install). It never returns the app's key, so one tenant holding its install's key learns nothing that signs for another install. The HMAC is computed by `marketplace_hmac_sha256(key, message)`, a plain (not security-definer) function on the built-in `sha256()`, since no crypto extension is installed; execute on it is revoked from `PUBLIC`.
- `marketplace_store_app_secrets` returns an `integer` (how many rows changed): it sets an app's secret digest and/or webhook key, only from the app's developer tenant, or from the platform role for a first-party app.
- `marketplace_developer_active` returns a `boolean`: whether a developer may serve (not suspended, its tenant not closed). It names nothing else.

The test pins each one's result type. `app_tenants_with_app_installed` returns tenant ids, but only to the platform role (`session_user`) or to a session of the app's own developer tenant; any other tenant gets nothing, so a customer cannot list who else installed an app.

| Function | Returns | Used for |
| --- | --- | --- |
| `auth_tenant_for_login(p_login text)` | `uuid` | sign-in by email or phone |
| `auth_tenant_for_invitation(p_token_hash text)` | `uuid` | an invitation link |
| `auth_tenant_for_pairing(p_code_hash text)` | `uuid` | device pairing |
| `api_key_tenant(p_public_id text, p_sandbox boolean)` | `uuid` | an API key's tenant, only when the tenant's sandbox flag matches the key's prefix (API-02, API-05, ADR 020) |
| `approval_tenant_for_email_token(p_token_hash text)` | `uuid` | approving from an email link (APR-03) |
| `document_share_tenant(p_token_hash text)` | `uuid` | a shared document link (TPL-04) |
| `crm_lead_form_tenant(p_token_hash text)` | `uuid` | a public CRM lead form (CRM-06) |
| `ecom_store_tenant(p_slug text, p_host text, p_handle text)` | `uuid` | the public storefront, by host and store handle (ECOM-06) |
| `ecom_whatsapp_tenant(p_token_hash text)` | `uuid` | a store's inbound WhatsApp webhook (ECOM-24) |
| `payment_tenant_for_callback_token(p_token_hash text)` | `uuid` | a payment provider's callback (M-Pesa) |
| `procurement_portal_tenant(p_kind text, p_token_hash text)` | `uuid` | the supplier portal's link, session or notice token (PRC-07, PRC-08) |
| `sso_tenant_for_email_domain(p_domain text)` | `setof uuid` | single sign-on by email domain (AUTH-11) |
| `sso_tenant_for_host(p_slug text, p_host text)` | `setof uuid` | single sign-on on the tenant's host (AUTH-11) |
| `app_assisted_tenant_for_contact(p_contact text)` | `setof uuid` | assisted onboarding by contact (ONB-) |
| `app_tenant_for_verified_domain(p_host text)` | `setof uuid` | a verified custom domain (BR-05) |
| `app_public_branding(p_slug text, p_host text)` | a table of public fields | the sign-in page's look (BR-04); documented exception |
| `app_prune_api_idempotency_keys(p_before timestamptz)` | `integer` | `api:prune-idempotency` (API-01, ADR 020): deletes `api_idempotency_keys` rows that expired before `p_before`, in every tenant, and returns how many; nothing else is read or changed; documented exception |
| `app_tenants_with_api_keys()` | `setof uuid` | `api:flush-usage` (API-03): active tenants with a key not revoked |
| `app_sandbox_tenant_ids()` | `setof uuid` | `sandbox:expire` (API-05): active developer sandboxes |
| `app_platform_usage_totals(p_day date)` | a table of `(unit, tenants, total)` | ADM-06: usage totals for the platform's reports, per unit on the latest metered day on or before `p_day`; counts only, never a tenant id or row; called only by the staff reports; documented exception |
| `app_active_tenant_ids()` | `setof uuid` | exchange rates, usage metering |
| `app_operator_tenant_id()` | `setof uuid` | the operator tenant (ADR 016) |
| `app_tenants_awaiting_handover(p_before timestamptz)` | `setof uuid` | assisted onboarding handovers |
| `app_tenants_pending_verification(p_before timestamptz)` | `setof uuid` | sign-ups waiting for verification |
| `app_tenants_with_billing_due(p_at timestamptz)` | `setof uuid` | `billing:cycle` |
| `app_tenants_with_domain_checks_due(p_at timestamptz)` | `setof uuid` | `domains:verify` |
| `app_tenants_with_sso_domain_checks_due(p_at timestamptz)` | `setof uuid` | `domains:verify`, SSO email domains (AUTH-11) |
| `app_tenants_with_sso_metadata_urls()` | `setof uuid` | `sso:refresh-metadata` (AUTH-11) |
| `app_tenants_with_due_approval_timers(p_at timestamptz)` | `setof uuid` | `approvals:process-timers` |
| `app_tenants_with_due_crm_reminders(p_at timestamptz)` | `setof uuid` | `crm:remind` |
| `app_tenants_with_expired_ecom_reservations(p_at timestamptz)` | `setof uuid` | `ecommerce:expire` |
| `app_tenants_with_due_automation(p_kind text, p_at timestamptz)` | `setof uuid` | `automation:scan` |
| `app_tenants_with_due_exports(p_at timestamptz, p_running_before timestamptz, p_queued_before timestamptz)` | `setof uuid` | `exports:expire` (ADR 015) |
| `app_tenants_with_due_fiscal_submissions(p_at timestamptz, p_stale_before timestamptz)` | `setof uuid` | fiscal submissions (KRA eTIMS, DGI) |
| `app_tenants_with_due_payment_intents(p_at timestamptz)` | `setof uuid` | payment status checks |
| `app_tenants_with_due_recurring_journals(p_on date)` | `setof uuid` | `accounting:recurring` |
| `app_tenants_with_due_stage_timers(p_at timestamptz)` | `setof uuid` | `workflow:process-stage-timers` |
| `app_tenants_with_expired_trials(p_at timestamptz)` | `setof uuid` | trial expiry |
| `app_tenants_with_hr_identifiers()` | `setof uuid` | `hr:rekey-digests`, every tenant with an employee identifier, suspended and closed ones included (docs/modules/hr.md decision 24) |
| `app_tenants_with_loyalty_sales_to_reprocess(p_at timestamptz, p_pending_before timestamptz)` | `setof uuid` | `loyalty:reprocess` |
| `app_tenants_with_modules_to_purge(p_before timestamptz)` | `setof uuid` | `billing:purge-report` |
| `app_tenants_with_pending_digests()` | `setof uuid` | `notifications:send-digests` |
| `app_tenants_with_pending_domains()` | `setof uuid` | domains waiting for their TXT record |
| `app_tenants_with_stuck_automation(p_stale_before timestamptz)` | `setof uuid` | `automation:scan reap` |
| `app_tenants_with_tenders()` | `setof uuid` | `procurement:rewrap-keys`, every tenant with a tender, suspended and closed ones included (ADR 019) |
| `app_tenants_for_key_rotation()` | `setof uuid` | `app:reencrypt` (NFR-06, APP_KEY rotation, docs/security.md): every tenant, whatever its status, since closed and suspended tenants still hold encrypted values |
| `app_tenants_with_unsettled_credit_changes(p_before timestamptz)` | `setof uuid` | `credit-limits:reconcile` |
| `app_tenants_owned_by_email(p_email text)` | `setof uuid` | ADM-01 (review L3): the console's owner-email search, every tenant where the email is an active Owner; audited, support and compliance only |
| `app_tenants_for_pack_updates()` | `setof uuid` | ADM-04 (ADR 022): every tenant that is not closed, suspended ones included, to receive a published tax release (one queued job per tenant) |
| `app_tenants_with_expired_support_grants(p_at timestamptz)` | `setof uuid` | `support:expire`: support access approved and past its end (RBAC-13, docs/modules/platform-admin.md) |
| `app_tenants_with_webhook_work(p_stale_before timestamptz, p_prune_before timestamptz)` | `setof uuid` | `webhooks:sweep` (ADR 021) |
| `oauth_token_tenant(p_kind text, p_id_hash text)` | `uuid` | the tenant of a marketplace app's authorization code, access or refresh token, by sha256 of its identifier (`auth_code`, `access_token`, `refresh_token`), for the token endpoint and the resource guard (API-02, MKT-02, ADR 024) |
| `app_tenants_with_app_installed(p_app_id uuid)` | `setof uuid` | tenants (not closed) with an active install of an app: staff suspending or reinstating it audit each, and a rolled webhook secret reaches each (ADM-05, MKT-02, ADR 024); answers only the platform role and the app's developer tenant |
| `oauth_client_secret_ok(p_client_id uuid, p_secret_sha256 text)` | `boolean` | the token endpoint's client-secret check, without the digest leaving the database (API-02, ADR 024); documented exception |
| `app_install_webhook_key(p_installation_id uuid)` | `text` | an install's derived signing key, for an active install of the session's tenant only (MKT-02, ADR 024); documented exception |
| `marketplace_store_app_secrets(p_app_id uuid, p_client_secret_sha256 text, p_webhook_key bytea)` | `integer` | setting an app's secrets, by its developer tenant or the platform role for first-party apps; returns how many rows changed (MKT-01, ADR 024); documented exception |
| `marketplace_developer_active(p_developer_id uuid)` | `boolean` | whether a developer's apps may serve (MKT-01, ADM-05, ADR 024); documented exception |

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
