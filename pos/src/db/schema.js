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
 * v5 (document templates, TPL-01, TPL-05): the POS module's synced
 * `templates` (the receipt templates that apply at the till's branch).
 *
 * v6 (LAY-05, BR-02): the POS module's synced `pos_layout` (the sell
 * screen's layout) and local `media_cache` (logos and category images
 * fetched from the server as data URIs, so they show offline).
 *
 * v7 (Inventory module, INV-17): the synced `inventory_stock` (read-only).
 *
 * v8 (Loyalty module, LOY-05, LOY-11, LOY-22): the synced `loyalty_settings`,
 * `loyalty_promotions` (snapshots), `loyalty_members` (balances by
 * customer) and `loyalty_gift_cards` (by code hash, never the code).
 *
 * v9 (HR module, HR-22, HR-23): the synced `hr_clock_staff` (who clocks in
 * on this till, with their clock-in PIN material; a snapshot), and local
 * `hr_punches` (clock-ins and clock-outs made here; uploads go through the
 * outbox) and `hr_clock_attempts` (wrong clock-in PINs counted per
 * employee on this till).
 *
 * v10 (POS-08, POS-10, owner decision 2026-10-22): local `pos_sales` keeps
 * the buyer snapshot (`buyer_tax_id`, `buyer_name`) the sale was printed
 * and uploaded with; null without a customer tax id.
 *
 * v11 (Pharmacy module, PHA-25): the synced `pharmacy_settings` (a snapshot),
 * `pharmacy_items` (profiles) and `pharmacy_lots` (the lots with stock at this
 * till's location, with their expiry and blocked state), read-only.
 * v12 (Restaurant module, REST-02, REST-06, REST-08, REST-13, REST-17): the
 * synced `restaurant_tables`, `restaurant_stations`, `restaurant_modifiers`,
 * `restaurant_settings` and `restaurant_orders` (the server's open orders,
 * folded into the till's), and local `rest_orders`: the open orders and tabs
 * kept on this till (`dirty` while a change waits to upload).
 *
 * v13 (Hotel module, HOT-35): the synced `hotel_in_house` (who is in house at
 * this till's property: room, guest name, folio, charge allowed and limit; a
 * snapshot), read-only, for the room-charge tender offline.
 *
 * v14 (Van sales module, VAN-27): the synced `van_sales_van`, `van_sales_routes`,
 * `van_sales_accounts` (by customer), `van_sales_loads` and `van_sales_runs`, and local
 * `vs_records`: what the rep's device makes (runs, visits, collections, counts), one row
 * each, with `state` pending until the server stored it (or rejected, parked with its code).
 *
 * v15 (Fuel station module, FUEL-22): the synced `fuel_station`, `fuel_nozzles`,
 * `fuel_prices`, `fuel_tanks` (with their strapping charts), `fuel_vehicles` and
 * `fuel_vouchers`, server-wins and read-only, and local `fuel_shifts`: the
 * forecourt shifts kept on this till (open or closed; uploads go through the
 * outbox).
 * v16 (Salon module, SAL-29): the synced `salon_settings`, `salon_staff` (staff and resources, with
 * their duty spans), `salon_services`, `salon_schedule` (a week of appointments), `salon_queue` and
 * `salon_packages`, and local `salon_records`: what the till makes (appointments, day steps, queue
 * changes), one row each, pending until the server stored it (or rejected, parked with its code).
 *
 * Raise the version and add a step to migrations.js for every change.
 */
export const SCHEMA_VERSION = 16;

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
    // v5: receipt templates (TPL-01).
    tableSchema({ name: 'templates', columns: [data, seenAt] }),
    // v6: the sell screen's layout (LAY-05).
    tableSchema({ name: 'pos_layout', columns: [data, seenAt] }),
    // v8: the Loyalty module's synced entities.
    tableSchema({ name: 'loyalty_settings', columns: [data, seenAt] }),
    tableSchema({ name: 'loyalty_promotions', columns: [data, seenAt] }),
    tableSchema({ name: 'loyalty_members', columns: [{ name: 'customer_id', type: 'string', isIndexed: true }, { name: 'kind', type: 'string' }, data, seenAt] }),
    tableSchema({ name: 'loyalty_gift_cards', columns: [{ name: 'code_hash', type: 'string', isIndexed: true }, data, seenAt] }),
    // v11: the Pharmacy module's synced entities (rules, item profiles, lots with stock here).
    tableSchema({ name: 'pharmacy_settings', columns: [data, seenAt] }),
    tableSchema({ name: 'pharmacy_items', columns: [{ name: 'item_id', type: 'string', isIndexed: true }, data, seenAt] }),
    tableSchema({ name: 'pharmacy_lots', columns: [{ name: 'item_id', type: 'string', isIndexed: true }, data, seenAt] }),
    // v13: the Hotel module's in-house guests (HOT-35), a snapshot.
    tableSchema({ name: 'hotel_in_house', columns: [{ name: 'room_number', type: 'string', isIndexed: true }, data, seenAt] }),
    // v14: the Fuel station module's synced entities (FUEL-22): the station, nozzles with their last
    // readings, prices (current and scheduled), tanks with strapping charts, fleet vehicles and vouchers.
    tableSchema({ name: 'fuel_station', columns: [data, seenAt] }),
    tableSchema({ name: 'fuel_nozzles', columns: [data, seenAt] }),
    tableSchema({ name: 'fuel_prices', columns: [data, seenAt] }),
    tableSchema({ name: 'fuel_tanks', columns: [data, seenAt] }),
    tableSchema({ name: 'fuel_vehicles', columns: [{ name: 'registration', type: 'string', isIndexed: true }, { name: 'party_id', type: 'string', isOptional: true, isIndexed: true }, data, seenAt] }),
    tableSchema({ name: 'fuel_vouchers', columns: [{ name: 'code', type: 'string', isIndexed: true }, data, seenAt] }),
    // v7: stock on hand at this till's location (Inventory, INV-17), read-only.
    tableSchema({ name: 'inventory_stock', columns: [{ name: 'item_id', type: 'string', isIndexed: true }, data, seenAt] }),
    // v9: the HR module's clock-in staff (HR-23), a snapshot.
    tableSchema({ name: 'hr_clock_staff', columns: [{ name: 'name', type: 'string' }, { name: 'number', type: 'string' }, { name: 'version', type: 'number' }, data, seenAt] }),
    // v11: the Restaurant module's synced entities (REST-02, REST-08, REST-07, REST-13, REST-17).
    tableSchema({ name: 'restaurant_tables', columns: [data, seenAt] }),
    tableSchema({ name: 'restaurant_stations', columns: [data, seenAt] }),
    tableSchema({ name: 'restaurant_modifiers', columns: [data, seenAt] }),
    tableSchema({ name: 'restaurant_settings', columns: [data, seenAt] }),
    tableSchema({ name: 'restaurant_orders', columns: [data, seenAt] }),
    // v13: the Van sales module's synced entities (VAN-27).
    tableSchema({ name: 'van_sales_van', columns: [data, seenAt] }),
    tableSchema({ name: 'van_sales_routes', columns: [data, seenAt] }),
    tableSchema({ name: 'van_sales_accounts', columns: [data, seenAt] }),
    tableSchema({ name: 'van_sales_loads', columns: [data, seenAt] }),
    tableSchema({ name: 'van_sales_runs', columns: [data, seenAt] }),
    // v13: runs, visits, collections and counts made on this device (VAN-27); `state` is pending until uploaded.
    tableSchema({
      name: 'vs_records',
      columns: [
        // run | visit | collection | count
        { name: 'kind', type: 'string', isIndexed: true },
        { name: 'run_id', type: 'string', isIndexed: true },
        { name: 'customer_id', type: 'string', isOptional: true, isIndexed: true },
        // pending | stored | rejected
        { name: 'state', type: 'string', isIndexed: true },
        { name: 'seq', type: 'number', isIndexed: true },
        { name: 'created_at', type: 'number' },
        data,
      ],
    }),
    // v16: the Salon module's synced entities and what the till makes (SAL-29).
    tableSchema({ name: 'salon_settings', columns: [data, seenAt] }),
    tableSchema({ name: 'salon_staff', columns: [data, seenAt] }),
    tableSchema({ name: 'salon_services', columns: [data, seenAt] }),
    tableSchema({ name: 'salon_schedule', columns: [data, seenAt] }),
    tableSchema({ name: 'salon_queue', columns: [data, seenAt] }),
    tableSchema({ name: 'salon_packages', columns: [data, seenAt] }),
    tableSchema({
      name: 'salon_records',
      columns: [
        // appointment | event | queue | mark
        { name: 'kind', type: 'string', isIndexed: true },
        { name: 'appointment_id', type: 'string', isIndexed: true },
        // pending | stored | rejected | local
        { name: 'state', type: 'string', isIndexed: true },
        { name: 'seq', type: 'number', isIndexed: true },
        { name: 'created_at', type: 'number' },
        data,
      ],
    }),
    // v6: images by server path (data URIs), local only.
    tableSchema({ name: 'media_cache', columns: [data, { name: 'fetched_at', type: 'number' }] }),

    // v4: selling, local only (uploads go through the outbox).
    tableSchema({
      name: 'pos_sales',
      columns: [
        { name: 'receipt_number', type: 'string', isIndexed: true },
        { name: 'shift_id', type: 'string', isIndexed: true },
        { name: 'status', type: 'string' },
        { name: 'sold_at', type: 'number', isIndexed: true },
        // v10: the buyer snapshot.
        { name: 'buyer_tax_id', type: 'string', isOptional: true, isIndexed: true },
        { name: 'buyer_name', type: 'string', isOptional: true },
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

    // v11: open orders and tabs kept on this till (REST-17); `dirty` while a change waits to upload.
    tableSchema({
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

    // v14: forecourt shifts kept on this till (FUEL-09, FUEL-10, FUEL-22), local only (uploads go through the outbox).
    tableSchema({ name: 'fuel_shifts', columns: [{ name: 'status', type: 'string', isIndexed: true }, { name: 'opened_at', type: 'number' }, data] }),

    // v9: clock-ins and clock-outs made on this till (HR-22, HR-23), local only (uploads go through the outbox).
    tableSchema({
      name: 'hr_punches',
      columns: [
        { name: 'employee_id', type: 'string', isIndexed: true },
        // in | out
        { name: 'kind', type: 'string' },
        { name: 'occurred_at', type: 'number', isIndexed: true },
      ],
    }),
    // v9: wrong clock-in PINs per employee on this till; `pin_stamp` names the PIN they were counted against.
    tableSchema({
      name: 'hr_clock_attempts',
      columns: [
        { name: 'failed_attempts', type: 'number' },
        { name: 'locked', type: 'boolean' },
        { name: 'pin_stamp', type: 'string' },
      ],
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
