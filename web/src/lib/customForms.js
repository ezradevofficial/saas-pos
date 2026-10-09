// CF-04, CF-05: custom forms on the web: the tenant's form types (the Forms
// menu and Settings → Forms), records, and line totals. Names, labels and
// option texts are the tenant's own words, typed once and shown as typed.
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { api } from '@/api/client'
import { useAuth } from '@/auth/AuthProvider'
import { toApiValue, toFormValue } from './customFields'

export const RECORD_STATUSES = ['draft', 'pending', 'submitted', 'approved', 'rejected', 'cancelled']
export const STATUS_TONES = { draft: 'neutral', pending: 'info', submitted: 'success', approved: 'success', rejected: 'danger', cancelled: 'neutral' }
export const typesKey = ['custom-form-types']

/** The form types the user may use (all of them, with `status`, for those who manage types). */
export function useCustomFormTypes({ status = 'active', enabled = true } = {}) {
  const { token, user } = useAuth()
  const query = useQuery({
    queryKey: [...typesKey, status],
    queryFn: () => api.get(`custom-form-types${status === 'active' ? '' : `?status=${status}`}`),
    enabled: Boolean(token && user) && enabled,
    staleTime: 60_000,
    retry: false,
  })
  return { ...query, types: Array.isArray(query.data?.data) ? query.data.data : [] }
}

/** The type of `/forms/:formKey`, from the types the user may use. */
export function useFormType() {
  const { formKey } = useParams()
  const { types, isPending, error } = useCustomFormTypes()
  return { formKey, type: types.find((entry) => entry.key === formKey) ?? null, isPending, error }
}

/** The line fields in the order the type names them (`line_fields`), then the rest. */
export function orderedLineFields(fields, order = []) {
  const rank = (field) => {
    const at = order.indexOf(field.key)
    return at < 0 ? order.length : at
  }
  return [...fields].sort((a, b) => rank(a) - rank(b))
}

let lineKey = 0
/** A new line for the table: its own key and each field's empty value. */
export function newLine(fields, record = null, timeZone = undefined) {
  lineKey += 1
  return { key: `line-${lineKey}`, values: Object.fromEntries(fields.map((field) => [field.key, toFormValue(field, record?.custom?.[field.key] ?? null, timeZone)])) }
}

/** The lines as the API takes them: only editable fields with a value. */
export function linesBody(fields, lines, timeZone) {
  const editable = fields.filter((field) => !field.readonly && field.type !== 'formula')
  return lines.map((line) => ({
    custom: Object.fromEntries(
      editable.flatMap((field) => {
        const value = toApiValue(field, line.values[field.key], timeZone)
        return value === null || value === undefined || (Array.isArray(value) && value.length === 0) ? [] : [[field.key, value]]
      }),
    ),
  }))
}

/** "12.50" → [1250n, 2]: a decimal string as a whole number and its scale; never a float. */
function scaled(text) {
  const value = String(text).trim()
  if (!/^-?\d+(\.\d+)?$/.test(value)) return null
  const [whole, fraction = ''] = value.replace('-', '').split('.')
  const big = BigInt(whole + fraction) * (value.startsWith('-') ? -1n : 1n)
  return [big, fraction.length]
}

function addDecimals(values) {
  let total = 0n
  let scale = 0
  for (const value of values) {
    const parts = scaled(value)
    if (!parts) continue
    const [big, places] = parts
    if (places > scale) {
      total *= 10n ** BigInt(places - scale)
      scale = places
    }
    total += big * 10n ** BigInt(scale - places)
  }
  if (scale === 0) return total.toString()
  const negative = total < 0n
  const digits = (negative ? -total : total).toString().padStart(scale + 1, '0')
  const text = `${digits.slice(0, -scale)}.${digits.slice(-scale)}`.replace(/0+$/, '').replace(/\.$/, '')
  return `${negative ? '-' : ''}${text}`
}

/**
 * CF-05: the total of each number and money line field, from the table's
 * values: a decimal string, or { amount_minor, currency } when every line
 * has the same currency (null when they differ). Money is summed in minor
 * units with BigInt, never as floats.
 */
export function lineTotals(fields, lines) {
  const totals = {}
  for (const field of fields) {
    if (field.type === 'number') {
      totals[field.key] = addDecimals(lines.map((line) => line.values[field.key]).filter((value) => value !== '' && value != null))
    } else if (field.type === 'money') {
      const values = lines.map((line) => line.values[field.key]).filter((value) => value && value.amount_minor !== '' && value.amount_minor != null && value.currency)
      const currencies = [...new Set(values.map((value) => value.currency))]
      totals[field.key] =
        currencies.length > 1 ? null : { amount_minor: values.reduce((sum, value) => sum + BigInt(value.amount_minor), 0n).toString(), currency: currencies[0] ?? null }
    }
  }
  return totals
}
