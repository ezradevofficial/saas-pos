// Dates read "7 Oct 2026" / "7 oct. 2026" with 24-hour times (design
// system, Content fundamentals): English uses day-month order.
const intlLocale = (locale) => (locale === 'en' ? 'en-GB' : locale)

/** "7 Oct 2026, 14:05"; `timeZone` (a company's, e.g. "Africa/Kinshasa") when given. */
export function formatDateTime(value, locale, timeZone) {
  if (!value) return null
  return new Intl.DateTimeFormat(intlLocale(locale), {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
    ...(timeZone ? { timeZone } : {}),
  }).format(new Date(value))
}

/** "7 Oct 2026" */
export function formatDate(value, locale) {
  if (!value) return null
  return new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value))
}

/**
 * A calendar date ("2026-10-07", as the API sends effective dates) for
 * display: read as that day, never shifted by the browser's time zone.
 */
export function formatCalendarDate(value, locale) {
  if (!value) return null
  return formatDate(`${String(value).slice(0, 10)}T12:00:00Z`, locale)
}

function zonedParts(date, timeZone) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(date)
  const get = (type) => Number(parts.find((part) => part.type === type)?.value)
  return { year: get('year'), month: get('month'), day: get('day'), hour: get('hour'), minute: get('minute'), second: get('second') }
}

/** Today's date ("2026-10-07") in a time zone (the company's), or the browser's. */
export function todayIn(timeZone, now = new Date()) {
  if (!timeZone) return now.toISOString().slice(0, 10)
  const { year, month, day } = zonedParts(now, timeZone)
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/** "2026-10-07T14:05" (a datetime-local value) in a time zone, for now or `date`. */
export function localDateTimeIn(timeZone, date = new Date()) {
  const { year, month, day, hour, minute } = zonedParts(date, timeZone)
  const pad = (n) => String(n).padStart(2, '0')
  return `${year}-${pad(month)}-${pad(day)}T${pad(hour)}:${pad(minute)}`
}

/**
 * A wall-clock time in a time zone ("2026-10-07T14:05" in
 * "Africa/Kinshasa") as a UTC ISO string, for the API.
 */
export function zonedToUtc(local, timeZone) {
  const [date, time = '00:00'] = String(local).split('T')
  const [year, month, day] = date.split('-').map(Number)
  const [hour, minute] = time.split(':').map(Number)
  const asUtc = Date.UTC(year, month - 1, day, hour, minute)
  if (!timeZone) return new Date(year, month - 1, day, hour, minute).toISOString()
  // The zone's offset at that moment, corrected once for a DST edge.
  const offsetAt = (instant) => {
    const p = zonedParts(new Date(instant), timeZone)
    return Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second) - instant
  }
  let instant = asUtc - offsetAt(asUtc)
  instant = asUtc - offsetAt(instant)
  return new Date(instant).toISOString()
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
