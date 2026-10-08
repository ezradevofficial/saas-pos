# ADR 001: Modular monolith, repository layout, CI and deploy

Status: Accepted (Sprint 1). The deploy pipeline is built but waits for the owner's Linode credentials.

## Context

The platform covers a point of sale and up to twelve business modules (inventory, stores, procurement, accounting, HR, payroll, CRM, e-commerce, fixed assets, manufacturing, loyalty). Each module can be sold on its own. The team is small, and Sprint 1 needs a working core (tenancy, identity, RBAC, audit, i18n) quickly. Microservices would add network calls, more deployments and distributed transactions before any of that is needed (concept note, section 9).

Three clients use the platform: a React web app (back office), an Expo / React Native POS and mobile app, and later third-party API clients. All of them need one API.

## Decision

**One Laravel application, split by module.**

- `api/app/Core` holds the platform core, which is always on. Each concern has its own namespace with its models, policies, requests, controllers and services:
  - `Tenancy`: tenants, companies, branches, locations, devices, row-level security, archiving
  - `Identity`: users, sign-up, sign-in, 2FA, sessions, invitations
  - `Rbac`: permission catalogue, roles, scoped assignments, field and limit rules
  - `Audit`: the hash-chained audit log
  - `Localisation`: request locale
  - `Notifications`: the SMS channel
  - `Http`: the API error envelope
- Business modules will live in `api/modules/<Module>` (from Sprint 2 onwards). Each one owns its tables, routes, migrations, policies, events and tests.
- Modules never read or write another module's tables. They talk through domain events (`SaleCompleted`, `GoodsReceived`, ...) and a small set of core services. The core already raises its own events this way; for example, `TenantProvisioned` is consumed by `Rbac\Listeners\ProvisionTenantRoles`.
- Feature flags are per tenant (RBAC-08):
  - `ModuleRegistry` knows the registered modules and which are active for the current tenant (`tenant_modules`). `core` is always active.
  - Permissions of an inactive module grant nothing.
  - The `EnsureModuleActive` middleware closes a module's routes.
- There is one versioned REST API under `/api/v1`, used by the web app, the POS and future integrations.

**Repository layout.** A single repository with npm workspaces:

| Path | What it holds |
| --- | --- |
| `api/` | Laravel 13, PHP 8.3, PostgreSQL 16, Redis |
| `web/` | React with JavaScript, Vite, Tailwind 4, shadcn/ui |
| `pos/` | Expo SDK 57 with React Native and NativeWind 4 |
| `packages/tokens` | Design tokens compiled for both apps (ADR 005) |
| `docs/` | Specs and these ADRs |
| `design/` | `tokens.json` |

**CI** (`.github/workflows/ci.yml`) runs on every pull request and on every push to `main` (NFR-12). A superseded run on the same branch is cancelled. The jobs:

| Job | What it runs |
| --- | --- |
| `api (tests)` | Creates the roles and databases on a `postgres:16` service as the `postgres` superuser (ADR 002), runs `composer migrate` as the schema owner, `pint --test`, and every test except the Isolation suite |
| `api (isolation)` | The same job definition (a matrix entry) with the same database setup, then only the tenant-isolation suite (`php artisan test --testsuite=Isolation`). It runs on its own so an isolation failure is visible by itself. The `Feature` suite excludes `tests/Feature/Isolation`, so no test runs twice. |
| `web` | `oxlint`, Vitest, and the production build |
| `pos` | Jest and `expo-doctor` (pinned as a pos devDependency) |
| `tokens` | Rebuilds the tokens and fails if the committed `dist/` differs; then the tokens tests |
| `i18n` | `npm run check:i18n`: the en/fr key sets match, and no key used in the code is missing (L10N-02) |

**Deploy** (`.github/workflows/deploy.yml` and `deploy-target.yml`) deploys to `dev`, then to `staging`, on Linode.

- **CI gates every deploy.** The owner's flow merges to `main` and pushes directly, without pull requests. So the deploy does not start on push. It starts when the CI workflow completes on `main` (`workflow_run`). It continues only if that run succeeded and came from a push, and it deploys exactly the commit CI tested (`head_sha`).
- A manual run (`workflow_dispatch`) is accepted only from `main`.
- If pull requests are adopted, also turn on branch protection for `main`, requiring the CI jobs, so that nothing reaches `main` untested.
- A `gate` job checks which environments have credentials (repository secrets `DEV_*` and `STAGING_*`, listed in the README). An environment without them is skipped with a notice, and the run still succeeds.
- Each deploy:
  1. builds the web app
  2. rsyncs `api/` and `web/dist` to the host. The host's `.env`, `storage/` and `vendor/` are never touched.
  3. on the host: `php artisan down --retry=60` with the release already there (skipped on a first deploy), so the API answers 503 until the last step. Then the rsync.
  4. deletes `bootstrap/cache/config.php` and `routes-*.php`, so the previous release's cached config is never booted by the new code (`composer install` runs `package:discover`)
  5. `composer install --no-dev`, then `php artisan config:clear`
  6. `php artisan app:preflight`: prints every readiness check (drivers, `APP_KEY`, `APP_DEBUG`, database roles) and stops the deploy when one fails
  7. `php artisan migrate --database=pgsql_owner --force`. Migrations run as the schema owner (ADR 002).
  8. `php artisan permissions:sync` (RBAC-01)
  9. config and route caches
  10. `php artisan horizon:terminate` when Horizon is installed, `queue:restart` otherwise
  11. `php artisan up`, only when every step above succeeded
- **If a deploy stops,** the API stays in maintenance (503) rather than serving new code against an un-migrated schema. Fix the cause (usually `.env`; `php artisan app:preflight` shows what is wrong), then re-run the workflow, or run the remaining steps on the host and `php artisan up`. The environment guard runs after the maintenance check, so requests get the 503 page, not the guard's error. Laravel's `/up` health route is never in maintenance and keeps reporting the guard. If the previous release cannot boot at all, `down` fails; the deploy warns and continues, since that release was not serving.
- **Environment guard (NFR-06).** Development drivers (log or array mail, log SMS, non-Redis cache or queue) are refused outside `local` and `testing` at the runtime entry points only: a global HTTP middleware, a queue worker's first loop, and the scheduler and worker commands. It never runs while the app boots. In Sprint 1 it ran in a service provider's `boot`, so `package:discover` during `composer install` threw with the previous release's cached config, and even `config:clear` could not run: one bad `.env` took the host down with no way to repair it through artisan. `app:preflight` reports the same checks without throwing.

## Consequences

- One deployable unit, one database and one transaction boundary. Cross-module consistency is easy. Module boundaries hold only by convention and review, until modules are split into their own folders with their own service providers.
- A module can later be extracted behind its events if it needs to scale on its own.
- Every PR runs the isolation suite against real PostgreSQL with real roles, never against SQLite or a superuser.
- **Pending.** Deploys are inert until the owner provisions the Linode hosts and adds the secrets. The hosts must be prepared once by hand: PHP 8.3 with `pdo_pgsql`, Composer, the web server, the `.env`, and the database roles from `api/database/scripts/create-roles.sql`.
- **Horizon is not installed yet.** Sprint 1 uses plain Redis queues. Adding `laravel/horizon` needs the owner's approval, because it is a new dependency. The deploy already uses `horizon:terminate` once Horizon is installed.
- **The rsync is not atomic.** Between the rsync and the end of `composer install`, the host serves new application code against the previous `vendor/`. Until the cached config is rebuilt, it also runs with no config cache. A request in that window can fail if a dependency changed. Moving to release directories with a symlink switch (build `vendor/` first, then swap) would close the window once the hosts exist.
- The deploy runs migrations while the old code is still serving requests. Migrations must stay backward compatible with the previous release (expand, then contract).
