import { CURRENCY_DECIMALS, formatAmount, setCurrencyDecimals } from './money';

// Same cases as web/src/lib/money.test.js: the POS copy must format identically.
// Money is stored in minor units (CLAUDE.md, Data conventions); CDF has no decimals.
describe('formatAmount', () => {
  it('formats KES minor units with two decimals', () => {
    expect(formatAmount(1245000, 'KES')).toBe('12,450.00');
  });

  it('formats CDF with no decimals', () => {
    expect(formatAmount(135000, 'CDF')).toBe('135,000');
  });

  it('uses comma decimals in French', () => {
    expect(formatAmount(4850, 'USD', 'fr')).toBe('48,50');
  });

  it('groups French thousands with a narrow no-break space', () => {
    expect(formatAmount(1245000, 'KES', 'fr')).toBe('12 450,00');
  });

  it('accepts bigint and string minor units without float drift', () => {
    expect(formatAmount(900719925474099312n, 'USD')).toBe('9,007,199,254,740,993.12');
    expect(formatAmount('4850', 'USD')).toBe('48.50');
  });

  it('formats negative amounts and pads small values', () => {
    expect(formatAmount(-500, 'KES')).toBe('-5.00');
    expect(formatAmount(5, 'USD')).toBe('0.05');
  });

  it('defaults to two decimals for unknown currencies', () => {
    expect(CURRENCY_DECIMALS).toEqual({ CDF: 0, KES: 2, USD: 2 });
    expect(formatAmount(100, 'EUR')).toBe('1.00');
  });

  it('treats invalid input as zero', () => {
    expect(formatAmount(undefined, 'KES')).toBe('0.00');
    expect(formatAmount('abc', 'CDF')).toBe('0');
  });

  it('formats with the tenant’s decimals once known (CUR-01)', () => {
    setCurrencyDecimals([{ code: 'XOF', decimals: 0 }, { code: 'KES', decimals: 2 }]);
    expect(formatAmount(1500, 'XOF')).toBe('1,500');
    setCurrencyDecimals([]);
    expect(formatAmount(1500, 'XOF')).toBe('15.00');
  });
});
