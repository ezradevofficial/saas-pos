import { addColumns, schemaMigrations } from '@nozbe/watermelondb/Schema/migrations';

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
  ],
});
