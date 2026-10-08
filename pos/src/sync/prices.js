import { Q } from '@nozbe/watermelondb';

/**
 * MD-03, NFR-04: the price of an item in a unit at the till, offline, with
 * the server's rules (App\Core\MasterData\Prices\PriceResolver):
 *
 * 1. The unit's own prices effective on the day (effective_from <= day)
 *    with min_quantity <= quantity: the highest quantity break wins, then
 *    the latest start date. An explicit unit price always wins.
 * 2. Else, for a unit other than the base unit, the base unit's price for
 *    quantity × factor base units, by the same rule, times the factor,
 *    rounded once, half up, to the currency's minor unit.
 * 3. Else null (the item cannot be sold from this list).
 *
 * The unit must be the item's base unit or one of its other units. The
 * day is the local date where the till sells (the settings' time zone):
 * use localDate(). Archived prices never reach the device (tombstones).
 * Decimals are compared and multiplied as scaled BigInts, never floats.
 */

/** "1.25" → { int: 125n, scale: 2 }. Throws on anything that is not a plain decimal. */
function decimal(value) {
  const text = String(value).trim();
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(text);
  if (!match) throw new Error(`Not a decimal number [${text}]`);
  const fraction = match[3] ?? '';
  const int = BigInt(`${match[2]}${fraction}`) * (match[1] ? -1n : 1n);
  return { int, scale: fraction.length };
}

function compare(a, b) {
  const x = decimal(a);
  const y = decimal(b);
  const scale = Math.max(x.scale, y.scale);
  const left = x.int * 10n ** BigInt(scale - x.scale);
  const right = y.int * 10n ** BigInt(scale - y.scale);
  return left < right ? -1 : left > right ? 1 : 0;
}

function multiplyDecimals(a, b) {
  const x = decimal(a);
  const y = decimal(b);
  const int = x.int * y.int;
  const scale = x.scale + y.scale;
  if (scale === 0) return String(int);
  const negative = int < 0n;
  const digits = String(negative ? -int : int).padStart(scale + 1, '0');
  return `${negative ? '-' : ''}${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}

/** minor × factor, rounded half up (away from zero) to a whole minor unit, as a string. */
export function multiplyMinor(amountMinor, factor) {
  const product = decimal(multiplyDecimals(amountMinor, factor));
  if (product.scale === 0) return String(product.int);
  const unit = 10n ** BigInt(product.scale);
  const negative = product.int < 0n;
  const absolute = negative ? -product.int : product.int;
  let whole = absolute / unit;
  if ((absolute % unit) * 2n >= unit) whole += 1n;
  return String(negative ? -whole : whole);
}

/** The date (Y-m-d) of `at` in `timeZone` (the branch's or company's, from settings). */
export function localDate(at = Date.now(), timeZone = 'UTC') {
  try {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date(at));
    const get = (type) => parts.find((part) => part.type === type)?.value;
    return `${get('year')}-${get('month')}-${get('day')}`;
  } catch {
    return new Date(at).toISOString().slice(0, 10);
  }
}

function best(prices, uomId, day, quantity) {
  let chosen = null;
  for (const price of prices) {
    if (price.uom_id !== uomId || price.effective_from > day || compare(price.min_quantity ?? '1', quantity) > 0) continue;
    if (
      !chosen ||
      compare(price.min_quantity ?? '1', chosen.min_quantity ?? '1') > 0 ||
      (compare(price.min_quantity ?? '1', chosen.min_quantity ?? '1') === 0 && price.effective_from > chosen.effective_from)
    ) {
      chosen = price;
    }
  }
  return chosen;
}

/**
 * Resolve from an item row (`base_uom_id`, `uoms: [{uom_id, factor}]`) and
 * that item's prices in one list. Resolves { amountMinor (string),
 * currency, source: 'unit' | 'base', factor, itemPriceId, effectiveFrom,
 * minQuantity } or null.
 */
export function resolvePrice({ item, uomId, prices, day, quantity = '1' }) {
  if (!item || !uomId) return null;
  const factor = uomId === item.base_uom_id ? '1' : (item.uoms ?? []).find((uom) => uom.uom_id === uomId)?.factor;
  if (factor == null) return null;

  const own = best(prices, uomId, day, String(quantity));
  if (own) {
    return { amountMinor: String(own.amount_minor), currency: own.currency, source: 'unit', factor: '1', itemPriceId: own.id, effectiveFrom: own.effective_from, minQuantity: own.min_quantity };
  }
  if (uomId === item.base_uom_id) return null;

  const base = best(prices, item.base_uom_id, day, multiplyDecimals(quantity, factor));
  if (!base) return null;
  return {
    amountMinor: multiplyMinor(base.amount_minor, factor),
    currency: base.currency,
    source: 'base',
    factor: String(factor),
    itemPriceId: base.id,
    effectiveFrom: base.effective_from,
    minQuantity: base.min_quantity,
  };
}

/** The price from the synced tables: item `itemId` in `uomId` from list `priceListId` on `day`. */
export async function priceFor(database, { itemId, uomId, priceListId, day, quantity = '1' }) {
  let item;
  try {
    item = (await database.get('items').find(itemId)).data;
  } catch {
    return null;
  }
  const rows = await database.get('item_prices').query(Q.where('item_id', itemId), Q.where('price_list_id', priceListId)).fetch();
  return resolvePrice({ item, uomId, prices: rows.map((row) => row.data), day, quantity });
}
