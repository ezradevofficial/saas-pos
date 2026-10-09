/**
 * NFR-04: the server entities this app knows how to store (GET sync/pull,
 * ADR 004). Each maps an entity key to a table of src/db/schema.js and says
 * which payload fields become columns; the whole payload is kept in `data`.
 * `children` are tables rebuilt from a row's nested lists on every change
 * (an item's barcodes, for scanning).
 *
 * The server says per entity whether it is incremental or a snapshot
 * (bootstrap); the engine follows what the server says. Entities the server
 * offers but this app version has not registered are skipped until an
 * update adds them, so a new entity (for example `item_prices`) is a schema
 * migration plus one registerEntity() call.
 */
const registry = new Map();

const digits = (text) => String(text ?? '').replace(/\D+/g, '');

export function registerEntity(key, definition) {
  if (!/^[a-z][a-z0-9_]{0,39}$/.test(key)) throw new Error(`Invalid sync entity key [${key}]`);
  registry.set(key, { key, children: [], columns: () => ({}), ...definition });
}

export function entityDefinition(key) {
  return registry.get(key) ?? null;
}

export function registeredEntities() {
  return [...registry.values()];
}

registerEntity('settings', { table: 'settings' });
registerEntity('currencies', { table: 'currencies', columns: (row) => ({ code: row.code ?? row.id }) });
registerEntity('exchange_rates', { table: 'exchange_rates', columns: (row) => ({ base: row.base, quote: row.quote }) });
registerEntity('tax_codes', { table: 'tax_codes', columns: (row) => ({ code: row.code ?? '' }) });
registerEntity('tax_categories', { table: 'tax_categories', columns: (row) => ({ name: row.name ?? '' }) });
registerEntity('price_lists', {
  table: 'price_lists',
  columns: (row) => ({ name: row.name ?? '', currency: row.currency ?? '', is_default: Boolean(row.is_default) }),
});
registerEntity('payment_methods', {
  table: 'payment_methods',
  columns: (row) => ({ name: row.name ?? '', type: row.type ?? '', position: Number(row.position ?? 0) }),
});
registerEntity('uoms', {
  table: 'uoms',
  columns: (row) => ({ code: row.code ?? '', name: row.name ?? '', server_updated_at: row.updated_at ?? null }),
});
registerEntity('item_categories', {
  table: 'item_categories',
  columns: (row) => ({ name: row.name ?? '', parent_id: row.parent_id ?? null, server_updated_at: row.updated_at ?? null }),
});
registerEntity('items', {
  table: 'items',
  columns: (row) => ({
    code: row.code ?? '',
    name: row.name ?? '',
    category_id: row.category_id ?? null,
    sellable: Boolean(row.sellable),
    server_updated_at: row.updated_at ?? null,
  }),
  children: [
    {
      table: 'item_barcodes',
      parentColumn: 'item_id',
      rows: (row) =>
        (row.barcodes ?? []).map((barcode, index) => ({
          id: `${row.id}:${index}`,
          barcode: String(barcode.barcode),
          uom_id: barcode.uom_id ?? null,
        })),
    },
  ],
});
registerEntity('item_prices', {
  table: 'item_prices',
  columns: (row) => ({
    price_list_id: row.price_list_id,
    item_id: row.item_id,
    uom_id: row.uom_id,
    amount_minor: String(row.amount_minor),
    currency: row.currency,
    effective_from: row.effective_from,
    min_quantity: String(row.min_quantity ?? '1'),
    server_updated_at: row.updated_at ?? null,
  }),
});
registerEntity('customers', {
  table: 'customers',
  columns: (row) => ({
    name: row.name ?? '',
    phones: (row.phones ?? []).map((phone) => digits(typeof phone === 'string' ? phone : phone?.number)).filter(Boolean).join(' '),
    server_updated_at: row.updated_at ?? null,
  }),
});
registerEntity('staff', {
  table: 'staff',
  columns: (row) => ({ name: row.name ?? '', locked: Boolean(row.locked) }),
});

// The POS module's entities (served only while the tenant has POS, RBAC-08).
registerEntity('pos_number_ranges', { table: 'pos_number_ranges', columns: (row) => ({ document_type: row.document_type ?? '' }) });
registerEntity('pos_open_shift', { table: 'pos_open_shift' });
// TPL-01, TPL-05: the receipt templates of the till's branch (snapshot).
registerEntity('templates', { table: 'templates' });
