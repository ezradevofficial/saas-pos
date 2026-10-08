# Platform

A multi-tenant business platform: a point of sale at its core, with business modules on a shared core (tenancy, identity, roles and permissions, audit, localisation). The display name comes from configuration: `APP_NAME` for the API, `VITE_APP_NAME` for web and `EXPO_PUBLIC_APP_NAME` for the POS.

| Path | What it holds |
| --- | --- |
| `api/` | Laravel 13 API (PHP 8.3, PostgreSQL 16, Redis), core in `app/Core` |
| `web/` | React web app (JavaScript, Vite, Tailwind 4, shadcn/ui) |
| `pos/` | Expo / React Native POS and mobile app (NativeWind 4) |
| `packages/tokens` | Design tokens compiled for web and POS |
| `docs/` | Specs (`concept-note.md`, `platform-core-spec.md`) and ADRs (`docs/adr/`) |
| `design/` | `tokens.json` |

Rules for contributors are in `CLAUDE.md`.

## Prerequisites

- PHP 8.3+ with `pdo_pgsql`, and Composer 2
- PostgreSQL 16+ and Redis 7. Install them locally, or run `docker compose up -d`.
- Node 24 and npm
- For the POS on a device: the Expo tooling (`npx expo`) and Android Studio or Xcode for a development build

## One-time setup

### 1. Database roles and databases

The API uses two roles (ADR 002):

- `app_owner` owns the schema and runs migrations.
- `app` is the runtime role. It is never a superuser and never bypasses row-level security.

Run the script as a PostgreSQL superuser:

```sh
# Local PostgreSQL where your OS user is a superuser:
bash api/database/scripts/setup-local-db.sh

# docker compose (superuser postgres/postgres):
PGSUPERUSER=postgres PGPASSWORD=postgres bash api/database/scripts/setup-local-db.sh
```

The script:

- creates the roles (`api/database/scripts/create-roles.sql`)
- drops and recreates the `app` and `app_test` databases, owned by `app_owner`
- grants `app` only data access

It accepts `PGHOST`, `PGPORT`, `PGSUPERUSER` (or `PGUSER`), `PGPASSWORD` and `DATABASES` (default `"app app_test"`). CI runs the same script.

If you used an earlier `docker-compose.yml` that made `app` the container superuser, run `docker compose down -v` first. A superuser `app` bypasses row-level security, and `HealthTest` fails.

### 2. API

```sh
cd api
composer setup          # install, .env from .env.example, key, migrate as app_owner, sync permissions
```

To rebuild the database from scratch later:

```sh
composer migrate:fresh  # as app_owner, seeds the permission and currency catalogues
```

Always migrate through `composer migrate` or `composer migrate:fresh`. Never run a plain `php artisan migrate`: it runs as the runtime role, which cannot create tables.

### 3. JavaScript workspaces

```sh
npm install                      # at the repository root: web, pos, packages/tokens
cp web/.env.example web/.env
cp pos/.env.example pos/.env
```

## Running locally

| What | Command | Port |
| --- | --- | --- |
| API | `npm run dev:api` (or `cd api && php artisan serve --port=8008`) | 8008 |
| Queue worker (codes, notifications) | `cd api && php artisan queue:work` | |
| Web app | `npm run dev:web` (`strictPort`) | 3008 |
| POS web preview (Playwright checks) | `npm run web -w pos` | 3009 |
| POS on a device or simulator (Expo) | `npm run dev:pos`, then `a` (Android) or `i` (iOS) | |

Mail and SMS use the `log` driver locally (SMS falls back to the log only in `local` and `testing`). Verification codes appear in `api/storage/logs/laravel.log`. Outside `local` and `testing` these drivers are refused by every request, queue worker and the scheduler (see the pre-deploy checklist).

## Tests

Run the tests you touched while working. CI runs everything.

| Package | Focused | Full |
| --- | --- | --- |
| API | `cd api && php artisan test --filter=ScopeResolverTest` or `php artisan test tests/Feature/Core/Rbac/ScopeResolverTest.php` | `php artisan test --exclude-testsuite=Isolation` |
| API, isolation suite (TEN-01) | | `cd api && php artisan test --testsuite=Isolation` |
| API, code style | | `cd api && vendor/bin/pint --test` |
| Web | `cd web && npx vitest run src/lib/money.test.js` | `cd web && npx vitest run`, `npm run lint -w web`, `npm run build -w web` |
| POS | `cd pos && npx jest src/lib/money.test.js` | `cd pos && npx jest`, `npm run doctor -w pos` |
| Tokens | | `npm run build -w @app/tokens && npm test -w @app/tokens` |
| Translations (L10N-02) | | `npm run check:i18n` |

- The API tests run against the `app_test` database as role `app`, never SQLite.
- The Isolation suite seeds two tenants and proves that nothing of tenant B is visible to tenant A. It covers every table, every route, lists and exports.
- After changing `design/tokens.json` or the tokens build, run `npm run build -w @app/tokens` and commit `packages/tokens/dist`.

## Tenant settings

Each tenant sets its own password minimum (AUTH-02), session idle timeout (AUTH-09) and default language (L10N-01):

| Endpoint | Body | Who |
| --- | --- | --- |
| `GET /api/v1/tenant/settings` | | `core.settings.edit` at tenant scope |
| `PATCH /api/v1/tenant/settings` | `password_min_length` 8 to 64, `session_timeout_minutes` 15 to 480, `default_locale` `en` or `fr` (each optional) | `core.settings.edit` at tenant scope |

Changes are audited as `core.settings.update` with the changed values before and after. In the web app: Settings, Security.

## CI

`.github/workflows/ci.yml` runs on every pull request and on every push to `main`. A newer push cancels the run it supersedes. The jobs:

| Job | What it runs |
| --- | --- |
| `api (tests)` | PostgreSQL 16 and Redis 7 services, then: roles and databases set up by the script above as `postgres`, `composer migrate`, `pint --test`, and the tests without the Isolation suite |
| `api (isolation)` | Same setup (same job, second matrix entry), then only the Isolation suite |
| `web` | `oxlint`, Vitest, and the production build |
| `pos` | Jest and `expo-doctor` (`npm run doctor -w pos`, version pinned) |
| `tokens` | Rebuild, then fail if the committed `dist/` differs; then the tokens tests |
| `i18n` | Missing or untranslated keys |

A PR does not merge unless every job passes.

## Deploy

`.github/workflows/deploy.yml` deploys `main` to **dev**, then to **staging**, on Linode.

- **CI gates the deploy.** Changes reach `main` by a direct push, so the deploy starts only when the CI workflow finishes on `main`, and only if CI succeeded. It deploys the exact commit CI tested.
- It can also be run by hand from `main` (Actions, Deploy, Run workflow).
- If pull requests are adopted, protect `main` with the CI jobs as required checks.

An environment deploys only when all of its settings below exist. Otherwise its job is skipped with a notice, and the run still succeeds.

Repository **secrets**, for `<ENV>` = `DEV` or `STAGING`:

| Secret | Value |
| --- | --- |
| `<ENV>_LINODE_HOST` | Host name or IP of the web/API server |
| `<ENV>_LINODE_USER` | SSH user that owns the deploy path |
| `<ENV>_LINODE_SSH_KEY` | Private key for that user (deploy key, no passphrase) |
| `<ENV>_LINODE_KNOWN_HOSTS` | The host's `known_hosts` line(s), from `ssh-keyscan <host>` checked against the console |
| `<ENV>_DEPLOY_PATH` | Absolute path on the host, e.g. `/srv/platform` |

Repository **variables**:

| Variable | Value |
| --- | --- |
| `<ENV>_API_URL` | Public API URL baked into the web build (required) |
| `APP_NAME` | Display name for the web build (optional, default `App`) |

Prepare each host once:

- PHP 8.3 with `pdo_pgsql`, Composer, and a web server that serves:
  - `<path>/web` as the web app
  - `<path>/api/public` as the API
- `<path>/api/.env`:
  - `DB_USERNAME=app`, plus the `DB_OWNER_*` owner credentials
  - `APP_ENV`, `APP_KEY`, Redis and mail settings
- The database roles from `api/database/scripts/create-roles.sql`, with strong passwords set via `ALTER ROLE`
- Connect directly or through **session** pooling only. The tenant setting is session-level, so transaction pooling would leak it (ADR 002).

### Pre-deploy checklist

Outside `local` and `testing`, the API refuses to serve with development drivers (`App\Core\Support\EnvironmentGuard`, NFR-06): every HTTP request, a queue worker's first loop, and `schedule:run`, `schedule:work`, `queue:work` and `queue:listen` fail with the list of problems. Bootstrap and deploy commands (`composer install`'s `package:discover`, `config:*`, `optimize*`, `down`, `up`) never check, so a host with a bad config can always be repaired.

`php artisan app:preflight` prints every check (mail transport, SMS driver, cache store, queue connection, `APP_KEY`, `APP_DEBUG`, and that the runtime database role is neither superuser nor BYPASSRLS and the owner is not a superuser) and exits 1 when one fails. The deploy runs it before migrating. Run it on the host after editing `.env`.

Before the first deploy of an environment, set in `<path>/api/.env`:

- [ ] `APP_ENV` (e.g. `dev`, `staging`, `production`), `APP_KEY`, `APP_DEBUG=false`, `APP_URL`
- [ ] `MAIL_MAILER` a real mailer (`smtp`, `ses`, `postmark`, `resend`), never `log` or `array`, with its credentials and `MAIL_FROM_ADDRESS`
- [ ] `SMS_DRIVER` a real provider, never `log`. Without one, any text message (phone sign-up, SMS codes) fails with `SmsNotConfigured`.
- [ ] `NOTIFICATIONS_PUSH_DRIVER`, `NOTIFICATIONS_SMS_DRIVER`, `NOTIFICATIONS_WHATSAPP_DRIVER` empty (channel unavailable), `none` or a real provider, never `fake`.
- [ ] `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` (the defaults), with `REDIS_*`
- [ ] `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS`: the web app's origin(s), comma-separated
- [ ] `DB_USERNAME=app` (runtime role) and `DB_OWNER_*` (migrations, `permissions:sync`, `currencies:sync` and `country-packs:publish`)
- [ ] a queue worker running (`php artisan queue:work` or Horizon)
- [ ] item images (MD-02): `MEDIA_DISK_DRIVER=s3` with a private Linode Object Storage bucket, see [Media storage](#media-storage). Without it, images are kept under `api/storage/app/media` on the web server.

Each deploy:

1. builds the web app
2. puts the API in maintenance (`php artisan down --retry=60`, with the release already on the host; skipped on a first deploy). Requests get 503 until the last step.
3. rsyncs `api/` and `web/dist`. The host's `.env`, `storage/` and `vendor/` are kept.
4. deletes the previous release's `bootstrap/cache/config.php` and `routes-*.php`, so nothing boots with them
5. runs `composer install --no-dev`, then `php artisan config:clear`
6. runs `php artisan app:preflight`; the deploy stops here if a check fails
7. runs `php artisan migrate --database=pgsql_owner --force`
8. runs `php artisan permissions:sync` (as the owner): upserts the permission catalogue and refreshes every tenant's system roles, each on the runtime connection under row-level security (ADR 006)
9. runs `php artisan currencies:sync` (as the owner): upserts the ISO 4217 currency catalogue from ICU (CDF overridden to 0 decimals), then gives each tenant's companies their country's currencies where missing, under row-level security (ADR 003)
10. runs `php artisan country-packs:publish KE` and `CD` (as the owner): loads `api/country-packs/{KE,CD}/pack.json` as a new pack version when the content changed, a no-op otherwise (CP-01, CP-03). New companies get the pack's tax codes; existing ones add missing codes with `POST companies/{company}/tax-codes/apply-pack`
11. runs `php artisan uoms:seed-defaults`: gives every tenant that lacks them the default units of measure (EA, KG, G, L, ML, M, BOX, PACK), under row-level security (MD-02). New tenants get them at sign-up.
12. runs `php artisan payment-methods:seed-defaults`: gives every active company the default payment methods it never had (cash in each of its country's currencies, the country's mobile money wallets and a card method, the last two switched off until configured), under row-level security (MD-04). An entry a company archived is never added again. New companies get them when created.
13. caches config and routes
14. restarts the workers: `horizon:terminate` when Horizon is installed, `queue:restart` otherwise
15. `php artisan up`, only when every step above succeeded

#### Media storage

Item images (MD-02) live on the `media` disk, at `tenants/{tenant}/items/{item}/{uuid}.{ext}`. They are never served from a public path. The API returns temporary URLs, valid 15 minutes.

- **Local driver** (default, development and tests): files under `api/storage/app/media`. URLs point to the signed route `GET /api/v1/media/{path}`, which checks the tenant and that the user may still view the item.
- **Linode Object Storage** (production): an S3-compatible bucket, private (no public ACL). URLs are presigned by the object store. This needs the `league/flysystem-aws-s3-v3` package, which is not installed yet. Add it before switching the driver. Set in `<path>/api/.env`:

| Variable | Value |
| --- | --- |
| `MEDIA_DISK_DRIVER` | `s3` |
| `MEDIA_ACCESS_KEY_ID` / `MEDIA_SECRET_ACCESS_KEY` | An Object Storage access key limited to the bucket (read/write) |
| `MEDIA_REGION` | The cluster's region, e.g. `eu-central-1` or `us-east-1` |
| `MEDIA_ENDPOINT` | The cluster endpoint, e.g. `https://eu-central-1.linodeobjects.com` |
| `MEDIA_BUCKET` | The bucket name |
| `MEDIA_USE_PATH_STYLE_ENDPOINT` | `false` (Linode supports virtual-hosted style) |

#### If a deploy stops

A failed step (most often `app:preflight` or a migration) leaves the API in maintenance: it answers 503, never new code against an old schema. `/up` (the health route) is never in maintenance; when drivers are wrong it still reports the guard's error. To recover:

1. Read the failed step's log in the Actions run (preflight prints every check).
2. Fix the cause on the host, usually `<path>/api/.env`, and check it with `php artisan app:preflight`.
3. Re-run the workflow. Or, on the host, run the remaining steps above in order, then `php artisan up`.

Only requests, queue workers and the scheduler run the environment guard. Every other artisan command works on a misconfigured host.

## Architecture decisions

- [001 Modular monolith, CI and deploy](docs/adr/001-modular-monolith.md)
- [002 Tenant isolation with row-level security](docs/adr/002-tenancy-rls.md)
- [003 Money and currency](docs/adr/003-money-and-currency.md)
- [004 Offline POS and sync](docs/adr/004-offline-sync.md)
- [005 Styling across web and POS](docs/adr/005-styling.md)
- [006 Roles and permissions](docs/adr/006-rbac.md)

Specs: [concept note](docs/concept-note.md), [platform core spec](docs/platform-core-spec.md).
