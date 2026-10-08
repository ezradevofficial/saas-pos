import { formatCalendarDate, formatDate, formatDateTime, formatLongDate, formatTime, localDateTimeIn, partOfDay, todayIn, zonedToUtc } from './dates'

describe('dates', () => {
  it('writes day, short month and year with 24-hour times', () => {
    expect(formatDateTime('2026-10-07T14:05:00', 'en')).toBe('7 Oct 2026, 14:05')
    expect(formatDateTime('2026-10-07T14:05:00', 'fr')).toMatch(/^7 oct\. 2026.*14:05$/)
    expect(formatDateTime(null, 'en')).toBeNull()
  })

  it('writes dates alone and 24-hour times alone', () => {
    expect(formatDate('2026-10-07T14:05:00', 'en')).toBe('7 Oct 2026')
    expect(formatDate('2026-10-07T14:05:00', 'fr')).toBe('7 oct. 2026')
    expect(formatTime('2026-10-07T18:05:00', 'en')).toBe('18:05')
    expect(formatDate(null, 'en')).toBeNull()
  })

  it('writes the long date for page headers', () => {
    expect(formatLongDate('2026-10-07T09:00:00', 'en')).toBe('Wednesday 7 Oct 2026')
    expect(formatLongDate('2026-10-07T09:00:00', 'fr')).toBe('mercredi 7 oct. 2026')
  })

  it('picks the greeting for the hour', () => {
    expect([partOfDay(6), partOfDay(12), partOfDay(17), partOfDay(18)]).toEqual(['morning', 'afternoon', 'afternoon', 'evening'])
  })
})

describe('company time zones', () => {
  it('formats in the company time zone', () => {
    expect(formatDateTime('2026-10-07T23:30:00Z', 'en', 'Africa/Nairobi')).toBe('8 Oct 2026, 02:30')
    expect(formatDateTime('2026-10-07T23:30:00Z', 'en', 'Africa/Kinshasa')).toBe('8 Oct 2026, 00:30')
  })

  it('reads calendar dates without shifting them', () => {
    expect(formatCalendarDate('2026-10-07', 'en')).toBe('7 Oct 2026')
  })

  it('converts wall-clock time in a zone to UTC and back', () => {
    expect(zonedToUtc('2026-10-08T02:30', 'Africa/Nairobi')).toBe('2026-10-07T23:30:00.000Z')
    expect(localDateTimeIn('Africa/Kinshasa', new Date('2026-10-07T23:30:00Z'))).toBe('2026-10-08T00:30')
    expect(todayIn('Africa/Nairobi', new Date('2026-10-07T22:00:00Z'))).toBe('2026-10-08')
  })
})
