# Deploying on Linode (NFR-01, NFR-07)

How to provision an environment (dev, staging, production) on Linode (Akamai Cloud) for the existing deploy workflow (`.github/workflows/deploy.yml` → `deploy-target.yml`). The README's "Deploy" section describes each deploy step; this file is the one-time setup. Nothing here has been run: it needs the owner's Linode account and credentials.

## Layout

| Piece | Staging | Production |
| --- | --- | --- |
| Web and API host | 1 × Dedicated 4 GB (nginx, PHP 8.3-FPM, the built web app) | 2+ × Dedicated 8 GB behind a NodeBalancer |
| Queue and scheduler host | the same host | 1+ × Dedicated 4 GB (Horizon, `schedule:work`) |
| Database | Managed PostgreSQL 16, 1 node | Managed PostgreSQL 16, 3 nodes (high availability) |
| Redis | on the host (Redis 7, local only) | its own 2 GB instance on the private network |
| Files | Object Storage bucket (private) | Object Storage bucket (private, versioned) + a replica bucket in another region |
| Custom domains (BR-05) | Caddy on the web host | Caddy on the web hosts |

All in one region close to the users (for Kenya and the DRC: the nearest Linode region with Managed PostgreSQL; record which), on a VPC/private network. Only 80/443 (and 22 from the deploy runner and admins) are open publicly; PostgreSQL and Redis listen on the private network only.

## Provisioning steps

1. **Account**: create an API token for the owner (not committed), a team user per admin with 2FA.
2. **Network**: a VPC in the chosen region; a Cloud Firewall allowing 80, 443 from anywhere and 22 from admin addresses and GitHub's runner ranges (or use a bastion).
3. **Database**: create the Managed PostgreSQL cluster (version 16, HA for production), with daily backups, PITR and the access list limited to the hosts' private addresses (docs/backups.md). Then, as its admin user, run `api/database/scripts/create-roles.sql` and set strong passwords:
   ```sql
   ALTER ROLE app_owner PASSWORD '…'; ALTER ROLE app PASSWORD '…'; ALTER ROLE app_platform PASSWORD '…';
   ```
   create the database owned by `app_owner` with the grants from `setup-local-db.sh` (the `for db` block), and raise `max_locks_per_transaction` to 256 if the plan allows it (full exports).
4. **Hosts**: Ubuntu 24.04 LTS, disk encryption on (docs/security.md). Install nginx, PHP 8.3 with `pdo_pgsql`, `pgsql`, `intl`, `mbstring`, `bcmath`, `pcntl`, `posix`, `redis` (or predis), `zip`, `gd`; Composer; Redis 7 (staging); certbot. Create the deploy user and `<path>` (e.g. `/srv/platform`), owned by it.
5. **Web server**: the nginx config in docs/security.md (TLS 1.2+, CSP, `/metrics` and `/api/v1/tls/ask` denied publicly). Certificates with certbot (`app.` and `api.` names) or on the NodeBalancer. Caddy for custom domains per docs/integrations.md.
6. **NodeBalancer** (production): HTTPS or TCP on 443 to each web host; health check `GET /health/ready` (HTTP, expect 200, every 10 s, 3 failures); `TRUSTED_PROXIES` set to its backend range `192.168.255.0/24`.
7. **Object Storage**: a private bucket per environment, an access key limited to it, versioning on (production); `MEDIA_DISK_DRIVER=s3` needs `league/flysystem-aws-s3-v3`, which is not installed yet (README, Media storage) — an owner decision before production.
8. **`.env`** on each host from the list below; `php artisan key:generate` once; `chmod 600 .env`.
9. **Workers**: the systemd units in the README ("Queue workers") for Horizon and the scheduler. Horizon runs one supervisor per queue (`api/config/horizon.php`): `default`, `notifications`, `automation`, `payments`, `fiscal`, `exports`, `imports`, `webhooks`. Check after the first deploy: `php artisan horizon:status` and `/metrics` `app_horizon_masters 1`.
10. **GitHub**: repository secrets and variables below; protect the `staging` and `production` environments with required reviewers.
11. **First deploy**: run the Deploy workflow by hand. Then on the host: `php artisan platform:operator` (the operator tenant, ADR 016) and `php artisan platform:user` (the first staff account); the next deploy runs `billing:backfill-subscriptions` once (or run it by hand).
12. **Monitoring**: the uptime monitor on `/health/ready`, the scrape of `/metrics` from the private network, the alert rules (docs/observability.md); a status page (docs/hardening.md).

## Environment variables (`<path>/api/.env`)

Required everywhere (`app:preflight` fails without them outside local and testing):

| Variable | Value |
| --- | --- |
| `APP_ENV` | `dev`, `staging` or `production` |
| `APP_KEY` | `php artisan key:generate` |
| `APP_DEBUG` | `false` |
| `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `PLATFORM_URL`, `APP_BASE_DOMAIN` | the environment's hosts |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME=app`, `DB_PASSWORD` | runtime role (RLS-bound) |
| `DB_OWNER_*` | `app_owner`; only on the host the deploy runs on |
| `DB_PLATFORM_*` | `app_platform` (ADR 013) |
| `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_HOST`, `REDIS_PASSWORD` | Redis |
| `MAIL_MAILER` (a real mailer), `MAIL_FROM_ADDRESS` and the mailer's credentials | never `log` or `array` |
| `SMS_DRIVER` and its credentials | never `log` |
| `HR_DIGEST_KEY` | a random 32-byte key, `base64:…`, separate from `APP_KEY` (docs/modules/hr.md) |
| `TRUSTED_PROXIES` | the NodeBalancer's backend range, or empty when reached directly |
| `PAYMENTS_CALLBACK_URL`, `MPESA_*`, `ETIMS_*` | docs/integrations.md |

Must **not** be set in production: `PAYROLL_PACK_DIR` (test statutory packs; allowed on staging only, and the guard refuses it elsewhere), `PAYMENTS_ALLOW_FAKE`, `PAYMENTS_DRIVER_*=fake`, `BILLING_DRIVER_*=fake`, `FISCAL_ALLOW_FAKE`, `NOTIFICATIONS_*_DRIVER=fake`.

Observability (warned by `app:preflight` when missing, never fatal):

| Variable | Value |
| --- | --- |
| `LOG_STACK` | `json` (file) or `json_stderr` (journal); `LOG_LEVEL=info` |
| `SENTRY_LARAVEL_DSN` | the API project's DSN (owner) |
| `SENTRY_TRACES_SAMPLE_RATE` | unset, or e.g. `0.05` |
| `METRICS_TOKEN` (required in production) and `METRICS_ALLOW` | a random token for the scraper, and the scraper's own private address (e.g. `192.168.140.25/32`; never `192.168.128.0/17`, which contains the NodeBalancer range). Requests through the NodeBalancer or `TRUSTED_PROXIES` are always refused |
| `HEALTH_REQUIRE_HORIZON` | `true` only where Horizon runs on the same host and should gate readiness |
| `STATUS_DISK`, `STATUS_PATH` | where `health:status` writes `status.json` (default the local disk) |
| `PRIVACY_DEADLINE_DAYS_KE`, `_CD` | once counsel confirms them (docs/privacy.md) |

## GitHub settings

| Kind | Name | Value |
| --- | --- | --- |
| secret | `<ENV>_LINODE_HOST`, `_USER`, `_SSH_KEY`, `_KNOWN_HOSTS`, `<ENV>_DEPLOY_PATH` | README, Deploy |
| secret | `SENTRY_AUTH_TOKEN` | optional: uploads web source maps |
| variable | `<ENV>_API_URL`, `APP_NAME` | README, Deploy |
| variable | `SENTRY_WEB_DSN`, `SENTRY_ORG`, `SENTRY_WEB_PROJECT`, `SENTRY_URL` | optional: web error tracking (per environment) |

## What each deploy runs (stage 8C additions)

Besides the README's steps: the web build carries the commit as its Sentry release (and uploads hidden source maps only when `SENTRY_AUTH_TOKEN` is set, deleting them before the files go live); `api/REVISION` records the commit for the API's Sentry release; migrations run as the owner, then `permissions:sync`, the catalogue steps, `billing:sync-modules`, and `billing:backfill-subscriptions` once per environment (a marker in `storage/app/deploy/`; a failure warns and retries on the next deploy instead of keeping the API down); after `up`, `health:status` checks readiness from the host.

## Owner inputs

- Linode account, API token, region choice, and the deploy SSH key and `known_hosts` per environment.
- Managed PostgreSQL plan, HA for production, backup retention and PITR window (docs/backups.md).
- Sentry: organisation, three projects (API, web, POS), their DSNs and an auth token for source maps.
- Status page supplier (docs/hardening.md) and the uptime and alerting service (docs/observability.md).
- The secrets vault (docs/security.md) and the penetration-test firm.
- Object Storage for media: approve `league/flysystem-aws-s3-v3`.
- Legal confirmation of data subject request deadlines and retention periods (docs/privacy.md).
