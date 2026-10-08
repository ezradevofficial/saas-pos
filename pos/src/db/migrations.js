import { schemaMigrations } from '@nozbe/watermelondb/Schema/migrations';

/**
 * One step per schema version after 1 (src/db/schema.js). A new synced
 * entity (for example `item_prices`) is a createTable step here, a table in
 * schema.js and a registration in src/sync/entities.js.
 *
 *     { toVersion: 2, steps: [createTable({ name: 'item_prices', columns: [...] })] }
 */
export const migrations = schemaMigrations({ migrations: [] });
