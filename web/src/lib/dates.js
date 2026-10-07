// Dates read "7 Oct 2026" / "7 oct. 2026" with 24-hour times (design
// system, Content fundamentals): English uses day-month order.
const intlLocale = (locale) => (locale === 'en' ? 'en-GB' : locale)

/** "7 Oct 2026, 14:05" */
export function formatDateTime(value, locale) {
  if (!value) return null
  return new Intl.DateTimeFormat(intlLocale(locale), {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(value))
}

/** "7 Oct 2026" */
export function formatDate(value, locale) {
  if (!value) return null
  return new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value))
}

/** "14:05" */
export function formatTime(value, locale) {
  if (!value) return null
  return new Intl.DateTimeFormat(intlLocale(locale), { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(value))
}

/** "Wednesday 7 Oct 2026" */
export function formatLongDate(value, locale) {
  return new Intl.DateTimeFormat(intlLocale(locale), { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' })
    .format(new Date(value))
    .replace(',', '')
}

/** Which greeting fits the hour: morning until noon, afternoon until 18:00. */
export function partOfDay(hour) {
  if (hour < 12) return 'morning'
  if (hour < 18) return 'afternoon'
  return 'evening'
}
