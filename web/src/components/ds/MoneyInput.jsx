import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { decimalsOf, decimalToMinor, formatDecimal, formatMinor, parseDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { TextField } from './TextField'

/**
 * A TextField for a number typed in the UI language ("12,450.50" in
 * English, "12 450,50" in French), never read through a float. It keeps
 * the text the user typed and reports `onChange(value)`:
 * - "" when empty,
 * - null while the text is not a valid number (the reason shows under the
 *   field once the user leaves it),
 * - otherwise the value (see each input below).
 * An `error` from the form (e.g. the API) wins over the input's own.
 */
function NumberField({ value, toText, fromParsed, options, example, onChange, onBlur, error, help, ...rest }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [text, setText] = useState(() => (value === '' || value === null || value === undefined ? '' : toText(value, locale)))
  const [touched, setTouched] = useState(false)
  const parsed = parseDecimal(text, { locale, ...options })
  const reason = parsed.error === 'decimals' && options.maxDecimals === 0 ? 'noDecimals' : parsed.error
  const own = touched && reason ? t(`ds.numberInput.${reason}`, { example, count: options.maxDecimals, max: options.max }) : null

  return (
    <TextField
      {...rest}
      inputMode="decimal"
      autoComplete="off"
      value={text}
      help={help}
      error={error || own || undefined}
      onChange={(event) => {
        const next = event.target.value
        setText(next)
        const result = parseDecimal(next, { locale, ...options })
        onChange?.(result.error ? null : result.value === '' ? '' : fromParsed(result.value))
      }}
      onBlur={(event) => {
        setTouched(true)
        // Tidy a valid number into the language's format ("12450.5" → "12,450.50").
        if (!parsed.error && parsed.value !== '') setText(toText(fromParsed(parsed.value), locale))
        onBlur?.(event)
      }}
    />
  )
}

/**
 * Money (CLAUDE.md, Data conventions): the currency code before the field,
 * the currency's decimals enforced (CDF none), and the value reported as
 * minor units in a string ("1245050" for USD 12,450.50).
 */
export function MoneyInput({ currency, decimals, value, onChange, ...rest }) {
  const places = decimals ?? decimalsOf(currency)
  const example = formatMinor(places === 0 ? '135000' : `1245050${'0'.repeat(Math.max(places - 2, 0))}`, places, useLocale())
  return (
    <NumberField
      {...rest}
      prefix={currency}
      value={value}
      example={example}
      options={{ maxDecimals: places }}
      toText={(minor, locale) => formatMinor(minor, places, locale)}
      fromParsed={(decimal) => decimalToMinor(decimal, places)}
      onChange={onChange}
    />
  )
}

/**
 * An exchange rate (CUR-03): above zero, up to 8 decimals and 10 digits
 * before the point (numeric(18,8)); reported as a decimal string ("2850.5").
 */
export function RateInput({ value, onChange, ...rest }) {
  return (
    <NumberField
      {...rest}
      value={value}
      example={formatDecimal('2850.5', useLocale())}
      options={{ maxDecimals: 8, maxIntegerDigits: 10, positive: true }}
      toText={(decimal, locale) => formatDecimal(decimal, locale)}
      fromParsed={(decimal) => decimal}
      onChange={onChange}
    />
  )
}

/** A tax rate in percent (MD-03): 0 to 100, up to 4 decimals; reported as a decimal string ("16"). */
export function PercentInput({ value, onChange, ...rest }) {
  return (
    <NumberField
      {...rest}
      suffix="%"
      value={value}
      example={formatDecimal('16', useLocale())}
      options={{ maxDecimals: 4, maxIntegerDigits: 3, max: '100' }}
      toText={(decimal, locale) => formatDecimal(decimal, locale)}
      fromParsed={(decimal) => decimal}
      onChange={onChange}
    />
  )
}
