# Deploying on Linode (NFR-01, NFR-07)

How to set up the **staging and pilot** environment: one Linode that runs everything (ADR 025). It serves the existing deploy workflow (`.github/workflows/deploy.yml` → `deploy-target.yml`). The README's "Deploy" section describes each deploy step. This file covers the one-time setup, the checks, and the go-live checklist.

Nothing here has run on Linode yet, because it needs the owner's account. The deploy steps after provisioning were rehearsed on a local production-like stack. The host scripts were rehearsed in dry runs and against a scratch PostgreSQL cluster. Both are recorded in [deploy-rehearsal-2026-10-22.md](deploy-rehearsal-2026-10-22.md).

## What the owner must do

Do these before the first provisioning run. Each item says which step needs it.

1. **Buy the Linode.** Shared CPU, 2 GB RAM, 1 vCPU, 50 GB disk (about USD 12 a month), Ubuntu 24.04 LTS, in the region you choose (probably Frankfurt). Turn disk encryption on when you create it. Add your own SSH key for root. Write down the IPv4 and IPv6 addresses. (Provisioning step 1.)
2. **Add the deploy SSH key.** Make a key pair with no passphrase for GitHub Actions (`ssh-keygen -t ed25519 -N '' -f deploy_key`). The public half goes into `DEPLOY_AUTHORIZED_KEY` for provisioning. The private half goes into the `STAGING_LINODE_SSH_KEY` GitHub secret, and the host's `known_hosts` line into `STAGING_LINODE_KNOWN_HOSTS` (step 6).
3. **Buy the domain and point it at Cloudflare.** Use Cloudflare's name servers at the registrar. Create `A` and `AAAA` records for `app.`, `api.` and `console.` that point at the Linode, with the proxy **off** ("DNS only", grey cloud). See "DNS" below for why. (Step 2.)
4. **Create the Object Storage bucket and a scoped key.** In the same region as the host, create a private bucket for the app's files, and an access key limited to that bucket (read and write). The variables are in the "Object Storage" section of this file. At go-live, add a second bucket and key for database backups.
5. **Set up Brevo for the domain.** Add the sending domain in Brevo, then add Brevo's SPF (TXT) and DKIM records in Cloudflare and wait until Brevo shows them as verified. Copy the SMTP login and an SMTP key from Brevo, SMTP & API. (Step 5, `MAIL_*`.)
6. **Get the KRA sandbox values.** Register as an OSCU integrator on the KRA eTIMS sandbox. KRA gives you the API base URL (`ETIMS_BASE_URL`) and the test PIN and branch to use. Until then, eTIMS submissions wait in the fiscal queue (docs/integrations.md).
7. **Keep the secrets.** Keep a copy of `/root/provision.env` and the API's `.env` in your password manager, and update it whenever either file changes.

Not needed for staging: an SMS provider, WhatsApp, Daraja paybill keys (each business enters its own Daraja sandbox keys in the app), a NodeBalancer or a VPC.

## Layout (staging and pilot)

| Piece | Where |
| --- | --- |
| Web app, platform console, API | nginx with Let's Encrypt, PHP 8.3-FPM (6 workers) on the one host |
| Queue workers, scheduler | Horizon (`HORIZON_PROFILE=single-host`, five processes) and a systemd timer for `schedule:run`, same host |
| Database | PostgreSQL 16 on the same host, localhost and the socket only, `max_locks_per_transaction = 256` |
| Redis | Same host, bound to localhost, with a password |
| Files | Linode Object Storage (private bucket); no local files directory |
| Backups | **Off until go-live** (`BACKUPS_ENABLED=false`); WAL-G is ready to switch on (docs/backups.md) |
| Monitoring | `app-host-watch.timer`, every 5 minutes, emails the owner; Sentry for errors (API, web; POS with the phone build) |
| Firewall | ufw: 22, 80 and 443 only; fail2ban for SSH |

There is no load balancer and no VPC, so `TRUSTED_PROXIES=none`. The later layout is under "Scale out".

### DNS

Cloudflare is used for DNS only. A proxied (orange cloud) record would put Cloudflare in front of nginx. Every request would then arrive from a Cloudflare address, and with `TRUSTED_PROXIES=none` the rate limits and the M-Pesa callback allow-list would see that address instead of the client's. Proxying needs Cloudflare's ranges in `TRUSTED_PROXIES` and an ADR change first.

## Provisioning the host

1. **Sign in** as root with your own key. Clone the repository somewhere root can read it, for example `git clone https://github.com/<owner>/<repo>.git /root/platform-src`. Only `deploy/host/` and `api/database/scripts/` are used.
2. **DNS**: check that the three names resolve to the host (`dig +short api.<domain>`). Let's Encrypt needs this.
3. **Write `/root/provision.env`** from the table below. Generate the secrets on the host and never put them in the repository:
   ```sh
   install -m 600 /dev/null /root/provision.env
   {
     echo "REDIS_PASSWORD=$(openssl rand -hex 32)"
     echo "DB_ADMIN_PASSWORD=$(openssl rand -hex 24)"
     echo "APP_PASSWORD=$(openssl rand -hex 24)"
     echo "APP_OWNER_PASSWORD=$(openssl rand -hex 24)"
     echo "APP_PLATFORM_PASSWORD=$(openssl rand -hex 24)"
   } >> /root/provision.env
   # then add the non-secret lines (domains, email, DEPLOY_AUTHORIZED_KEY) with an editor
   ```
4. **Dry run, then run** (as root, from the checkout):
   ```sh
   cd /root/platform-src
   set -a; . /root/provision.env; set +a
   bash deploy/host/provision-single-host.sh --dry-run     # prints every command and file; changes nothing
   bash deploy/host/provision-single-host.sh               # about 5 minutes the first time
   ```
   The script is idempotent: run it again after any change, or one step at a time with `--steps nginx,services`. If the nginx step stops with "No certificate", DNS does not point at the host yet. Fix the records and run `--steps nginx` again.
5. **Write the API's `.env`** at `<path>/api/.env` (template below), then run `bash deploy/host/provision-single-host.sh --steps app-dirs`, which sets its owner and mode (root, group = the deploy user, 0640).
6. **GitHub**: the secrets and variables under "GitHub settings". `ssh-keyscan -t ed25519 <host IP>` gives the `known_hosts` line. Check its fingerprint against `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the host.
7. **First deploy**: run the Deploy workflow by hand from `main`. Its `billing:backfill-subscriptions` step warns "No operator tenant yet", which is expected. Then, on the host, as the deploy user, before anyone signs up:
   ```sh
   cd <path>/api
   php artisan platform:operator --country=KE --country=CD   # the operator tenant (ADR 016); --country is required
   php artisan platform:user <email> --role=owner            # the first staff account; prints a one-time setup link (24 h)
   php artisan billing:backfill-subscriptions                # idempotent
   ```
   and as root `systemctl restart app-horizon` (the unit waits for the first deploy's `artisan` file).
   Then run the checks under "After provisioning".

### Environment for the provisioning script

`/root/provision.env`, root only (0600). Keep a copy in the password manager.

| Variable | Value | Needed by |
| --- | --- | --- |
| `APP_DOMAIN`, `API_DOMAIN`, `CONSOLE_DOMAIN` | `app.<domain>`, `api.<domain>`, `console.<domain>` | nginx, certificates, host watch |
| `LETSENCRYPT_EMAIL` | the owner's address, for expiry notices | nginx |
| `HEALTH_ALERT_EMAIL` | where the host watch sends alerts | services |
| `DEPLOY_AUTHORIZED_KEY` | the deploy key's **public** half (`ssh-ed25519 AAAA… deploy`) | app-dirs |
| `DEPLOY_USER`, `DEPLOY_PATH` | default `deploy`, `/srv/platform` | app-dirs, php, services |
| `REDIS_PASSWORD` | `openssl rand -hex 32`; the same value goes into the API's `.env` | redis |
| `DB_ADMIN_PASSWORD` | the cluster admin `db_admin`; used only by provisioning, never by the app | db-roles |
| `APP_PASSWORD`, `APP_OWNER_PASSWORD`, `APP_PLATFORM_PASSWORD` | the roles `app`, `app_owner`, `app_platform`; the same values go into the API's `.env` | db-roles |
| `DB_NAME` | default `app` | db-roles |
| `BACKUPS_ENABLED` | `false` until go-live | backups, postgres |
| `OBJECT_STORAGE_CLUSTER`, `BACKUP_BUCKET`, `BACKUP_ACCESS_KEY_ID`, `BACKUP_SECRET_ACCESS_KEY`, `WALG_LIBSODIUM_KEY` | only with `BACKUPS_ENABLED=true` (docs/backups.md) | backups |
| `BACKUP_PITR_DAYS`, `BACKUP_DAILY_DAYS`, `BACKUP_MONTHLY_MONTHS` | default 7, 30, 12 | backups |
| `HOST_RAM_MB`, `SWAP_SIZE_MB`, `FPM_MAX_CHILDREN`, `PG_MAX_CONNECTIONS`, `REDIS_MAXMEMORY` | overrides; the defaults suit 2 GB | |
| `PG_PROFILE`, `PG_ALLOW_SUBNET`, `PG_LISTEN_ADDRESS` | only for "Scale out" | postgres, firewall |

The steps, in order: `swap packages firewall fail2ban upgrades app-dirs php redis tools backups postgres db-roles nginx services`.

## After provisioning

On the host:

```sh
sudo -u postgres psql -c 'show max_locks_per_transaction' -c 'show listen_addresses' -c 'show archive_mode'   # 256, localhost, off
psql "host=127.0.0.1 user=postgres dbname=postgres sslmode=require"   # must fail: "pg_hba.conf rejects connection"
psql "host=127.0.0.1 user=app dbname=app sslmode=disable"             # must fail: no TLS
ss -ltnp | grep -E ':(5432|6379)\b'                                   # 127.0.0.1 and [::1] only
sudo ufw status                                                       # 22, 80, 443
systemctl list-timers 'app-*'                                         # scheduler (1 min), host watch (5 min)
sudo /usr/local/lib/app-host/host-watch.sh --dry-run                  # "All checks pass" once deployed
swapon --show; sudo fail2ban-client status sshd
```

Test that alerts arrive: `sudo systemctl stop php8.3-fpm`, wait for the next 5-minute mark (one "Problem" email), then `sudo systemctl start php8.3-fpm` (one "Recovered" email).

## After every deploy

From the host (or the runner, for the public ones):

```sh
php artisan app:preflight            # warns: no SMS driver; in production also "Backups are off"
php artisan health:status            # exit 0, "operational"
php artisan horizon:status
curl -fsS https://api.<domain>/health/live
curl -fsS https://api.<domain>/health/ready     # 200 and every check "ok"
curl -s -o /dev/null -w '%{http_code}\n' https://api.<domain>/metrics            # 403 (nginx)
curl -sI https://api.<domain>/health/live | grep -iE 'x-content-type|x-frame|content-security|strict-transport|x-powered'   # no X-Powered-By
curl -sI https://app.<domain>/ | grep -i content-security-policy
curl -s -H "Authorization: Bearer $METRICS_TOKEN" http://127.0.0.1:8080/metrics | grep app_horizon_masters   # on the host: 1
```

## The API's `.env` (staging and pilot)

At `<path>/api/.env`, before the first deploy. The deploy never writes it, and its preflight fails without it. The file is owned by root, group = the deploy user, mode 0640: the app user (PHP-FPM, Horizon, the deploy's `artisan` commands, which must load it) reads it and cannot edit it, and nobody else reads it. The deploy user owns the directory, so this guards against mistakes, not against a compromised deploy user (who can read the secrets either way). Keep a copy in the password manager. `php artisan key:generate` cannot run before the first deploy, so the keys come from OpenSSL. Everything in `<…>` is a placeholder:

```sh
cat > <path>/api/.env <<EOF
APP_NAME="<display name>"
APP_ENV=staging
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=https://api.<domain>
FRONTEND_URL=https://app.<domain>
CORS_ALLOWED_ORIGINS=https://app.<domain>,https://console.<domain>
PLATFORM_URL=https://console.<domain>
APP_BASE_DOMAIN=<domain>
TRUSTED_PROXIES=none

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=app
DB_SSLMODE=require
DB_USERNAME=app
DB_PASSWORD=<APP_PASSWORD from provision.env>
DB_OWNER_USERNAME=app_owner
DB_OWNER_PASSWORD=<APP_OWNER_PASSWORD from provision.env>
DB_PLATFORM_USERNAME=app_platform
DB_PLATFORM_PASSWORD=<APP_PLATFORM_PASSWORD from provision.env>

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=<REDIS_PASSWORD from provision.env>
HORIZON_PROFILE=single-host
HEALTH_REQUIRE_HORIZON=true

MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=2525
MAIL_USERNAME=<Brevo SMTP login>
MAIL_PASSWORD=<Brevo SMTP key>
MAIL_FROM_ADDRESS=<no-reply@domain>
MAIL_FROM_NAME="\${APP_NAME}"

MPESA_BASE_URL=https://sandbox.safaricom.co.ke
ETIMS_BASE_URL=<OSCU sandbox base URL from KRA>

BACKUPS_ENABLED=false
HR_DIGEST_KEY=base64:$(openssl rand -base64 32)
METRICS_TOKEN=$(openssl rand -hex 32)
LOG_STACK=json
LOG_LEVEL=info
EOF
```

Then add the Object Storage variables (the "Object Storage" section) and run `--steps app-dirs` to set the file's mode. `MAIL_SCHEME=smtp` on port 2525 upgrades with STARTTLS. Brevo offers it, and `app:preflight` signs in the same way, so a wrong key or a blocked port stops the deploy. Linode blocks 25, 465 and 587 on new accounts. Leave `SMS_DRIVER`, `NOTIFICATIONS_SMS_DRIVER` and `NOTIFICATIONS_WHATSAPP_DRIVER` unset: there is no SMS provider and WhatsApp is off. Set `SENTRY_LARAVEL_DSN` to the API project's DSN (Sentry is on for staging since 2026-10-28).

Required everywhere (`app:preflight` fails without them outside local and testing):

| Variable | Value |
| --- | --- |
| `APP_ENV` | `staging` now; `production` from go-live |
| `APP_KEY` | `base64:` and 32 random bytes; rotation in docs/security.md |
| `APP_DEBUG` | `false` |
| `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `PLATFORM_URL`, `APP_BASE_DOMAIN` | the environment's hosts |
| `DB_HOST=127.0.0.1`, `DB_PORT`, `DB_DATABASE`, `DB_SSLMODE=require`, `DB_USERNAME=app`, `DB_PASSWORD` | runtime role (RLS-bound), over TLS on localhost |
| `DB_OWNER_*` | `app_owner`, used by the deploy's migrations. `DB_OWNER_LOCK_TIMEOUT` (default `5s`) is how long a migration waits for a table lock (below) |
| `DB_PLATFORM_*` | `app_platform` (ADR 013) |
| `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Redis; `app:preflight` pings it. `REDIS_CLIENT` defaults to `predis` (set `phpredis` only where the extension is installed) |
| `MAIL_MAILER=smtp` and the Brevo settings above | never `log` or `array`; preflight signs in to the relay |
| `SMS_DRIVER` and its credentials | never `log`. **No SMS provider is implemented yet** (owner decision 2026-10-22: launch email-only): leave it empty, or set `none`. That is email-only mode, a supported setting, not a fault: see [Email-only mode](#email-only-mode-no-sms-provider) below. Preflight warns "SMS unavailable: email-only mode" and passes |
| `HR_DIGEST_KEY` | a random 32-byte key, `base64:…`, separate from `APP_KEY` (docs/modules/hr.md) |
| `TRUSTED_PROXIES` | `none` (no load balancer, Cloudflare DNS only) |
| `PAYMENTS_CALLBACK_URL`, `MPESA_*`, `ETIMS_*` | docs/integrations.md; Daraja sandbox and the KRA sandbox for now |

Must **not** be set in production: `PAYROLL_PACK_DIR` (test statutory packs; allowed on staging only, and the guard refuses it elsewhere), `PAYMENTS_ALLOW_FAKE`, `PAYMENTS_DRIVER_*=fake`, `BILLING_DRIVER_*=fake`, `FISCAL_ALLOW_FAKE`, `NOTIFICATIONS_*_DRIVER=fake`.

Observability (warned by `app:preflight` when missing, never fatal):

| Variable | Value |
| --- | --- |
| `LOG_STACK` | `json` (file) or `json_stderr` (journal); `LOG_LEVEL=info` |
| `SENTRY_LARAVEL_DSN` | the API project's DSN (set on staging) |
| `SENTRY_TRACES_SAMPLE_RATE` | unset, or e.g. `0.05` |
| `METRICS_TOKEN` (required in production) | read on the host only, through `127.0.0.1:8080`; nginx denies `/metrics` publicly |
| `HORIZON_PROFILE` | `single-host` here; unset once workers have a host of their own |
| `HORIZON_MEMORY_LIMIT` | the Horizon master's limit in MB, default 192 |
| `HEALTH_REQUIRE_HORIZON` | `true` here (Horizon runs on the same host) |
| `BACKUPS_ENABLED` | `false` until go-live; then `true`, together with provisioning (docs/backups.md) |
| `STATUS_DISK`, `STATUS_PATH` | where `health:status` writes `status.json` (default the local disk; it is rewritten every minute) |
| `PRIVACY_DEADLINE_DAYS_KE`, `_CD` | once counsel confirms them (docs/privacy.md) |

## Disk

One 50 GB disk holds the system, the database, its WAL, logs and the app. Files are in Object Storage. The host watch emails at 80% use (`WATCH_DISK_PCT`). Where to look:

```sh
sudo du -xsh /var/lib/postgresql /var/log /srv/platform 2>/dev/null
sudo journalctl --disk-usage            # vacuum with: journalctl --vacuum-size=500M
sudo -u postgres psql -c "select datname, pg_size_pretty(pg_database_size(datname)) from pg_database"
```

With backups on, a growing `pg_wal` means archiving is failing. The host watch reports that separately (docs/backups.md). Never delete files in `pg_wal` by hand.

## Updates and restarts

- unattended-upgrades installs Ubuntu security updates daily and never reboots. When `/var/run/reboot-required` exists, reboot in a quiet window: `sudo reboot`. Every service starts again by itself.
- PostgreSQL minor releases come from the PGDG repository and restart the database, so apply them by hand in a quiet window: `sudo apt-get update && sudo apt-get install --only-upgrade postgresql-16 postgresql-client-16`.
- A cluster created before the script ran may have no data checksums (the packages step warns). Enabling them needs the cluster stopped: `sudo systemctl stop postgresql@16-main && sudo -u postgres /usr/lib/postgresql/16/bin/pg_checksums --enable -D /var/lib/postgresql/16/main && sudo systemctl start postgresql@16-main`.

## Go-live checklist

Before the first real business uses the platform. Every item is done and recorded in the ops log first:

- [ ] **Backups on.** Create the backup bucket and key, generate `WALG_LIBSODIUM_KEY` and keep it in the password manager, set `BACKUPS_ENABLED=true` in `/root/provision.env` and the API's `.env`, then run `--steps tools,backups,postgres` (PostgreSQL restarts once). Check that the first base backup ran (`sudo systemctl start app-db-backup` or wait for 01:30 UTC). Then run a restore drill that passes: `sudo /usr/local/lib/app-host/restore-drill.sh` (docs/backups.md). `app:preflight` stops warning "Backups are off".
- [x] **Sentry DSNs** for the API and web projects (set 2026-10-28); the POS DSN goes into the phone build (`SENTRY_LARAVEL_DSN`, `SENTRY_WEB_DSN`, the POS build's DSN; docs/observability.md).
- [ ] **eTIMS certified.** KRA has certified the OSCU integration. Set the production `ETIMS_BASE_URL` and each branch's live device details (docs/integrations.md).
- [ ] **Daraja paybill keys.** Safaricom has taken the app live. Set `MPESA_BASE_URL=https://api.safaricom.co.ke`, and the operator's paybill or till keys for subscription billing. Each business enters its own live keys in the app.
- [ ] **Rotate the Brevo and Daraja keys** used during staging and the pilot: make new ones, update the `.env` and the password manager copy, run `php artisan config:cache` and `app:preflight`, then revoke the old ones.
- [ ] `APP_ENV=production`, and an external uptime monitor on `/health/ready`. The host watch cannot report its own host going down (ADR 025).
- [ ] Optionally, versioning on the files bucket (owner decision).

## Scale out

When one host is no longer enough (the host watch's connection or disk alerts, slow requests during exports), without changing the app:

1. A second Linode for the database in the same region, with a VPC between the two. Run `provision-single-host.sh --steps swap,packages,firewall,fail2ban,upgrades,backups,postgres,db-roles` there with `PG_PROFILE=dedicated`, `PG_ALLOW_SUBNET=<VPC subnet>` and `PG_LISTEN_ADDRESS=<its VPC address>`. TLS and `scram-sha-256` stay required, and the superuser stays socket-only.
2. Move the data with the logical copy in docs/backups.md, point `DB_HOST` at the new address, then `php artisan config:cache` and `app:preflight`.
3. Later: workers on their own host (unset `HORIZON_PROFILE`), then the concept note's layout with a NodeBalancer (`TRUSTED_PROXIES=192.168.255.0/24`, health check `GET /health/ready`). Managed PostgreSQL is also possible if Linode confirms its admin has `BYPASSRLS` (ADR 002, ADR 025).

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
| `AWS_BUCKET` | the bucket name. **Setting it switches tenant files to the bucket**; no other variable is needed |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | an Object Storage access key with read/write on this environment's bucket only (Linode: Object Storage → Access Keys → Limited access). Never committed; the scrubber redacts them from logs and error reports |
| `AWS_DEFAULT_REGION` | the cluster's id, e.g. `de-fra-1` (Frankfurt) |
| `AWS_ENDPOINT` | the cluster endpoint, e.g. `https://de-fra-1.linodeobjects.com` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` (virtual-hosted style, Linode's default). `true` only for a bucket name containing dots |
| `MEDIA_DISK` | leave unset. `media` forces the local disk `api/storage/app/media` (development; `app:preflight` fails on it in production and warns on staging), `s3` forces the bucket |

On the object store, the disk name `media` that rows written earlier carry also points at the bucket (`config/filesystems.php`), so those rows read the files the migration below copied there under the same keys. `media_local` always names `api/storage/app/media`, the migration's source.

### Preflight

`php artisan app:preflight` (run by every deploy before migrating) has an **Object storage** check: it requires the bucket in production and every `AWS_*` value above, then writes a probe object under `platform/preflight/`, reads it back and deletes it. An unreachable bucket, a wrong key, a refused write or a probe that cannot be deleted fails the deploy. `/health/ready` runs the same write, read and delete on every check (`HEALTH_STORAGE_DISK`, default the tenant files disk).

### Moving existing local files

**Staging: run this once right after the first deploy with this change**, since it kept files on its disk until then. Hosts that kept files on disk before this change (`api/storage/app/media`, and proof photos or marketplace logos under `api/storage/app/private`) copy them once, from the host that has them, after setting the variables above:

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
- **Region**: the Object Storage cluster, in the same region as the hosts (see Layout), e.g. `de-fra-1` (Frankfurt).
- **Access keys**: one per environment, with "Limited access" to that environment's bucket only (read/write), none with access to all buckets. Put them in that environment's `.env` and the vault, never in GitHub.

## GitHub settings

| Kind | Name | Value |
| --- | --- | --- |
| secret | `STAGING_LINODE_HOST`, `STAGING_LINODE_USER`, `STAGING_LINODE_SSH_KEY`, `STAGING_LINODE_KNOWN_HOSTS`, `STAGING_DEPLOY_PATH` | the host's address, `deploy`, the deploy key's private half, the `known_hosts` line, `/srv/platform` (README, Deploy) |
| secret | `SENTRY_AUTH_TOKEN` | optional: uploads web source maps (not on staging) |
| variable | `STAGING_API_URL`, `APP_NAME` | `https://api.<domain>`; the display name |
| variable | `SENTRY_WEB_DSN`, `SENTRY_ORG`, `SENTRY_WEB_PROJECT`, `SENTRY_URL` | web error tracking: `SENTRY_WEB_DSN` is set on staging; the others only with source maps |

Protect the `staging` environment with a required reviewer once others can push.

## What each deploy runs (stage 8C additions)

Besides the README's steps: the web build carries the commit as its Sentry release (and uploads hidden source maps only when `SENTRY_AUTH_TOKEN` is set, deleting them before the files go live); `api/REVISION` records the commit for the API's Sentry release; migrations run as the owner (with a lock timeout and retries, below), then `permissions:sync`, the catalogue steps, `billing:sync-modules`, and `billing:backfill-subscriptions` once per environment (a marker in `storage/app/deploy/`; a failure warns and retries on the next deploy instead of keeping the API down); after `up`, `health:status` checks readiness from the host.

### Migrations and locks (Phase 8 review L9)

A migration that alters a table needs a lock that a running transaction may hold: a queue worker, a long export. Without a limit it would wait, and every query on that table would queue behind it, the API's included once it is back up.

- The owner connection (`pgsql_owner`, used only by migrations and the deploy commands) sets `lock_timeout` from `DB_OWNER_LOCK_TIMEOUT` (default `5s`; empty to wait forever). A migration that cannot get its lock in time fails with SQLSTATE `55P03`. Each migration runs in its own transaction, so the one that failed is rolled back and the earlier ones stay applied.
- The deploy runs `bash database/scripts/migrate-with-retry.sh`: up to 3 attempts, 15 seconds apart (`MIGRATE_ATTEMPTS`, `MIGRATE_RETRY_WAIT`), retrying only on a lock timeout. Any other failure stops the deploy at once, with the API still in maintenance.
- If the third attempt still times out, find the blocking session (`select pid, state, xact_start, query from pg_stat_activity where datname = current_database() order by xact_start`), let it finish or stop it (for example `php artisan horizon:terminate`), then run the deploy again. `MigrationLockTimeoutTest` covers the timeout and the retry rule.

## Owner inputs

The list at the top ("What the owner must do") covers staging and the pilot. Still open for later:

- Sentry organisation and projects (go-live checklist).
- Status page supplier (docs/hardening.md) and an external uptime monitor.
- Penetration-test firm; a secrets vault once there is a team (docs/security.md).
- Legal confirmation of data subject request deadlines and retention periods (docs/privacy.md), and of hosting outside Kenya and the DRC (concept note).

## Email-only mode (no SMS provider)

Until an SMS provider is implemented the platform runs email-only (AUTH-01 to AUTH-05, NOT-01, owner decision 2026-10-22). One check, `SmsAvailability` (`api/app/Core/Notifications/Sms/SmsAvailability.php`), answers whether text messages can be sent: only when `SMS_DRIVER` names a driver in `SmsSender::DRIVERS`. `log` counts only in `local` and `testing` (and is refused outside them by `EnvironmentGuard`); empty, `none` or an unknown name is email-only mode.

In email-only mode:

- **Refused with 422 `sms_unavailable`** ("Text messages aren’t available yet. Use your email address.", in English and French): sign-up with a phone number, password reset for a phone number (for every number alike, so the answer says nothing about accounts), inviting a user by phone, assisted onboarding with a phone contact, enrolling in two-step sign-in by text message, resending a code by SMS, and the storefront's sign-in code by SMS. Nothing is stored or sent.
- **Sign-in**: an active user still signs in with a phone number and password (no code is needed). A user whose second step is by text message gets the code by verified email instead; with no verified email the sign-in is refused, never let through without its second step.
- **Notifications**: every SMS delivery is written as skipped with the reason `sms_unavailable` (never counted as sent) and the message goes by WhatsApp where that channel is configured and the user has a verified phone, else by email. A customer's order update by SMS goes by WhatsApp where configured; its email goes as usual. Marketing never changes channel (consent is per channel). Notification preferences hide SMS.
- **Apps**: `GET /api/v1/public/branding` returns `meta.sms_available`; the web app, the storefront and the POS rider sign-in ask for an email address only and say why.
- **Phone-only users**: none can exist in production. Phone sign-up, phone invitations and phone onboarding are refused before any user is created, contacts cannot be changed afterwards, and production has never run with SMS. A sign-up left unverified is closed after 24 hours (`onboarding:prune-pending`). Anywhere one does exist (a local or staging database from before), an active one signs in with the password; one that needs a code (unverified, or two-step by text message with no verified email) is refused with "Ask your Owner or support to add an email address to your account."
- **Turning SMS on later**: add the provider to `SmsSender::DRIVERS` and its binding in `IdentityServiceProvider`, then set `SMS_DRIVER`. Nothing else changes. Replies such as STOP by SMS are out of scope until then (docs/privacy.md).

