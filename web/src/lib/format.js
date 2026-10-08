// L10N-03: numbers, money and dates in the UI language, times in the
// company's time zone. Builds on dates.js and money.js (BigInt, never
// floats for money) so every screen formats the same way.
import { formatCalendarDate, formatDate, formatDateTime } from './dates'
import { decimalsOf, formatDecimal, formatMinor } from './money'

export { formatCalendarDate, formatDate, formatDateTime, formatDecimal, formatMinor }

const NUMBER_LOCALES = { en: 'en-KE', fr: 'fr-CD' }
const numberLocale = (locale) => NUMBER_LOCALES[String(locale ?? 'en').slice(0, 2)] ?? NUMBER_LOCALES.en

/** A whole number with the language's grouping: 12,450 / 12 450. */
export function formatInteger(value, locale) {
  if (value === null || value === undefined || value === '') return ''
  try {
    return new Intl.NumberFormat(numberLocale(locale), { maximumFractionDigits: 0 }).format(BigInt(String(value)))
  } catch {
    return String(value)
  }
}

/** Money with its code first (CLAUDE.md): "KES 12,450.00", "CDF 135,000". */
export function formatMoney(minor, currency, locale, decimals = decimalsOf(currency)) {
  if (minor === null || minor === undefined || minor === '') return ''
  return `${currency} ${formatMinor(minor, decimals, locale)}`
}

/** A file size: "840 KB", "1.2 MB" (1,2 Mo in French). */
export function formatBytes(bytes, locale) {
  const size = Number(bytes) || 0
  const french = String(locale ?? 'en').startsWith('fr')
  const units = french ? ['o', 'Ko', 'Mo'] : ['B', 'KB', 'MB']
  let index = 0
  let value = size
  while (value >= 1024 && index < units.length - 1) {
    value /= 1024
    index += 1
  }
  const text = new Intl.NumberFormat(numberLocale(locale), { maximumFractionDigits: index === 2 ? 1 : 0 }).format(value)
  return `${text} ${units[index]}`
}

/** An ISO instant, a calendar date or anything else, for display (dates in `timeZone`). */
export function formatWhen(value, locale, timeZone) {
  if (typeof value !== 'string') return null
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return formatCalendarDate(value, locale)
  if (/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/.test(value) && !Number.isNaN(Date.parse(value))) return formatDateTime(value, locale, timeZone)
  return null
}
