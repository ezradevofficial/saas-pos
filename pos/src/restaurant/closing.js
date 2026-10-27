import { uuidv7 } from '../lib/random';
import { equalQty, scaled, split, totals } from './bill';
import { billLines, liveLines } from './orderModel';
import { taxRateFor } from '../pos/tax';

/**
 * REST-13, REST-14, REST-16: closing an order into normal POS sales.
 *
 * buildPlan() decides the parts (whole bill, by item, by seat or equally)
 * with the shared bill maths (bill.js) and fixes, once, the id of each
 * part's sale and of each sale line, so the same part always becomes the
 * same sale (a retry or a restart never rings it twice). partCart() turns a
 * part into a cart the existing payment path takes: every line is the order
 * line at its unit price plus its modifier effects; the service charge and
 * the tip are lines of the items named in the company's settings, the
 * charge taxed by its item's tax code, the tip never taxed (REST-13).
 * `ext.restaurant` becomes the sale's `extensions.restaurant`.
 */
export const MODES = ['full', 'item', 'seat', 'equal'];

/** The settings row of the till's company, or the first one. */
export function settingsOf(rows, companyId) {
  const row = rows.find((candidate) => String(candidate.id) === String(companyId)) ?? rows[0] ?? null;
  if (!row) return { percent: '0', serviceItemId: null, tipItemId: null, tipPresets: [], present: false };
  return {
    percent: String(row.service_charge_percent ?? '0'),
    serviceItemId: row.service_charge_item_id ?? null,
    tipItemId: row.tip_item_id ?? null,
    tipPresets: (row.tip_presets ?? []).map(Number).filter((value) => Number.isFinite(value) && value > 0),
    present: true,
  };
}

/** The service charge as the Bill takes it: the percent and the tax rate of the service item today (null when exempt). */
export function serviceFor(settings, catalogue) {
  if (!settings || Number(settings.percent) <= 0) return { percent: '0', tax_rate: null };
  const item = settings.serviceItemId ? catalogue?.itemById.get(settings.serviceItemId) : null;
  const code = item ? catalogue.taxCodes.get(item.tax_code_id) : null;
  let rate = null;
  try {
    rate = code ? taxRateFor(code, catalogue.day).rate : null;
  } catch {
    rate = null;
  }
  return { percent: settings.percent, tax_rate: rate };
}

/** What blocks closing, as a code, or null: a service charge without its item, a tip item, an item no longer sold. */
export function closingProblem(order, settings, catalogue, { tipMinor = 0n } = {}) {
  if (!liveLines(order).length) return 'order_empty';
  if (Number(settings?.percent) > 0 && (!settings.serviceItemId || !catalogue.itemById.has(settings.serviceItemId))) return 'service_item_missing';
  if (BigInt(tipMinor) > 0n && (!settings?.tipItemId || !catalogue.itemById.has(settings.tipItemId))) return 'tip_item_missing';
  if (liveLines(order).some((line) => !catalogue.itemById.has(line.item_id))) return 'item_unknown';
  return null;
}

/** The order's bill: totals of the live lines with the company's service charge and a tip. */
export function billOf(order, settings, catalogue, tipMinor = 0) {
  return totals(billLines(order), serviceFor(settings, catalogue), tipMinor);
}

/**
 * The parts of a split. `options`: { parts } for equal, { groups } (lists of
 * line ids) for item. Throws what the Bill throws (a split the server would
 * refuse). Returns the plan: { id, mode, tip_minor, sales: [saleId],
 * parts: [{ saleId, lines: [{ id, lineId, qty }], line_ids, service_charge_minor, tip_minor, total_minor }] }.
 */
export function buildPlan(order, { settings, catalogue, mode, options = {}, tipMinor = 0, ids = uuidv7 }) {
  const lines = billLines(order);
  const live = liveLines(order);
  const service = serviceFor(settings, catalogue);
  const parts = split(lines, mode, service, tipMinor, options);
  const count = parts.length;
  const seatMode = mode === 'seat';
  const sharedIds = seatMode ? live.filter((line) => line.seat === null || line.seat === undefined).map((line) => line.id) : [];

  const planned = parts.map((part, index) => {
    let wanted;
    if (mode === 'equal' || mode === 'full') {
      // Every line, a share of each quantity in an equal split.
      wanted = live.map((line) => ({ lineId: line.id, qty: mode === 'equal' ? equalQty(line.qty, index, count) : String(line.qty) }));
    } else {
      wanted = part.line_ids.map((lineId) => ({ lineId, qty: String(live.find((line) => line.id === lineId).qty) }));
      // REST-14: a seat split shares the unseated lines equally between the seats.
      for (const lineId of sharedIds) wanted.push({ lineId, qty: equalQty(live.find((line) => line.id === lineId).qty, index, count) });
    }
    wanted = wanted.filter((entry) => scaled(entry.qty, 6) > 0n);
    return {
      saleId: ids(),
      serviceLineId: ids(),
      tipLineId: ids(),
      lines: wanted.map((entry) => ({ id: ids(), lineId: entry.lineId, qty: entry.qty })),
      line_ids: mode === 'item' || mode === 'seat' ? [...new Set(wanted.map((entry) => entry.lineId))] : live.map((line) => line.id),
      service_charge_minor: part.service_charge_minor,
      tip_minor: part.tip_minor,
      total_minor: part.total_minor,
    };
  });
  return { id: ids(), mode, tip_minor: String(tipMinor), parts: planned, sales: planned.map((part) => part.saleId), made_at: Date.now() };
}

/** Parts of the plan not yet paid, by index. */
export function unpaidParts(order) {
  const plan = order.local.plan;
  if (!plan) return [];
  const paid = new Set((order.local.paid ?? []).map((entry) => entry.sale_id));
  return plan.parts.map((part, index) => ({ part, index })).filter(({ part }) => !paid.has(part.saleId));
}

const itemLabel = (line) => (line.modifiers?.length ? `${line.name} (${line.modifiers.map((modifier) => modifier.name).join(', ')})` : line.name);

/**
 * The cart of part `index`: what PosProvider loads so the normal payment
 * screen takes it. `final` is true for the last part still to be paid.
 */
export function partCart(order, plan, index, { settings, catalogue, now = Date.now() }) {
  const part = plan.parts[index];
  const byId = new Map(order.lines.map((line) => [line.id, line]));
  const list = catalogue.defaultList;
  const lines = [];
  const base = {
    discountMinor: '0',
    override: null,
    priceOverride: null,
    priceSet: true,
    discountBy: null,
    actorProof: null,
    priceListId: list?.id ?? null,
  };
  for (const entry of part.lines) {
    const line = byId.get(entry.lineId);
    const item = catalogue.itemById.get(line.item_id);
    const unit = BigInt(line.unit_price_minor) + (line.modifiers ?? []).reduce((sum, modifier) => sum + BigInt(modifier.price_minor ?? 0), 0n);
    const uomId = catalogue.salesUom(item);
    lines.push({
      ...base,
      id: entry.id,
      itemId: item.id,
      name: itemLabel(line),
      code: item.code,
      uomId,
      uomCode: catalogue.uoms.get(uomId)?.code ?? null,
      qty: String(entry.qty),
      unitPriceMinor: String(unit),
      listPriceMinor: String(unit),
      taxInclusive: Boolean(line.tax_inclusive),
      taxCodeId: line.tax_code_id ?? item.tax_code_id ?? null,
    });
  }
  const extra = (itemId, amount, id) => {
    if (BigInt(amount) <= 0n) return;
    const item = catalogue.itemById.get(itemId);
    // closingProblem() has refused a charge or tip without its item; never ring one without it.
    if (!item) throw new Error('service_item_missing');
    const uomId = catalogue.salesUom(item);
    lines.push({
      ...base,
      id,
      itemId: item.id,
      name: item.name,
      code: item.code,
      uomId,
      uomCode: catalogue.uoms.get(uomId)?.code ?? null,
      qty: '1',
      unitPriceMinor: String(amount),
      listPriceMinor: String(amount),
      // The charge is on the net amount, tax added on top by the item's tax code; a tip is never taxed.
      taxInclusive: false,
      taxCodeId: item.tax_code_id ?? null,
    });
  };
  // Stable ids: a charge or tip line of a part is the same line every time the part is loaded.
  extra(settings.serviceItemId, part.service_charge_minor, part.serviceLineId);
  extra(settings.tipItemId, part.tip_minor, part.tipLineId);

  const paidCount = (order.local.paid ?? []).filter((entry) => plan.sales.includes(entry.sale_id)).length;
  const customer = order.customer_id ? { id: order.customer_id, name: order.local.customer_name ?? '', phone: null, price_list_id: null } : null;
  return {
    id: part.saleId,
    lines,
    customer,
    priceListId: list?.id ?? null,
    startedAt: now,
    tenders: [],
    ext: {
      restaurant: {
        order_id: order.id,
        mode: plan.mode,
        part: index + 1,
        parts: plan.parts.length,
        final: paidCount + 1 >= plan.parts.length,
        line_ids: part.line_ids,
        covers: order.covers,
      },
    },
  };
}
