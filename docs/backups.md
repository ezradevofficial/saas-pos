# Backups and recovery (NFR-07)

The target is daily full backups plus point-in-time recovery (PITR), an RPO of 15 minutes or less, an RTO of 4 hours or less, and a restore tested every month.

**Staging and the pilot run without database backups (ADR 025).** The owner accepts losing staging data. WAL archiving is off, and nothing is copied off the host. `app:preflight` warns in production: "Backups are off: enable before go-live". Everything needed to switch backups on is built and tested (`deploy/host`). Switching on is the first item of the go-live checklist (docs/deploy-linode.md), and no real business data goes on the host before that.

## What is backed up

| Data | Where | Backup | RPO |
| --- | --- | --- | --- |
| PostgreSQL (everything business) | PostgreSQL 16 on the host (ADR 025) | **off until go-live**. Then WAL-G: continuous WAL archiving plus a nightly base backup, encrypted on the host (libsodium) and sent to a private Object Storage bucket. Kept: 7 days of PITR, 30 dailies, 12 monthlies | none while off; then minutes (`archive_timeout` 60 s) |
| Files (uploads, exports) | Linode Object Storage | the bucket itself; no copy on or from the host. Versioning, or a replica bucket, is an owner decision at go-live | n/a |
| Redis | the host | none needed: cache, queues and locks. Queued jobs lost in a Redis failure are rebuilt by the schedulers (fiscal queue, payment timers, webhooks sweep, billing cycle) | n/a |
| Configuration | `/root/provision.env` and `<path>/api/.env` on the host, GitHub secrets | a copy in the owner's password manager, updated on every change. There is no vault (docs/security.md) | on change |
| The backup encryption key (`WALG_LIBSODIUM_KEY`) | `/etc/wal-g/walg.json` on the host | the password manager. **Without it no backup can be restored** | on change |
| Code | GitHub | the repository | n/a |

Restoring the database also restores personal data that was anonymised after the restore point: after any restore, re-run the completed deletion requests (docs/privacy.md).

## Turning backups on (go-live)

1. In Object Storage, in the host's region, create a private bucket for backups (not the files bucket) and an access key limited to it, with read and write.
2. Generate the encryption key and put it in the password manager **before** going further: `openssl rand -hex 32`.
3. Add to `/root/provision.env`: `BACKUPS_ENABLED=true`, `OBJECT_STORAGE_CLUSTER` (for example `de-fra-1`), `BACKUP_BUCKET`, `BACKUP_ACCESS_KEY_ID`, `BACKUP_SECRET_ACCESS_KEY`, `WALG_LIBSODIUM_KEY`. Optionally change the retention: `BACKUP_PITR_DAYS`, `BACKUP_DAILY_DAYS`, `BACKUP_MONTHLY_MONTHS`.
4. Run `bash deploy/host/provision-single-host.sh --steps tools,backups,postgres`. It installs WAL-G (the pinned version, checksum checked), writes `/etc/wal-g/walg.json` (root:postgres, 0640) and the nightly timer, turns on `archive_mode`, and restarts PostgreSQL once.
5. Take the first base backup now: `sudo systemctl start app-db-backup && journalctl -u app-db-backup -n 30`.
6. Run a restore drill (below). It must pass.
7. Set `BACKUPS_ENABLED=true` in the API's `.env`, then `php artisan config:cache` and `php artisan app:preflight`: the backups warning is gone.

The host watch then also reports failing or lagging WAL archiving (more than 20 files waiting) and a base backup older than 26 hours.

## Restore (RTO 4 h)

Decide first: **point in time** (a bad migration or a mass edit: restore to just before it) or **latest** (data damaged or the disk lost).

1. **Declare** the incident. Put the API in maintenance and stop the workers, so nothing writes to a database about to be replaced: `php artisan down`, then as root `systemctl stop app-horizon app-scheduler.timer`. (15 min)
2. **Prove the backup first** with the drill. It restores into a scratch cluster on port 5433 and runs `backup:verify`, and the live database is only read: `sudo /usr/local/lib/app-host/restore-drill.sh --target-time '2026-11-02 14:05:00+03'` (or no `--target-time` for the latest). It needs free disk of about 1.2 times the database's size. `backup:verify` compares the copy with the live database, so after a mass delete or edit the row counts may differ by more than `--tolerance`. In that case, read the per-table differences it prints and confirm that they are the damage being undone. (30 to 90 min, by size)
3. **Replace the live data** (as root). Keep the damaged copy as evidence and as the fallback. One 50 GB disk holds both copies only while the database is under about 20 GB; otherwise attach a Block Storage volume for the old copy first.
   ```sh
   systemctl stop postgresql@16-main
   mv /var/lib/postgresql/16/main /var/lib/postgresql/16/main.before-restore-$(date -u +%Y%m%dT%H%M%SZ)
   install -d -m 700 -o postgres -g postgres /var/lib/postgresql/16/main
   runuser -u postgres -- /usr/local/bin/wal-g --config /etc/wal-g/walg.json backup-fetch /var/lib/postgresql/16/main LATEST
   cat > /etc/postgresql/16/main/conf.d/zz-restore.conf <<'EOF'
   restore_command = '/usr/local/bin/wal-g --config /etc/wal-g/walg.json wal-fetch %f %p'
   recovery_target_time = '2026-11-02 14:05:00+03'
   recovery_target_action = 'promote'
   EOF
   runuser -u postgres -- touch /var/lib/postgresql/16/main/recovery.signal
   systemctl start postgresql@16-main
   until [ "$(runuser -u postgres -- psql -Atc 'select pg_is_in_recovery()')" = f ]; do sleep 5; done
   rm /etc/postgresql/16/main/conf.d/zz-restore.conf && systemctl reload postgresql@16-main
   ```
   For the latest state, leave out the `recovery_target_time` line. (30 to 90 min)
4. **Roles**: the restored cluster has the same roles (`db_admin`, `app_owner`, `app`, `app_platform`) and passwords. Check with `php artisan app:preflight`.
5. **New base backup**: the restore started a new timeline, so take one at once: `systemctl start app-db-backup`.
6. **Switch on**: as root `systemctl start app-horizon app-scheduler.timer`, then `php artisan up`. (15 min)
7. **Reconcile**:
   - POS devices re-upload anything not acknowledged (idempotent, NFR-04): sales after the restore point come back by themselves as devices sync.
   - Re-run completed data deletion requests made after the restore point (docs/privacy.md).
   - Fiscal: compare submissions with KRA eTIMS and DGI for the gap; the fiscal queue resends what is missing.
   - Payments: reconcile M-Pesa callbacks for the gap from the Daraja portal.
8. **Close**: keep the old data directory for 7 days, then delete it (it holds every tenant's data). Write the incident report (cause, RPO and RTO achieved).

## Monthly restore test (runbook)

From go-live: first working Monday of each month, on the host. 30 minutes to 2 hours.

1. Note the time and the current schema version: `php artisan migrate:status --database=pgsql_owner | tail -1`.
2. Restore the latest backup: `sudo /usr/local/lib/app-host/restore-drill.sh`. Then restore a point in time 15 minutes ago: `sudo /usr/local/lib/app-host/restore-drill.sh --target-time "$(date -u -d '15 minutes ago' '+%F %T+00')"`. Each one restores into a scratch cluster on port 5433, with archiving off so it never writes to the bucket. Each waits for recovery, runs `backup:verify` against `<db>_drill`, then deletes the copy. Both must pass. The PITR run proves the RPO.
3. Time the whole exercise: it must fit the 4-hour RTO with room to spare. Each drill appends its result and duration to `/var/lib/app-host/drill-history.log`.
4. Spot-check: run once with `--keep`. The drill stops its cluster but keeps `/var/lib/app-host/drill/<stamp>/`. Start it with `runuser -u postgres -- /usr/lib/postgresql/16/bin/pg_ctl -D <dir>/data -o "-c config_file=<dir>/postgresql.conf" -l <dir>/server.log start`, sign in as `app_owner` with `psql "host=127.0.0.1 port=5433 dbname=app_drill sslmode=require"`, and open a recent sale. Then stop it (`pg_ctl -D <dir>/data stop`) and delete the directory: it holds every tenant's data.
5. Record the date, backup used, durations, `backup:verify` output and the person who ran it in the ops log. A failure is an incident: fix it and repeat within the week.

`restore-drill.sh --source dir:<path>` restores a `pg_basebackup -X stream` copy instead of WAL-G, optionally to a point in time with `--wal-archive <dir>`. That path was rehearsed on 2026-10-22 (docs/deploy-rehearsal-2026-10-22.md).

## Logical copy (pg_dump), rehearsed

For a copy before a risky migration, a move to another host (docs/deploy-linode.md, Scale out), or a copy while backups are off. Rehearsed on 2026-10-22 (docs/deploy-rehearsal-2026-10-22.md): dump, restore and `backup:verify` passed (343 tables, every row), and the runtime role read the copy only under its tenant.

```sh
# 1. Dump as the owner (BYPASSRLS, so every tenant's rows are included). Keep the file private.
umask 077
PGPASSWORD="$DB_OWNER_PASSWORD" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U app_owner -d app -Fc -f app-$(date -u +%Y%m%dT%H%M%SZ).dump

# 2. A second, empty database on the same cluster, with the same roles and grants (as the cluster admin,
#    on the single host db_admin over TLS on localhost; DB_ADMIN_PASSWORD is in the password manager).
PGHOST=127.0.0.1 PGPORT=5432 PGUSER=db_admin PGPASSWORD=… PGSSLMODE=require DB_NAME=app_restore \
APP_PASSWORD=… APP_OWNER_PASSWORD=… APP_PLATFORM_PASSWORD=… bash api/database/scripts/provision-db.sh
#    The three passwords must be the current ones: the script sets them on the shared roles.

# 3. Restore as the owner.
PGPASSWORD="$DB_OWNER_PASSWORD" pg_restore -h "$DB_HOST" -p "$DB_PORT" -U app_owner -d app_restore --exit-on-error app-….dump

# 4. Compare (on the deploy host, which has DB_OWNER_*).
php artisan backup:verify --target-database=app_restore

# 5. Drop the copy and shred the dump when done (as the admin): DROP DATABASE app_restore;
```

A restore onto another cluster needs the three roles there first (`provision-db.sh` with that cluster's admin), then `BACKUP_VERIFY_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD` for step 4. Give the `BACKUP_VERIFY_*` values on the command line (`BACKUP_VERIFY_HOST=… php artisan backup:verify …`), never in `.env`: the deploy caches the config, and the command reads them from the process environment. Dumps hold every tenant's personal data: never leave them on a laptop or in a bucket without encryption.

`BackupVerifyTest` covers the comparison rules and the command.
