import { compareDecimal, CURRENCY_DECIMALS, decimalsOf, decimalToMinor, formatAmount, formatDecimal, minorToDecimal, parseDecimal } from './money'

// Money is stored in minor units (CLAUDE.md, Data conventions); CDF has no decimals.
describe('formatAmount', () => {
  it('formats KES minor units with two decimals', () => {
    expect(formatAmount(1245000, 'KES')).toBe('12,450.00')
  })

  it('formats CDF with no decimals', () => {
    expect(formatAmount(135000, 'CDF')).toBe('135,000')
  })

  it('uses comma decimals in French', () => {
    expect(formatAmount(4850, 'USD', 'fr')).toBe('48,50')
  })

  it('groups French thousands with a narrow no-break space', () => {
    expect(formatAmount(1245000, 'KES', 'fr')).toBe('12 450,00')
  })

  it('accepts bigint and string minor units without float drift', () => {
    expect(formatAmount(900719925474099312n, 'USD')).toBe('9,007,199,254,740,993.12')
    expect(formatAmount('4850', 'USD')).toBe('48.50')
  })

  it('formats negative amounts and pads small values', () => {
    expect(formatAmount(-500, 'KES')).toBe('-5.00')
    expect(formatAmount(5, 'USD')).toBe('0.05')
  })

  it('defaults to two decimals for unknown currencies', () => {
    expect(CURRENCY_DECIMALS).toEqual({ CDF: 0, KES: 2, USD: 2 })
    expect(formatAmount(100, 'EUR')).toBe('1.00')
  })

  it('treats invalid input as zero', () => {
    expect(formatAmount(undefined, 'KES')).toBe('0.00')
    expect(formatAmount('abc', 'CDF')).toBe('0')
  })
})

describe('parseDecimal', () => {
  it('reads English input with optional thousands separators', () => {
    expect(parseDecimal('12,450.50', { locale: 'en' })).toEqual({ value: '12450.50', error: null })
    expect(parseDecimal('12450.5', { locale: 'en' })).toEqual({ value: '12450.5', error: null })
    expect(parseDecimal(' 7 ', { locale: 'en' })).toEqual({ value: '7', error: null })
    expect(parseDecimal('', { locale: 'en' })).toEqual({ value: '', error: null })
  })

  it('reads French input with spaces and a decimal comma', () => {
    expect(parseDecimal('12 450,50', { locale: 'fr' }).value).toBe('12450.50')
    expect(parseDecimal('12 450,50', { locale: 'fr' }).value).toBe('12450.50')
    expect(parseDecimal('48,5', { locale: 'fr' }).value).toBe('48.5')
    // A lone point from a phone keypad is the decimal mark.
    expect(parseDecimal('48.5', { locale: 'fr' }).value).toBe('48.5')
  })

  it('refuses ambiguous grouping, letters and too many decimals', () => {
    expect(parseDecimal('12,45', { locale: 'en' }).error).toBe('format')
    expect(parseDecimal('12a', { locale: 'en' }).error).toBe('format')
    expect(parseDecimal('1.2.3', { locale: 'en' }).error).toBe('format')
    expect(parseDecimal('-5', { locale: 'en' }).error).toBe('format')
    expect(parseDecimal('12.505', { locale: 'en', maxDecimals: 2 }).error).toBe('decimals')
    expect(parseDecimal('12.5', { locale: 'en', maxDecimals: 0 }).error).toBe('decimals')
  })

  it('checks positive values and a maximum', () => {
    expect(parseDecimal('0.000', { positive: true, maxDecimals: 8 }).error).toBe('positive')
    expect(parseDecimal('100.0001', { maxDecimals: 4, max: '100' }).error).toBe('range')
    expect(parseDecimal('100', { maxDecimals: 4, max: '100' }).error).toBeNull()
  })
})

describe('minor units and decimals', () => {
  it('converts decimals to minor units by currency', () => {
    expect(decimalToMinor('12450.5', decimalsOf('USD'))).toBe('1245050')
    expect(decimalToMinor('135000', decimalsOf('CDF'))).toBe('135000')
    expect(decimalToMinor('', 2)).toBe('')
    expect(minorToDecimal('1245050', 2)).toBe('12450.50')
    expect(minorToDecimal('135000', 0)).toBe('135000')
  })

  it('prefers the tenant decimals of a currency', () => {
    expect(decimalsOf('CDF')).toBe(0)
    expect(decimalsOf('KES', [{ code: 'KES', decimals: 0 }])).toBe(0)
  })

  it('formats rates without trailing zeros in either language', () => {
    expect(formatDecimal('2850.00000000', 'en')).toBe('2,850')
    expect(formatDecimal('0.00035088', 'en')).toBe('0.00035088')
    expect(formatDecimal('2850.50000000', 'fr')).toBe('2 850,5')
    expect(formatDecimal('16.0000', 'en', { minDecimals: 1 })).toBe('16.0')
  })

  it('compares decimal strings without floats', () => {
    expect(compareDecimal('0.1', '0.10')).toBe(0)
    expect(compareDecimal('99999999999.00000001', '99999999999')).toBe(1)
  })
})
