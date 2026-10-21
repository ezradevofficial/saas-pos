# Backups and recovery (NFR-07)

Daily full backups plus point-in-time recovery (PITR); RPO 15 minutes or less, RTO 4 hours or less; a restore tested every month.

## What is backed up

| Data | Where | Backup | RPO |
| --- | --- | --- | --- |
| PostgreSQL (everything business) | Linode Managed PostgreSQL | daily snapshot plus continuous WAL for PITR, kept 14 days (owner sets the window) | minutes (WAL); 15 min target |
| Media, exports, status (`media` disk) | Linode Object Storage | bucket versioning on, plus a nightly `rclone sync` to a second bucket in another region | 24 h (versioning covers deletes and overwrites at once) |
| Redis | Linode instance | none needed: cache, queues and locks. Queued jobs lost in a Redis failure are rebuilt by the schedulers (fiscal queue, payment timers, webhooks sweep, billing cycle) | n/a |
| Configuration | `api/.env` on each host, GitHub secrets | the vault (docs/security.md); until then an encrypted copy in the owner's password manager | on change |
| Code | GitHub | the repository | n/a |

Restoring the database also restores personal data that was anonymised after the restore point: after any restore, re-run the completed deletion requests (docs/privacy.md).

## Managed PostgreSQL settings (owner, once per environment)

- Plan with **high availability** (a standby node) for production; staging may run a single node.
- Backups: daily, retention 14 days, PITR on. Confirm in the cluster's settings and record them here.
- Access list: only the API and worker hosts' private addresses.
- Encrypted at rest (Linode default; confirm on creation).

## Restore (RTO 4 h)

Decide first: **point in time** (a bad migration or a mass edit: restore to just before it) or **latest** (the cluster is lost).

1. **Declare** the incident; put the API in maintenance (`php artisan down`) if it still runs, so no new writes go to a database about to be replaced. (15 min)
2. **Restore** from the Linode console or API into a **new** cluster: choose the backup or the point in time. Never overwrite the current cluster: it is the evidence and the fallback. (30 to 90 min, by size)
3. **Roles**: the restored cluster has the same roles (`app_owner`, `app`, `app_platform`) and passwords. Check with `psql` as each.
4. **Verify** the restore against the source (if the source is still readable) or against the last known counts:
   `php artisan backup:verify --target-database=<restored db>` with `BACKUP_VERIFY_HOST`, `BACKUP_VERIFY_PORT`, `BACKUP_VERIFY_USERNAME` (the owner role) and `BACKUP_VERIFY_PASSWORD` pointing at the new cluster. It compares the schema version, the tables and each table's rows; a point-in-time restore may lack recent rows within `--tolerance` (default 5%). (15 min)
5. **Switch**: point `DB_HOST` (and the owner and platform hosts) in every host's `.env` at the new cluster, `php artisan config:cache`, `php artisan app:preflight`, then `php artisan up`. Restart Horizon (`php artisan horizon:terminate`). (15 min)
6. **Reconcile**:
   - POS devices re-upload anything not acknowledged (idempotent, NFR-04): sales after the restore point come back by themselves as devices sync.
   - Re-run completed data deletion requests made after the restore point (docs/privacy.md).
   - Fiscal: compare submissions with KRA eTIMS and DGI for the gap; the fiscal queue resends what is missing.
   - Payments: reconcile M-Pesa callbacks for the gap from the Daraja portal.
7. **Close**: keep the old cluster for 7 days, then delete it. Write the incident report (cause, RPO and RTO achieved).

## Monthly restore test (runbook)

First working Monday of each month, on **staging** data restored from **production** backups (or from staging's own backups until production exists). 1 to 2 hours.

1. Note the time and the current schema version: `php artisan migrate:status --database=pgsql_owner | tail -1`.
2. Restore yesterday's backup and a point in time 15 minutes ago into two new clusters (console: Databases, the cluster, Backups, Restore).
3. Run `php artisan backup:verify --target-database=<db>` against each (as in step 4 above). Both must pass. A PITR restore within 15 minutes proves the RPO.
4. Time the whole exercise from step 2: it must fit the 4-hour RTO with room to spare.
5. Spot-check: sign in to a restored copy with a staging test user (a throwaway API host pointed at it) and open a recent sale.
6. Delete the test clusters. Record date, backup used, durations, `backup:verify` output and the person who ran it in the ops log. A failure is an incident: fix and repeat within the week.

`BackupVerifyTest` covers the comparison rules and the command.
