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
 * Raise the version and add a step to migrations.js for every change.
 */
export const SCHEMA_VERSION = 1;

const data = { name: 'data', type: 'string' };
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
      ],
    }),
    tableSchema({
      name: 'uoms',
      columns: [{ name: 'code', type: 'string' }, { name: 'name', type: 'string' }, serverUpdatedAt, data],
    }),
    tableSchema({
      name: 'customers',
      columns: [
        { name: 'name', type: 'string' },
        // Digits of every phone number, space separated, for lookup at the till.
        { name: 'phones', type: 'string' },
        serverUpdatedAt,
        data,
      ],
    }),

    // Synced, snapshots.
    tableSchema({ name: 'settings', columns: [data] }),
    tableSchema({ name: 'currencies', columns: [{ name: 'code', type: 'string' }, data] }),
    tableSchema({
      name: 'exchange_rates',
      columns: [{ name: 'base', type: 'string', isIndexed: true }, { name: 'quote', type: 'string', isIndexed: true }, data],
    }),
    tableSchema({ name: 'tax_codes', columns: [{ name: 'code', type: 'string' }, data] }),
    tableSchema({ name: 'tax_categories', columns: [{ name: 'name', type: 'string' }, data] }),
    tableSchema({
      name: 'price_lists',
      columns: [{ name: 'name', type: 'string' }, { name: 'currency', type: 'string' }, { name: 'is_default', type: 'boolean' }, data],
    }),
    tableSchema({
      name: 'payment_methods',
      columns: [{ name: 'name', type: 'string' }, { name: 'type', type: 'string' }, { name: 'position', type: 'number' }, data],
    }),
    tableSchema({
      name: 'staff',
      columns: [{ name: 'name', type: 'string' }, { name: 'locked', type: 'boolean' }, data],
    }),

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
      ],
    }),
  ],
});
