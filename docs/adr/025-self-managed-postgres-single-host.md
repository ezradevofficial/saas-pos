# ADR 025: Self-managed PostgreSQL on a single host to start

Status: Accepted (owner decisions of 2026-10-22; NFR-01, NFR-06, NFR-07, TEN-01)

## Context

The first real environment is for staging and a pilot with a few businesses, not for general availability. The owner wants it cheap and simple: one Linode, about USD 12 a month, run by one person.

Three facts shaped the choice:

- **Managed PostgreSQL may not fit ADR 002.** `provision-db.sh` must create `app_owner` with `BYPASSRLS`, and PostgreSQL 16+ lets only a superuser or a `BYPASSRLS` role do that. Whether Linode's managed admin (`linpostgres`) has `BYPASSRLS` is unconfirmed (deploy rehearsal 2026-10-22). A managed cluster also costs more than the whole host.
- **The full export and the isolation fixture need `max_locks_per_transaction = 256`** (ADR 002, ADR 015). A self-managed cluster sets it; a managed plan may not allow it.
- **Linode blocks outbound ports 25, 465 and 587 on new accounts.** Mail must use a relay on another port.

The concept note and CLAUDE.md describe the target layout: separate web, queue and database servers behind a NodeBalancer. This ADR is a deliberate, temporary step back from that target for staging and the pilot. It does not change the target.

## Decision

### One host runs everything

One Linode shared plan: 2 GB RAM, 1 vCPU, 50 GB disk, Ubuntu 24.04 LTS. The owner picks the region (probably Frankfurt). The scripts name no region. The Object Storage cluster is one variable (`OBJECT_STORAGE_CLUSTER`, for example `de-fra-1`).

`deploy/host/provision-single-host.sh` provisions the host. It is idempotent: it rewrites only files whose content changed, restarts only what those changes need, never drops a database, role, table or file it did not write, takes every secret from the environment and prints none. It has `--dry-run` and `--steps`.

| Piece | Setting |
| --- | --- |
| PostgreSQL 16 (PGDG, signing key fingerprint checked) | Data checksums on new clusters. Sized from RAM for a shared host: `shared_buffers` 256 MB, `effective_cache_size` 768 MB, `work_mem` 4 MB, `maintenance_work_mem` 64 MB, `max_connections` 40. `max_locks_per_transaction = 256`, `max_prepared_transactions = 0` (ADR 004). `listen_addresses = 'localhost'`, `ssl = on`. |
| pg_hba (TEN-01) | The superuser connects only through the local socket, by peer, as the `postgres` OS user. It is refused on every TCP address. Every other client uses TLS with `scram-sha-256` on 127.0.0.1 or ::1. Everything else is refused. No `trust`, no `md5`. |
| Database roles | A cluster admin `db_admin` (`NOSUPERUSER CREATEDB CREATEROLE BYPASSRLS`) runs the unchanged `provision-db.sh` over TLS on localhost, the same path as a managed admin. That creates `app_owner`, `app` and `app_platform` (ADR 002, ADR 013). The app never uses `db_admin`. |
| Redis | Bound to 127.0.0.1 and ::1, `requirepass`, `maxmemory` 192 MB with `noeviction`: Redis holds the queues, so a full Redis refuses writes instead of dropping jobs. |
| PHP 8.3-FPM | `php.ini-production` plus `expose_php = Off` and `display_errors = Off`. One pool of 6 workers on 2 GB, running as the deploy user. |
| nginx | TLS 1.2 and 1.3, certificates from Let's Encrypt (webroot, renewal hook reloads nginx), the docs/security.md headers and CSP. `/metrics` and the TLS ask endpoint are denied publicly and served only on an internal `127.0.0.1:8080` listener. |
| Horizon | systemd unit, `HORIZON_PROFILE=single-host`: payments, fiscal, notifications, default and automation share one supervisor in that priority order. Exports, imports and webhooks keep one process each. That makes five worker processes, not eight or more. `MemoryMax=1G`, `Nice=5`, so web requests come first. |
| Scheduler | A systemd timer runs `schedule:run` every minute. Every scheduled command is `onOneServer()`. |
| Host | ufw allows only 22, 80 and 443. fail2ban guards sshd. unattended-upgrades applies security updates without automatic reboots. A 2 GB swap file with `vm.swappiness = 10`. |
| Proxies | No load balancer and no VPC: `TRUSTED_PROXIES=none`. Cloudflare DNS records stay **DNS only**. A proxied record would make every client address a Cloudflare address, which the rate limits and the M-Pesa callback allow-list would then see. |

### Files in Object Storage from the start

Uploads and exports go to a private Linode Object Storage bucket from day one. The host keeps no persistent local files directory, so no file backup is needed and a rebuilt host loses no files. The app side, and its variables, are built on another branch and documented in the "Object Storage" section of docs/deploy-linode.md.

### Backups off until go-live, ready to switch on

Staging and the pilot run **without database backups**. The owner accepts losing staging data. Nothing is archived:

- `BACKUPS_ENABLED=false` (the default) leaves `archive_mode = off` and `archive_command = ''`. No WAL piles up waiting for an archiver, and no backup tool is downloaded.
- `app:preflight` warns in production while `BACKUPS_ENABLED` is false: "Backups are off: enable before go-live".

The go-live path is built and tested now, so switching it on is a settings change, not a project:

- `BACKUPS_ENABLED=true` turns on WAL archiving with WAL-G. The version is pinned to v3.0.9 and its SHA-256 is checked; the checksum and the archive's contents were checked again on 2026-10-22. Backups go to their own Object Storage bucket under `db/`, encrypted on the host with libsodium before upload. A nightly base backup timer runs at 01:30 UTC.
- Retention: 7 days of point-in-time recovery, 30 daily and 12 monthly base backups (`walg-retention.jq`; the owner may change these numbers).
- `restore-drill.sh` restores into a scratch cluster on another port, with archiving off so it never writes to the bucket. It waits for recovery to finish, renames the database to `<db>_drill` and runs `backup:verify`. It works from WAL-G, or from a `pg_basebackup` copy with an optional WAL archive and a target time.

### Secrets

- The API's `.env` on the server is owned by root, mode 0640, group = the deploy user. The owner's "root-only" decision is met as closely as the deploy allows. PHP-FPM, Horizon and the deploy's `artisan` commands (including `config:cache`, whose cached copy holds the same values) run as the app user and must read it. That user cannot edit it, and nobody else can read it. A strict root-only 0600 file would stop every deploy at preflight. The provisioning variables live in `/root/provision.env` (0600, root).
- GitHub Actions secrets hold the deploy SSH key and `known_hosts`.
- The owner keeps a copy of both files in their password manager. There is no vault for now (docs/security.md lists the later options).

### Monitoring

- No Sentry on staging. Preflight warns that the DSN is missing, which is expected.
- `app-host-watch.timer` runs every 5 minutes. It checks `GET https://<api>/health/ready` through nginx and TLS, plus disk use, database connections and long transactions; with backups on, it also checks archiving and backup age. It emails the owner (`HEALTH_ALERT_EMAIL`) through the app's own SMTP settings, once when the set of problems changes and once on recovery. A failed email is tried again on the next run.

### Mail, SMS and integrations

- Mail goes through the owner's Brevo SMTP relay: `smtp-relay.brevo.com`, port 2525 with STARTTLS (required), `MAIL_FROM_NAME="${APP_NAME}"`. `app:preflight` signs in to the relay without sending. The repository holds placeholders only.
- No SMS provider. Email-only mode is built on another branch. WhatsApp is off.
- M-Pesa uses the Daraja sandbox (`MPESA_BASE_URL=https://sandbox.safaricom.co.ke`). Each business enters its own Daraja keys in the app.
- eTIMS uses the KRA OSCU sandbox. `ETIMS_BASE_URL` stays a placeholder until the owner supplies the value KRA gives.

## Consequences

- Cost is about USD 12 a month, plus Object Storage (USD 5 a month for the first 250 GB) and the domain.
- **One host is a single point of failure.** A host loss means rebuilding from the scripts and, until go-live, starting with an empty database. Uptime will be below NFR-01's 99.5% target, and that is accepted for staging and the pilot. **No real business data before backups are on** (go-live checklist).
- The host watch runs on the host it watches, so it cannot report the host itself being down. An external uptime monitor is on the go-live checklist.
- One vCPU serves web requests, workers and the database. Heavy exports or imports slow the API. The single-host Horizon profile and the systemd priorities soften this. The scale-out path is the sign that it is time to move.
- `db_admin` keeps PostgreSQL 16's automatic ADMIN membership (without SET or INHERIT) on the three roles it created: that is how `CREATEROLE` works. Its password is needed only to run provisioning again and lives in the password manager, never in the app's `.env`.
- Security updates install themselves, but PostgreSQL minor releases restart the database and reboots are manual. Both are applied by hand in a quiet window.
- **Scale out** later without changing the app: `PG_PROFILE=dedicated` sizes PostgreSQL for a host of its own, `PG_ALLOW_SUBNET` and `PG_LISTEN_ADDRESS` open it to a private subnet over TLS, and `HORIZON_PROFILE` is unset once workers have their own host. Alternatively, move to Managed PostgreSQL if Linode confirms `BYPASSRLS` for its admin. Moving to the target layout in the concept note (a NodeBalancer, `TRUSTED_PROXIES=192.168.255.0/24`) is a later decision. It reuses `provision-db.sh` and the restore drill.

## Rehearsal

On 2026-10-22, without system packages on a Mac (PostgreSQL 17 binaries, so the 16-only details were not exercised): the full test suite of `deploy/host` (bash 3.2, shellcheck 0.11.0), every script's dry run, and the db-roles step and restore drills against a scratch cluster that used the script's own `pg_hba.conf` and `postgresql.conf`. Results are in docs/deploy-rehearsal-2026-10-22.md, "Single-host rehearsal".
