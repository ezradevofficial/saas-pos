import { addColumns, createTable, schemaMigrations } from '@nozbe/watermelondb/Schema/migrations';

/**
 * One step per schema version after 1 (src/db/schema.js); a test checks
 * that the steps reach SCHEMA_VERSION. A new synced entity (for example
 * `item_prices`) is a createTable step here, a table in schema.js and a
 * registration in src/sync/entities.js.
 */
const data = { name: 'data', type: 'string' };
const seenAt = { name: 'seen_at', type: 'number', isIndexed: true };

export const SYNCED_TABLES = [
  'items',
  'item_categories',
  'uoms',
  'customers',
  'settings',
  'currencies',
  'exchange_rates',
  'tax_codes',
  'tax_categories',
  'price_lists',
  'payment_methods',
  'staff',
];

export const migrations = schemaMigrations({
  migrations: [
    {
      // NFR-04: resets keep old rows until the last page; outbox groups; attempts still to report.
      toVersion: 2,
      steps: [
        ...SYNCED_TABLES.map((table) => addColumns({ table, columns: [{ name: 'seen_at', type: 'number', isIndexed: true }] })),
        addColumns({ table: 'outbox', columns: [{ name: 'group_key', type: 'string', isOptional: true, isIndexed: true }] }),
        addColumns({ table: 'local_pin_attempts', columns: [{ name: 'report_failed', type: 'number' }] }),
      ],
    },
    {
      // Item prices per list, item and unit (incremental sync entity `item_prices`).
      toVersion: 3,
      steps: [
        createTable({
          name: 'item_prices',
          columns: [
            { name: 'price_list_id', type: 'string', isIndexed: true },
            { name: 'item_id', type: 'string', isIndexed: true },
            { name: 'uom_id', type: 'string' },
            { name: 'amount_minor', type: 'string' },
            { name: 'currency', type: 'string' },
            { name: 'effective_from', type: 'string' },
            { name: 'min_quantity', type: 'string' },
            { name: 'server_updated_at', type: 'string', isOptional: true },
            { name: 'data', type: 'string' },
            { name: 'seen_at', type: 'number', isIndexed: true },
          ],
        }),
      ],
    },
    {
      // Selling (POS-01..POS-06): POS module entities and local sale records.
      toVersion: 4,
      steps: [
        createTable({ name: 'pos_number_ranges', columns: [{ name: 'document_type', type: 'string', isIndexed: true }, data, seenAt] }),
        createTable({ name: 'pos_open_shift', columns: [data, seenAt] }),
        createTable({
          name: 'pos_sales',
          columns: [
            { name: 'receipt_number', type: 'string', isIndexed: true },
            { name: 'shift_id', type: 'string', isIndexed: true },
            { name: 'status', type: 'string' },
            { name: 'sold_at', type: 'number', isIndexed: true },
            data,
          ],
        }),
        createTable({
          name: 'pos_records',
          columns: [
            { name: 'kind', type: 'string', isIndexed: true },
            { name: 'sale_id', type: 'string', isOptional: true, isIndexed: true },
            { name: 'shift_id', type: 'string', isIndexed: true },
            { name: 'created_at', type: 'number' },
            data,
          ],
        }),
        createTable({ name: 'pos_shifts', columns: [{ name: 'status', type: 'string', isIndexed: true }, { name: 'opened_at', type: 'number' }, data] }),
        createTable({ name: 'pos_held', columns: [{ name: 'created_at', type: 'number' }, data] }),
        createTable({ name: 'pos_counters', columns: [data] }),
        createTable({ name: 'pos_state', columns: [data] }),
      ],
    },
    {
      // LAY-05, BR-02: the sell screen's layout and the image cache.
      toVersion: 5,
      steps: [
        createTable({ name: 'pos_layout', columns: [data, seenAt] }),
        createTable({ name: 'media_cache', columns: [data, { name: 'fetched_at', type: 'number' }] }),
      ],
    },
  ],
});
