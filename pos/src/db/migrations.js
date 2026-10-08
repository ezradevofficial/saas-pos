import { addColumns, createTable, schemaMigrations } from '@nozbe/watermelondb/Schema/migrations';

/**
 * One step per schema version after 1 (src/db/schema.js); a test checks
 * that the steps reach SCHEMA_VERSION. A new synced entity (for example
 * `item_prices`) is a createTable step here, a table in schema.js and a
 * registration in src/sync/entities.js.
 */
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
  ],
});
