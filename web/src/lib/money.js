// Money is stored in minor units with an ISO 4217 code (CLAUDE.md, Data conventions).
// Formatting uses BigInt so large amounts never pass through a float.

export const CURRENCY_DECIMALS = { CDF: 0, KES: 2, USD: 2 }

const LOCALE_TAGS = { en: 'en-KE', fr: 'fr-CD' }

/** Minor units as a BigInt; anything that is not a whole number becomes 0n. */
export function toMinor(minor) {
  try {
    if (typeof minor === 'bigint') return minor
    if (typeof minor === 'number') return Number.isFinite(minor) ? BigInt(Math.round(minor)) : 0n
    if (typeof minor === 'string' && /^\s*-?\d+\s*$/.test(minor)) return BigInt(minor.trim())
  } catch {
    // fall through
  }
  return 0n
}

function localeTag(locale) {
  const language = String(locale ?? 'en').toLowerCase().split(/[-_]/)[0]
  return LOCALE_TAGS[language] ?? LOCALE_TAGS.en
}

/**
 * Format an amount given in minor units, without the currency code.
 * formatAmount(1245000, 'KES') → "12,450.00"; formatAmount(4850, 'USD', 'fr') → "48,50".
 */
export function formatAmount(minor, currency, locale = 'en') {
  const decimals = CURRENCY_DECIMALS[String(currency ?? '').toUpperCase()] ?? 2
  const tag = localeTag(locale)
  const value = toMinor(minor)
  const negative = value < 0n
  const absolute = negative ? -value : value
  const scale = 10n ** BigInt(decimals)

  const whole = new Intl.NumberFormat(tag, { maximumFractionDigits: 0 }).format(absolute / scale)
  let text = whole
  if (decimals > 0) {
    const separator = new Intl.NumberFormat(tag).formatToParts(1.5).find((part) => part.type === 'decimal')?.value ?? '.'
    text += separator + String(absolute % scale).padStart(decimals, '0')
  }
  return negative ? `-${text}` : text
}

/** The decimals of a currency: the tenant's setting when given, else the defaults above (CDF 0). */
export function decimalsOf(currency, tenantCurrencies = []) {
  const code = String(currency ?? '').toUpperCase()
  const tenant = tenantCurrencies.find((entry) => entry.code === code)
  if (tenant && Number.isInteger(tenant.decimals)) return tenant.decimals
  return CURRENCY_DECIMALS[code] ?? 2
}

const SPACES = /[\s  ]/g

/**
 * Read a number typed in the UI language, without floats: "12,450.50"
 * (en) or "12 450,50" (fr; a lone "." is also read as the decimal point,
 * as phone keypads type it). Thousands separators are optional, but when
 * used every group after the first has three digits, so "12,45" is never
 * read as 1245.
 *
 * Returns `{ value, error }`: value is a plain decimal string ("12450.5")
 * or "" when empty; error is null, "format", "decimals" (more than
 * `maxDecimals`), "integer" (more than `maxIntegerDigits`), "positive"
 * (zero when `positive`) or "range" (above `max`).
 */
export function parseDecimal(text, { locale = 'en', maxDecimals = 2, maxIntegerDigits = 15, positive = false, max = null } = {}) {
  const raw = String(text ?? '').trim()
  if (raw === '') return { value: '', error: null }
  const french = String(locale).toLowerCase().startsWith('fr')

  let decimalMark = french ? ',' : '.'
  let groupMark = french ? ' ' : ','
  let source = raw.replace(SPACES, ' ')
  if (french && !source.includes(',') && (source.match(/\./g) ?? []).length === 1 && !/\.\d{3}(\D|$)/.test(source)) {
    decimalMark = '.'
  }
  if (!french) source = source.replace(/ /g, ',')

  const parts = source.split(decimalMark)
  if (parts.length > 2) return { value: '', error: 'format' }
  const [whole, fraction = null] = parts
  if (fraction !== null && !/^\d+$/.test(fraction)) return { value: '', error: 'format' }

  const groups = whole.split(groupMark)
  if (groups.some((group) => !/^\d+$/.test(group))) return { value: '', error: 'format' }
  if (groups.length > 1 && (groups[0].length > 3 || groups.slice(1).some((group) => group.length !== 3))) return { value: '', error: 'format' }

  const integer = groups.join('').replace(/^0+(?=\d)/, '')
  if (integer.length > maxIntegerDigits) return { value: '', error: 'integer' }
  if (fraction !== null && fraction.length > maxDecimals) return { value: '', error: 'decimals' }

  const value = fraction ? `${integer}.${fraction}` : integer
  if (positive && /^[0.]+$/.test(value)) return { value: '', error: 'positive' }
  if (max !== null && compareDecimal(value, String(max)) > 0) return { value: '', error: 'range' }
  return { value, error: null }
}

/** Compare two non-negative decimal strings: -1, 0 or 1. */
export function compareDecimal(a, b) {
  const [ai, af = ''] = String(a).split('.')
  const [bi, bf = ''] = String(b).split('.')
  const width = Math.max(af.length, bf.length)
  const left = BigInt(ai + af.padEnd(width, '0'))
  const right = BigInt(bi + bf.padEnd(width, '0'))
  return left === right ? 0 : left < right ? -1 : 1
}

/** A decimal string ("12450.5") as minor units ("1245050") for `decimals`. */
export function decimalToMinor(value, decimals) {
  if (value === '' || value === null || value === undefined) return ''
  const [whole, fraction = ''] = String(value).split('.')
  return BigInt(whole + fraction.padEnd(decimals, '0').slice(0, decimals)).toString()
}

/** Minor units as a plain decimal string for an input ("1245050", 2 → "12450.50"). */
export function minorToDecimal(minor, decimals) {
  const value = toMinor(minor)
  const negative = value < 0n
  const absolute = negative ? -value : value
  if (decimals === 0) return `${negative ? '-' : ''}${absolute}`
  const scale = 10n ** BigInt(decimals)
  return `${negative ? '-' : ''}${absolute / scale}.${String(absolute % scale).padStart(decimals, '0')}`
}

/**
 * A decimal string (an exchange or tax rate, "2850.00000000") for display
 * in the UI language, trailing zeros dropped down to `minDecimals`:
 * formatDecimal("2850.50000000", "en") → "2,850.5"; in fr "2 850,5".
 */
export function formatDecimal(value, locale = 'en', { minDecimals = 0 } = {}) {
  if (value === null || value === undefined || value === '') return ''
  const text = String(value).trim()
  const negative = text.startsWith('-')
  const [whole, fraction = ''] = text.replace(/^-/, '').split('.')
  let digits = fraction.replace(/0+$/, '')
  if (digits.length < minDecimals) digits = digits.padEnd(minDecimals, '0')
  const tag = localeTag(locale)
  let result = new Intl.NumberFormat(tag, { maximumFractionDigits: 0 }).format(BigInt(whole || '0'))
  if (digits) {
    const separator = new Intl.NumberFormat(tag).formatToParts(1.5).find((part) => part.type === 'decimal')?.value ?? '.'
    result += separator + digits
  }
  return negative ? `-${result}` : result
}

/** Minor units with explicit decimals (a tenant's setting), without the code: formatMinor("5000", 2) → "50.00". */
export function formatMinor(minor, decimals, locale = 'en') {
  return formatDecimal(minorToDecimal(minor, decimals), locale, { minDecimals: decimals })
}
