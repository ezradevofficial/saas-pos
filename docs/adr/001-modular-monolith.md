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
| `api` | Creates the roles and databases on a `postgres:16` service as the `postgres` superuser (ADR 002), runs `composer migrate` as the schema owner, `pint --test`, and every test except the Isolation suite |
| `isolation` | Same database setup, then the tenant-isolation suite (`php artisan test --testsuite=Isolation`). It is a separate job so an isolation failure is visible on its own. |
| `web` | `oxlint`, Vitest, and the production build |
| `pos` | Jest and `expo-doctor` |
| `tokens` | Rebuilds the tokens and fails if the committed `dist/` differs; then the tokens tests |
| `i18n` | `npm run check:i18n`: the en/fr key sets match, and no key used in the code is missing (L10N-02) |

**Deploy** (`.github/workflows/deploy.yml` and `deploy-target.yml`) runs on push to `main` and by hand. It deploys to `dev`, then to `staging`, on Linode.

- A `gate` job checks which environments have credentials (repository secrets `DEV_*` and `STAGING_*`, listed in the README). An environment without them is skipped with a notice, and the run still succeeds.
- Each deploy:
  1. builds the web app
  2. rsyncs `api/` and `web/dist` to the host. The host's `.env`, `storage/` and `vendor/` are never touched.
  3. on the host: `composer install --no-dev`
  4. `php artisan migrate --database=pgsql_owner --force`. Migrations run as the schema owner (ADR 002).
  5. `php artisan permissions:sync` (RBAC-01)
  6. config and route caches
  7. `php artisan horizon:terminate` when Horizon is installed, `queue:restart` otherwise

## Consequences

- One deployable unit, one database and one transaction boundary. Cross-module consistency is easy. Module boundaries hold only by convention and review, until modules are split into their own folders with their own service providers.
- A module can later be extracted behind its events if it needs to scale on its own.
- Every PR runs the isolation suite against real PostgreSQL with real roles, never against SQLite or a superuser.
- **Pending.** Deploys are inert until the owner provisions the Linode hosts and adds the secrets. The hosts must be prepared once by hand: PHP 8.3 with `pdo_pgsql`, Composer, the web server, the `.env`, and the database roles from `api/database/scripts/create-roles.sql`.
- **Horizon is not installed yet.** Sprint 1 uses plain Redis queues. Adding `laravel/horizon` needs the owner's approval, because it is a new dependency. The deploy already uses `horizon:terminate` once Horizon is installed.
- The deploy runs migrations while the old code is still serving requests. Migrations must stay backward compatible with the previous release (expand, then contract).
