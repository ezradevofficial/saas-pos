/**
 * VAN-11, VAN-13: credit on the route, decided on the device from the last
 * synced limit and balance. Pure functions over exact integers (minor units),
 * so they run offline and in Jest.
 *
 * - The limit is the customer's, in one currency (`credit_limit_currency`).
 *   No limit set means credit is refused; a sale in another currency is
 *   refused (no conversion, decision 7).
 * - Available = limit - balance - unsynced credit sales + unsynced
 *   collections, all in the limit's currency. "Unsynced" is what this device
 *   made that the pulled balance may not include yet (accountEffects).
 * - The server never refuses a stored sale; it recomputes and flags. The
 *   decision made here travels with the sale as `extensions.van_sales.credit`
 *   (`offline` true when the device decided without a connection).
 */
const big = (value) => BigInt(String(value ?? '0'));

export const CREDIT_CODES = ['credit_without_customer', 'no_credit_limit', 'limit_currency_differs', 'over_limit', 'amount'];

/** The synced balance of an account in one currency (0 when it has none). */
export function balanceIn(account, currency) {
  return (account?.balances ?? []).filter((balance) => balance.currency === currency).reduce((sum, balance) => sum + big(balance.amount_minor), 0n);
}

const hasLimit = (account) => account?.credit_limit_minor !== null && account?.credit_limit_minor !== undefined && account?.credit_limit_minor !== '';

/**
 * Where the customer stands in `currency`: { code } when credit cannot be
 * given, else { limit, balance, available } as BigInt. `unsynced` is
 * { creditMinor, collectedMinor }; `onCartMinor` is credit tenders already on
 * the sale being paid.
 */
export function creditPosition({ account, currency, unsynced = {}, onCartMinor = 0n }) {
  if (!hasLimit(account)) return { code: 'no_credit_limit' };
  if (account.credit_limit_currency !== currency) return { code: 'limit_currency_differs', limitCurrency: account.credit_limit_currency };
  const limit = big(account.credit_limit_minor);
  const balance = balanceIn(account, currency) + big(unsynced.creditMinor) - big(unsynced.collectedMinor) + big(onCartMinor);
  const room = limit - balance;
  return { code: null, limit, balance, available: room > 0n ? room : 0n };
}

/**
 * Whether `amountMinor` may be put on credit. Resolves { ok: true, credit,
 * available } where `credit` is the snapshot for the sale's extension, or
 * { ok: false, code, available? } with one of CREDIT_CODES.
 */
export function decideCredit({ account, customerId, currency, amountMinor, unsynced = {}, onCartMinor = 0n, offline = false, at = Date.now() }) {
  if (!customerId) return { ok: false, code: 'credit_without_customer' };
  const amount = big(amountMinor);
  if (amount <= 0n) return { ok: false, code: 'amount' };
  const position = creditPosition({ account, currency, unsynced, onCartMinor });
  if (position.code) return { ok: false, code: position.code, limitCurrency: position.limitCurrency };
  if (amount > position.available) return { ok: false, code: 'over_limit', available: String(position.available) };
  return {
    ok: true,
    available: String(position.available - amount),
    credit: {
      limit_minor: String(position.limit),
      balance_minor: String(position.balance),
      currency,
      decided_at: new Date(at).toISOString(),
      offline: Boolean(offline),
    },
  };
}

/**
 * What this device made for one customer in one currency that the pulled
 * balance may not hold: credit tendered on its van sales (voided ones left
 * out) and collections. The caller passes only the uncounted ones (sales not
 * acknowledged or made after the last pull, collections not stored or made
 * after it).
 */
export function accountEffects({ sales = [], collections = [], customerId, currency }) {
  let creditMinor = 0n;
  for (const sale of sales) {
    if (sale.local?.status === 'voided' || sale.customer_id !== customerId) continue;
    for (const payment of sale.payments ?? []) {
      if (payment.currency === currency && sale.local?.methods?.[payment.id]?.type === 'credit') creditMinor += big(payment.amount_minor);
    }
  }
  let collectedMinor = 0n;
  for (const collection of collections) {
    if (collection.local?.voided || collection.customer_id !== customerId || collection.currency !== currency) continue;
    collectedMinor += big(collection.amount_minor);
  }
  return { creditMinor: String(creditMinor), collectedMinor: String(collectedMinor) };
}
