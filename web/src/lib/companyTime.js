// L10N-03: the app's convention for instants in lists and details of a
// company's records (the approvals inbox, the automation run log): the
// record's company time zone, labelled with the zone's short name when it
// differs from the browser's ("8 Oct 2026, 17:00 EAT"), so a reader in
// another zone is never misled. Without a company zone, the browser's.
// The notification bell is personal and stays in the browser's zone.
import { formatDateTime, formatTime } from './dates'

/** The browser's time zone, or null where Intl cannot say. */
function browserZone() {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone ?? null
  } catch {
    return null
  }
}

/** "EAT", "WAT" (or "GMT+1"): the zone's short name at `value`. */
function zoneName(value, timeZone, locale) {
  try {
    const parts = new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : locale, { timeZone, timeZoneName: 'short' }).formatToParts(new Date(value))
    return parts.find((part) => part.type === 'timeZoneName')?.value ?? null
  } catch {
    return null
  }
}

/**
 * An instant in `company`'s time zone ({timezone}), labelled with the
 * zone's short name when it differs from the browser's. `format` is the
 * date-and-time format by default.
 */
export function formatCompanyTime(value, locale, company, format = formatDateTime) {
  if (!value) return null
  const zone = company?.timezone
  if (!zone) return format(value, locale)
  let text
  try {
    text = format(value, locale, zone)
  } catch {
    return format(value, locale)
  }
  if (zone === browserZone()) return text
  const name = zoneName(value, zone, locale)
  return name ? `${text} ${name}` : text
}

/** The time of day only ("17:00 EAT"), with the same zone rules. */
export const formatCompanyClock = (value, locale, company) => formatCompanyTime(value, locale, company, formatTime)
