import { appSchema, tableSchema } from '@nozbe/watermelondb';

/**
 * NFR-04, ADR 004: the till's local database (WatermelonDB: SQLite on the
 * device, LokiJS in the web preview and in Jest).
 *
 * Synced tables mirror one server entity each (src/sync/entities.js). Every
 * synced row keeps the server's payload whole in `data` (JSON); the other
 * columns are copies of the fields the till filters or sorts on. Server
 * timestamps are kept as `server_updated_at` (WatermelonDB reserves
 * `updated_at` for numbers).
 *
 * Local tables: `sync_state` (cursor per entity, plus the `__meta` row),
 * `outbox` (records waiting to upload), `device` (this till's pairing;
 * token and secret live in the platform keystore, never here) and
 * `local_pin_attempts` (wrong PINs counted offline, AUTH-06).
 *
 * v4 (selling, POS-01..POS-06): the POS module's synced `pos_number_ranges`
 * and `pos_open_shift`, and local `pos_sales` (completed sales, kept for
 * receipts, voids and returns), `pos_records` (voids, refunds, cash
 * movements), `pos_shifts`, `pos_held` (parked carts, never uploaded) and
 * `pos_counters` (receipt numbers used per range) and `pos_state` (the open
 * cart, kept across restarts).
 *
 * v5 (LAY-05, BR-02): the POS module's synced `pos_layout` (the sell
 * screen's layout) and local `media_cache` (logos and category images
 * fetched from the server as data URIs, so they show offline).
 *
 * Raise the version and add a step to migrations.js for every change.
 */
export const SCHEMA_VERSION = 5;

const data = { name: 'data', type: 'string' };
// v2: the pull page that last wrote the row (a per-entity counter, never a clock), so
// a reset or replace removes only rows the server did not send again.
const seenAt = { name: 'seen_at', type: 'number', isIndexed: true };
const serverUpdatedAt = { name: 'server_updated_at', type: 'string', isOptional: true };

export const schema = appSchema({
  version: SCHEMA_VERSION,
  tables: [
    // Synced, incremental.
    tableSchema({
      name: 'items',
      columns: [
        { name: 'code', type: 'string', isIndexed: true },
        { name: 'name', type: 'string' },
        { name: 'category_id', type: 'string', isOptional: true, isIndexed: true },
        { name: 'sellable', type: 'boolean' },
        serverUpdatedAt,
        data,
        seenAt,
      ],
    }),
    // v3: prices per list, item and unit (incremental; tombstones when unusable).
    tableSchema({
      name: 'item_prices',
      columns: [
        { name: 'price_list_id', type: 'string', isIndexed: true },
        { name: 'item_id', type: 'string', isIndexed: true },
        { name: 'uom_id', type: 'string' },
        { name: 'amount_minor', type: 'string' },
        { name: 'currency', type: 'string' },
        { name: 'effective_from', type: 'string' },
        { name: 'min_quantity', type: 'string' },
        serverUpdatedAt,
        data,
        seenAt,
      ],
    }),
    tableSchema({
      name: 'item_barcodes',
      columns: [
        { name: 'item_id', type: 'string', isIndexed: true },
        { name: 'barcode', type: 'string', isIndexed: true },
        { name: 'uom_id', type: 'string', isOptional: true },
      ],
    }),
    tableSchema({
      name: 'item_categories',
      columns: [
        { name: 'name', type: 'string' },
        { name: 'parent_id', type: 'string', isOptional: true, isIndexed: true },
        serverUpdatedAt,
        data,
        seenAt,
      ],
    }),
    tableSchema({
      name: 'uoms',
      columns: [{ name: 'code', type: 'string' }, { name: 'name', type: 'string' }, serverUpdatedAt, data, seenAt],
    }),
    tableSchema({
      name: 'customers',
      columns: [
        { name: 'name', type: 'string' },
        // Digits of every phone number, space separated, for lookup at the till.
        { name: 'phones', type: 'string' },
        serverUpdatedAt,
        data,
        seenAt,
      ],
    }),

    // Synced, snapshots.
    tableSchema({ name: 'settings', columns: [data, seenAt] }),
    tableSchema({ name: 'currencies', columns: [{ name: 'code', type: 'string' }, data, seenAt] }),
    tableSchema({
      name: 'exchange_rates',
      columns: [{ name: 'base', type: 'string', isIndexed: true }, { name: 'quote', type: 'string', isIndexed: true }, data, seenAt],
    }),
    tableSchema({ name: 'tax_codes', columns: [{ name: 'code', type: 'string' }, data, seenAt] }),
    tableSchema({ name: 'tax_categories', columns: [{ name: 'name', type: 'string' }, data, seenAt] }),
    tableSchema({
      name: 'price_lists',
      columns: [{ name: 'name', type: 'string' }, { name: 'currency', type: 'string' }, { name: 'is_default', type: 'boolean' }, data, seenAt],
    }),
    tableSchema({
      name: 'payment_methods',
      columns: [{ name: 'name', type: 'string' }, { name: 'type', type: 'string' }, { name: 'position', type: 'number' }, data, seenAt],
    }),
    tableSchema({
      name: 'staff',
      columns: [{ name: 'name', type: 'string' }, { name: 'locked', type: 'boolean' }, data, seenAt],
    }),

    // v4: the POS module's synced entities.
    tableSchema({ name: 'pos_number_ranges', columns: [{ name: 'document_type', type: 'string', isIndexed: true }, data, seenAt] }),
    tableSchema({ name: 'pos_open_shift', columns: [data, seenAt] }),
    // v5: the sell screen's layout (LAY-05).
    tableSchema({ name: 'pos_layout', columns: [data, seenAt] }),
    // v5: images by server path (data URIs), local only.
    tableSchema({ name: 'media_cache', columns: [data, { name: 'fetched_at', type: 'number' }] }),

    // v4: selling, local only (uploads go through the outbox).
    tableSchema({
      name: 'pos_sales',
      columns: [
        { name: 'receipt_number', type: 'string', isIndexed: true },
        { name: 'shift_id', type: 'string', isIndexed: true },
        { name: 'status', type: 'string' },
        { name: 'sold_at', type: 'number', isIndexed: true },
        data,
      ],
    }),
    tableSchema({
      name: 'pos_records',
      columns: [
        // void | refund | cash_movement
        { name: 'kind', type: 'string', isIndexed: true },
        { name: 'sale_id', type: 'string', isOptional: true, isIndexed: true },
        { name: 'shift_id', type: 'string', isIndexed: true },
        { name: 'created_at', type: 'number' },
        data,
      ],
    }),
    tableSchema({
      name: 'pos_shifts',
      columns: [{ name: 'status', type: 'string', isIndexed: true }, { name: 'opened_at', type: 'number' }, data],
    }),
    tableSchema({ name: 'pos_held', columns: [{ name: 'created_at', type: 'number' }, data] }),
    tableSchema({ name: 'pos_counters', columns: [data] }),
    // The sale being rung up (and its payments in progress), so it survives an app restart.
    tableSchema({ name: 'pos_state', columns: [data] }),

    // Local.
    tableSchema({
      name: 'sync_state',
      columns: [
        { name: 'cursor', type: 'string', isOptional: true },
        { name: 'pulled_at', type: 'number', isOptional: true },
        { name: 'data', type: 'string', isOptional: true },
      ],
    }),
    tableSchema({
      name: 'outbox',
      columns: [
        { name: 'seq', type: 'number', isIndexed: true },
        { name: 'kind', type: 'string', isIndexed: true },
        { name: 'record_id', type: 'string', isIndexed: true },
        // v2: rows of one group (a shift) upload in order: a later row waits for an earlier pending one.
        { name: 'group_key', type: 'string', isOptional: true, isIndexed: true },
        { name: 'payload', type: 'string' },
        // pending | failed | acknowledged
        { name: 'status', type: 'string', isIndexed: true },
        { name: 'attempts', type: 'number' },
        { name: 'next_attempt_at', type: 'number' },
        { name: 'last_error', type: 'string', isOptional: true },
        { name: 'result', type: 'string', isOptional: true },
        { name: 'created_at', type: 'number' },
        { name: 'updated_at', type: 'number' },
      ],
    }),
    tableSchema({
      name: 'device',
      columns: [
        { name: 'device_id', type: 'string' },
        { name: 'name', type: 'string' },
        { name: 'location_id', type: 'string' },
        { name: 'status', type: 'string' },
        { name: 'paired_at', type: 'string', isOptional: true },
      ],
    }),
    tableSchema({
      name: 'local_pin_attempts',
      columns: [
        { name: 'failed_attempts', type: 'number' },
        { name: 'locked', type: 'boolean' },
        { name: 'occurred_at', type: 'string', isOptional: true },
        { name: 'pin_version', type: 'number' },
        { name: 'reported', type: 'boolean' },
        // v2: the count still to report (kept when a right PIN resets failed_attempts).
        { name: 'report_failed', type: 'number' },
      ],
    }),
  ],
});
