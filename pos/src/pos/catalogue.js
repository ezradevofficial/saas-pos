import { Q } from '@nozbe/watermelondb';
import { localDate, resolvePrice } from '../sync/prices';
import { createCurrencies } from './currency';
import { taxRateFor, TaxRateNeeded } from './tax';

/**
 * MD-03, POS-01, POS-11, NFR-03: everything the selling screen reads from
 * the synced tables, loaded once per sync into memory (so adding to the
 * cart never waits on the database):
 *
 * - settings (company, branch, location, time zone), currencies and rates;
 * - tax codes, price lists (the company's default list sets the sale
 *   currency), payment methods in their order;
 * - items with their price on today's default list (sales unit, qty 1),
 *   sellability re-checked for today's tax rate (a "Rate needed" item is
 *   shown disabled with the reason; rates are never invented), a lower-
 *   cased search key, and a barcode index;
 * - prices per list and item, for quantity breaks and customer lists.
 */
export async function loadCatalogue({ database, now = Date.now() }) {
  const all = async (name) => (await database.get(name).query().fetch()).map((record) => record.data).filter(Boolean);
  const [settingsRows, currencies, rates, taxCodes, priceLists, paymentMethods, categories, items, prices, uoms, staff] = await Promise.all([
    all('settings'),
    all('currencies'),
    all('exchange_rates'),
    all('tax_codes'),
    all('price_lists'),
    all('payment_methods'),
    all('item_categories'),
    all('items'),
    all('item_prices'),
    all('uoms'),
    all('staff'),
  ]);
  const settings = settingsRows.find((row) => row.id === 'device') ?? settingsRows[0] ?? null;
  return buildCatalogue({ settings, currencies, rates, taxCodes, priceLists, paymentMethods, categories, items, prices, uoms, staff, now });
}

/** The catalogue from plain rows (tests build it directly). */
export function buildCatalogue({ settings = null, currencies = [], rates = [], taxCodes = [], priceLists = [], paymentMethods = [], categories = [], items = [], prices = [], uoms = [], staff = [], now = Date.now() }) {
  const timeZone = settings?.timezone ?? 'UTC';
  const day = localDate(now, timeZone);
  const money = createCurrencies({ currencies, rates });
  const taxById = new Map(taxCodes.map((code) => [code.id, code]));
  const uomById = new Map(uoms.map((uom) => [uom.id, uom]));
  const listById = new Map(priceLists.map((list) => [list.id, list]));
  const defaultList = priceLists.find((list) => list.is_default) ?? priceLists[0] ?? null;
  const baseCurrency = settings?.company?.base_currency ?? defaultList?.currency ?? null;
  const saleCurrency = defaultList?.currency ?? baseCurrency;

  // prices: list → item → rows
  const pricesByList = new Map();
  for (const price of prices) {
    if (!pricesByList.has(price.price_list_id)) pricesByList.set(price.price_list_id, new Map());
    const byItem = pricesByList.get(price.price_list_id);
    if (!byItem.has(price.item_id)) byItem.set(price.item_id, []);
    byItem.get(price.item_id).push(price);
  }

  const salesUom = (item) => item.uoms?.find((uom) => uom.is_sales_default)?.uom_id ?? item.base_uom_id;

  function priceFor(item, uomId, listId, quantity = '1') {
    const rows = pricesByList.get(listId)?.get(item.id) ?? [];
    return resolvePrice({ item, uomId, prices: rows, day, quantity: String(quantity) });
  }

  const barcodes = new Map();
  const tiles = [];
  const itemById = new Map();
  for (const item of items) {
    itemById.set(item.id, item);
    for (const barcode of item.barcodes ?? []) barcodes.set(String(barcode.barcode), { itemId: item.id, uomId: barcode.uom_id ?? null });
    const uomId = salesUom(item);
    const price = defaultList ? priceFor(item, uomId, defaultList.id) : null;
    let reason = item.sellable === false ? (item.reason ?? 'not_sellable') : null;
    if (!reason) {
      try {
        taxRateFor(taxById.get(item.tax_code_id), day);
      } catch (error) {
        if (!(error instanceof TaxRateNeeded)) throw error;
        reason = item.tax_code_id && taxById.has(item.tax_code_id) ? 'tax_rate_needed' : 'tax_code_missing';
      }
    }
    if (!reason && !price) reason = 'price_missing';
    tiles.push({
      id: item.id,
      name: item.name,
      code: String(item.code ?? ''),
      categoryId: item.category_id ?? null,
      uomId,
      price: price?.amountMinor ?? null,
      currency: price?.currency ?? saleCurrency,
      sellable: !reason,
      reason,
      search: `${String(item.name ?? '').toLowerCase()} ${String(item.code ?? '').toLowerCase()}`,
    });
  }
  tiles.sort((a, b) => a.name.localeCompare(b.name));

  const usedCategories = new Set(tiles.map((tile) => tile.categoryId).filter(Boolean));
  const chips = categories.filter((category) => usedCategories.has(category.id)).sort((a, b) => String(a.name).localeCompare(String(b.name)));

  /** Second currency for dual display (CUR-05): the first of base and reporting currencies other than the sale's, with a rate. */
  const dualCurrency = (() => {
    const candidates = [baseCurrency, ...(settings?.company?.reporting_currencies ?? [])].filter((code) => code && code !== saleCurrency);
    return candidates.find((code) => money.rateFor(saleCurrency, code, now)) ?? null;
  })();

  /** Currencies counted in the drawer: base, reporting and cash methods' currencies. */
  const cashCurrencies = [
    ...new Set([baseCurrency, ...(settings?.company?.reporting_currencies ?? []), ...paymentMethods.filter((method) => method.type === 'cash' && method.currency).map((method) => method.currency)].filter(Boolean)),
  ];

  return {
    settings,
    timeZone,
    day,
    money,
    taxCodes: taxById,
    uoms: uomById,
    priceLists,
    listById,
    defaultList,
    baseCurrency,
    saleCurrency,
    dualCurrency,
    cashCurrencies,
    paymentMethods: [...paymentMethods].sort((a, b) => Number(a.position ?? 0) - Number(b.position ?? 0)),
    categories: chips,
    tiles,
    itemById,
    barcodes,
    staff,
    priceFor,
    salesUom,
  };
}

/** Tiles matching a category and a typed search (name or code), in order. */
export function filterTiles(tiles, { categoryId = null, query = '' } = {}) {
  const text = query.trim().toLowerCase();
  return tiles.filter((tile) => (!categoryId || tile.categoryId === categoryId) && (!text || tile.search.includes(text)));
}

/** The item a scanned or typed code names: a barcode first, then an exact item code. */
export function findByCode(catalogue, code) {
  const text = String(code ?? '').trim();
  if (!text) return null;
  const barcode = catalogue.barcodes.get(text);
  if (barcode) return { item: catalogue.itemById.get(barcode.itemId), uomId: barcode.uomId };
  const tile = catalogue.tiles.find((candidate) => candidate.code.toLowerCase() === text.toLowerCase());
  return tile ? { item: catalogue.itemById.get(tile.id), uomId: null } : null;
}

/** Customers whose name or phone matches (POS-08), from the synced table. */
export async function searchCustomers(database, query, limit = 20) {
  const text = query.trim();
  if (!text) return (await database.get('customers').query(Q.sortBy('name', Q.asc), Q.take(limit)).fetch()).map((record) => record.data);
  const like = `%${Q.sanitizeLikeString(text)}%`;
  const digits = text.replace(/\D+/g, '');
  const conditions = [Q.where('name', Q.like(like))];
  if (digits.length >= 3) conditions.push(Q.where('phones', Q.like(`%${Q.sanitizeLikeString(digits)}%`)));
  return (await database.get('customers').query(Q.or(...conditions), Q.sortBy('name', Q.asc), Q.take(limit)).fetch()).map((record) => record.data);
}
