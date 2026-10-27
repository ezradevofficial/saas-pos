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
      // Document templates (TPL-01, TPL-05): the receipt templates synced from the server.
      toVersion: 5,
      steps: [createTable({ name: 'templates', columns: [data, seenAt] })],
    },
    {
      // LAY-05, BR-02: the sell screen's layout and the image cache.
      toVersion: 6,
      steps: [
        createTable({ name: 'pos_layout', columns: [data, seenAt] }),
        createTable({ name: 'media_cache', columns: [data, { name: 'fetched_at', type: 'number' }] }),
      ],
    },
    {
      // INV-17: stock on hand at the till's location (the Inventory module's read-only entity).
      toVersion: 7,
      steps: [createTable({ name: 'inventory_stock', columns: [{ name: 'item_id', type: 'string', isIndexed: true }, data, seenAt] })],
    },
    {
      // Loyalty module (LOY-05, LOY-11, LOY-22): settings, promotions, balances and gift cards.
      toVersion: 8,
      steps: [
        createTable({ name: 'loyalty_settings', columns: [data, seenAt] }),
        createTable({ name: 'loyalty_promotions', columns: [data, seenAt] }),
        createTable({ name: 'loyalty_members', columns: [{ name: 'customer_id', type: 'string', isIndexed: true }, { name: 'kind', type: 'string' }, data, seenAt] }),
        createTable({ name: 'loyalty_gift_cards', columns: [{ name: 'code_hash', type: 'string', isIndexed: true }, data, seenAt] }),
      ],
    },
    {
      // HR module (HR-22, HR-23): clock-in staff, punches made here and wrong clock-in PINs.
      toVersion: 9,
      steps: [
        createTable({ name: 'hr_clock_staff', columns: [{ name: 'name', type: 'string' }, { name: 'number', type: 'string' }, { name: 'version', type: 'number' }, data, seenAt] }),
        createTable({
          name: 'hr_punches',
          columns: [
            { name: 'employee_id', type: 'string', isIndexed: true },
            { name: 'kind', type: 'string' },
            { name: 'occurred_at', type: 'number', isIndexed: true },
          ],
        }),
        createTable({
          name: 'hr_clock_attempts',
          columns: [
            { name: 'failed_attempts', type: 'number' },
            { name: 'locked', type: 'boolean' },
            { name: 'pin_stamp', type: 'string' },
          ],
        }),
      ],
    },
    {
      // POS-08, POS-10: the buyer snapshot on local sales (older sales keep null: they upload as they were).
      toVersion: 10,
      steps: [
        addColumns({
          table: 'pos_sales',
          columns: [
            { name: 'buyer_tax_id', type: 'string', isOptional: true, isIndexed: true },
            { name: 'buyer_name', type: 'string', isOptional: true },
          ],
        }),
      ],
    },
    {
      // Pharmacy module (PHA-25): rules, item profiles and the lots with stock at this location.
      toVersion: 11,
      steps: [
        createTable({ name: 'pharmacy_settings', columns: [data, seenAt] }),
        createTable({ name: 'pharmacy_items', columns: [{ name: 'item_id', type: 'string', isIndexed: true }, data, seenAt] }),
        createTable({ name: 'pharmacy_lots', columns: [{ name: 'item_id', type: 'string', isIndexed: true }, data, seenAt] }),
      ],
    },
    {
      // Restaurant module (REST-02, REST-17): floor, stations, modifiers, settings, the server's orders
      // and the open orders kept on this till.
      toVersion: 12,
      steps: [
        createTable({ name: 'restaurant_tables', columns: [data, seenAt] }),
        createTable({ name: 'restaurant_stations', columns: [data, seenAt] }),
        createTable({ name: 'restaurant_modifiers', columns: [data, seenAt] }),
        createTable({ name: 'restaurant_settings', columns: [data, seenAt] }),
        createTable({ name: 'restaurant_orders', columns: [data, seenAt] }),
        createTable({
          name: 'rest_orders',
          columns: [
            { name: 'kind', type: 'string' },
            { name: 'status', type: 'string', isIndexed: true },
            { name: 'table_id', type: 'string', isOptional: true, isIndexed: true },
            { name: 'waiter_id', type: 'string', isIndexed: true },
            { name: 'dirty', type: 'boolean', isIndexed: true },
            { name: 'opened_at', type: 'number' },
            data,
          ],
        }),
      ],
    },
    {
      // Hotel module (HOT-35): who is in house at this till's property (a snapshot).
      toVersion: 13,
      steps: [createTable({ name: 'hotel_in_house', columns: [{ name: 'room_number', type: 'string', isIndexed: true }, data, seenAt] })],
    },
    {
      // Van sales module (VAN-27): the synced route data and what the rep's device makes.
      toVersion: 14,
      steps: [
        createTable({ name: 'van_sales_van', columns: [data, seenAt] }),
        createTable({ name: 'van_sales_routes', columns: [data, seenAt] }),
        createTable({ name: 'van_sales_accounts', columns: [data, seenAt] }),
        createTable({ name: 'van_sales_loads', columns: [data, seenAt] }),
        createTable({ name: 'van_sales_runs', columns: [data, seenAt] }),
        createTable({
          name: 'vs_records',
          columns: [
            { name: 'kind', type: 'string', isIndexed: true },
            { name: 'run_id', type: 'string', isIndexed: true },
            { name: 'customer_id', type: 'string', isOptional: true, isIndexed: true },
            { name: 'state', type: 'string', isIndexed: true },
            { name: 'seq', type: 'number', isIndexed: true },
            { name: 'created_at', type: 'number' },
            data,
          ],
        }),
      ],
    },
  ],
});
