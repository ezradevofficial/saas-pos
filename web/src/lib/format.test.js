import { formatBytes, formatInteger, formatMoney, formatWhen } from './format'

describe('format (L10N-03)', () => {
  it('formats numbers and money per language, money code first', () => {
    expect(formatInteger('12450', 'en')).toBe('12,450')
    expect(formatInteger('12450', 'fr').replace(/\s/g, ' ')).toBe('12 450')
    expect(formatMoney('1245000', 'KES', 'en')).toBe('KES 12,450.00')
    expect(formatMoney('135000', 'CDF', 'en')).toBe('CDF 135,000')
  })

  it('formats instants in the company time zone and calendar dates as that day', () => {
    expect(formatWhen('2026-10-07T23:30:00Z', 'en', 'Africa/Nairobi')).toBe('8 Oct 2026, 02:30')
    expect(formatWhen('2026-10-07', 'en', 'Africa/Nairobi')).toBe('7 Oct 2026')
    expect(formatWhen('blue', 'en')).toBeNull()
  })

  it('formats file sizes', () => {
    expect(formatBytes(2 * 1024 * 1024, 'en')).toBe('2 MB')
    expect(formatBytes(860 * 1024, 'fr')).toBe('860 Ko')
  })
})
