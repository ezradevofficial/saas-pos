import { accountEffects, creditPosition, decideCredit } from './credit';

// VAN-11, VAN-13: credit is decided on the device from the last synced limit and balance.

const account = (extra = {}) => ({ id: 'c1', credit_limit_minor: '500000', credit_limit_currency: 'KES', balances: [{ currency: 'KES', amount_minor: '100000' }], ...extra });
const at = Date.parse('2026-11-02T08:00:00Z');

describe('credit within the limit (VAN-11)', () => {
  it('allows credit within the available amount and records the decision', () => {
    const decision = decideCredit({ account: account(), customerId: 'c1', currency: 'KES', amountMinor: '400000', at });
    expect(decision.ok).toBe(true);
    expect(decision.available).toBe('0');
    expect(decision.credit).toEqual({ limit_minor: '500000', balance_minor: '100000', currency: 'KES', decided_at: '2026-11-02T08:00:00.000Z', offline: false });
  });

  it('refuses credit over the limit and says what is available', () => {
    const decision = decideCredit({ account: account(), customerId: 'c1', currency: 'KES', amountMinor: '400001', at });
    expect(decision).toMatchObject({ ok: false, code: 'over_limit', available: '400000' });
  });

  it('marks the decision offline when the device had no connection', () => {
    const decision = decideCredit({ account: account(), customerId: 'c1', currency: 'KES', amountMinor: '1000', offline: true, at });
    expect(decision.credit.offline).toBe(true);
  });

  it('counts credit sales and collections the device made since the last pull', () => {
    const sale = { customer_id: 'c1', local: { methods: { p1: { type: 'credit' }, p2: { type: 'cash' } } }, payments: [{ id: 'p1', currency: 'KES', amount_minor: '150000' }, { id: 'p2', currency: 'KES', amount_minor: '9999' }] };
    const voided = { ...sale, local: { ...sale.local, status: 'voided' } };
    const collection = { customer_id: 'c1', currency: 'KES', amount_minor: '50000', local: {} };
    const unsynced = accountEffects({ sales: [sale, voided], collections: [collection], customerId: 'c1', currency: 'KES' });
    expect(unsynced).toEqual({ creditMinor: '150000', collectedMinor: '50000' });
    // limit 5000.00, balance 1000.00 + 1500.00 - 500.00 = 2000.00: 3000.00 left.
    expect(creditPosition({ account: account(), currency: 'KES', unsynced }).available).toBe(300000n);
    expect(decideCredit({ account: account(), customerId: 'c1', currency: 'KES', amountMinor: '300001', unsynced, at }).code).toBe('over_limit');
    expect(decideCredit({ account: account(), customerId: 'c1', currency: 'KES', amountMinor: '300000', unsynced, at }).ok).toBe(true);
  });

  it('counts credit already on the sale being paid', () => {
    expect(decideCredit({ account: account(), customerId: 'c1', currency: 'KES', amountMinor: '200000', onCartMinor: 250000n, at })).toMatchObject({ ok: false, code: 'over_limit', available: '150000' });
  });

  it('refuses credit when no limit is set', () => {
    for (const noLimit of [{ credit_limit_minor: null }, { credit_limit_minor: undefined }, { credit_limit_minor: '' }]) {
      expect(decideCredit({ account: account(noLimit), customerId: 'c1', currency: 'KES', amountMinor: '100', at }).code).toBe('no_credit_limit');
    }
    expect(decideCredit({ account: null, customerId: 'c1', currency: 'KES', amountMinor: '100', at }).code).toBe('no_credit_limit');
  });

  it('refuses a sale in another currency than the limit (no conversion)', () => {
    expect(decideCredit({ account: account(), customerId: 'c1', currency: 'USD', amountMinor: '100', at })).toMatchObject({ ok: false, code: 'limit_currency_differs', limitCurrency: 'KES' });
  });

  it('refuses credit without a customer or without an amount', () => {
    expect(decideCredit({ account: account(), customerId: null, currency: 'KES', amountMinor: '100', at }).code).toBe('credit_without_customer');
    expect(decideCredit({ account: account(), customerId: 'c1', currency: 'KES', amountMinor: '0', at }).code).toBe('amount');
  });

  it('has nothing available when the balance is already above the limit', () => {
    const over = account({ balances: [{ currency: 'KES', amount_minor: '600000' }] });
    expect(creditPosition({ account: over, currency: 'KES' }).available).toBe(0n);
  });
});
