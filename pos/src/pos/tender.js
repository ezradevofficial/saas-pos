import { convertExact, roundCash } from './currency';
import { add, compare, exact, isPositive, ROUND, round, sub } from './exact';

/**
 * CUR-06, POS-03: a sale paid in several currencies, change in a chosen
 * one. A port of App\Core\Currency\Tender\TenderCalculator (ADR 003), so
 * the till asks for, credits and gives back exactly what the server will
 * accept:
 *
 * - one time and one chosen rate row per pair for the whole calculation
 *   (mid side for tenders and change);
 * - paid: the exact sum of the tenders in the due currency, rounded DOWN
 *   to its minor unit; remaining = due − paid, never negative;
 * - lines: each tender's share of paid, by largest remainder (each within
 *   one minor unit of its exact value, summing to paid exactly);
 * - change: the exact overpayment in the change currency, rounded DOWN to
 *   its cash rounding (the shop never over-gives);
 * - roundingMinor: what the shop keeps from that rounding, half up;
 * - amountDueIn: what is still due, asked in another currency, rounded UP
 *   to that currency's cash rounding (the customer never underpays).
 *
 * `money` is createCurrencies() (decimals, cashStep, rateFor). Amounts are
 * minor units as bigint, digit strings or whole numbers; results are digit
 * strings.
 */
export class RateUnavailable extends Error {
  constructor(a, b) {
    super(`rate_unavailable ${a}/${b}`);
    this.name = 'RateUnavailable';
    this.code = 'rate_unavailable';
    this.pair = [a, b];
  }
}

function pairRates(money, at) {
  const chosen = new Map();
  return (a, b) => {
    if (a === b) return null;
    const key = [a, b].sort().join('/');
    if (!chosen.has(key)) {
      const rate = money.rateFor(a, b, at);
      if (!rate) throw new RateUnavailable(a, b);
      chosen.set(key, rate);
    }
    return chosen.get(key);
  };
}

export function calculateTender({ due, tenders = [], changeCurrency, money, at = Date.now() }) {
  const dueMinor = BigInt(String(due.minor));
  if (dueMinor < 0n) throw new Error('The amount due cannot be negative.');
  const currency = due.currency;
  const rateFor = pairRates(money, at);
  const decimals = money.decimals;

  let paidExact = exact(0n);
  const lines = tenders.map((tender) => {
    const amount = BigInt(String(tender.amountMinor));
    if (amount < 0n) throw new Error('A tender amount cannot be negative.');
    const rate = rateFor(tender.currency, currency);
    const value = convertExact(amount, tender.currency, currency, rate, decimals);
    paidExact = add(paidExact, value);
    return { tender, rate, exact: value };
  });

  const paid = round(paidExact, ROUND.DOWN);
  const inDue = allocate(lines.map((line) => line.exact), paid);
  const remaining = paid < dueMinor ? dueMinor - paid : 0n;
  const overpayExact = sub(paidExact, dueMinor);

  const target = changeCurrency ?? currency;
  let change = 0n;
  let changeRate = null;
  let rounding = exact(0n);
  if (isPositive(overpayExact)) {
    changeRate = rateFor(currency, target);
    change = roundCash(convertExact(overpayExact, currency, target, changeRate, decimals), money.cashStep(target), ROUND.DOWN);
    rounding = sub(overpayExact, convertExact(change, target, currency, changeRate, decimals));
  }

  return {
    due: { minor: String(dueMinor), currency },
    paidInDue: { minor: String(paid), currency },
    remaining: { minor: String(remaining), currency },
    change: { minor: String(change), currency: target },
    changeRate,
    overpaid: paid > dueMinor,
    settled: remaining === 0n,
    roundingMinor: String(round(rounding, ROUND.HALF_UP)),
    lines: lines.map((line, index) => ({ tender: line.tender, rate: line.rate, inDue: { minor: String(inDue[index]), currency } })),
  };
}

/**
 * Largest remainder: every line floored, then the units still missing to
 * reach `total` go one each to the lines with the largest fractional
 * parts, earlier lines first on a tie.
 */
export function allocate(values, total) {
  const floors = values.map((value) => round(value, ROUND.FLOOR));
  const fractions = values.map((value, index) => sub(value, floors[index]));
  const missing = Number(BigInt(total) - floors.reduce((sum, value) => sum + value, 0n));
  const order = values.map((_, index) => index).sort((x, y) => compare(fractions[y], fractions[x]) || x - y);
  for (const index of order.slice(0, Math.max(0, missing))) floors[index] += 1n;
  return floors;
}

/** What to ask for when `remaining` (minor units of `from`) is paid in `currency`: rounded UP to its cash rounding. */
export function amountDueIn({ remaining, from, currency, money, at = Date.now(), cash = true }) {
  const minor = BigInt(String(remaining));
  // Card and mobile money are paid to the minor unit: cash rounding is for notes and coins only.
  const step = cash ? money.cashStep(currency) : 1n;
  if (from === currency) return String(roundCash(exact(minor), step, ROUND.UP));
  const rate = money.rateFor(from, currency, at);
  if (!rate) throw new RateUnavailable(from, currency);
  return String(roundCash(convertExact(minor, from, currency, rate, money.decimals), step, ROUND.UP));
}
