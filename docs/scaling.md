# Scaling assumptions (NFR-05)

Target for year one: 5,000 tenants and 50,000 POS devices without re-architecture. This is the sizing the design assumes and what changes, in order, as it grows.

## Load model

| Quantity | Year-one assumption |
| --- | --- |
| Tenants | 5,000; median 2 companies, 3 branches, 10 devices; a long tail of single-till shops |
| Devices | 50,000; most sync every 30 to 60 s while online, offline up to 7 days (NFR-04) |
| Sales | about 2 million a day at peak (40 per device per day); 4 lines each on average |
| Stock movements | about one per sale line with Inventory: about 8 million a day |
| Audit entries | several per business action: about 10 million a day |
| Usage meters | one row per tenant, unit and day (LIM-01): about 30,000 a day |
| Back-office users | about 20,000 active a day, mostly reads |

## What already scales out

- **API hosts are stateless.** Sessions are bearer tokens in PostgreSQL, the cache and queues are Redis, files are on Object Storage. Add hosts behind the NodeBalancer; Horizon workers run on their own hosts (docs/deploy-linode.md).
- **Queues are separated** by kind (default, notifications, automation, payments, fiscal, exports, imports, webhooks), each with its own Horizon supervisor and process limits, so a burst of one never delays another.
- **Sync is incremental and idempotent** (ADR 004): device pulls use `updated_at`/`sync_xid` cursors on indexed columns; uploads ignore known UUIDs.
- **Scheduled work fans out per tenant** through security-definer functions that return only tenant ids with work due (ADR 002), so the scheduler does not scan every tenant.

## Indexes

- Every tenant table's `tenant_id` is indexed (`Blueprint::tenantId()`), and row-level security adds `tenant_id = current tenant` to every query, so indexes for hot reads lead with `tenant_id` (or with `company_id`, whose rows belong to one tenant).
- **`ForeignKeyIndexTest`** fails when a foreign key in a tenant table has no index whose leading columns serve it (optionally after `tenant_id`/`company_id`). Exempt by rule: references to the currency catalogue and `*_by` actor columns. The 333 existing keys without one are a reviewed allow-list (`tests/Feature/Schema/ForeignKeyIndexAllowList.php`) on configuration, header and low-volume tables; the test also fails on entries no longer needed, so the list only shrinks. New tables must index their keys.
- Stage 8C added the two obvious gaps on the largest table: `pos_sales (tenant_id, customer_id, sold_at)` (partial, customer history and data subject requests) and `pos_sales (tenant_id, device_id, sold_at)`, both built concurrently.

## Partitioning candidates

Not partitioned yet: below about 200 million rows a table with the right indexes is simpler and fast enough. Partition when a table passes that, or when its indexes stop fitting in memory. The plan for each:

| Table | Grows by | Partition by | Notes |
| --- | --- | --- | --- |
| `pos_sales`, `pos_sale_lines`, `pos_sale_payments` | ~2 M, 8 M, 2.5 M a day | range on `sold_at` (monthly) | lines and payments by their sale's month (add `sold_at` to them first); unique `(tenant_id, receipt_number)` must include the partition key, so it becomes `(tenant_id, receipt_number, sold_at)` plus a lookup table if global uniqueness is still needed |
| `inventory_movements` | ~8 M a day | range on `occurred_at` (monthly) | stock on hand is kept in `inventory_stock`, so old partitions are read only by reports and valuation |
| `audit_logs` | ~10 M a day | range on `occurred_at` (monthly) | the hash chain is per tenant and ordered by `seq`; partitioning by time keeps each tenant's chain readable across partitions. The append-only trigger moves to each partition. Old partitions can move to cheaper storage but are never deleted (AUD-03) |
| `usage_meters` | ~30 k a day | range on `day` (yearly) | small; partition only for retention |
| `notification_deliveries`, `webhook_deliveries`, `automation_runs` | high, short-lived | range on `created_at` (monthly) | candidates for dropping old partitions once their retention is agreed |

Hash partitioning by `tenant_id` is not planned: most tenants are small, and row-level security already confines every query to one tenant through the indexes.

## Database sizing and limits

- **Connections.** Each API PHP-FPM worker and each Horizon process holds one connection. Session pooling only (never transaction pooling: the tenant setting is per session, ADR 002). Size `max_connections` to (API hosts × FPM workers) + (worker hosts × Horizon processes) + 20; move to PgBouncer in session mode when that passes about 400.
- **Device sync waits on the oldest transaction in the cluster** (ADR 004): keep the database cluster to this application, and watch `app_sync_lag_seconds`.
- **Read replicas** for reports and exports are the next step after vertical scaling: exports and dashboards already read through repeatable-read, read-only transactions, so they can move to a replica with a separate connection.
- **Lock slots.** The isolation fixture and full exports touch many tables in one transaction: `max_locks_per_transaction = 256` in CI, locally and on the self-managed host (ADR 025).

## When to revisit

Re-check this file when any of these is reached: 1,000 tenants, 10,000 devices, a table above 100 million rows, the p95 alert firing weekly, or Horizon queues backing up daily.
