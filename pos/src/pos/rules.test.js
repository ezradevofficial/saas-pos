import { approvers, check } from './authority';
import { drawNumber, nextToReport, renderPattern } from './numbering';
import { discountPercent, extend, lineAmounts, rateOn, saleTotals, taxOn, taxRateFor, TaxRateNeeded } from './tax';

const vat = {
  id: 'tc-vat',
  code: 'VAT16',
  kind: 'standard',
  rates: [
    { rate: '14.0000', effective_from: '2020-01-01', effective_to: '2025-12-31', needs_confirmation: false },
    { rate: '16.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false },
  ],
};
const pending = { id: 'tc-new', code: 'EXC', kind: 'standard', rates: [{ rate: null, effective_from: '2026-01-01', needs_confirmation: true }] };
const exempt = { id: 'tc-ex', code: 'EX', kind: 'exempt', rates: [] };
const taxCodes = new Map([vat, pending, exempt].map((code) => [code.id, code]));
const ctx = { taxCodes, day: '2026-10-09' };

describe('tax at the till (POS-11)', () => {
  it('takes the rate in force on the local day', () => {
    expect(rateOn(vat, '2025-06-01').rate).toBe('14.0000');
    expect(rateOn(vat, '2026-10-09').rate).toBe('16.0000');
    expect(taxRateFor(vat, '2026-10-09')).toEqual({ rate: '16.0000', applied: true });
    expect(taxRateFor(exempt, '2026-10-09')).toEqual({ rate: null, applied: false });
  });

  it('refuses a rate that is needed, never inventing one', () => {
    expect(() => taxRateFor(pending, '2026-10-09')).toThrow(TaxRateNeeded);
    expect(() => taxRateFor(vat, '2019-01-01')).toThrow(TaxRateNeeded);
    expect(lineAmounts({ unitPriceMinor: '100', qty: '1', taxCodeId: 'tc-new', taxInclusive: true }, ctx)).toEqual({ blocked: 'tax_rate_needed', grossMinor: '100' });
    expect(lineAmounts({ unitPriceMinor: '100', qty: '1', taxCodeId: null, taxInclusive: true }, ctx).blocked).toBe('tax_code_missing');
  });

  it('computes inclusive and exclusive tax half up, as TaxCalculator', () => {
    expect(taxOn(76000n, '16.0000', true)).toBe(10483n);
    expect(taxOn(65517n, '16.0000', false)).toBe(10483n);
    expect(taxOn(5n, '16.0000', false)).toBe(1n);
    expect(taxOn(3n, '16.0000', false)).toBe(0n);
  });

  it('extends unit price × quantity once, half up, then takes the discount off before tax', () => {
    expect(extend('333', '1.5')).toBe(500n);
    expect(extend('25000', '2')).toBe(50000n);
    const inclusive = lineAmounts({ unitPriceMinor: '25000', qty: '2', discountMinor: '5000', taxCodeId: 'tc-vat', taxInclusive: true }, ctx);
    expect(inclusive).toEqual({ grossMinor: '50000', discountMinor: '5000', taxMinor: '6207', totalMinor: '45000', taxRate: '16.0000', taxCodeId: 'tc-vat' });
    const exclusive = lineAmounts({ unitPriceMinor: '1000', qty: '3', discountMinor: '0', taxCodeId: 'tc-vat', taxInclusive: false }, ctx);
    expect(exclusive.totalMinor).toBe('3480');
    expect(lineAmounts({ unitPriceMinor: '1000', qty: '1', discountMinor: '1001', taxCodeId: 'tc-vat', taxInclusive: true }, ctx).blocked).toBe('discount_above_price');
  });

  it('sums the sale like the server (subtotal = Σ gross)', () => {
    const lines = [
      lineAmounts({ unitPriceMinor: '25000', qty: '2', discountMinor: '5000', taxCodeId: 'tc-vat', taxInclusive: true }, ctx),
      lineAmounts({ unitPriceMinor: '6500', qty: '1', taxCodeId: 'tc-ex', taxInclusive: true }, ctx),
    ];
    expect(saleTotals(lines)).toEqual({ subtotal_minor: '56500', discount_minor: '5000', tax_minor: '6207', total_minor: '51500' });
  });
});

describe('discount and override limits (POS-07, RBAC-06, AUTH-08)', () => {
  const cashier = { id: 'u-c', name: 'Grace', permissions: ['pos.sale.create'], limits: {} };
  const giver = { id: 'u-g', name: 'Ann', permissions: ['pos.discount.give'], limits: { max_discount_percent: '5.0000' } };
  const manager = { id: 'u-m', name: 'Peter', permissions: ['pos.discount.give', 'pos.sale.void', 'pos.sale.refund', 'pos.cash.move', 'pos.price.override'], limits: { max_discount_percent: '20.0000', max_refund_amount: '100.0000' } };

  it('computes the discount percentage like the server', () => {
    expect(discountPercent('5000', '50000')).toBe('10.0000');
    expect(discountPercent('1', '3')).toBe('33.3333');
  });

  it('allows within the limit, refuses without the permission or above the limit', () => {
    expect(check(cashier, 'discount', '1.0000')).toEqual({ allowed: false, permission: 'pos.discount.give', reason: 'permission' });
    expect(check(giver, 'discount', '5.0000').allowed).toBe(true);
    expect(check(giver, 'discount', '5.0001')).toMatchObject({ allowed: false, reason: 'limit' });
    expect(check(manager, 'refund', '100')).toMatchObject({ allowed: true });
    expect(check(manager, 'refund', '100.01')).toMatchObject({ allowed: false, reason: 'limit' });
    expect(check(cashier, 'void').allowed).toBe(false);
    expect(check(manager, 'pay_out').allowed).toBe(true);
  });

  it('applies no limit to an Owner (staff row owner, as the server)', () => {
    const owner = { id: 'u-o', permissions: ['pos.discount.give', 'pos.sale.refund'], limits: {}, owner: true };
    expect(check(owner, 'discount', '90.0000').allowed).toBe(true);
    expect(check(owner, 'refund', '1000000').allowed).toBe(true);
    expect(check({ ...owner, permissions: [] }, 'discount', '1.0000').allowed).toBe(false);
  });

  it('offers only staff who could approve, never the requester or a locked PIN', () => {
    const locked = { ...manager, id: 'u-l', locked: true };
    expect(approvers([cashier, giver, manager, locked], 'discount', '10.0000', 'u-c').map((member) => member.id)).toEqual(['u-m']);
    expect(approvers([cashier, giver, manager], 'discount', '10.0000', 'u-m')).toEqual([]);
  });
});

describe('receipt numbers from device ranges (NUM-01, NUM-02)', () => {
  const at = Date.parse('2026-10-09T08:00:00Z');
  const ranges = [
    { id: 'rg-2', document_type: 'pos.receipt', period: '2026', pattern: 'R-WL2-{MM}-{000001}', from: 501, to: 1000, next: 501 },
    { id: 'rg-1', document_type: 'pos.receipt', period: '2026', pattern: 'R-WL2-{MM}-{000001}', from: 1, to: 500, next: 498 },
    { id: 'rg-old', document_type: 'pos.receipt', period: '2025', pattern: 'R-25-{00001}', from: 1, to: 500, next: 1 },
    { id: 'rf-1', document_type: 'pos.refund', period: 'all', pattern: 'F{YY}-{0001}', from: 1, to: 50, next: 1 },
  ];

  it('renders the frozen pattern with the local month and the padded counter', () => {
    expect(renderPattern('R-{BRANCH}-{00001}', { BRANCH: 'WL' }, 42)).toBe('R-WL-00042');
    expect(renderPattern('R-{0001}', {}, 123456)).toBe('R-123456');
    expect(renderPattern('R-{BRANCH}-{01}', {}, 1)).toBeNull();
  });

  it('draws in range order, skips other years, and moves on when a range runs out', () => {
    let used = {};
    const first = drawNumber({ ranges, used, documentType: 'pos.receipt', at, timeZone: 'Africa/Nairobi' });
    expect(first).toMatchObject({ rangeId: 'rg-1', seq: 498, number: 'R-WL2-10-000498' });
    used = first.used;
    used = drawNumber({ ranges, used, documentType: 'pos.receipt', at, timeZone: 'Africa/Nairobi' }).used;
    used = drawNumber({ ranges, used, documentType: 'pos.receipt', at, timeZone: 'Africa/Nairobi' }).used;
    const next = drawNumber({ ranges, used, documentType: 'pos.receipt', at, timeZone: 'Africa/Nairobi' });
    expect(next).toMatchObject({ rangeId: 'rg-2', seq: 501, number: 'R-WL2-10-000501' });
    expect(nextToReport(ranges, next.used, 'pos.receipt', '2026')).toBe(502);
    expect(drawNumber({ ranges, documentType: 'pos.refund', at, timeZone: 'UTC' }).number).toBe('F26-0001');
  });

  it('asks for a top-up below the threshold and has no number once every range is spent', () => {
    const small = [{ id: 'a', document_type: 'pos.receipt', period: 'all', pattern: 'R{001}', from: 1, to: 3, next: 1 }];
    const drawn = drawNumber({ ranges: small, documentType: 'pos.receipt', at, timeZone: 'UTC' });
    expect(drawn).toMatchObject({ number: 'R001', remaining: 2, topUp: true });
    expect(drawNumber({ ranges: small, used: { a: 4 }, documentType: 'pos.receipt', at, timeZone: 'UTC' })).toBeNull();
    // The server's `next` wins when it is ahead of the till (another install used numbers).
    expect(drawNumber({ ranges: [{ ...small[0], next: 3 }], used: { a: 2 }, documentType: 'pos.receipt', at, timeZone: 'UTC' }).seq).toBe(3);
    // A range in use reports its own next, never a later range's start.
    expect(nextToReport(ranges, {}, 'pos.receipt', '2026')).toBe(498);
  });
});
