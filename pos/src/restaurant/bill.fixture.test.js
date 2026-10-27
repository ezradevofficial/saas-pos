import fixture from '../../../api/tests/Fixtures/restaurant/bill.json';
import { allocate, equalQty, split, totals } from './bill';

// REST-13, REST-14: the till's bill maths replays the fixture the server's Bill replays
// (api/tests/Fixtures/restaurant/bill.json), so both agree to the minor unit.

// The server's JSON may hold a number where the till has a digit string: compare as text.
const text = (rows) => rows.map((row) => Object.fromEntries(Object.entries(row).map(([key, value]) => [key, typeof value === 'number' ? String(value) : value])));

describe('shared restaurant bill fixture (REST-13, REST-14)', () => {
  for (const testCase of fixture.cases) {
    it(testCase.name, () => {
      if (testCase.kind === 'totals') {
        expect(totals(testCase.lines, testCase.service, testCase.tip_minor)).toEqual(testCase.expect);
        return;
      }
      const options = Array.isArray(testCase.options) ? {} : testCase.options;
      const parts = split(testCase.lines, testCase.mode, testCase.service, testCase.tip_minor, options);
      expect(text(parts)).toEqual(text(testCase.expect));
      // The parts add up to the order's total exactly.
      const whole = totals(testCase.lines, testCase.service, testCase.tip_minor);
      expect(parts.reduce((sum, row) => sum + BigInt(row.total_minor), 0n)).toBe(BigInt(whole.total_minor));
      expect(parts.reduce((sum, row) => sum + BigInt(row.tip_minor), 0n)).toBe(BigInt(whole.tip_minor));
      expect(parts.reduce((sum, row) => sum + BigInt(row.service_charge_minor), 0n)).toBe(BigInt(whole.service_charge_minor));
    });
  }

  for (const row of fixture.equal_qty) {
    it(`equalQty ${row.qty} part ${row.index + 1} of ${row.count}`, () => {
      expect(equalQty(row.qty, row.index, row.count)).toBe(row.expect);
    });
  }

  it('equal parts of a quantity add up to the quantity', () => {
    for (const [qty, count, total] of [['1', 3, 1000000n], ['7', 6, 7000000n], ['2.5', 7, 2500000n], ['0.000001', 3, 1n], ['12.345678', 20, 12345678n]]) {
      const sum = Array.from({ length: count }, (_, index) => equalQty(qty, index, count)).reduce((acc, value) => acc + BigInt(value.replace('.', '')), 0n);
      expect(sum).toBe(total);
    }
  });

  it('allocates by largest remainder and shares equally when the weights are zero', () => {
    expect(allocate(100n, [1n, 1n, 1n]).map(String)).toEqual(['34', '33', '33']);
    expect(allocate(5n, [0n, 0n]).map(String)).toEqual(['3', '2']);
  });

  it('refuses a split the server refuses', () => {
    const lines = [{ id: 'a', qty: '1', unit_price_minor: '100', tax_inclusive: false }];
    expect(() => split(lines, 'equal', {}, 0, { parts: 1 })).toThrow();
    expect(() => split(lines, 'equal', {}, 0, { parts: 21 })).toThrow();
    expect(() => split(lines, 'item', {}, 0, { groups: [['a']] })).toThrow();
    expect(() => split(lines, 'seat')).toThrow();
    expect(() => totals(lines, {}, -1)).toThrow();
  });
});
