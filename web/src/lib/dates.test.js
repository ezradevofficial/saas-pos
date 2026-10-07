import { formatDateTime, formatLongDate, partOfDay } from './dates'

describe('dates', () => {
  it('writes day, short month and year with 24-hour times', () => {
    expect(formatDateTime('2026-10-07T14:05:00', 'en')).toBe('7 Oct 2026, 14:05')
    expect(formatDateTime('2026-10-07T14:05:00', 'fr')).toMatch(/^7 oct\. 2026.*14:05$/)
    expect(formatDateTime(null, 'en')).toBeNull()
  })

  it('writes the long date for page headers', () => {
    expect(formatLongDate('2026-10-07T09:00:00', 'en')).toBe('Wednesday 7 Oct 2026')
    expect(formatLongDate('2026-10-07T09:00:00', 'fr')).toBe('mercredi 7 oct. 2026')
  })

  it('picks the greeting for the hour', () => {
    expect([partOfDay(6), partOfDay(12), partOfDay(17), partOfDay(18)]).toEqual(['morning', 'afternoon', 'afternoon', 'evening'])
  })
})
