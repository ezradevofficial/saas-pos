# Deploy rehearsal, 2026-10-22 (NFR-01, NFR-07)

A local, production-like run of the deploy, so problems surface before the owner provides Linode credentials. Base: `main` at `2b2aef10`; branch `chore/deploy-rehearsal`.

## What ran

Everything on one Mac, in a scratch directory, on its own ports (never the dev ports 3008 and 8008 or the dev databases). No Docker (not installed); local binaries.

| Piece | How | Stands in for |
| --- | --- | --- |
| PostgreSQL 17.11 | own cluster (`initdb`, `pg_ctl`), port 55901, TCP only, `scram-sha-256`, `max_locks_per_transaction = 256`, `max_prepared_transactions = 0` | Managed PostgreSQL |
| Cluster admin | role `rh_admin`: `NOSUPERUSER CREATEDB CREATEROLE`, run once without and once with `BYPASSRLS` | Linode's `linpostgres` |
| Redis 7 | port 56901, `requirepass`, no persistence | Redis on the staging host |
| API | `php artisan serve` (4 workers), port 8901, `APP_ENV=production`, `APP_DEBUG=false`, config and routes cached, `php.ini` overrides `display_errors = Off`, `expose_php = Off` | nginx + PHP-FPM |
| Web | `npm run build -w web` with the workflow's variables, served statically on port 3901 with the `try_files` fallback and the CSP from docs/security.md | nginx serving `<path>/web` |
| Queue | one `php artisan horizon` | the `app-horizon` systemd unit |
| Mail | `MAIL_MAILER=smtp` to a local SMTP sink writing a rehearsal file | a real mailer (`log` is refused in production) |

The deploy itself: `api/` was copied with the workflow's exact `rsync` excludes, `api/REVISION` written, and the two remote heredocs of `deploy-target.yml` were **extracted from the workflow file and run unchanged** (maintenance, then install, preflight, migrate, catalogue steps, backfill, caches, worker restart, `up`, `health:status`). First deploy, then four redeploys as fixes landed. A full deploy takes 10 to 18 s after `composer install` (135 migrations on an empty database, 35 MB).

## Results

| Check | Result |
| --- | --- |
| `app:preflight` | passes; warns: no SMS driver, no Sentry DSN |
| Migrations as `app_owner` | 135 run, then idempotent |
| Catalogue steps (`permissions:sync` … `billing:sync-modules`) | pass; the country packs print their "to confirm" lists (KE VAT_WHT rate, movable holidays) |
| `health:status` | `degraded` before Horizon runs (expected: workers elsewhere), `operational` after |
| `/health/live`, `/health/ready`, `/up` | 200; readiness: database, Redis, queue, storage, Horizon all ok |
| `/metrics` | 404 without a token and with a wrong one; 200 with `METRICS_TOKEN` (`app_horizon_masters 1`, `app_database_up 1`, every queue's size) |
| Security headers (API) | `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, data-response CSP, `X-Request-Id`; no `X-Powered-By` once `expose_php = Off`. HSTS needs HTTPS (not tested here) |
| Errors | JSON with a translated message and code; no trace or path |
| Web app | CSP header served; sign-in page and sign-in through the browser (Playwright) to onboarding, no console errors |
| CORS | preflight from the web origin allowed; another origin gets only the web origin back |
| Sign-up → code → verify → sign-in | the email code was read from the rehearsal SMTP sink; verify and sign-in returned tokens |
| POS device | device created, pairing code, paired, `GET /api/v1/sync/pull` 200 with the device token; secret activation 200 |
| Queued job | Horizon completed `RestampItemsForTax` on `default` (3 times), 0 failed |
| `pg_dump` → restore → `backup:verify` | 2.3 MB custom-format dump in 0.5 s, restore in 5 s, `backup:verify`: 343 tables, 2337 / 2337 rows, "The restore matches the source"; the runtime role sees nothing without a tenant and its own rows with one |
| `APP_KEY` rotation | no command exists on `main`. Rotated by hand: without `APP_PREVIOUS_KEYS` the old device secret gave 500 (`The MAC is invalid`); with it, 200. Sign-in unaffected |
| Logs | JSON lines, emails and tokens scrubbed (0 occurrences of the test addresses) |

## What failed, and the fix

| # | Found | Fix | Test |
| --- | --- | --- | --- |
| 1 | The documented database steps fail as a managed admin. `create-roles.sql`: *Only roles with the BYPASSRLS attribute may create roles with the BYPASSRLS attribute*. With BYPASSRLS, `CREATE DATABASE app OWNER app_owner`: *must be able to SET ROLE "app_owner"* (PostgreSQL 16+). The docs also pointed at copying a block out of `setup-local-db.sh`, which drops databases | `api/database/scripts/provision-db.sh`: checks the admin first, creates the roles with required generated passwords, joins `app_owner` only for the database and default privileges, never drops; grants shared with `setup-local-db.sh` in `database-grants.sql` | rehearsed both admin modes and a re-run; `setup-local-db.sh` re-run for the test database |
| 2 | First deploy: no `storage/` (never rsynced), so the next deploy's `php artisan down` failed (`storage/framework/down: No such file`), and compiled views had nowhere to go | both remote steps of `deploy-target.yml` create the storage skeleton | rehearsed deploys |
| 3 | Preflight said "Ready", then the first migration died: `Class "Redis" not found` (config defaulted to `phpredis`; the documented `.env` names no `REDIS_CLIENT`; the extension is optional) | default `REDIS_CLIENT` is `predis` (a dependency, what CI uses); preflight pings Redis and fails with the reason | `PreflightTest`; on the host, `REDIS_CLIENT=phpredis` and a wrong password each fail preflight |
| 4 | Horizon exited right after starting: *Memory limit exceeded: Using 76/64MB*. Under systemd it would restart every 5 s and never run a job | `horizon.memory_limit` 192, `HORIZON_MEMORY_LIMIT` | `HorizonMemoryTest` (boots the app in a subprocess, requires 50% headroom) |
| 5 | `TRUSTED_PROXIES` was read with `env()` in `bootstrap/app.php` and a provider: null once `config:cache` runs, so the NodeBalancer range was **never trusted** in a deployed environment (rate limits and the M-Pesa callback allowlist see the balancer's address) and the "empty" warning fired on every boot even when set (325 lines in this rehearsal) | `config('app.trusted_proxies')`, applied at boot (`TrustedProxies::boot`); `none` for a host reached directly, without the warning | unit and payments `TrustedProxiesTest`; on the host the warning stopped |
| 6 | First deploy: `billing:backfill-subscriptions` ran with no operator and no tenants, succeeded and wrote its once-only marker, so a tenant signing up before `platform:operator` was never backfilled (by hand later: "1 trial subscriptions started") | the command fails until the operator exists; the deploy warns and retries next time | `BackfillSubscriptionsTest` |
| 7 | `SMS_DRIVER=<any name>` passed preflight as OK while sending nothing: no SMS provider is implemented, every name but `log` binds `NullSmsSender` | preflight warns on a name the release does not implement (`SmsSender::DRIVERS`) | `PreflightTest` |
| 8 | Docs: `php artisan key:generate` cannot run before the first deploy (no `vendor/`; it also asks for confirmation in production) | docs: generate `APP_KEY`, `HR_DIGEST_KEY` and `METRICS_TOKEN` with OpenSSL | rehearsed |
| 9 | Docs: `php artisan platform:operator` without `--country` does nothing; `platform:user` needs an email | docs give the full commands | rehearsed |
| 10 | Docs: PHP's defaults leak `X-Powered-By: PHP/8.3.x` and print notices with server paths into responses | docs: `php.ini-production`, `expose_php = Off`, `display_errors = Off` | rehearsed with the overrides |
| 11 | Docs: no post-deploy check list, no logical backup path, `BACKUP_VERIFY_*` silently ignored if put in `.env` (config is cached), no tested `APP_KEY` rotation steps | docs/deploy-linode.md "After every deploy"; docs/backups.md "Logical copy"; docs/security.md "Rotating APP_KEY" | rehearsed |

Not changed, worth knowing:

- `health:status` exits 0 when only Horizon is missing (`degraded`); the deploy treats it as a warning anyway.
- There is still no bulk re-encrypt after an `APP_KEY` rotation: the old key must stay in `APP_PREVIOUS_KEYS` (docs/security.md).
- `php artisan serve` is a stand-in; PHP-FPM, nginx, TLS, HSTS, the NodeBalancer health check and Caddy were not exercised.

## Owner inputs still needed

- **Managed PostgreSQL admin with `BYPASSRLS`**: confirm with Linode that `linpostgres` has it (`select rolbypassrls from pg_roles where rolname = current_user`). Without it `app_owner` cannot be created and ADR 002's role model needs another host (self-managed PostgreSQL) — a decision for the owner.
- Whether the plan allows `max_locks_per_transaction = 256`.
- Linode account, region, hosts, VPC and firewall, deploy SSH key and `known_hosts`, the GitHub secrets and variables (docs/deploy-linode.md).
- DNS and certificates for `app.`, `api.` and the platform console; `APP_BASE_DOMAIN`, `BRANDING_CNAME_TARGET`.
- A mail provider (SMTP, SES, Postmark or Resend) and its credentials; the sending domain's SPF and DKIM.
- **An SMS provider**: none is implemented. Until one is chosen and built, phone sign-up and SMS codes fail; email works.
- M-Pesa (Daraja) and KRA eTIMS credentials and callback URL (docs/integrations.md).
- Sentry organisation, projects and DSNs; uptime and alerting service; status page.
- Object Storage: approve `league/flysystem-aws-s3-v3` (media stays on local disk until then).
- The secrets vault; backup retention and PITR window.
- Country packs: the KE `VAT_WHT` rate and the holiday lists printed as "to confirm".

## The real staging deploy, step by step

On a workstation, once:

```sh
APP_PW="$(openssl rand -base64 24)"; OWNER_PW="$(openssl rand -base64 24)"; PLATFORM_PW="$(openssl rand -base64 24)"
# keep these three in the vault
```

From a host on the cluster's access list (the staging host), with the repository checked out or `api/database/scripts/` copied:

```sh
PGHOST=<cluster private host> PGPORT=<port> PGUSER=linpostgres PGPASSWORD='<admin password>' PGSSLMODE=require \
DB_NAME=app APP_PASSWORD="$APP_PW" APP_OWNER_PASSWORD="$OWNER_PW" APP_PLATFORM_PASSWORD="$PLATFORM_PW" \
bash api/database/scripts/provision-db.sh
```

On the staging host, as the deploy user (`<path>` e.g. `/srv/platform`):

```sh
mkdir -p <path>/api <path>/web
cat > <path>/api/.env <<EOF
APP_NAME="<display name>"
APP_ENV=staging
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=https://api.<domain>
FRONTEND_URL=https://app.<domain>
CORS_ALLOWED_ORIGINS=https://app.<domain>
PLATFORM_URL=https://console.<domain>
APP_BASE_DOMAIN=<domain>
DB_CONNECTION=pgsql
DB_HOST=<cluster private host>
DB_PORT=<port>
DB_DATABASE=app
DB_SSLMODE=require
DB_USERNAME=app
DB_PASSWORD=$APP_PW
DB_OWNER_USERNAME=app_owner
DB_OWNER_PASSWORD=$OWNER_PW
DB_PLATFORM_USERNAME=app_platform
DB_PLATFORM_PASSWORD=$PLATFORM_PW
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=<redis requirepass>
MAIL_MAILER=<smtp|ses|postmark|resend>
MAIL_FROM_ADDRESS=<no-reply@domain>
# mail credentials …
HR_DIGEST_KEY=base64:$(openssl rand -base64 32)
TRUSTED_PROXIES=none
LOG_STACK=json
LOG_LEVEL=info
METRICS_TOKEN=$(openssl rand -hex 32)
EOF
chmod 600 <path>/api/.env
```

Then the systemd units (README, Queue workers), nginx (docs/security.md) with PHP-FPM using `php.ini-production`, `expose_php = Off`. In GitHub: `STAGING_LINODE_HOST`, `_USER`, `_SSH_KEY`, `_KNOWN_HOSTS`, `STAGING_DEPLOY_PATH` secrets and the `STAGING_API_URL` variable; run the Deploy workflow by hand from `main`. Expect two warnings: `billing:backfill-subscriptions` ("No operator tenant yet") and possibly `health:status` until Horizon is up. Then on the host:

```sh
cd <path>/api
sudo systemctl enable --now app-horizon app-scheduler
php artisan platform:operator --country=KE --country=CD
php artisan platform:user <your email> --role=owner
php artisan billing:backfill-subscriptions
php artisan app:preflight
php artisan horizon:status
php artisan health:status
curl -fsS https://api.<domain>/health/ready
curl -s -o /dev/null -w '%{http_code}\n' https://api.<domain>/metrics      # 403 or 404
```

and from the metrics scraper's private address (docs/observability.md), `/metrics` with the bearer token shows `app_horizon_masters 1`.

Then sign up through `https://app.<domain>` with a real mailbox, pair a POS device, and restore-test once (docs/backups.md, Logical copy).
