import { convertExact, ratePayload } from './currency';
import { add, compare, div, exact, mul, ROUND, round, sub } from './exact';

/**
 * POS-01, POS-03..POS-05, POS-09: the records the till uploads, in the
 * exact shapes of the POS module's Form Requests
 * (api/modules/POS/Http/Requests/Device, docs/modules/pos.md "API").
 * Amounts are minor units as digit strings, quantities decimal strings,
 * ids UUID v7 made on the device, times ISO 8601 (the server's clock as
 * the till knows it). Optional fields are left out rather than sent empty,
 * so a resend hashes the same (idempotent uploads).
 */
const clean = (object) => Object.fromEntries(Object.entries(object).filter(([, value]) => value !== undefined));

/** A core override (AUTH-08) as the API takes it, or undefined. */
export function overridePayload(override) {
  if (!override) return undefined;
  if (override.token) return { token: override.token };
  return {
    id: override.id,
    kid: override.kid,
    manager_user_id: override.manager_user_id,
    cashier_user_id: override.cashier_user_id ?? null,
    permission: override.permission,
    reference: override.reference,
    authorised_at: override.authorised_at,
    signature: override.signature,
  };
}

/**
 * A completed sale. `lines` are cart lines with their computed amounts
 * (`amounts`), `payments` the tenders with their share in the sale
 * currency (`inDue`) and rate, `change` the tender result's change.
 */
export function salePayload({ id, shiftId, cashierId, actorProof, customerId, number, soldAt, offline, currency, priceListId, lines, totals, payments, change, changeRate }) {
  return clean({
    id,
    shift_id: shiftId,
    cashier_id: cashierId,
    actor_proof: actorProof ?? undefined,
    customer_id: customerId ?? undefined,
    receipt_seq: number.seq,
    receipt_number: number.number,
    number_range_id: number.rangeId,
    sold_at: soldAt,
    offline: Boolean(offline),
    currency,
    price_list_id: priceListId ?? undefined,
    lines: lines.map((line) =>
      clean({
        id: line.id,
        item_id: line.itemId,
        item_name: line.name,
        uom_id: line.uomId,
        qty: String(line.qty),
        unit_price_minor: String(line.unitPriceMinor),
        list_price_minor: line.listPriceMinor == null ? undefined : String(line.listPriceMinor),
        price_list_id: line.priceListId ?? undefined,
        tax_inclusive: Boolean(line.taxInclusive),
        discount_minor: line.amounts.discountMinor,
        tax_code_id: line.amounts.taxCodeId ?? undefined,
        tax_rate: line.amounts.taxRate ?? undefined,
        tax_minor: line.amounts.taxMinor,
        total_minor: line.amounts.totalMinor,
        override: overridePayload(line.override),
        price_override: overridePayload(line.priceOverride),
        actor_proof: line.actorProof ?? undefined,
      }),
    ),
    totals,
    payments: payments.map((payment) => tenderPayload(payment, currency)),
    change:
      change && BigInt(change.minor) > 0n
        ? clean({ currency: change.currency, amount_minor: String(change.minor), rate: change.currency === currency ? undefined : ratePayload(changeRate) })
        : undefined,
  });
}

function tenderPayload(payment, saleCurrency) {
  return clean({
    id: payment.id,
    payment_method_id: payment.methodId,
    currency: payment.currency,
    amount_minor: String(payment.amountMinor),
    amount_in_sale_minor: String(payment.inSaleMinor),
    rate: payment.currency === saleCurrency ? undefined : ratePayload(payment.rate),
    provider_reference: payment.reference || undefined,
    status: payment.status ?? undefined,
  });
}

/** POS-04: a shift opened (and, when `closing` is given, closed). */
export function shiftPayload({ id, openedById, openedAt, openingFloat, actorProof, closing }) {
  return clean({
    id,
    opened_by_id: openedById,
    opened_at: openedAt,
    opening_float: openingFloat.map((row) => ({ currency: row.currency, amount_minor: String(row.amountMinor) })),
    actor_proof: actorProof ?? undefined,
    closing: closing
      ? clean({
          closed_by_id: closing.closedById,
          closed_at: closing.closedAt,
          counted: closing.counted.map((row) => ({ currency: row.currency, amount_minor: String(row.amountMinor) })),
          note: closing.note || undefined,
          actor_proof: closing.actorProof ?? undefined,
        })
      : undefined,
  });
}

/** POS-04: cash paid into or out of the drawer, with a reason. */
export function cashMovementPayload({ id, shiftId, userId, kind, currency, amountMinor, reason, occurredAt, override, actorProof }) {
  return clean({
    id,
    shift_id: shiftId,
    user_id: userId,
    kind,
    currency,
    amount_minor: String(amountMinor),
    reason,
    occurred_at: occurredAt,
    override: overridePayload(override),
    actor_proof: actorProof ?? undefined,
  });
}

/** POS-05: a whole completed sale voided. */
export function voidPayload({ id, saleId, voidedById, voidedAt, reason, override, actorProof }) {
  return clean({ id, sale_id: saleId, voided_by_id: voidedById, voided_at: voidedAt, reason, override: overridePayload(override), actor_proof: actorProof ?? undefined });
}

/** POS-05: some lines of a sale given back and refunded. */
export function refundPayload({ id, saleId, shiftId, cashierId, number, refundedAt, reason, totalMinor, lines, payments, saleCurrency, override, actorProof }) {
  return clean({
    id,
    sale_id: saleId,
    shift_id: shiftId,
    cashier_id: cashierId,
    receipt_seq: number.seq,
    receipt_number: number.number,
    number_range_id: number.rangeId,
    refunded_at: refundedAt,
    reason,
    total_minor: String(totalMinor),
    lines: lines.map((line) => ({ id: line.id, sale_line_id: line.saleLineId, qty: String(line.qty) })),
    payments: payments.map((payment) => tenderPayload(payment, saleCurrency)),
    override: overridePayload(override),
    actor_proof: actorProof ?? undefined,
  });
}

/**
 * POS-05: the amounts of a refund, as RefundUploads computes them: each
 * line's share of the sold line's total and tax for the quantity given
 * back, after what earlier refunds took (half up, so partial refunds add
 * up to the line exactly). `already` maps sale line id → quantity already
 * refunded. Throws `refund_qty_exceeded`.
 */
export function refundAmounts(sale, requested, already = {}) {
  const byId = new Map(sale.lines.map((line) => [line.id, line]));
  const taken = { ...already };
  let total = 0n;
  let tax = 0n;
  const lines = requested.map((request) => {
    const line = byId.get(request.saleLineId);
    if (!line) throw Object.assign(new Error('sale_line_unknown'), { code: 'sale_line_unknown' });
    const sold = exact(String(line.qty));
    const before = exact(String(taken[line.id] ?? '0'));
    const after = add(before, exact(String(request.qty)));
    if (compare(after, sold) > 0) throw Object.assign(new Error('refund_qty_exceeded'), { code: 'refund_qty_exceeded', saleLineId: line.id });
    const share = (amount, part) => round(div(mul(exact(String(amount)), part), sold), ROUND.HALF_UP);
    const lineTotal = share(line.total_minor, after) - share(line.total_minor, before);
    const lineTax = share(line.tax_minor, after) - share(line.tax_minor, before);
    taken[line.id] = decimalString(after);
    total += lineTotal;
    tax += lineTax;
    return { ...request, totalMinor: String(lineTotal), taxMinor: String(lineTax) };
  });
  return { lines, totalMinor: String(total), taxMinor: String(tax) };
}

export function decimalString(value) {
  // Quantities have at most 6 decimals (numeric(18,6)).
  const scaled = round(mul(value, 1000000n), ROUND.HALF_UP);
  const whole = scaled / 1000000n;
  const fraction = String(scaled % 1000000n).padStart(6, '0').replace(/0+$/, '');
  return fraction ? `${whole}.${fraction}` : String(whole);
}

/**
 * CUR-04, RBAC-06: an amount of the sale in the company's base currency,
 * as FxSnapshot::convert does it: converted at the sale's base rate and
 * rounded half up to the base currency's minor unit. `base` is the
 * sale's stored { currency, rate } (rate null when the sale is in the base
 * currency). Null when the till had no rate.
 */
export function toBaseMinor(minor, saleCurrency, base, decimals) {
  if (!base) return null;
  if (base.currency === saleCurrency) return BigInt(String(minor));
  if (!base.rate) return null;
  return round(convertExact(BigInt(String(minor)), saleCurrency, base.currency, base.rate, decimals), ROUND.HALF_UP);
}

/**
 * RBAC-06: a refund's value for the approver's max_refund_amount, the
 * server's way (RefundUploads): each refund line converted to the base
 * currency and rounded, the rounded lines summed, in major units with 4
 * decimals. Null when the sale stored no base rate.
 */
export function refundBaseMajor(sale, refundLines, decimals) {
  const base = sale.local?.base;
  if (!base) return null;
  let total = 0n;
  for (const line of refundLines) {
    const converted = toBaseMinor(line.totalMinor, sale.currency, base, decimals);
    if (converted === null) return null;
    total += converted;
  }
  const scale = decimals(base.currency);
  return toFixedMajor(total, scale);
}

function toFixedMajor(minor, scale) {
  const negative = minor < 0n;
  const digits = String(negative ? -minor : minor).padStart(scale + 1, '0');
  const whole = scale ? digits.slice(0, -scale) : digits;
  const fraction = (scale ? digits.slice(-scale) : '').padEnd(4, '0');
  return `${negative ? '-' : ''}${whole}.${fraction}`;
}

/** Quantities already given back per sale line, from the sale's refund records (exact decimals). */
export function refundedQuantities(refunds) {
  const out = {};
  for (const refund of refunds) for (const line of refund.lines) out[line.sale_line_id] = decimalString(add(exact(String(out[line.sale_line_id] ?? '0')), exact(String(line.qty))));
  return out;
}

/** What is left to give back of a sold quantity (exact decimals, never below 0). */
export function quantityLeft(sold, refunded = '0') {
  const left = sub(exact(String(sold)), exact(String(refunded)));
  return compare(left, exact(0n)) > 0 ? decimalString(left) : '0';
}

/**
 * H1: refunds are paid at the sale's own rates. The rate between the
 * sale currency and `currency` is the sale's tender rate in that currency,
 * else none (the refund is offered only in currencies the sale was paid
 * in). Returns { amountMinor, inSaleMinor, rate } for refunding
 * `totalMinor` (sale currency) in `currency`, or null when no amount of
 * `currency` comes within one minor unit of the refund at that rate.
 */
export function refundTender(sale, totalMinor, currency, decimals) {
  if (currency === sale.currency) return { amountMinor: String(totalMinor), inSaleMinor: String(totalMinor), rate: null };
  const tendered = sale.payments.find((payment) => payment.currency === currency && payment.rate);
  if (!tendered) return null;
  const rate = { ...tendered.rate, mid: tendered.rate.rate };
  const amount = round(convertExact(BigInt(totalMinor), sale.currency, currency, rate, decimals), ROUND.HALF_UP);
  const back = convertExact(amount, currency, sale.currency, rate, decimals);
  if (compare(sub(back, exact(BigInt(totalMinor))), exact(1n)) >= 0 || compare(sub(exact(BigInt(totalMinor)), back), exact(1n)) >= 0) return null;
  return { amountMinor: String(amount), inSaleMinor: String(round(back, ROUND.HALF_UP)), rate };
}
