import { add, compare, div, exact, mul, ROUND, round, sub, toFixed } from './exact';

/**
 * POS-11, MD-03: tax at the till, as the server computes it
 * (App\Core\MasterData\Taxes\TaxCalculator::forLine with one code):
 *
 * - the rate in force on the sale's local day (effective_from <= day <=
 *   effective_to, the latest start wins); none, or one that needs
 *   confirmation, is "Rate needed": the item cannot be sold (rates are
 *   never invented);
 * - exempt codes add no tax; withholding codes are not charged at the till;
 * - exclusive: tax = net × rate / 100, half up; total = net + tax;
 * - inclusive: tax = gross × rate / (100 + rate), half up; total = gross.
 *
 * Line amounts (docs/modules/pos.md): gross = unit price × qty rounded
 * half up once; the discount comes off gross before tax.
 */
export class TaxRateNeeded extends Error {
  constructor(code) {
    super('tax_rate_needed');
    this.name = 'TaxRateNeeded';
    this.code = 'tax_rate_needed';
    this.taxCode = code?.code ?? null;
  }
}

/** The tax code's rate row in force on `day` (Y-m-d), or null. */
export function rateOn(code, day) {
  let chosen = null;
  for (const rate of code?.rates ?? []) {
    if (rate.effective_from > day || (rate.effective_to != null && rate.effective_to < day)) continue;
    if (!chosen || rate.effective_from > chosen.effective_from) chosen = rate;
  }
  return chosen;
}

/** { rate: "16.0000" | null, applied: boolean } or throws TaxRateNeeded. */
export function taxRateFor(code, day) {
  if (!code) throw new TaxRateNeeded(null);
  if (code.kind === 'exempt' || code.kind === 'withholding') return { rate: null, applied: false };
  const row = rateOn(code, day);
  if (!row || row.rate == null || row.needs_confirmation) throw new TaxRateNeeded(code);
  return { rate: toFixed(exact(String(row.rate)), 4), applied: true };
}

/** Tax in minor units (bigint) on `amountMinor` at `rate` (a percentage string, or null for none). */
export function taxOn(amountMinor, rate, inclusive) {
  if (rate == null) return 0n;
  const r = exact(String(rate));
  const amount = exact(amountMinor);
  return inclusive ? round(div(mul(amount, r), add(r, 100n)), ROUND.HALF_UP) : round(div(mul(amount, r), 100n), ROUND.HALF_UP);
}

/** unit price × qty, rounded half up once (Amounts::extend). */
export const extend = (unitPriceMinor, qty) => round(mul(exact(unitPriceMinor), exact(String(qty))), ROUND.HALF_UP);

/**
 * One line's amounts: { grossMinor, discountMinor, taxMinor, totalMinor,
 * taxRate, taxCodeId } as digit strings, or { blocked: 'tax_rate_needed' |
 * 'tax_code_missing' | 'discount_above_price' }.
 */
export function lineAmounts(line, { taxCodes, day }) {
  const gross = extend(line.unitPriceMinor, line.qty);
  const discount = BigInt(String(line.discountMinor ?? '0'));
  if (discount > gross) return { blocked: 'discount_above_price', grossMinor: String(gross) };
  const code = line.taxCodeId ? taxCodes.get(line.taxCodeId) : null;
  if (!code) return { blocked: 'tax_code_missing', grossMinor: String(gross) };
  let rate;
  try {
    rate = taxRateFor(code, day).rate;
  } catch (error) {
    if (error instanceof TaxRateNeeded) return { blocked: 'tax_rate_needed', grossMinor: String(gross) };
    throw error;
  }
  const amount = gross - discount;
  const tax = taxOn(amount, rate, line.taxInclusive);
  const total = line.taxInclusive ? amount : amount + tax;
  return {
    grossMinor: String(gross),
    discountMinor: String(discount),
    taxMinor: String(tax),
    totalMinor: String(total),
    taxRate: rate,
    taxCodeId: code.id,
  };
}

/** Sale totals: subtotal = Σ gross, discount, tax, total (the server's sums). */
export function saleTotals(amounts) {
  const sum = (key) => amounts.reduce((total, line) => total + BigInt(line[key] ?? '0'), 0n);
  return {
    subtotal_minor: String(sum('grossMinor')),
    discount_minor: String(sum('discountMinor')),
    tax_minor: String(sum('taxMinor')),
    total_minor: String(sum('totalMinor')),
  };
}

/** Discount as a percentage of gross, 4 decimals half up (the server's RBAC-06 check). */
export function discountPercent(discountMinor, grossMinor) {
  if (BigInt(grossMinor) === 0n) return '0.0000';
  return toFixed(div(mul(exact(discountMinor), 100n), exact(grossMinor)), 4);
}

/** a <= b for decimal strings. */
export const notAbove = (a, b) => compare(exact(String(a)), exact(String(b))) <= 0;

export { sub };
