import { createCurrencies } from './currency';
import { amountDueIn, calculateTender, RateUnavailable } from './tender';

// CUR-06: the same vectors as api/tests/Feature/Core/Currency/TenderCalculatorTest.php,
// so the till asks for, credits and gives back what the server accepts.
const NOW = Date.parse('2026-10-09T10:00:00Z');
const currencies = [
  { code: 'USD', decimals: 2, cash_rounding_minor: '1' },
  { code: 'CDF', decimals: 0, cash_rounding_minor: '50' },
];
const shop = { id: 'r1', base: 'USD', quote: 'CDF', kind: 'shop', mid: '2850.00000000', effective_at: '2026-10-01T00:00:00Z' };
const money = (rates = [shop]) => createCurrencies({ currencies, rates });

const calc = (dueMinor, dueCurrency, tenders, change, rates) =>
  calculateTender({
    due: { minor: dueMinor, currency: dueCurrency },
    tenders: tenders.map(([amountMinor, currency]) => ({ amountMinor, currency })),
    changeCurrency: change,
    money: money(rates),
    at: NOW,
  });

describe('calculateTender (TenderCalculatorTest vectors)', () => {
  it('USD 20 and CDF 57,000 towards USD 48.50 leaves USD 8.50, asked as CDF 24,250', () => {
    const result = calc(4850, 'USD', [[2000, 'USD'], [57000, 'CDF']], 'CDF');
    expect(result.paidInDue.minor).toBe('4000');
    expect(result.remaining.minor).toBe('850');
    expect(result.change).toEqual({ minor: '0', currency: 'CDF' });
    expect(result.overpaid).toBe(false);
    expect(result.settled).toBe(false);
    expect(result.roundingMinor).toBe('0');
    expect(result.lines[1].inDue.minor).toBe('2000');
    expect(amountDueIn({ remaining: 850, from: 'USD', currency: 'CDF', money: money(), at: NOW })).toBe('24250');
    expect(amountDueIn({ remaining: 850, from: 'USD', currency: 'USD', money: money(), at: NOW })).toBe('850');
    // Mobile money and cards are paid to the franc: no cash rounding (USD 8.50 = CDF 24,225).
    expect(amountDueIn({ remaining: 850, from: 'USD', currency: 'CDF', money: money(), at: NOW, cash: false })).toBe('24225');
  });

  it('paying the asked CDF settles with no change and the rounding reported', () => {
    const result = calc(4850, 'USD', [[2000, 'USD'], [57000, 'CDF'], [24250, 'CDF']], 'CDF');
    expect(result.settled).toBe(true);
    expect(result.paidInDue.minor).toBe('4850');
    expect(result.remaining.minor).toBe('0');
    expect(result.change.minor).toBe('0');
    expect(result.overpaid).toBe(false);
    expect(result.roundingMinor).toBe('1');
  });

  it('rounds change in CDF down to 50', () => {
    const result = calc(4850, 'USD', [[2000, 'USD'], [85000, 'CDF']], 'CDF');
    expect(result.overpaid).toBe(true);
    expect(result.paidInDue.minor).toBe('4982');
    expect(result.remaining.minor).toBe('0');
    expect(result.change).toEqual({ minor: '3750', currency: 'CDF' });
    expect(result.roundingMinor).toBe('1');
  });

  it('overpays in USD with change in USD or in CDF', () => {
    const inUsd = calc(4850, 'USD', [[5000, 'USD']], 'USD');
    expect(inUsd.change.minor).toBe('150');
    expect(inUsd.roundingMinor).toBe('0');
    expect(inUsd.overpaid).toBe(true);

    const inCdf = calc(4850, 'USD', [[5000, 'USD']], 'CDF');
    expect(inCdf.change.minor).toBe('4250');
    expect(inCdf.roundingMinor).toBe('1');
  });

  it('overpays in one currency with a CDF sale', () => {
    const same = calc(10000, 'CDF', [[20000, 'CDF']], 'CDF');
    expect(same.change.minor).toBe('10000');
    expect(same.roundingMinor).toBe('0');

    const usd = calc(10000, 'CDF', [[20000, 'CDF']], 'USD');
    expect(usd.change.minor).toBe('350');
    expect(usd.roundingMinor).toBe('25');

    const note = calc(10000, 'CDF', [[500, 'USD']], 'CDF');
    expect(note.paidInDue.minor).toBe('14250');
    expect(note.change.minor).toBe('4250');
  });

  it('never gives negative change on exact or short payments', () => {
    const exactPay = calc(4850, 'USD', [[4850, 'USD']], 'CDF');
    expect(exactPay.change.minor).toBe('0');
    expect(exactPay.overpaid).toBe(false);
    expect(exactPay.settled).toBe(true);

    const nothing = calc(4850, 'USD', [], 'CDF');
    expect(nothing.remaining.minor).toBe('4850');
    expect(nothing.change.minor).toBe('0');
    expect(nothing.paidInDue.minor).toBe('0');
  });

  it('with the pair stored both ways, paying the requested CDF leaves nothing due (one row per pair)', () => {
    const reference = { id: 'r2', base: 'CDF', quote: 'USD', kind: 'reference', mid: '0.00034483', effective_at: new Date(NOW - 60000).toISOString() };
    const rates = [shop, reference];
    for (const usd of [2000, 1999, 1]) {
      const first = calc(4850, 'USD', [[usd, 'USD']], 'CDF', rates);
      const asked = amountDueIn({ remaining: first.remaining.minor, from: 'USD', currency: 'CDF', money: money(rates), at: NOW });
      const settled = calc(4850, 'USD', [[usd, 'USD'], [asked, 'CDF']], 'CDF', rates);
      expect(settled.settled).toBe(true);
      expect(settled.remaining.minor).toBe('0');
      expect(settled.lines[1].rate.kind).toBe('shop');
    }
  });

  it('line amounts in the due currency sum to paid', () => {
    const result = calc(10000, 'USD', [[1000, 'CDF'], [1000, 'CDF'], [1000, 'CDF'], [100, 'USD']], 'CDF');
    expect(result.paidInDue.minor).toBe('205');
    expect(result.lines.map((line) => line.inDue.minor)).toEqual(['35', '35', '35', '100']);
  });

  it('uses the largest remainder so no line absorbs the others’ rounding', () => {
    const result = calc(10000, 'USD', [...Array.from({ length: 57 }, () => [1000, 'CDF']), [100, 'USD']], 'CDF');
    expect(result.paidInDue.minor).toBe('2100');
    const inDue = result.lines.map((line) => line.inDue.minor);
    expect(inDue.reduce((sum, value) => sum + Number(value), 0)).toBe(2100);
    expect(inDue[57]).toBe('100');
    expect(inDue.slice(0, 57)).toEqual([...Array(5).fill('36'), ...Array(52).fill('35')]);
  });

  it('refuses a tender without a rate', () => {
    expect(() => calc(4850, 'USD', [[1000, 'KES']], 'USD')).toThrow(RateUnavailable);
  });

  it('refuses negative amounts', () => {
    expect(() => calc(4850, 'USD', [[-1, 'USD']], 'USD')).toThrow();
  });

  it('ignores rates that start after the sale and prefers a shop rate over a newer reference rate', () => {
    const later = { id: 'r3', base: 'USD', quote: 'CDF', kind: 'shop', mid: '3000', effective_at: new Date(NOW + 60000).toISOString() };
    const newerReference = { id: 'r4', base: 'USD', quote: 'CDF', kind: 'reference', mid: '2900', effective_at: new Date(NOW - 1000).toISOString() };
    const chosen = money([shop, later, newerReference]).rateFor('CDF', 'USD', NOW);
    expect(chosen.id).toBe('r1');
  });
});
