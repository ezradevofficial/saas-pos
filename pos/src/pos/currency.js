import { CURRENCY_DECIMALS } from '../lib/money';
import { add, compare, div, divideScaled, exact, movePoint, mul, ROUND, round } from './exact';

/**
 * CUR-01, CUR-03, CUR-09: currencies and rates on the till, from the synced
 * `currencies` and `exchange_rates` rows (ADR 003):
 *
 * - decimals and cash rounding per currency (tenant data, CUR-01);
 * - rateFor(a, b, at): for the pair stored in either direction, the latest
 *   `shop` row effective at or before `at`, else the latest `reference`
 *   row; direction (alphabetical base, then newest id) only breaks ties,
 *   as ExchangeRates::stored does on the server. Offline the cached rows
 *   are used (CUR-09); the rate chosen is recorded on the sale.
 * - convertExact(minor, from, to, rate): Converter::exact, in the stored
 *   direction only (multiply from the base; divide from the quote,
 *   quantised to 20 decimals half up), never through an 8-decimal inverse.
 */
export function createCurrencies({ currencies = [], rates = [] } = {}) {
  const byCode = new Map(currencies.map((row) => [String(row.code ?? row.id), row]));
  const parsedRates = rates
    .filter((row) => row && row.base && row.quote && row.mid)
    .map((row) => ({ ...row, at: Date.parse(row.effective_at ?? '') || 0 }));

  const decimals = (code) => {
    const row = byCode.get(code);
    if (row && row.decimals != null) return Number(row.decimals);
    return CURRENCY_DECIMALS[code] ?? 2;
  };

  const cashStep = (code) => {
    const step = byCode.get(code)?.cash_rounding_minor;
    const value = step == null ? 1n : BigInt(String(step));
    return value > 0n ? value : 1n;
  };

  function rateFor(a, b, at = Date.now()) {
    if (a === b) return null;
    const time = typeof at === 'number' ? at : Date.parse(at);
    let best = null;
    for (const row of parsedRates) {
      const pair = (row.base === a && row.quote === b) || (row.base === b && row.quote === a);
      if (!pair || row.at > time) continue;
      if (!best || better(row, best)) best = row;
    }
    return best;
  }

  return { decimals, cashStep, rateFor, codes: () => [...byCode.keys()], has: (code) => byCode.has(code) };
}

function better(row, current) {
  const shop = (r) => (r.kind === 'shop' ? 1 : 0);
  if (shop(row) !== shop(current)) return shop(row) > shop(current);
  if (row.at !== current.at) return row.at > current.at;
  if (row.base !== current.base) return row.base < current.base;
  return String(row.id ?? '') > String(current.id ?? '');
}

/** `minor` of `from` in `to`, before rounding (minor units, exact), at `rate` (1 base = mid quote). */
export function convertExact(minor, from, to, rate, decimals) {
  const amount = exact(minor);
  if (from === to) return amount;
  if (!rate) throw new Error(`Converting ${from} to ${to} needs a rate`);
  const scaled = movePoint(amount, decimals(to) - decimals(from));
  if (from === rate.base && to === rate.quote) return mul(scaled, exact(rate.mid));
  if (from === rate.quote && to === rate.base) return divideScaled(scaled, exact(rate.mid), 20);
  throw new Error(`A ${rate.base}/${rate.quote} rate cannot convert ${from} to ${to}`);
}

/** CashRounding::round: an exact amount rounded to a multiple of the currency's cash step. */
export function roundCash(value, step, mode) {
  return round(div(exact(value), exact(step)), mode) * step;
}

/** The rate as the API takes it on a payment or change (CUR-04, CUR-09). */
export function ratePayload(rate) {
  if (!rate) return undefined;
  return { rate: String(rate.mid), base: rate.base, quote: rate.quote, kind: rate.kind ?? null, effective_at: rate.effective_at ?? null };
}

/** Sum of exact values. */
export const sumExact = (values) => values.reduce((total, value) => add(total, value), exact(0n));

export { compare, ROUND };
