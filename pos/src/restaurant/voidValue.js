import { div, exact, toFixed } from '../pos/exact';
import { toBaseMinor } from '../pos/payloads';
import { lineAmounts } from './bill';

/**
 * REST-11, RBAC-06: the value of a line in the company's base currency, in
 * major units with 4 decimals (the form the staff row's limits compare), or
 * null when the till has no rate to the base currency (then only a manager
 * can approve). Computed from the line as the Bill prices it.
 */
export function lineBaseMajor(line, currency, catalogue, at = Date.now()) {
  const payable = lineAmounts(line).payable;
  const base = catalogue.baseCurrency ?? currency;
  const { money } = catalogue;
  let minor;
  if (base === currency) {
    minor = payable;
  } else {
    const rate = money.rateFor(currency, base, at);
    if (!rate) return null;
    const converted = toBaseMinor(payable, currency, { currency: base, rate: { id: rate.id ?? null, base: rate.base, quote: rate.quote, mid: String(rate.mid), kind: rate.kind ?? null, effective_at: rate.effective_at ?? null } }, money.decimals);
    if (converted === null) return null;
    minor = converted;
  }
  return toFixed(div(exact(minor), exact(10n ** BigInt(money.decimals(base)))), 4);
}
