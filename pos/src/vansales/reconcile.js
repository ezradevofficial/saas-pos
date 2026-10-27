import { add, compare, exact, sub } from '../pos/exact';
import { decimalString } from '../pos/payloads';

/**
 * VAN-18, VAN-19, VAN-20: the end-of-day count, computed on the device from
 * the confirmed load and the day's local records, so the rep sees the
 * equation before submitting. The server recomputes and decides (it answers
 * balanced / pending approval / rejected).
 *
 *   load = sold + returned + damaged + variance
 *
 * - load: the run's confirmed load plus goods customers returned to the van
 *   (a refund of a van sale), VAN-16, VAN-19;
 * - sold: gross quantity of the run's van sales, voided ones left out;
 * - returned and damaged: what the rep counts;
 * - variance: a shortage is positive, a surplus negative.
 * Quantities are exact decimal strings in the item's base unit.
 */
const zero = '0';
const qtyOf = (value) => exact(String(value ?? zero));

/** A van sale of this run that still stands. */
export const isRunSale = (sale, runId) => sale?.extensions?.van_sales?.run_id === runId && sale.local?.status !== 'voided';

/**
 * Items of the day: { item_id, item_name, load_qty, sold_qty, customer_returns }
 * from the confirmed load sheets (`loads`, the pulled rows), the run's sales
 * and the refunds recorded against them.
 */
export function dayItems({ runId, loads = [], sales = [], refunds = [] }) {
  const rows = new Map();
  const row = (itemId, name) => {
    if (!rows.has(itemId)) rows.set(itemId, { item_id: itemId, item_name: name ?? '', load: exact(0n), sold: exact(0n), returns: exact(0n) });
    const found = rows.get(itemId);
    if (name && !found.item_name) found.item_name = name;
    return found;
  };
  for (const load of loads) {
    if (load.status !== 'confirmed' || (load.run_id && load.run_id !== runId)) continue;
    for (const line of load.lines ?? []) {
      const entry = row(line.item_id, line.item_name);
      entry.load = add(entry.load, qtyOf(line.qty_confirmed));
    }
  }
  const mine = sales.filter((sale) => isRunSale(sale, runId));
  const lineOf = new Map();
  for (const sale of mine) {
    for (const line of sale.lines ?? []) {
      lineOf.set(line.id, line);
      const entry = row(line.item_id, line.item_name);
      entry.sold = add(entry.sold, qtyOf(line.qty));
    }
  }
  for (const refund of refunds) {
    for (const line of refund.lines ?? []) {
      const original = lineOf.get(line.sale_line_id);
      if (!original) continue;
      const entry = row(original.item_id, original.item_name);
      entry.returns = add(entry.returns, qtyOf(line.qty));
    }
  }
  return [...rows.values()]
    .map((entry) => ({ item_id: entry.item_id, item_name: entry.item_name, load_qty: decimalString(add(entry.load, entry.returns)), sold_qty: decimalString(entry.sold), customer_returns: decimalString(entry.returns) }))
    .sort((a, b) => a.item_name.localeCompare(b.item_name) || a.item_id.localeCompare(b.item_id));
}

/** The equation for one item with the counted figures: variance = load - sold - returned - damaged. */
export function stockEquation({ load_qty: load, sold_qty: sold, returned_qty: returned = zero, damaged_qty: damaged = zero }) {
  const variance = sub(sub(sub(qtyOf(load), qtyOf(sold)), qtyOf(returned)), qtyOf(damaged));
  return {
    load_qty: decimalString(qtyOf(load)),
    sold_qty: decimalString(qtyOf(sold)),
    returned_qty: decimalString(qtyOf(returned)),
    damaged_qty: decimalString(qtyOf(damaged)),
    variance_qty: decimalString(variance),
    balanced: compare(variance, exact(0n)) === 0,
  };
}

/** A count quantity typed by the rep: "" or "0" is zero, otherwise a decimal up to 6 places; null when invalid. */
export function parseCountQty(text) {
  const clean = String(text ?? '').trim().replace(',', '.');
  if (clean === '') return '0';
  if (!/^\d{1,12}(\.\d{1,6})?$/.test(clean)) return null;
  return decimalString(exact(clean));
}

const KINDS = { cash: 'cash', mobile_money: 'mobile_money' };

/**
 * VAN-20: expected cash and mobile money per currency, from the run's sales
 * (tenders less change given), collections and refunds. Voided sales and
 * voided collections count for nothing; credit never counts. Resolves
 * [{ kind, currency, expected_minor }] sorted by kind then currency.
 */
export function expectedTenders({ runId, sales = [], collections = [], refunds = [] }) {
  const totals = new Map();
  const addTo = (kind, currency, minor) => {
    if (!KINDS[kind]) return;
    const key = `${kind}:${currency}`;
    totals.set(key, (totals.get(key) ?? 0n) + BigInt(String(minor)));
  };
  const standing = sales.filter((sale) => isRunSale(sale, runId));
  const ids = new Set(standing.map((sale) => sale.id));
  for (const sale of standing) {
    for (const payment of sale.payments ?? []) addTo(sale.local?.methods?.[payment.id]?.type, payment.currency, payment.amount_minor);
    if (sale.change && BigInt(String(sale.change.amount_minor ?? 0)) > 0n) addTo('cash', sale.change.currency, -BigInt(String(sale.change.amount_minor)));
  }
  for (const collection of collections) {
    if (collection.run_id === runId && !collection.local?.voided) addTo(collection.local?.method_type, collection.currency, collection.amount_minor);
  }
  for (const refund of refunds) {
    if (!ids.has(refund.sale_id)) continue;
    for (const payment of refund.payments ?? []) addTo(refund.local?.method_type, payment.currency, -BigInt(String(payment.amount_minor)));
  }
  return [...totals.entries()]
    .map(([key, minor]) => {
      const [kind, currency] = key.split(':');
      return { kind, currency, expected_minor: String(minor) };
    })
    .sort((a, b) => a.kind.localeCompare(b.kind) || a.currency.localeCompare(b.currency));
}

/** Counted against expected: [{ kind, currency, expected_minor, counted_minor, variance_minor }] (variance = counted - expected). */
export function tenderVariances(expected, counted = {}) {
  return expected.map((row) => {
    const minor = counted[`${row.kind}:${row.currency}`];
    const countedMinor = minor === undefined || minor === '' ? null : BigInt(String(minor));
    return { ...row, counted_minor: countedMinor === null ? null : String(countedMinor), variance_minor: countedMinor === null ? null : String(countedMinor - BigInt(row.expected_minor)) };
  });
}
