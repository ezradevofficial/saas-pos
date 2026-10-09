import { convertExact } from '../currency';
import { exact, round, ROUND } from '../exact';

/**
 * POS-03, CUR-06: the payment choices on the payment screen, from the
 * synced payment methods in their order. A method tied to a currency is
 * one choice; a cash method without a currency is one choice per drawer
 * currency (base, reporting, cash currencies) that has a rate to the sale
 * currency; any other method without a currency takes the sale currency.
 */
export function paymentOptions(catalogue, at = Date.now()) {
  const options = [];
  const sale = catalogue.saleCurrency;
  const convertible = (currency) => currency === sale || Boolean(catalogue.money.rateFor(currency, sale, at));
  for (const method of catalogue.paymentMethods) {
    const currencies = method.currency ? [method.currency] : method.type === 'cash' ? catalogue.cashCurrencies : [sale];
    for (const currency of currencies) {
      if (!convertible(currency)) continue;
      options.push({ key: `${method.id}:${currency}`, method, currency });
    }
  }
  return options;
}

/**
 * Quick amounts for cash in `currency` towards `asked` (minor units, the
 * amount still due asked in that currency): the exact amount, then the
 * next round amounts above it (10, 100, 1,000 … of the currency's cash
 * step), at most `count` in all.
 */
export function quickAmounts(asked, step, count = 4) {
  const due = BigInt(asked);
  if (due <= 0n) return [];
  const out = [due];
  for (let unit = BigInt(step) * 10n; out.length < count && unit <= due * 100n; unit *= 10n) {
    for (const multiple of [1n, 2n, 5n]) {
      const size = unit * multiple;
      const next = ((due + size - 1n) / size) * size;
      if (next > out[out.length - 1] && out.length < count) out.push(next);
    }
  }
  return out.map(String);
}

/** A tender's value in the sale currency for display ("≈ USD 7.02"), rounded half up. */
export function inSaleCurrency(catalogue, amountMinor, currency, at = Date.now()) {
  if (currency === catalogue.saleCurrency) return String(amountMinor);
  const rate = catalogue.money.rateFor(currency, catalogue.saleCurrency, at);
  if (!rate) return null;
  return String(round(convertExact(exact(BigInt(amountMinor)), currency, catalogue.saleCurrency, rate, catalogue.money.decimals), ROUND.HALF_UP));
}
