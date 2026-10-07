# Sprint 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A running skeleton where a tenant can sign up, sign in, create companies, branches, locations and users, and see only what their role allows, with tenant isolation proven by tests, a token-driven web shell and a POS app whose themes switch at runtime.

**Architecture:** Laravel modular monolith under `api/` with the always-on core in `api/app/Core/<Area>/`. PostgreSQL row-level security keyed on the `app.tenant_id` session setting isolates tenants; the API connects as a non-superuser role so RLS always applies. Sanctum bearer tokens authenticate the web app and the POS; each token carries its `tenant_id` and setting the tenant context happens when the token is resolved. Roles and the permission catalogue use spatie/laravel-permission; scope (tenant, company, branch, location) is our own `role_assignments` table and `ScopeResolver`. The web app (React + Tailwind 4 + shadcn/ui, JavaScript) and the POS (Expo + NativeWind 4 on Tailwind 3) both consume `packages/tokens`, generated from `design/tokens.json`.

**Tech stack:** PHP 8.3, Laravel 13, PostgreSQL 16+ (17 locally), Redis via predis, Sanctum 4, spatie/laravel-permission, pragmarx/google2fa; React 19, Vite 8, Tailwind 4, shadcn/ui (`tsx: false`), React Router, TanStack Query, i18next; Expo 57, NativeWind 4.2 + Tailwind 3.4; Vitest, Jest, PHPUnit 12.

**Spec:** `docs/platform-core-spec.md` (IDs below), `CLAUDE.md`, `design/system/README.md`, `design/system/components/*/README.md`, `design/system/components/bundle.css` (reference look), `design/screens/BoDashboard.dc.html` (back-office shell).

## Global Constraints

- No product name in code, UI text, packages, folders, DB names, emails, tests or docs. Display name from `APP_NAME` (API), `VITE_APP_NAME` (web), `EXPO_PUBLIC_APP_NAME` (POS) and the `app.name` translation key.
- Ports: web 3008 (`strictPort`), API 8008. POS web preview (for Playwright only): 3009.
- Every tenant table: `tenant_id uuid not null` defaulting to `NULLIF(current_setting('app.tenant_id', true), '')::uuid`, indexed, FK to `tenants`, `ENABLE` + `FORCE ROW LEVEL SECURITY`, policy `tenant_isolation`. Never bypass RLS in requests, jobs, commands or reports.
- Primary keys UUID v7 (`HasUuids` on Laravel 13 generates v7). Timestamps UTC (`timestampTz`).
- Never hard-delete business records: `archived_at` + archive/restore endpoints (TEN-06).
- Permission names `module.resource.action`. Core module name is `core`.
- Every endpoint: Form Request validation, policy check through `ScopeResolver`, IDs checked to belong to the tenant and the user's scope.
- Every user-facing string in `en` and `fr` translation files; missing keys fail tests.
- Tokens only for styling; no Tailwind default palette, no arbitrary values, no inline style except runtime tenant theme variables.
- Design system README wins over screen mockups where they differ (e.g. weights 400/500, 600 only for page and dialog titles; nav items 32px tall, 13px, 16px icons; page title `h1` 24px).
- Tests are written with each task. Run only the tests the task touched. Report what ran.
- UI tasks end with a Playwright MCP walkthrough on port 3008 (screenshot to `.playwright-mcp/`, console has no errors).
- Branch per task group, merge to `main`, push, pull (see `CLAUDE.md`, Git workflow).

## Review Focus

1. **Superuser connection silently disables RLS.** The API must connect as role `app` (no superuser, no BYPASSRLS). Test: `HealthTest` asserts `select rolsuper, rolbypassrls from pg_roles where rolname = current_user` is `false,false` (Task 1).
2. **A new tenant table without RLS.** Test enumerates every `public` table with a `tenant_id` column and asserts `relrowsecurity` and `relforcerowsecurity` are true and a policy exists (Task 3); the isolation suite also seeds two tenants and counts cross-tenant rows per table (Task 13).
3. **Tenant context leaking between requests or queued jobs on a reused connection.** Test: two sequential requests with tokens of tenants A then B; second sees only B. Context is reset at request start and set only from the resolved token (Task 3, Task 6).
4. **Permission cache served across tenants.** Test: tenant A grants a permission to its role named `Cashier`; tenant B's `Cashier` user does not get it, with cache warm (Task 9).
5. **Last Owner removed or demoted, including by deactivation.** Tests on assignment delete, role change and user deactivate (Task 10).

---

## Task 1: Database roles, connections, Redis and API conventions

**Files:**
- Create: `api/database/scripts/create-roles.sql`, `api/database/scripts/setup-local-db.sh`
- Modify: `api/config/database.php` (add `pgsql_owner`), `api/.env`, `api/.env.example`, `api/phpunit.xml`, `api/composer.json` (scripts), `api/tests/TestCase.php`, `api/tests/Feature/HealthTest.php`
- Create: `api/tests/Concerns/RefreshTenantDatabase.php`
- Install: `predis/predis`, `laravel/sanctum` (via `php artisan install:api`)

**Interfaces:**
- Produces: connection `pgsql` (role `app`, runtime), connection `pgsql_owner` (role `app_owner`, migrations only); `composer migrate` → `php artisan migrate --database=pgsql_owner`; trait `Tests\Concerns\RefreshTenantDatabase` used by every feature test; route prefix `/api/v1`.

- [ ] **Step 1: Write the roles script**

```sql
-- api/database/scripts/create-roles.sql
-- Run as a superuser. app_owner owns the schema and runs migrations;
-- app is the runtime role and is always subject to row-level security (TEN-01).
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_owner') THEN
    CREATE ROLE app_owner LOGIN PASSWORD 'app_owner' NOSUPERUSER BYPASSRLS;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app') THEN
    CREATE ROLE app LOGIN PASSWORD 'app' NOSUPERUSER NOBYPASSRLS;
  END IF;
END $$;
```

`setup-local-db.sh` drops and recreates `app` and `app_test` owned by `app_owner`, then in each database: `ALTER SCHEMA public OWNER TO app_owner; GRANT USAGE ON SCHEMA public TO app; ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app; ... GRANT USAGE, SELECT ON SEQUENCES TO app; ... GRANT EXECUTE ON FUNCTIONS TO app;`. Passwords come from env vars with the defaults above (local only).

- [ ] **Step 2: Run it locally** (`bash api/database/scripts/setup-local-db.sh`), then set `.env`: `DB_USERNAME=app DB_PASSWORD=app DB_OWNER_USERNAME=app_owner DB_OWNER_PASSWORD=app_owner`, `REDIS_CLIENT=predis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=array`. Same keys in `.env.example`. `phpunit.xml`: `DB_USERNAME=app`, `DB_PASSWORD=app`, `DB_OWNER_USERNAME=app_owner`, `DB_OWNER_PASSWORD=app_owner`, `DB_DATABASE=app_test`, `CACHE_STORE=array`.

- [ ] **Step 3: `pgsql_owner` connection** in `config/database.php`: copy of `pgsql` with `username => env('DB_OWNER_USERNAME')`, `password => env('DB_OWNER_PASSWORD')`.

- [ ] **Step 4: Test trait**

```php
// api/tests/Concerns/RefreshTenantDatabase.php
namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshTenantDatabase
{
    use RefreshDatabase;

    // Migrations run as the schema owner; tests run as the RLS-bound app role.
    protected function migrateFreshUsing(): array
    {
        return ['--database' => 'pgsql_owner', '--seed' => false];
    }
}
```

`TestCase::setUp()` also resets tenant context (added in Task 3).

- [ ] **Step 5: Failing test** — add to `HealthTest`:

```php
public function test_runtime_role_cannot_bypass_row_level_security(): void
{
    $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');
    $this->assertFalse($role->rolsuper);
    $this->assertFalse($role->rolbypassrls);
}
```

Run `php artisan test --filter=HealthTest` → fails before `.env`/phpunit changes, passes after.

- [ ] **Step 6: Sanctum and predis.** `composer require predis/predis`; `php artisan install:api --without-migration-prompt` (creates `routes/api.php`, Sanctum migration). Prefix API routes with `v1` (`bootstrap/app.php`: `apiPrefix: 'api/v1'`). `config/cors.php` publish: allowed origin `env('FRONTEND_URL')` (3008) and `http://localhost:3009`.

- [ ] **Step 7: Composer scripts:** `"migrate": "@php artisan migrate --database=pgsql_owner"`, `"migrate:fresh": "@php artisan migrate:fresh --database=pgsql_owner"`. Run `composer migrate:fresh`; run `HealthTest`.

- [ ] **Step 8: Commit** `chore(api): runtime and owner database roles, predis, sanctum (TEN-01)`.

---

## Task 2: Tokens package

**Files:**
- Create: `packages/tokens/build.mjs`, `packages/tokens/src/index.js` (replace stub), `packages/tokens/src/contrast.js`, `packages/tokens/src/derive.js`, `packages/tokens/test/*.test.js`, `packages/tokens/vitest.config.js`
- Generated (committed): `packages/tokens/dist/tokens.css`, `dist/tailwind.css`, `dist/tailwind-v3-preset.js`, `dist/native-themes.js`, `dist/tokens.json`
- Modify: `packages/tokens/package.json` (`exports`, `scripts.build`, `scripts.test`)

**Interfaces:**
- Produces:
  - `dist/tokens.css`: `:root` = light values; `[data-theme="dark"], .dark` = dark; `[data-theme="executive"]`, `[data-theme="warm"]`. Variables named exactly as tokens: `--surface-100`, `--ink`, `--primary`, `--space-4`, `--radius-md`, `--shadow-lg`, `--font-sans`, `--font-mono`, `--font-display`, plus type styles `--text-body-size`, `--text-body-line` etc. Alias `{primary}` → `var(--primary)`.
  - `dist/tailwind.css`: Tailwind 4 `@theme inline` mapping `--color-surface-100: var(--surface-100)` for every colour token, `--spacing-*`? No: spacing via `--spacing-1..12` names `space-N`; radius `--radius-sm|md|lg|pill`; shadows `--shadow-sm|lg`; fonts; font sizes `--text-display|h1|h2|h3|body-lg|body|label|caption|amount|amount-lg` with line-heights. It also starts with `@theme { --color-*: initial; --spacing: initial; }` so Tailwind's default palette and arbitrary spacing scale are disabled.
  - `dist/tailwind-v3-preset.js`: `module.exports = { theme: { colors: { 'surface-100': 'var(--surface-100)', ... }, spacing: {...}, borderRadius: {...}, fontFamily: {...}, fontSize: {...} } }` (replaces, not extends, so defaults are gone).
  - `dist/native-themes.js`: `module.exports = { light: { '--surface-100': '#fbfbfa', ... }, dark: {...}, executive: {...}, warm: {...} }` with aliases resolved to literal values.
  - `src/contrast.js`: `contrastRatio(hexA, hexB) → number`, `meetsAA(fg, bg, { large = false } = {}) → boolean`.
  - `src/derive.js`: `deriveBrandPair(hex, { mode: 'light'|'dark' }) → { base, hover, tint, on }` (hover = 12% darker in light / lighter in dark via OKLCH lightness; `on` = `#ffffff` or `#18181b`, whichever has higher contrast; tint = lightness 0.96 light / 0.22 dark).
  - `src/index.js` re-exports the above plus `themes` (from `dist/native-themes.js`) and `OVERRIDABLE_TOKENS = ['primary','primary-hover','on-primary','primary-tint','accent','accent-hover','on-accent','sidebar','sidebar-ink','sidebar-active','sidebar-ink-active','sidebar-border','radius-md','radius-lg','font-sans','font-display']`.

- [ ] **Step 1: Failing tests** (`test/build.test.js`): building from `design/tokens.json` produces `tokens.css` containing `--surface-100: #fbfbfa;` under `:root` and `--surface-100: #0c0d0e;` under the dark selector; `--focus: var(--primary);`; every colour token present in all four themes (missing values inherit light); `tailwind.css` contains `--color-ink: var(--ink);` and `--color-*: initial;`; v3 preset has no `blue` key; native themes `dark['--ink'] === '#f4f4f5'` and `light['--focus'] === '#0f4c55'`.
- [ ] **Step 2: Failing tests** (`test/contrast.test.js`): `contrastRatio('#ffffff','#000000')` ≈ 21; `meetsAA('#65656d','#fbfbfa')` true; `meetsAA('#a1a1aa','#ffffff')` false; every `ink`/`ink-muted` on `surface-100|200|300` in all four themes meets AA (guards the source tokens).
- [ ] **Step 3: Failing tests** (`test/derive.test.js`): `deriveBrandPair('#0f4c55')` returns `on: '#ffffff'`, hover darker than base, tint lighter; result `on` vs `base` meets AA.
- [ ] **Step 4: Implement** `build.mjs` (reads `../../design/tokens.json`, writes `dist/`), `contrast.js` (WCAG relative luminance), `derive.js` (hex ↔ OKLCH conversion implemented locally; no new dependency).
- [ ] **Step 5:** `npm run build -w @app/tokens && npx vitest run -c packages/tokens/vitest.config.js` → all pass.
- [ ] **Step 6: Commit** `feat(tokens): compile design tokens for web and POS (BR-01)`.

---

## Task 3: Tenant schema, RLS and tenant context

**Files:**
- Create: `api/app/Core/Tenancy/TenantContext.php`, `api/app/Core/Tenancy/Rls.php`, `api/app/Core/Tenancy/BelongsToTenant.php`, `api/app/Core/Tenancy/Archivable.php`, `api/app/Core/Tenancy/Http/ResetTenantContext.php`, `api/app/Core/Tenancy/Http/RequireTenant.php`, `api/app/Core/Tenancy/Jobs/TenantAware.php`, `api/app/Core/Tenancy/Models/{Tenant,Company,Branch,Location,Device}.php`, `api/app/Providers/CoreServiceProvider.php`
- Create migrations: `2026_10_08_000100_create_tenants_table.php`, `..._000110_create_companies_table.php`, `..._000120_create_branches_table.php`, `..._000130_create_locations_table.php`, `..._000140_create_devices_table.php`
- Test: `api/tests/Feature/Core/Tenancy/RlsCoverageTest.php`, `TenantContextTest.php`

**Interfaces:**
- Produces:
  - `TenantContext` (singleton): `set(?string $tenantId): void` (runs `select set_config('app.tenant_id', ?, false)` on `pgsql`, stores id, sets spatie cache key — see Task 9 hook `TenantContext::onChange(callable)`), `id(): ?string`, `require(): string` (throws `TenantContextMissing`), `run(string $tenantId, callable $fn): mixed` (sets, runs, restores previous).
  - `Rls::enable(string $table): void` (`ENABLE` + `FORCE` + policy `tenant_isolation` USING/WITH CHECK `tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid`); `Rls::enableOnKey(string $table, string $column = 'id')` for `tenants`.
  - Blueprint macro `$table->tenantId()` → `uuid('tenant_id')` with DB default expression above, index, FK `tenants(id)` on delete restrict.
  - Trait `BelongsToTenant`: `creating` fills `tenant_id` from `TenantContext::require()` if empty.
  - Trait `Archivable`: `archive()`, `restore()`, `scopeActive()`, `isArchived()`; column `archived_at timestampTz null`.
  - Middleware `ResetTenantContext` (global, first) sets context to null; `RequireTenant` (API group, after auth) aborts 401 if no context.
  - Job middleware `TenantAware` reads `$job->tenantId` and wraps `handle` in `TenantContext::run`.
  - Tables:
    - `tenants(id uuid pk, name, status enum active|suspended|closed, default_locale en|fr, settings jsonb default '{}', created_at, updated_at)`; settings keys: `password_min_length` (int ≥ 8, default 8), `session_timeout_minutes` (15–480, default 60).
    - `companies(id, tenant_id, name, legal_name, tax_id null, country char(2) KE|CD, base_currency char(3), fiscal_year_start_month smallint 1..12, address jsonb, timezone, archived_at, timestamps)`.
    - `branches(id, tenant_id, company_id fk, name, code, timezone null, address jsonb, archived_at, timestamps)`.
    - `locations(id, tenant_id, branch_id fk, name, type enum outlet|warehouse|store|office, archived_at, timestamps)`.
    - `devices(id, tenant_id, location_id fk, name, status enum pending|active|suspended|unpaired, pairing_code_hash null, pairing_code_expires_at null, paired_at null, last_seen_at null, timestamps)`.

- [ ] **Step 1: Failing test `RlsCoverageTest`:**

```php
public function test_every_tenant_table_forces_row_level_security(): void
{
    $tables = collect(DB::select("
        select c.relname, c.relrowsecurity, c.relforcerowsecurity,
               exists(select 1 from pg_policies p where p.tablename = c.relname and p.policyname = 'tenant_isolation') as has_policy
        from pg_class c join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind = 'r'
          and exists(select 1 from information_schema.columns col
                     where col.table_schema = 'public' and col.table_name = c.relname and col.column_name = 'tenant_id')
    "));
    $this->assertNotEmpty($tables);
    foreach ($tables as $t) {
        $this->assertTrue($t->relrowsecurity && $t->relforcerowsecurity && $t->has_policy, "{$t->relname} lacks forced RLS");
    }
}
```

Plus `tenants` itself has forced RLS keyed on `id`.

- [ ] **Step 2: Failing test `TenantContextTest`:** create tenants A and B (inside `TenantContext::run`) with one company each; with context A, `Company::count() === 1` and the row is A's; with context null, `Company::count() === 0`; inserting a company for B while context is A throws a `QueryException` (WITH CHECK); `run()` restores the previous context after an exception.
- [ ] **Step 3: Implement** the classes and migrations above. Migrations call `Rls::enable(...)` after `Schema::create`. Register macro + singleton in `CoreServiceProvider`; register `ResetTenantContext` globally (prepend) in `bootstrap/app.php`.
- [ ] **Step 4:** `TestCase::setUp()` → `app(TenantContext::class)->set(null)` after parent setup.
- [ ] **Step 5:** `composer migrate:fresh && php artisan test tests/Feature/Core/Tenancy` → pass.
- [ ] **Step 6: Commit** `feat(core): tenants, companies, branches, locations, devices with forced RLS (TEN-01..05)`.

---

## Task 4: Audit log (append-only, hash-chained)

**Files:**
- Create: `api/app/Core/Audit/{Auditor.php, AuditEntry.php (model), Audited.php (trait), AuditContext.php, Console/VerifyAuditChain.php}`
- Migration: `..._000200_create_audit_tables.php` (`audit_logs`, `audit_chain_heads`)
- Test: `api/tests/Feature/Core/Audit/AuditLogTest.php`

**Interfaces:**
- Produces:
  - `audit_logs(id uuid v7, tenant_id, seq bigint, occurred_at timestampTz, device_time timestampTz null, user_id uuid null, on_behalf_of_user_id uuid null, action string, module string, auditable_type string null, auditable_id uuid null, before jsonb null, after jsonb null, ip inet null, user_agent text null, device_id uuid null, location_id uuid null, prev_hash char(64), hash char(64))`, unique `(tenant_id, seq)`.
  - `audit_chain_heads(tenant_id pk, seq bigint, hash char(64))` (tenant table, RLS).
  - Trigger `audit_logs_append_only` raising `audit log is append-only` on UPDATE or DELETE; `REVOKE UPDATE, DELETE ON audit_logs FROM app`.
  - `Auditor::record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, array $extra = []): AuditEntry` — locks `audit_chain_heads` row `FOR UPDATE` (inserting it on first use), computes `hash = sha256(prev_hash . canonicalJson(entry fields without hash))`, inserts, updates head. `canonicalJson` sorts keys recursively. Actor, IP, user agent, device and on-behalf-of come from `AuditContext` (filled by middleware after auth).
  - `Auditor::verify(string $tenantId): ?int` returns first broken `seq` or null.
  - Trait `Audited` on models: `created` → `{module}.{resource}.create`, `updated` (dirty fields only, excluding timestamps) → `.update`, archive/restore → `.archive`/`.restore`. Models declare `protected string $auditModule = 'core'; protected string $auditResource = 'company';` and `$auditHidden = ['password', ...]`.
  - Command `audit:verify {tenant}`.
  - Actions vocabulary for later tasks: `auth.sign_in`, `auth.sign_in_failed`, `auth.sign_out`, `rbac.role.create|update|archive`, `rbac.assignment.create|delete`, `core.user.invite|deactivate|reactivate`.

- [ ] **Step 1: Failing tests:** creating a company writes one entry with `after.name`; updating name writes `before`/`after` with only `name`; entries chain (`entry2.prev_hash === entry1.hash`); `DB::update('update audit_logs ...')` throws; `delete` throws; tampering via the owner connection (`DB::connection('pgsql_owner')` with trigger disabled for the test: `ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_append_only`, change `after`, re-enable) makes `Auditor::verify` return that `seq`; tenant B cannot read tenant A's entries.
- [ ] **Step 2: Implement.** Add `Audited` to Company, Branch, Location, Device.
- [ ] **Step 3:** run `php artisan test tests/Feature/Core/Audit` → pass. **Commit** `feat(core): append-only hash-chained audit log (AUD-01..03)`.

---

## Task 5: Translations (API, web, POS) and the missing-key check

**Files:**
- Create: `api/lang/en/{app,auth,validation,core,rbac}.php`, `api/lang/fr/...` (same files; publish Laravel's `validation.php` and translate to French), `api/tests/Feature/TranslationParityTest.php`
- Create: `web/src/i18n/index.js`, `web/src/locales/en.json`, `web/src/locales/fr.json`, `web/src/i18n/i18n.test.js`
- Create: `pos/src/i18n/index.js`, `pos/src/locales/{en,fr}.json`, `pos/src/i18n/i18n.test.js`
- Create: `scripts/check-i18n.mjs` (root), root `package.json` script `check:i18n`
- Install (web): `i18next`, `react-i18next`; (pos): same.

**Interfaces:**
- Produces: `t('app.name')` everywhere returns the env display name (web: `i18next` init with `interpolation`, resource `app.name` = `{{appName}}` injected from config at init; API: `__('app.name')` = `config('app.name')` via `lang/*/app.php` returning `['name' => env('APP_NAME')]`).
- Locale selection: API reads `Accept-Language` (en|fr, default tenant `default_locale`) via middleware `SetLocale`; web from user profile, fallback browser, fallback `en`; POS from device locale.
- `check-i18n.mjs`: fails (exit 1) when `en.json` and `fr.json` key sets differ (web and pos), when a `t('literal.key')` used in `web/src` or `pos/src` is missing from either file, or when an `fr` value is empty or identical to `en` for strings longer than 3 characters unless listed in `scripts/i18n-same-allowlist.json` (codes like "KES", "OK").

- [ ] **Step 1: Failing tests:** API parity test loads every `lang/en/*.php` and `lang/fr/*.php`, flattens keys, asserts equal sets; web/pos tests run the same parity check in-process and assert `t('app.name')` equals `import.meta.env.VITE_APP_NAME` / `process.env.EXPO_PUBLIC_APP_NAME`.
- [ ] **Step 2: Implement.** French copy follows `design/system/README.md` content rules (sentence case, verbs on buttons, no exclamation marks).
- [ ] **Step 3:** Run the three tests and `npm run check:i18n`. **Commit** `feat(i18n): English and French catalogues with missing-key check (L10N-01, L10N-02)`.

---

## Task 6: Sign-up, sign-in, tokens, sessions and login security

**Files:**
- Create: `api/app/Core/Identity/Models/User.php` (move from `app/Models/User.php`; update `config/auth.php`), `Models/PersonalAccessToken.php`, `Models/VerificationChallenge.php`, `Models/LoginEvent.php`
- Create: `api/app/Core/Identity/Services/{SignUp.php, Authenticate.php, PasswordPolicy.php, Challenges.php, LoginThrottle.php, SessionTimeout.php}`
- Create: `api/app/Core/Identity/Http/Controllers/{SignUpController, VerifyController, SignInController, SessionController, MeController}.php`, `Http/Requests/*`, `Http/Resources/{UserResource, SessionResource}.php`
- Create: `api/app/Core/Identity/Notifications/{VerificationCode, NewDeviceSignIn}.php`, `api/app/Core/Notifications/Channels/SmsChannel.php`, `api/app/Core/Notifications/Sms/{SmsSender.php, LogSmsSender.php}`
- Create: `api/resources/security/common-passwords.txt` (the 10,000 most common passwords list, lower-cased; source: SecLists `10k-most-common.txt`, MIT licence noted in file header)
- Migrations: `..._000300_create_identity_tables.php` (replace default users migration: `users`, `verification_challenges`, `login_events`; drop default `password_reset_tokens`/`sessions` tables), Sanctum `personal_access_tokens` gets `tenant_id uuid not null` (no RLS: read before tenant is known; holds token hashes only) and `uuid` morphs; SQL function `auth_tenant_for_login(p_login text) returns uuid` `SECURITY DEFINER` owned by `app_owner`, `SET search_path = public`.
- Routes (`routes/api.php`, prefix `/api/v1`): `POST auth/sign-up`, `POST auth/verify`, `POST auth/verify/resend`, `POST auth/sign-in`, `POST auth/sign-out`, `GET me`, `PATCH me` (name, locale), `GET auth/sessions`, `DELETE auth/sessions/{id}`.
- Tests: `api/tests/Feature/Core/Identity/{SignUpTest, SignInTest, PasswordPolicyTest, SessionTest, LoginSecurityTest}.php`

**Interfaces:**
- Consumes: `TenantContext`, `Auditor`, Tenant/Company/Branch/Location models, role templates seeding hook `TenantProvisioned` event (listened to by RBAC in Task 9).
- Produces:
  - `users(id, tenant_id, name, email citext null unique, phone null unique (E.164), password, locale en|fr, status enum pending|active|deactivated, email_verified_at, phone_verified_at, two_factor_secret text null (encrypted cast), two_factor_confirmed_at null, two_factor_method enum totp|sms null, failed_sign_ins int default 0, locked_until null, last_sign_in_at null, is_platform_staff bool default false, timestamps)`; check: email or phone not null. Login identifiers are unique across tenants (unique indexes see all rows despite RLS).
  - `verification_challenges(id, tenant_id, user_id, purpose enum verify_contact|two_factor|password_reset, channel email|sms, destination, code_hash, attempts smallint, expires_at, consumed_at null, created_at)` — system table, no RLS, accessed only by `Challenges`.
  - `login_events(id, tenant_id, user_id, ip inet, user_agent, fingerprint char(64), succeeded bool, created_at)` (RLS).
  - `SignUp::handle(array $data): array{user: User, challenge: VerificationChallenge}` where `$data = name, email|phone, password, country (KE|CD), locale (en|fr), business_name`. Creates in one transaction under the new tenant's context: tenant (`name` = business_name, `default_locale`), company (`name` = business_name, `country`, `base_currency` KE→KES, CD→USD, `fiscal_year_start_month` 1, timezone KE→`Africa/Nairobi`, CD→`Africa/Kinshasa`), branch (`__('core.defaults.branch')` in chosen locale), location (`__('core.defaults.location')`, type outlet), owner user (status pending); dispatches `TenantProvisioned($tenant, $owner)`; sends a 6-digit code (email via mail, phone via `SmsChannel`), valid 30 minutes.
  - `POST auth/verify {challenge_id, code}` → `{token, user}`; marks contact verified, user active. Max 5 attempts per challenge.
  - `Authenticate::attempt(string $login, string $password, string $ip, string $userAgent): SignInResult` (`status` = `ok|two_factor_required|locked|invalid|unverified|deactivated`, `token?`, `challenge_id?`, `retry_after?`). Lookup: `auth_tenant_for_login(login)` → set context → find user. Constant-time failure path (hash a dummy password when unknown).
  - Lockout (AUTH-10): 5 consecutive failures → `locked_until = now()+15 min`; Laravel `RateLimiter` 10 attempts/minute per IP and 5/minute per login → HTTP 429 with `Retry-After`. New-device alert: `fingerprint = sha256(user_agent . ip /24)`; first successful sign-in from a fingerprint not seen in `login_events` for that user (and user has prior events) notifies the user.
  - Tokens: custom `PersonalAccessToken` model (`Sanctum::usePersonalAccessTokenModel`) with `tenant_id`, `name` (device label), `ip`, `user_agent`; `findToken()` sets `TenantContext` from the token's `tenant_id` before returning. `Sanctum::authenticateAccessTokensUsing` rejects tokens where `last_used_at ?? created_at` is older than tenant `session_timeout_minutes` (AUTH-09) or user not active.
  - `PasswordPolicy::rules(?Tenant $tenant): array` → `['required','string','min:'.max(8, tenant min), new NotCommonPassword]`; message keys `auth.password.common`, `validation.min.string`.
  - `GET auth/sessions` lists current user's tokens (`id, name, ip, user_agent, last_used_at, current: bool`); `DELETE auth/sessions/{id}` revokes own token (404 for others).
  - Audit actions `auth.sign_in`, `auth.sign_in_failed`, `auth.sign_out`.
  - Error envelope for all API errors: `{ "message": string (translated), "code": string, "errors"?: {field: [messages]} }`.

- [ ] **Step 1: Failing tests (SignUpTest):** sign-up with email creates exactly one tenant, company, branch, location and pending owner; returns `challenge_id`; mail faked with a 6-digit code; verifying with the right code returns a token and `GET me` works with it; wrong code 5 times then the right one fails; expired (31 min, `travel`) fails; duplicate email returns 422 `validation.unique`; phone sign-up sends SMS via `LogSmsSender` (fake); locale `fr` makes the default branch name the French string.
- [ ] **Step 2: Failing tests (SignInTest):** correct password → token; wrong → 422 `auth.failed` and audit `auth.sign_in_failed`; unverified → 403 code `unverified`; deactivated → 403 `deactivated`; sign-in by phone works; token of tenant A gives `GET me` for A, then a token of tenant B on the same app instance gives B (context reset between requests).
- [ ] **Step 3: Failing tests (PasswordPolicyTest):** `password123`, `qwertyuiop` rejected as common; 7 chars rejected; tenant min 12 rejects an 11-char password at sign-up of an invited user (exercise through `PasswordPolicy::rules` directly).
- [ ] **Step 4: Failing tests (SessionTest, LoginSecurityTest):** list shows two sessions after two sign-ins, `current` flags one; revoking the other makes it 401; token idle beyond 60 min (`travel(61)->minutes()`) → 401; 5 wrong passwords lock for 15 min (correct password then returns `locked`); 6th attempt in a minute from the same login → 429; sign-in from a new fingerprint after a previous one sends `NewDeviceSignIn`.
- [ ] **Step 5: Implement** everything above.
- [ ] **Step 6:** `php artisan test tests/Feature/Core/Identity` → pass. **Commit** `feat(identity): sign-up with OTP, sign-in, sessions, lockout (AUTH-01, 02, 09, 10)`.

---

## Task 7: Two-factor, password reset

**Files:**
- Install: `pragmarx/google2fa`, `bacon/bacon-qr-code` (QR SVG for the enrolment screen)
- Create: `api/app/Core/Identity/Services/TwoFactor.php`, `Http/Controllers/{TwoFactorController, PasswordResetController}.php`
- Routes: `POST auth/two-factor/challenge {challenge_id, code}` (completes sign-in), `POST me/two-factor/totp` (start enrolment → `{secret, otpauth_url, qr_svg}`), `POST me/two-factor/totp/confirm {code}`, `POST me/two-factor/sms` (enable SMS, sends confirm code), `POST me/two-factor/sms/confirm {code}`, `DELETE me/two-factor {password}`, `POST auth/password/forgot {login}` (always 202), `POST auth/password/reset {login, code, password}`
- Tests: `api/tests/Feature/Core/Identity/{TwoFactorTest, PasswordResetTest}.php`

**Interfaces:**
- Produces: `TwoFactor::required(User $u): bool` = any role assigned to the user has `requires_two_factor = true` (column added to `roles` in Task 9; until then false — Task 9 adds a test for the role path). When required but not enrolled, sign-in returns a token with Sanctum ability `two-factor:enroll` only; every other route uses middleware `abilities:*`-compatible check `EnsureFullAccessToken` that rejects such tokens with code `two_factor_enrollment_required`.
- Reset codes: 6 digits, 30 minutes, single use, 5 attempts; successful reset revokes all the user's tokens, clears lockout, audits `auth.password_reset`.

- [ ] **Step 1: Failing tests:** TOTP enrol → confirm with `Google2FA::getCurrentOtp(secret)` → next sign-in returns `two_factor_required` with `challenge_id`; completing with a valid TOTP returns a token; invalid code fails; SMS method sends a code and accepts it; disabling requires the correct password; forgot for unknown login still 202 and sends nothing; reset with valid code changes password and revokes old tokens; reused code fails; code at 31 minutes fails.
- [ ] **Step 2: Implement. Step 3:** run the two test files. **Commit** `feat(identity): TOTP and SMS two-factor, password reset (AUTH-03, AUTH-04)`.

---

## Task 8: Organisation API (companies, branches, locations, devices)

**Files:**
- Create: `api/app/Core/Tenancy/Http/Controllers/{CompanyController, BranchController, LocationController, DeviceController, DevicePairingController}.php`, `Http/Requests/*`, `Http/Resources/*`, `api/app/Core/Tenancy/Policies/{CompanyPolicy, BranchPolicy, LocationPolicy, DevicePolicy}.php`
- SQL function `auth_tenant_for_pairing(p_code_hash text) returns uuid` (SECURITY DEFINER).
- Routes: `apiResource` minus `destroy` for `companies`, `companies.branches` (shallow), `branches.locations` (shallow), `locations.devices` (shallow); `POST {resource}/{id}/archive`, `POST {resource}/{id}/restore`; `POST devices/{id}/pairing-code` (returns 8-char code once, valid 15 min), `POST devices/{id}/suspend`, `POST devices/{id}/unpair`, `POST devices/pair {code, device_name}` (public, rate-limited; returns a device token with ability `device`).
- Tests: `api/tests/Feature/Core/Tenancy/{CompanyApiTest, BranchApiTest, LocationApiTest, DeviceApiTest}.php`

**Interfaces:**
- Consumes: `ScopeResolver` (Task 9). **Order note:** implement Task 9 before this task's policies, or stub policies to `ScopeResolver` calls and write the permission-denied tests after Task 9. Recommended execution order: Task 9 then Task 8.
- Produces: list endpoints return only rows inside the user's allowed scopes (`ScopeResolver::visibleIds`); archive refuses the tenant's last active company/branch/location with 422 code `last_active` (TEN-06 business rule); archived records excluded from `?status=active` default and included with `?status=all`.

- [ ] **Step 1: Failing tests per resource:** create/update/archive/restore happy path with Owner; Branch Manager of branch A cannot see branch B's locations (404) nor create a branch (403); archiving the only location → 422 `last_active`; KE company defaults `base_currency=KES`; device pairing: code works once, second use 422, after 15 min 422, paired token can call `GET devices/me`; suspended device token → 401; tenant B ids → 404 for tenant A.
- [ ] **Step 2: Implement. Step 3:** run the four test files. **Commit** `feat(core): organisation and device pairing API (TEN-02..06)`.

---

## Task 9: RBAC — catalogue, tenant roles, scoped assignments, module flags, cache

**Files:**
- Install: `spatie/laravel-permission`; publish config + migration, then edit the migration: `roles` gets `id uuid`, `tenant_id` (RLS), `description`, `is_system bool`, `template_key null`, `is_owner bool`, `requires_two_factor bool`, `archived_at`; unique `(tenant_id, name, guard_name)`; `role_has_permissions` gets `tenant_id` (RLS); `permissions` stays global (catalogue) with extra `module`, `resource`, `action`; drop `model_has_roles` / `model_has_permissions` (not used).
- Create: `api/app/Core/Rbac/{PermissionRegistry.php, ScopeResolver.php, Scope.php, RoleTemplates.php, role-templates.php, FieldRules.php, LimitRules.php, OwnerGuard.php, ModuleRegistry.php, Models/{Role.php, Permission.php, RoleAssignment.php, FieldRule.php, LimitRule.php, TenantModule.php}, Http/Middleware/EnsureModuleActive.php, Console/SyncPermissions.php, Listeners/ProvisionTenantRoles.php}`
- Migrations: `role_assignments`, `field_rules`, `limit_rules`, `tenant_modules`
- Tests: `api/tests/Feature/Core/Rbac/{ScopeResolverTest, PermissionCacheTest, RoleTemplatesTest, ModuleFlagsTest, FieldAndLimitRulesTest}.php`

**Interfaces:**
- Produces:
  - `PermissionRegistry::register(string $module, array $resources)` e.g. `register('core', ['company' => ['view','create','edit','archive'], ...])`; `all(): Collection`; command `permissions:sync` upserts `permissions` (name `module.resource.action`). Core catalogue: `core.company|branch|location|device` × `view,create,edit,archive`; `core.device.pair`; `core.user` × `view,invite,edit,deactivate`; `core.role` × `view,create,edit,archive,assign`; `core.audit` × `view,export`; `core.settings.edit`; `core.access_review` × `view,export`.
  - `Scope` value object: `Scope::tenant()`, `Scope::company($id)`, `Scope::branch($id)`, `Scope::location($id)`.
  - `role_assignments(id, tenant_id, user_id fk, role_id fk, scope_type enum tenant|company|branch|location, scope_id uuid, created_by, timestamps)` unique `(user_id, role_id, scope_type, scope_id)`.
  - `ScopeResolver::can(User $u, string $permission, ?Scope $target = null): bool` — true when the module is active (`ModuleRegistry::isActive`), user active, and some assignment's role (not archived) has the permission and covers the target: tenant covers everything; company covers itself, its branches and their locations; branch covers itself and its locations; location covers itself. `$target = null` means "anywhere".
  - `ScopeResolver::visibleIds(User $u, string $permission): VisibleScope` with `->all` bool, `->companyIds`, `->branchIds`, `->locationIds` (expanded downwards) and `applyTo(Builder $q, string $level)`.
  - `Gate::before` delegates `$user->can('core.company.view', $model)` to `ScopeResolver` with the model's scope (`HasScope` interface: `scope(): Scope`).
  - `GET me/permissions` → `{ permissions: [{name, scopes: [{type,id}]}], modules: [...] }` (drives UI; RBAC-09).
  - `RoleTemplates::provision(Tenant $t)` seeds the 13 templates (RBAC-03) as `is_system = true` roles from `role-templates.php` (permission name patterns with `*`), and assigns Owner at tenant scope to the owner user (listener on `TenantProvisioned`). Templates: Owner (`*`, `is_owner`), Admin (`core.*` except none), Branch Manager, Cashier, Waiter, Storekeeper, Accountant, HR Officer, Payroll Officer, Procurement Officer, Approver, Employee self-service, Read-only Auditor (`*.view`, `core.audit.*`, `core.access_review.*`). Module permissions not yet registered simply match nothing until their module registers them.
  - Spatie cache per tenant: `TenantContext::onChange` sets `PermissionRegistrar::$cacheKey = 'permission.cache.'.$tenantId` and resets the in-memory loaded permissions; role/permission changes call `forgetCachedPermissions()` for the current tenant only.
  - `tenant_modules(tenant_id, module, status active|inactive, activated_at, deactivated_at)`; `ModuleRegistry::isActive(string $module): bool` (`core` always true); middleware alias `module:{name}`.
  - `field_rules(tenant_id, role_id, resource, field, mode hidden|readonly)`; `FieldRules::for(User, string $resource): array{hidden: string[], readonly: string[]}` — a field is hidden/readonly only if every role the user holds (any scope) restricts it; `FieldRules::filter(User, string $resource, array $data): array`.
  - `limit_rules(tenant_id, role_id, key, value numeric(18,4))`; keys: `max_discount_percent`, `max_refund_amount`, `max_approval_amount`, `credit_limit_override`; `LimitRules::max(User, string $key, ?Scope $scope): ?string` (highest across covering roles; null = no limit configured = not allowed).

- [ ] **Step 1: Failing tests (ScopeResolverTest):** user with Branch Manager at branch A: can `core.location.view` at a location of A, cannot at a location of B; company-scoped Admin reaches every branch of that company and none of another company; tenant-scoped Owner reaches everything; archived role grants nothing; deactivated user gets false; `visibleIds` for the branch manager contains exactly A's locations.
- [ ] **Step 2: Failing tests (PermissionCacheTest):** tenants A and B each have role `Cashier`; A grants `core.company.view`; warm the cache in A (`can`), switch context to B: B cashier cannot `core.company.view`; flush in A does not flush B's key (assert via `Cache::has`).
- [ ] **Step 3: Failing tests (RoleTemplatesTest, ModuleFlagsTest, FieldAndLimitRulesTest):** sign-up provisions 13 system roles and an Owner assignment; system role update → 403 code `system_role`; copying a system role creates an editable one; a test-registered module `demo` with permission `demo.thing.view`: inactive → `can` false and route with `module:demo` → 403 code `module_inactive`, `me/permissions` omits it; active → allowed. Field rule hides `cost_price` only when all roles hide it; limit rule max across two roles returns the higher.
- [ ] **Step 4: Implement.** `permissions:sync` runs in `SignUp` path for tests via `RefreshTenantDatabase` seeding (`--seed` false; call `permissions:sync` in `TestCase::setUp` once per process).
- [ ] **Step 5:** run `php artisan test tests/Feature/Core/Rbac`. **Commit** `feat(rbac): tenant roles, scoped assignments, module flags, per-tenant cache (RBAC-01..06, 08, 09)`.

---

## Task 10: Users, invitations, roles API, owner safety, access review

**Files:**
- Create: `api/app/Core/Identity/Http/Controllers/{UserController, InvitationController, AcceptInvitationController}.php`, `api/app/Core/Rbac/Http/Controllers/{RoleController, AssignmentController, AccessReviewController, PermissionCatalogueController}.php`, requests, resources, `api/app/Core/Identity/Models/Invitation.php`
- Migration: `invitations(id, tenant_id, email null, phone null, name, assignments jsonb [{role_id, scope_type, scope_id}], token_hash, expires_at, accepted_at null, revoked_at null, invited_by, timestamps)`; SQL function `auth_tenant_for_invitation(p_token_hash text) returns uuid` SECURITY DEFINER.
- Routes: `GET users`, `GET users/{id}`, `PATCH users/{id}`, `POST users/{id}/deactivate`, `POST users/{id}/reactivate`, `POST users/{id}/sign-out-everywhere`, `GET|POST invitations`, `POST invitations/{id}/revoke`, `GET auth/invitations/{token}` (public: shows tenant name, invitee), `POST auth/invitations/{token}/accept {name, password}` → token; `GET|POST roles`, `GET|PATCH roles/{id}`, `POST roles/{id}/copy`, `POST roles/{id}/archive`, `GET permissions` (catalogue grouped by module/resource), `GET|POST users/{id}/assignments`, `DELETE assignments/{id}`, `GET access-review` (+ `?format=csv`).
- Tests: `api/tests/Feature/Core/Identity/{InvitationTest, UserAdminTest}.php`, `api/tests/Feature/Core/Rbac/{RoleApiTest, OwnerSafetyTest, AccessReviewTest}.php`

**Interfaces:**
- Produces: invitations expire after 7 days (AUTH-05); accepting creates an active user with the invitation's assignments; inviter can only grant assignments within their own scope and only roles whose permissions they hold (no privilege escalation; 403 code `cannot_grant`). `OwnerGuard::assertNotLastOwner(User $target)` used by assignment delete, user deactivate and role archive: 422 code `last_owner` (RBAC-10). Deactivation revokes tokens, keeps history (AUTH-13). Every role/assignment change audited (RBAC-12). Access review lists user, role, scope type, scope name, granted by, granted at; CSV export audited as `core.access_review.export`.

- [ ] **Step 1: Failing tests:** invite → accept → sign-in works; expired invite (8 days) 410 code `invitation_expired`; branch manager cannot invite with a company-scope role (403 `cannot_grant`); removing the last Owner assignment → 422 `last_owner`; deactivating the last Owner → 422; with two Owners, removing one works; deactivated user's token → 401 and their audit entries remain; role permission edit on custom role takes effect immediately (cache flushed); access review CSV has header row and one row per assignment; tenant B ids → 404.
- [ ] **Step 2: Implement. Step 3:** run the five test files. **Commit** `feat(core): users, invitations, roles and access review with owner safety (AUTH-05, AUTH-13, RBAC-10, RBAC-12)`.

---

## Task 11: Web foundation — Tailwind 4, shadcn/ui, theme runtime, design-system components

**Files:**
- Install (web): `tailwindcss @tailwindcss/vite`, `@fontsource-variable/geist @fontsource-variable/geist-mono`, `lucide-react`, `clsx tailwind-merge class-variance-authority`, shadcn primitives via `npx shadcn@latest init` (JavaScript, `tsx: false`, alias `@/`) then `npx shadcn@latest add button input label select checkbox switch dialog dropdown-menu tabs table badge card sonner`; `@app/tokens` workspace dependency.
- Create: `web/jsconfig.json` (`@/*` → `src/*`), `web/src/index.css` (imports tokens CSS, Tailwind, shadcn variable mapping exactly as `CLAUDE.md` lists), `web/src/lib/utils.js` (`cn`), `web/src/theme/{ThemeProvider.jsx, themes.js, applyTenantTheme.js}`, `web/src/components/ds/{Button,TextField,Select,Checkbox,Switch,StatusBadge,Alert,SyncStatus,Money,KpiTile,DataTable,Card,Tabs,Dialog,PosTile,SaleTotal,StageTracker,ApprovalCard,Icon,index}.jsx`, `web/src/lib/money.js`, tests beside each component (`*.test.jsx`).
- Create: `web/src/dev/ComponentGallery.jsx` (route `/dev/components`, only when `import.meta.env.DEV`) rendering every component in every state for Playwright checks.

**Interfaces:**
- Produces:
  - `ThemeProvider({ theme = 'light', overrides = {}, children })`: sets `data-theme` and the `dark` class on `document.documentElement`, applies `overrides` (only keys in `OVERRIDABLE_TOKENS`) as CSS variables on `:root` via `applyTenantTheme`; `useTheme() → { theme, setTheme, overrides, setOverrides }`; persists the chosen theme per user in `localStorage` (try/catch).
  - `formatAmount(minor: number|bigint|string, currency: string, locale = 'en'): string` — input in minor units; decimals from `CURRENCY_DECIMALS = { CDF: 0, KES: 2, USD: 2 }` (default 2); `fr` locale uses `fr-CD` grouping (narrow no-break space) and comma decimals; `Money({ amount, currency, secondary, size, tone })` renders `<span class="text-ink-muted">KES</span> 12,450.00` with tabular numerals (`font-variant-numeric: tabular-nums` via a token-backed utility class `tabular-nums`).
  - Component props mirror `design/system/components/index.d.ts` exactly, with these deliberate differences: amounts are minor units; every default label comes from i18n (`t('ds.syncStatus.online')` etc.) instead of English literals.
  - Look: match `design/system/components/bundle.css` rules converted to token classes (e.g. button `h-9 px-3 rounded-md text-label`, `lg` = `h-12`; `pay` variant `bg-accent text-on-accent hover:bg-accent-hover`; StatusBadge = 6px dot `rounded-pill` + word, no fill; focus ring `outline-2 outline-offset-2 outline-focus`).

- [ ] **Step 1: Failing tests:** `formatAmount(1245000,'KES')` → `12,450.00`; `formatAmount(135000,'CDF')` → `135,000`; `formatAmount(4850,'USD','fr')` → `48,50`; `Money` renders currency code first; `Button variant="pay"` has `bg-accent`; `StatusBadge tone="success"` renders a dot element and the text, no `bg-success` fill on the badge; `SaleTotal` button label is `Charge KES 48.50` from `t('ds.saleTotal.pay', {amount})` and disabled when total is 0; `Switch` toggles `aria-checked`; `Dialog` traps focus and closes on Escape (shadcn); `DataTable` shows translated empty text; `ThemeProvider` sets `data-theme="dark"` and class `dark`; override `primary` sets `--primary` on `:root`; override of non-overridable `success` is ignored.
- [ ] **Step 2: Implement.**
- [ ] **Step 3:** `npx vitest run src/components/ds src/theme src/lib` → pass. Start web (`npm run dev:web`), Playwright MCP: open `http://localhost:3008/dev/components`, screenshot in light, dark, executive, warm (switcher on the page); console has no errors.
- [ ] **Step 4: Commit** `feat(web): Tailwind 4, shadcn/ui mapped to tokens, 18 design-system components (BR-01)`.

---

## Task 12: Web app shell — auth screens, sidebar, company switcher, users, roles, appearance

**Files:**
- Install (web): `react-router`, `@tanstack/react-query`.
- Create: `web/src/api/client.js` (fetch wrapper: base `VITE_API_URL` + `/api/v1`, bearer token, `Accept-Language`, JSON error envelope → `ApiError{status, code, message, errors}`), `web/src/auth/{AuthProvider.jsx, RequireAuth.jsx, usePermissions.js}`, `web/src/routes.jsx`, `web/src/layouts/{AppShell.jsx, Sidebar.jsx, CompanySwitcher.jsx, AuthLayout.jsx}`, `web/src/pages/auth/{SignIn, SignUp, Verify, TwoFactor, ForgotPassword, ResetPassword, AcceptInvitation}.jsx`, `web/src/pages/{Home.jsx, NotFound.jsx}`, `web/src/pages/settings/{Organisation.jsx, Users.jsx, UserDetail.jsx, InviteUser.jsx, Roles.jsx, RoleDetail.jsx, Appearance.jsx, Sessions.jsx}.jsx`, tests beside pages for behaviour (MSW not added: mock `client.js` with `vi.mock`).

**Interfaces:**
- Consumes: API from Tasks 6–10; ds components and ThemeProvider from Task 11; i18n from Task 5.
- Produces:
  - Token in `localStorage` key `app.token` (try/catch; falls back to memory); 401 clears it and routes to `/sign-in`.
  - `usePermissions()` → `{ can(permission, scope?) , modules }` from `GET me/permissions`; navigation items declare `permission` and `module`; hidden when not allowed (RBAC-08/09).
  - Sidebar per design system Layout section + `BoDashboard` structure: 232px, `bg-sidebar`, logo block (neutral mark: `ink` square with inner `surface-100` square + `t('app.name')` + tenant name) separated by `border-sidebar-border`; groups with small uppercase labels (Overview, Operations, Finance and people, Workspace) — Sprint 1 shows only Dashboard and Settings (Organisation, Users, Roles, Appearance, Sessions); later modules add items when their module is active. Items 32px tall, `text-body` 13px, 16px Lucide icons, active `bg-sidebar-active text-sidebar-ink-active shadow-sm`. On phone width the sidebar becomes a top bar with a menu button opening a sheet.
  - Company switcher: Select listing companies the user can view (`core.company.view`) plus "All companies" when tenant-scoped; stores `app.companyId`; sent as `X-Company-Id` header (informational for later reporting).
  - Organisation page: tree of companies → branches → locations with create/edit/archive dialogs and devices per location (pairing code dialog shows the code once).
  - Users page: DataTable (name, contact, roles with scopes, status badge), invite dialog with role + scope pickers; user detail with assignments, deactivate/reactivate (confirm dialog, danger button), sign out everywhere.
  - Roles page: list (system roles marked, copy action), detail with permission matrix grouped by module/resource (checkboxes), `requires_two_factor` switch, field rules and limit rules editors.
  - Appearance page: theme picker (Light, Dark, Executive, Warm) applying at runtime through `ThemeProvider` + a "test tenant theme" preview button applying `primary`/`accent` overrides.
  - Sessions page: list with sign-out per session.

- [ ] **Step 1: Failing tests:** SignIn submits `{login,password}` and on `two_factor_required` routes to `/two-factor`; API 422 field errors render under fields; nav hides Users when `core.user.view` absent; Appearance switches `data-theme`; Users page renders rows from mocked API and opens invite dialog; deactivate shows confirm dialog first.
- [ ] **Step 2: Implement.**
- [ ] **Step 3:** run touched vitest files. Start API (`composer migrate:fresh` on `app` db first) and web. **Playwright walkthrough:** sign up a tenant (read the OTP from `storage/logs/laravel.log` mail/SMS log), verify, land on Home; create company, branch, location; invite a user with Cashier at that location; open the invitation link, accept, sign in as the cashier in a second context and confirm Settings → Users is hidden and Organisation shows only that location; switch themes on Appearance (screenshot each); phone width 390px screenshot of shell; console free of errors. Screenshots to `.playwright-mcp/sprint1-*.png`.
- [ ] **Step 4: Commit** `feat(web): app shell, auth screens, organisation, users, roles, appearance (AUTH-01..05, RBAC-04, BR-01)`.

---

## Task 13: POS foundation — NativeWind themes at runtime, i18n, web preview

**Files:**
- Install (pos, via `npx expo install`): `nativewind@4.2.7`, `tailwindcss@^3.4`, `react-native-reanimated`, `react-native-safe-area-context`, `react-native-web`, `react-dom`, `@expo/metro-runtime`; `@app/tokens` workspace dependency.
- Create: `pos/tailwind.config.js` (preset `@app/tokens/dist/tailwind-v3-preset.js`, `content: ['./App.js','./src/**/*.{js,jsx}']`), `pos/global.css`, `pos/babel.config.js`, `pos/metro.config.js` (`withNativeWind`), `pos/src/theme/{ThemeProvider.jsx, useTheme.js}`, `pos/src/screens/ThemePreview.jsx`, `pos/src/components/ds/{Button,Money,StatusBadge,SyncStatus,PosTile,SaleTotal}.jsx` (the POS-critical six now; the remaining twelve in phase 4), tests beside each, `pos/package.json` script `web: expo start --web --port 3009`.

**Interfaces:**
- Produces: `ThemeProvider({ theme, overrides })` wraps the root `View` with `vars({...themes[theme], ...overrides})` from `@app/tokens/dist/native-themes.js`; components use only token classes (`bg-surface-200 text-ink rounded-md`); `ThemePreview` screen shows SyncStatus, PosTiles, SaleTotal and a theme switcher (Light, Dark, Executive, Warm, Test tenant) with 48px targets.

- [ ] **Step 1: Failing tests:** ThemeProvider passes dark `--surface-100` value in its style vars; `Button variant="pay"` renders with class `bg-accent`; `Money` formats CDF without decimals; `SaleTotal` shows translated charge label; `PosTile` with `stock=0` is disabled and announces out of stock.
- [ ] **Step 2: Implement. Step 3:** run `npx jest src` in `pos`; start `npm run web -w pos` (port 3009), Playwright: switch all five themes, screenshot each, console clean.
- [ ] **Step 4: Commit** `feat(pos): NativeWind theme runtime from tokens, core POS components (BR-01)`.

---

## Task 14: Tenant isolation suite

**Files:**
- Create: `api/tests/Feature/Isolation/TenantIsolationTest.php`, `api/tests/Support/TwoTenants.php` (builds tenants A and B through the real sign-up service with companies, branches, locations, devices, users, roles, assignments, invitations, audit entries).

**Interfaces:**
- Produces: the suite CI runs as the "isolation" job (`php artisan test --testsuite=Isolation`; add `<testsuite name="Isolation">` to `phpunit.xml`).

- [ ] **Step 1: Tests:**
  - For every table with `tenant_id`: with context A, `select count(*) from {table} where tenant_id = B` is 0 and `count(*)` equals A's own rows.
  - For every registered `GET` route under `api/v1` with route parameters: substitute B's ids for each parameter type (company, branch, location, device, user, role, invitation, assignment) and call as A's Owner → 404 or 403, never 200.
  - For every list route without parameters: response JSON contains none of B's ids (`assertStringNotContainsString` over the raw body for each B id).
  - CSV export of access review as A contains no B ids.
- [ ] **Step 2:** run `php artisan test --testsuite=Isolation` → pass. **Commit** `test(core): tenant isolation suite across tables, routes and exports (TEN-01)`.

---

## Task 15: CI/CD and ADRs

**Files:**
- Create: `.github/workflows/ci.yml`, `.github/workflows/deploy.yml`, `docs/adr/001-modular-monolith.md`, `002-tenancy-rls.md`, `003-money-and-currency.md`, `004-offline-sync.md`, `005-styling.md`, `006-rbac.md`, root `README.md` (setup: roles script, ports, commands; no product name).
- Modify: `web/package.json` (`lint` = oxlint), `api` add `laravel/pint` check in CI.

**Interfaces:**
- `ci.yml` jobs (on PR and push to `main`): `api` (services `postgres:16`, `redis:7`; run `create-roles.sql` + db setup as `postgres`; `composer install`; `composer migrate`; `vendor/bin/pint --test`; `php artisan test --exclude-testsuite=Isolation`), `isolation` (same setup; `php artisan test --testsuite=Isolation`), `web` (`npm ci`; `npm run lint -w web`; `npx vitest run` in web; `npm run build -w web`), `pos` (`npx jest` in pos), `tokens` (`npm run build -w @app/tokens && git diff --exit-code packages/tokens/dist` + vitest), `i18n` (`npm run check:i18n`).
- `deploy.yml`: manual + on push to `main`; jobs `dev` and `staging` run only `if: ${{ secrets.LINODE_HOST != '' }}`-style gating via an env check step that exits neutral when secrets are absent; steps: rsync build to host, `composer install --no-dev`, `php artisan migrate --database=pgsql_owner --force`, `php artisan horizon:terminate`. Documented in ADR 001 as pending owner-provided credentials.
- ADR content: context, decision, consequences; 002 lists the global (non-RLS) tables and why (`migrations`, `cache*`, `jobs*`, `failed_jobs`, `personal_access_tokens`, `verification_challenges`, `permissions`) and the three SECURITY DEFINER lookup functions; 005 records Tailwind 4 (web) vs Tailwind 3.4 + NativeWind 4.2.7 (POS, because NativeWind 5 is still a release candidate) and how the tokens package feeds each; 006 records Spatie usage and our extensions.

- [ ] **Step 1:** write files; validate workflow YAML with `npx --yes @action-validator/cli .github/workflows/ci.yml` (or `actionlint` if available).
- [ ] **Step 2: Commit** `chore(ci): CI for api, isolation, web, pos, tokens, i18n; gated deploy; ADRs 001-006 (NFR-12)`.

---

## Execution order

1 → 2 → 3 → 4 → 5 → 6 → 7 → 9 → 8 → 10 → 11 → 12 → 13 → 14 → 15. Tasks 2 and 11 touch only `packages/` and `web/` and may run in parallel with API tasks when using separate worktrees; otherwise run sequentially.

## Self-review notes

- Spec coverage for Sprint 1 IDs: TEN-01 (T1, T3, T14), TEN-02..05 (T3, T8), TEN-06 (T3, T8), AUTH-01 (T6), AUTH-02 (T6), AUTH-03 (T7), AUTH-04 (T7), AUTH-05 (T10), AUTH-09 (T6), AUTH-10 (T6), AUTH-13 (T10), RBAC-01..06 (T9), RBAC-08..10 (T9, T10), RBAC-12 (T10), AUD-01..03 (T4), L10N-01..02 (T5), BR-01 (T2, T11, T13), NFR-12 (T15).
- Deliberately deferred: AUTH-06..08 (POS PIN, switching, manager override → phase 4), AUTH-11 SSO (phase 8), RBAC-07/11/13 (phase 3/8), AUD-04 search UI (phase 3 with notifications), new-sign-in country detection (needs a GeoIP source; device fingerprint alert ships now).
