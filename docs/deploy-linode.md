# Deploying on Linode (NFR-01, NFR-07)

How to provision an environment (dev, staging, production) on Linode (Akamai Cloud) for the existing deploy workflow (`.github/workflows/deploy.yml` → `deploy-target.yml`). The README's "Deploy" section describes each deploy step; this file is the one-time setup. It has not been run on Linode yet (it needs the owner's account), but every step after provisioning was rehearsed on a local production-like stack on 2026-10-22 and corrected from what it found: [deploy-rehearsal-2026-10-22.md](deploy-rehearsal-2026-10-22.md), which also lists the exact staging commands.

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
3. **Database**: create the Managed PostgreSQL cluster (version 16, HA for production), with daily backups, PITR and the access list limited to the hosts' private addresses (docs/backups.md). Raise `max_locks_per_transaction` to 256 if the plan allows it (full exports) and keep `max_prepared_transactions` at 0 (ADR 004). Then, from a host on the access list, run `provision-db.sh` as the cluster's admin user:
   ```sh
   PGHOST=<cluster private host> PGPORT=<port> PGUSER=<admin> PGPASSWORD='<admin password>' PGSSLMODE=require \
   DB_NAME=app APP_PASSWORD="$(openssl rand -base64 24)" APP_OWNER_PASSWORD="$(openssl rand -base64 24)" \
   APP_PLATFORM_PASSWORD="$(openssl rand -base64 24)" bash api/database/scripts/provision-db.sh
   ```
   (export the three passwords first and keep them: they go into `.env` and the vault). It creates the roles `app_owner` (NOSUPERUSER BYPASSRLS), `app` and `app_platform`, the database owned by `app_owner` and its grants and default privileges (`database-grants.sql`, shared with `setup-local-db.sh`), and never drops anything, so it is safe to run again. Never run `setup-local-db.sh` on a real environment: it drops the databases.
   - **The admin user must have `BYPASSRLS`** (or be a superuser): PostgreSQL 16+ lets only such a role create `app_owner`, which needs it (ADR 002). The script stops with a clear message otherwise. **Owner: confirm with Linode that the Managed PostgreSQL admin (`linpostgres`) has `BYPASSRLS`** (`select rolbypassrls from pg_roles where rolname = current_user`) before choosing the plan. If it does not, the options are a self-managed PostgreSQL on its own Linode (encrypted volume, docs/security.md) or a support request; ask before changing ADR 002.
   - A non-superuser admin is made a member of `app_owner` for `CREATE DATABASE … OWNER app_owner` and `ALTER DEFAULT PRIVILEGES` (PostgreSQL 16+ requires it), then removed again.
4. **Hosts**: Ubuntu 24.04 LTS, disk encryption on (docs/security.md). Install nginx, PHP 8.3 (FPM and CLI) with `pdo_pgsql`, `pgsql`, `intl`, `mbstring`, `bcmath`, `pcntl`, `posix`, `zip`, `gd`; Composer; Redis 7 with `requirepass` (staging); certbot. The API talks to Redis through predis (a Composer dependency, the default `REDIS_CLIENT`); the `redis` extension is optional and then needs `REDIS_CLIENT=phpredis`. Use `php.ini-production` for FPM and CLI and set `expose_php = Off` (otherwise every response carries `X-Powered-By: PHP/8.3.x`) and `display_errors = Off` (PHP notices otherwise reach response bodies with server paths). Create the deploy user and `<path>` (e.g. `/srv/platform`) and `<path>/api`, owned by it. The deploy creates `storage/` and its skeleton itself (it is never rsynced); FPM must run as the deploy user, or in its group with `storage/` and `bootstrap/cache/` group-writable.
5. **Web server**: the nginx config in docs/security.md (TLS 1.2+, CSP, `/metrics` and `/api/v1/tls/ask` denied publicly). Certificates with certbot (`app.` and `api.` names) or on the NodeBalancer. Caddy for custom domains per docs/integrations.md.
6. **NodeBalancer** (production): HTTPS or TCP on 443 to each web host; health check `GET /health/ready` (HTTP, expect 200, every 10 s, 3 failures); `TRUSTED_PROXIES` set to its backend range `192.168.255.0/24`.
7. **Object Storage**: a private bucket per environment, an access key limited to it, versioning on (production). Set `MEDIA_DISK=s3` and the `AWS_*` variables; see [Object Storage](#object-storage) below.
8. **`.env`** at `<path>/api/.env` on each host from the list below, before the first deploy (the deploy never writes it, and its preflight fails without it); `chmod 600 .env`. `php artisan key:generate` cannot run yet (no `vendor/` before the first deploy, and it asks for confirmation in production), so generate both keys with OpenSSL:
   ```sh
   echo "APP_KEY=base64:$(openssl rand -base64 32)"
   echo "HR_DIGEST_KEY=base64:$(openssl rand -base64 32)"
   echo "METRICS_TOKEN=$(openssl rand -hex 32)"
   ```
   Every host of one environment shares the same `APP_KEY` and `HR_DIGEST_KEY`.
9. **Workers**: the systemd units in the README ("Queue workers") for Horizon and the scheduler. Horizon runs one supervisor per queue (`api/config/horizon.php`): `default`, `notifications`, `automation`, `payments`, `fiscal`, `exports`, `imports`, `webhooks`. The master's memory limit is `HORIZON_MEMORY_LIMIT` (default 192 MB; the booted app alone uses about 65 MB). Check after the first deploy: `php artisan horizon:status` and `/metrics` `app_horizon_masters 1`.
10. **GitHub**: repository secrets and variables below; protect the `staging` and `production` environments with required reviewers.
11. **First deploy**: run the Deploy workflow by hand. Its `billing:backfill-subscriptions` step warns "No operator tenant yet": expected. Then on the host, before anyone signs up:
    ```sh
    php artisan platform:operator --country=KE --country=CD   # the operator tenant (ADR 016); --country is required
    php artisan platform:user <email> --role=owner            # the first staff account; prints a one-time setup link (24 h)
    php artisan billing:backfill-subscriptions                # idempotent; the next deploy runs it once more and records it
    ```
    Then the checks under "After every deploy" below.
12. **Monitoring**: the uptime monitor on `/health/ready`, the scrape of `/metrics` from the private network, the alert rules (docs/observability.md); a status page (docs/hardening.md).

## After every deploy

From the host (or the runner, for the public ones):

```sh
php artisan health:status            # exit 0; "degraded" only when Horizon runs elsewhere
php artisan horizon:status           # on the queue host
curl -fsS https://api.<domain>/health/live
curl -fsS https://api.<domain>/health/ready     # 200 and every check "ok"
curl -s -o /dev/null -w '%{http_code}\n' https://api.<domain>/metrics            # 404 (403 from nginx)
curl -sI https://api.<domain>/health/live | grep -iE 'x-content-type|x-frame|content-security|strict-transport|x-powered'   # no X-Powered-By
curl -sI https://app.<domain>/ | grep -i content-security-policy
```

## Environment variables (`<path>/api/.env`)

Required everywhere (`app:preflight` fails without them outside local and testing):

| Variable | Value |
| --- | --- |
| `APP_ENV` | `dev`, `staging` or `production` |
| `APP_KEY` | `base64:` and 32 random bytes (`openssl rand -base64 32`), see step 8; rotation in docs/security.md |
| `APP_DEBUG` | `false` |
| `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `PLATFORM_URL`, `APP_BASE_DOMAIN` | the environment's hosts |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME=app`, `DB_PASSWORD` | runtime role (RLS-bound) |
| `DB_OWNER_*` | `app_owner`; only on the host the deploy runs on. `DB_OWNER_LOCK_TIMEOUT` (default `5s`) is how long a migration waits for a table lock (below) |
| `DB_PLATFORM_*` | `app_platform` (ADR 013) |
| `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Redis; `app:preflight` pings it. `REDIS_CLIENT` defaults to `predis` (set `phpredis` only where the extension is installed) |
| `MAIL_MAILER` (a real mailer), `MAIL_FROM_ADDRESS` and the mailer's credentials | never `log` or `array` |
| `SMS_DRIVER` and its credentials | never `log`. **No SMS provider is implemented yet**: leave it unset until one is (owner input); phone sign-up and SMS codes fail with `SmsNotConfigured`, email works. Preflight warns on an unset or unknown name |
| `HR_DIGEST_KEY` | a random 32-byte key, `base64:…`, separate from `APP_KEY` (docs/modules/hr.md) |
| `TRUSTED_PROXIES` | the NodeBalancer's backend range `192.168.255.0/24`, or `none` when reached directly (staging on one host); empty logs a warning on every boot |
| `PAYMENTS_CALLBACK_URL`, `MPESA_*`, `ETIMS_*` | docs/integrations.md |

Must **not** be set in production: `PAYROLL_PACK_DIR` (test statutory packs; allowed on staging only, and the guard refuses it elsewhere), `PAYMENTS_ALLOW_FAKE`, `PAYMENTS_DRIVER_*=fake`, `BILLING_DRIVER_*=fake`, `FISCAL_ALLOW_FAKE`, `NOTIFICATIONS_*_DRIVER=fake`.

Observability (warned by `app:preflight` when missing, never fatal):

| Variable | Value |
| --- | --- |
| `LOG_STACK` | `json` (file) or `json_stderr` (journal); `LOG_LEVEL=info` |
| `SENTRY_LARAVEL_DSN` | the API project's DSN (owner) |
| `SENTRY_TRACES_SAMPLE_RATE` | unset, or e.g. `0.05` |
| `METRICS_TOKEN` (required in production) and `METRICS_ALLOW` | a random token for the scraper, and the scraper's own private address (e.g. `192.168.140.25/32`; never `192.168.128.0/17`, which contains the NodeBalancer range). Requests through the NodeBalancer or `TRUSTED_PROXIES` are always refused |
| `HORIZON_MEMORY_LIMIT` | the Horizon master's limit in MB, default 192 |
| `HEALTH_REQUIRE_HORIZON` | `true` only where Horizon runs on the same host and should gate readiness |
| `STATUS_DISK`, `STATUS_PATH` | where `health:status` writes `status.json` (default the local disk) |

Tenant files: `MEDIA_DISK=s3` and the `AWS_*` variables, required in production; see [Object Storage](#object-storage).
| `PRIVACY_DEADLINE_DAYS_KE`, `_CD` | once counsel confirms them (docs/privacy.md) |

## Object Storage

Decision 2026-10-22 (owner): every app file lives in Linode Object Storage from the start, never on a web host's disk, so web hosts stay stateless (NFR-01, docs/scaling.md) and a lost host loses no file (NFR-07). The adapter is `league/flysystem-aws-s3-v3` (approved by the owner).

### What is stored, and where

One private bucket per environment. Every key starts with its tenant, `tenants/{tenant uuid}/` (TEN-01), built only by `App\Core\Files\TenantFiles` (`TenantKeysTest` fails on a key built anywhere else):

| Files | Key under `tenants/{tenant}/` | Served by |
| --- | --- | --- |
| Logos, favicons, sign-in backgrounds (BR-02) | `branding/{uuid}.{ext}` | `GET /api/v1/branding/assets/{path}`: signed, stable for a day, `Cache-Control: public, max-age=86400` |
| Item images (MD-02), also on the storefront and the POS | `items/{item}/{uuid}.{ext}` | signed route bound to the user (`/media/...`), 15 minutes; storefront and POS sync stream them through their own routes |
| Custom field files (CF-01) and custom form attachments (CF-04) | `custom-fields/...`, `custom-forms/...` | signed route bound to the user, 15 minutes |
| Approval attachments (APR-03) | `approvals/{request}/...` | signed route bound to the user, 15 minutes |
| Imports: source, error and result files (IMP-01) | `imports/{import}/...` | signed route bound to the user, 15 minutes |
| Tenant exports (EXP-02) | `exports/{export}.zip` | signed route bound to the Owner, 5 minutes; a closing export's emailed link until it expires |
| HR documents (HR-27) | `hr/documents/...` | signed route bound to the user, 5 minutes, each download audited (HR-11) |
| Fixed asset documents (FA-29) | `fixed-assets/{asset}/...` | the authenticated API |
| Procurement bid files (PRC-08), sealed | `procurement/bids/{tender}/...` | the authenticated API, after opening |
| Delivery proof photos (ECOM-41) | `ecommerce/proofs/...` | not shown yet; the ECOM-41 follow-up streams them through a signed route bound to the user |
| Marketplace app logos (MKT-01) | `marketplace/apps/{app}/...` (the developer's tenant) | the API, cached a day once approved |

The platform's own probes (`app:preflight`, `/health/ready`) write, read and delete `platform/...` objects. Document PDFs are rendered on request and never stored.

### Private and public files

The bucket is private: no public ACL, no public-read prefix, no bucket policy, no CORS. **No code hands out a presigned bucket URL.** Every file goes through the API, which streams it from the bucket after its own checks:

- **Private files** use the existing short-lived signed routes, bound to the user they were signed for. The API checks again, when the file is fetched, that this user is active and may still see the record (field rules, scope, a support session's grant, RBAC-13), and audits the download where the module does (HR-11). A presigned URL would skip all of that for its lifetime and could not be revoked.
- **Public files** (the tenant's logo on the sign-in page, storefront images, approved marketplace logos) are streamed by the API too, with cache headers, rather than from a public-read bucket or prefix. This was chosen as the safer option: one private bucket with nothing world-readable, so a wrong key or a mistaken upload can never be exposed, and every response carries `nosniff` and a restrictive CSP. The brand asset URL changes once a day, so browsers and any CDN in front of the API cache it. Revisit if storefront traffic makes the API the bottleneck: a CDN in front of `GET /api/v1/branding/assets/*` and the shop image route comes before any public bucket.
- The web CSP no longer needs `https://*.linodeobjects.com` in `img-src` (docs/security.md); it does no harm while kept.

### Configuration (`<path>/api/.env`)

| Variable | Value |
| --- | --- |
| `MEDIA_DISK` | `s3` (staging and production). `media` keeps files under `api/storage/app/media` (development only). `app:preflight` fails in production on anything but `s3` and warns on staging |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | an Object Storage access key with read/write on this environment's bucket only (Linode: Object Storage → Access Keys → Limited access). Never committed; the scrubber redacts them from logs and error reports |
| `AWS_DEFAULT_REGION` | the cluster's region id, e.g. `eu-central-1` (Frankfurt) |
| `AWS_BUCKET` | the bucket name, e.g. `<name>-production` |
| `AWS_ENDPOINT` | the cluster endpoint, `https://<region>.linodeobjects.com` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` (virtual-hosted style, Linode's default). `true` only for a bucket name containing dots |

With `MEDIA_DISK=s3`, the disk name `media` that rows written earlier carry also points at the bucket (`config/filesystems.php`), so those rows read the files the migration below copied there under the same keys. `media_local` always names `api/storage/app/media`, the migration's source.

### Preflight

`php artisan app:preflight` (run by every deploy before migrating) has an **Object storage** check: it requires `MEDIA_DISK=s3` in production and every `AWS_*` value above, then writes a probe object under `platform/preflight/`, reads it back and deletes it. An unreachable bucket, a wrong key, a refused write or a probe that cannot be deleted fails the deploy. `/health/ready` runs the same write, read and delete on every check (`HEALTH_STORAGE_DISK`, default the tenant files disk).

### Moving existing local files

Hosts that kept files on disk before this change (`api/storage/app/media`, and proof photos or marketplace logos under `api/storage/app/private`) copy them once, from the host that has them, after setting the variables above:

```sh
php artisan storage:migrate-to-object-storage --dry-run      # lists what would be copied; writes nothing
php artisan storage:migrate-to-object-storage                # copies and verifies; keeps every local file
php artisan storage:migrate-to-object-storage --delete-local # again: deletes each local file whose copy is verified
```

Each copy keeps its key and is verified by size and SHA-256 read back from the bucket. A key already in the bucket with the same content counts as done, so the command can be stopped and run again (it resumes). A key already there with other content is a conflict: never overwritten, reported, and the command exits 1. A copy that does not verify is removed from the bucket and its local file kept. Work files, probes, unfinished `.part` uploads and keys without a tenant are left alone. Marketplace logos stored before this change keep their old key (`marketplace/apps/...`, no tenant), because approved versions cannot be edited; new uploads use the developer's tenant.

### Expiry and clean-up

`exports:expire` (hourly) deletes expired export zips and any unfinished `.part` upload from the disk each export row names, so from the bucket. Item images deleted in the app are deleted from the bucket after the commit. Nothing else deletes files (TEN-06: business records are archived, their files kept).

Bucket settings: versioning on in production (docs/backups.md), no lifecycle rule that deletes current objects. A lifecycle rule may expire **noncurrent** versions after the backup window.

### Owner inputs

- **Buckets**: one per environment, e.g. `<name>-dev`, `<name>-staging`, `<name>-production` (bucket names are global per cluster; no product name until it is chosen), plus the replica bucket in a second region for production (docs/backups.md).
- **Region**: the Object Storage cluster, in the same region as the hosts (see Layout), e.g. `eu-central-1` (Frankfurt).
- **Access keys**: one per environment, with "Limited access" to that environment's bucket only (read/write), none with access to all buckets. Put them in that environment's `.env` and the vault, never in GitHub.

## GitHub settings

| Kind | Name | Value |
| --- | --- | --- |
| secret | `<ENV>_LINODE_HOST`, `_USER`, `_SSH_KEY`, `_KNOWN_HOSTS`, `<ENV>_DEPLOY_PATH` | README, Deploy |
| secret | `SENTRY_AUTH_TOKEN` | optional: uploads web source maps |
| variable | `<ENV>_API_URL`, `APP_NAME` | README, Deploy |
| variable | `SENTRY_WEB_DSN`, `SENTRY_ORG`, `SENTRY_WEB_PROJECT`, `SENTRY_URL` | optional: web error tracking (per environment) |

## What each deploy runs (stage 8C additions)

Besides the README's steps: the web build carries the commit as its Sentry release (and uploads hidden source maps only when `SENTRY_AUTH_TOKEN` is set, deleting them before the files go live); `api/REVISION` records the commit for the API's Sentry release; migrations run as the owner (with a lock timeout and retries, below), then `permissions:sync`, the catalogue steps, `billing:sync-modules`, and `billing:backfill-subscriptions` once per environment (a marker in `storage/app/deploy/`; a failure warns and retries on the next deploy instead of keeping the API down); after `up`, `health:status` checks readiness from the host.

### Migrations and locks (Phase 8 review L9)

A migration that alters a table needs a lock that a running transaction may hold: a queue worker on another host, a long export. Without a limit it would wait, and every query on that table would queue behind it, the API's included once it is back up.

- The owner connection (`pgsql_owner`, used only by migrations and the deploy commands) sets `lock_timeout` from `DB_OWNER_LOCK_TIMEOUT` (default `5s`; empty to wait forever). A migration that cannot get its lock in time fails with SQLSTATE `55P03`. Each migration runs in its own transaction, so the one that failed is rolled back and the earlier ones stay applied.
- The deploy runs `bash database/scripts/migrate-with-retry.sh`: up to 3 attempts, 15 seconds apart (`MIGRATE_ATTEMPTS`, `MIGRATE_RETRY_WAIT`), retrying only on a lock timeout. Any other failure stops the deploy at once, with the API still in maintenance.
- If the third attempt still times out, find the blocking session (`select pid, state, xact_start, query from pg_stat_activity where datname = current_database() order by xact_start`), let it finish or stop it (for example `php artisan horizon:terminate` on the queue host), then run the deploy again. `MigrationLockTimeoutTest` covers the timeout and the retry rule.

## Owner inputs

- Linode account, API token, region choice, and the deploy SSH key and `known_hosts` per environment.
- Managed PostgreSQL plan, HA for production, backup retention and PITR window (docs/backups.md).
- Sentry: organisation, three projects (API, web, POS), their DSNs and an auth token for source maps.
- Status page supplier (docs/hardening.md) and the uptime and alerting service (docs/observability.md).
- The secrets vault (docs/security.md) and the penetration-test firm.
- Object Storage: one bucket per environment, the region, and an access key per environment limited to that bucket (see [Object Storage](#object-storage), Owner inputs).
- Legal confirmation of data subject request deadlines and retention periods (docs/privacy.md).
