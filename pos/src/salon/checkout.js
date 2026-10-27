import { uuidv7 } from '../lib/random';

/**
 * SAL-16, SAL-22: the sale a salon visit becomes. The cart is built from the appointment (its services as the
 * server planned them), with the tip and the fee as lines of the items in the company's settings, and loaded into the
 * normal payment screens, so payments, M-Pesa, fiscal and receipts use the existing path.
 *
 * `cart.ext.salon` is what travels in the sale's `extensions.salon` (the cart adjuster in register.js passes it on):
 * `{ appointment_id, tips?: [{ staff_id, amount_minor }], sellers?, fee? }`. Who earns the commission of a service
 * line is the server's record of the appointment, never the till's word. `cart.ext.salon_lines` stays on the till: it
 * says which salon service each cart line is (a package pays one service).
 */
export class CheckoutError extends Error {
  constructor(code) {
    super(code);
    this.name = 'CheckoutError';
    this.code = code;
  }
}

/** A tip shared among staff: equal parts, the odd minor units to the first ones. Returns `[{ staff_id, amount_minor }]`. */
export function splitTip(totalMinor, staffIds) {
  const total = BigInt(totalMinor ?? 0);
  const ids = [...new Set(staffIds)];
  if (total <= 0n || !ids.length) return [];
  const each = total / BigInt(ids.length);
  const extra = Number(total - each * BigInt(ids.length));
  return ids.map((id, index) => ({ staff_id: id, amount_minor: String(each + (index < extra ? 1n : 0n)) })).filter((share) => BigInt(share.amount_minor) > 0n);
}

/** The sum of a list of tips. */
export const tipTotal = (tips) => (tips ?? []).reduce((sum, tip) => sum + BigInt(tip.amount_minor), 0n);

/** Why an appointment cannot be rung yet, as a code, or null. */
export function checkoutProblem(appointment, { fee = false } = {}) {
  if (!appointment) return 'appointment_unknown';
  if (fee) return appointment.fee_status === 'due' ? null : 'no_fee_due';
  if (appointment.status === 'paid') return 'already_paid';
  if (['cancelled', 'no_show'].includes(appointment.status)) return 'appointment_closed';
  if (!appointment.lines?.length) return 'no_lines';
  return null;
}

/**
 * The cart for an appointment (or for its fee when `fee` is true). The price of each line is the price list's, as the
 * cart rings it everywhere, else the plan's price on the appointment.
 */
export function checkoutCart({ appointment, settings, catalogue, tips = [], fee = false, now = Date.now(), cartId = uuidv7(now) }) {
  const problem = checkoutProblem(appointment, { fee });
  if (problem) throw new CheckoutError(problem);
  const list = catalogue.defaultList;
  const base = { discountMinor: '0', override: null, priceOverride: null, priceSet: true, discountBy: null, actorProof: null, priceListId: list?.id ?? null };
  const lines = [];
  const serviceLines = {};

  const push = (id, item, amount, extra = {}) => {
    const uomId = catalogue.salesUom(item);
    lines.push({
      ...base,
      id,
      itemId: item.id,
      name: item.name,
      code: item.code,
      uomId,
      uomCode: catalogue.uoms?.get(uomId)?.code ?? null,
      qty: '1',
      unitPriceMinor: String(amount),
      listPriceMinor: String(amount),
      taxInclusive: Boolean(list?.tax_inclusive),
      taxCodeId: item.tax_code_id ?? null,
      ...extra,
    });
  };

  if (fee) {
    const item = settings?.fee_item_id ? catalogue.itemById.get(settings.fee_item_id) : null;
    if (!item) throw new CheckoutError('fee_item_missing');
    // The fee is a fixed amount the server worked out; it is rung as it is, never taxed twice.
    push(uuidv7(now), item, appointment.fee_minor, { taxInclusive: false });
  } else {
    for (const line of appointment.lines) {
      const item = catalogue.itemById.get(line.item_id);
      if (!item) throw new CheckoutError('item_missing');
      const listed = list ? catalogue.priceFor(item, catalogue.salesUom(item), list.id, '1') : null;
      push(line.id, item, listed?.amountMinor ?? line.price_minor, { taxInclusive: listed ? Boolean(list.tax_inclusive) : false });
      serviceLines[line.id] = line.service_id;
    }

    const tip = tipTotal(tips);

    if (tip > 0n) {
      const item = settings?.tip_item_id ? catalogue.itemById.get(settings.tip_item_id) : null;
      if (!item) throw new CheckoutError('tip_item_missing');
      // A tip is not revenue of the services and is never taxed.
      push(uuidv7(now), item, tip, { taxInclusive: false });
    }
  }

  const salon = { appointment_id: appointment.id };
  if (fee) salon.fee = true;
  else if (tips.length) salon.tips = tips.map((share) => ({ staff_id: share.staff_id, amount_minor: String(share.amount_minor) }));

  return {
    id: cartId,
    lines,
    customer: appointment.client_id ? { id: appointment.client_id, name: appointment.client_name ?? '', phone: null, price_list_id: null } : null,
    priceListId: list?.id ?? null,
    startedAt: now,
    tenders: [],
    ext: { salon, salon_lines: serviceLines },
  };
}
