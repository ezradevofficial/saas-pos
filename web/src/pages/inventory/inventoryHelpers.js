// Helpers the Inventory screens share that are not components: form
// errors, typed serials, unit options, list filter fields and the lines of
// transfers and adjustments as the API takes them.
import { createElement } from 'react'
import { useTranslation } from 'react-i18next'
import { TextField } from '@/components/ds'
import { decimalsOf } from '@/lib/money'
import { useUoms } from '@/pages/catalogue/catalogueData'
import { negate, statusLabel } from './inventoryData'

/** The message of a field from a 422, else undefined. */
export const fieldError = (error, field) => {
  const messages = error?.status === 422 ? error.errors?.[field] : null
  return messages ? (Array.isArray(messages) ? messages[0] : String(messages)) : undefined
}

/** Serial numbers typed in one box, separated by commas, spaces or new lines. */
export const parseSerials = (text) =>
  String(text ?? '')
    .split(/[\s,;]+/)
    .map((serial) => serial.trim())
    .filter(Boolean)

/**
 * INV-03: the units a quantity may be typed in: the item's base unit
 * first, then its other units (core item units). `baseUom` (from the
 * item's stock detail) names the base unit when the unit list cannot.
 */
export function useUnitOptions(item, baseUom) {
  const { t } = useTranslation()
  const uoms = useUoms()
  if (!item) return []
  const baseCode = baseUom?.code ?? uoms.all.find((uom) => uom.id === item.base_uom_id)?.code
  return [
    { value: item.base_uom_id ?? baseUom?.id ?? '', label: baseCode ? t('inventory.baseUnit', { unit: baseCode.toUpperCase() }) : t('inventory.baseUnitUnknown') },
    ...(item.uoms ?? []).filter((uom) => uom.uom_id !== item.base_uom_id).map((uom) => ({ value: uom.uom_id, label: uom.code || uom.uom_id })),
  ].filter((option) => option.value)
}

/** A date filter for a list's drawer (`from`, `to`: calendar days); the chip shows the date as typed. */
export const dateField = (name, label) => ({
  name,
  label,
  render: ({ value, onChange }) => createElement(TextField, { type: 'date', label, value, onChange: (event) => onChange(event.target.value), className: 'w-full' }),
  valueLabel: (value) => value,
})

/** A document list's status filter: every status of the document, then "all". */
export function statusField(t, statuses) {
  return {
    name: 'status',
    label: t('inventory.filters.status'),
    options: [{ value: '', label: t('inventory.filters.all.status') }, ...statuses.map((status) => ({ value: status, label: statusLabel(t, status) }))],
  }
}

/** Minor units with up to 4 decimals ("1250.5") as major units ("12.505") for a currency. */
export function minorToMajor(minor, currency) {
  if (minor === null || minor === undefined) return null
  const places = decimalsOf(currency)
  const text = String(minor)
  const negative = text.startsWith('-')
  const [whole, fraction = ''] = text.replace(/^-/, '').split('.')
  const digits = whole.padStart(places + 1, '0')
  const major = places ? `${digits.slice(0, -places)}.${digits.slice(-places)}${fraction}` : `${digits}${fraction ? `.${fraction}` : ''}`
  return `${negative ? '-' : ''}${major}`
}

let nextKey = 0
/** A blank line of a stock document (a key keeps React's rows apart). */
export const blankLine = () => ({ key: `line-${(nextKey += 1)}`, item: null, uom_id: '', qty: '', direction: 'out', batch_id: '', batch_number: '', expires_on: '', unit_cost_minor: '', serials: '' })

/** Whether a line is filled enough to send. */
export const lineReady = (line) => Boolean(line.item && line.qty)

/**
 * INV-08: a transfer line as the API takes it ({item_id, uom_id?, qty,
 * batch_id?, serials?}). INV-10: an adjustment line, signed by its
 * direction, with batch number, expiry and unit cost for increases (minor
 * units in `currency`, the company's base currency, ADR 003).
 */
export function lineBody(line, mode, currency = null) {
  const serials = parseSerials(line.serials)
  const body = { item_id: line.item.id, ...(line.uom_id ? { uom_id: line.uom_id } : {}), qty: line.qty, ...(serials.length ? { serials } : {}) }
  if (mode === 'transfer') return { ...body, ...(line.batch_id ? { batch_id: line.batch_id } : {}) }
  const increase = line.direction === 'in'
  return {
    ...body,
    qty: increase ? line.qty : negate(line.qty),
    ...(!increase && line.batch_id ? { batch_id: line.batch_id } : {}),
    ...(increase && line.batch_number.trim() ? { batch_number: line.batch_number.trim() } : {}),
    ...(increase && line.expires_on ? { expires_on: line.expires_on } : {}),
    ...(increase && line.unit_cost_minor !== '' && line.unit_cost_minor !== null && line.unit_cost_minor !== undefined ? { unit_cost_minor: line.unit_cost_minor, unit_cost_currency: currency } : {}),
  }
}

/** Field names of every line, so `formErrors` puts the API's messages under their fields. */
export const lineFieldNames = (count) =>
  Array.from({ length: count }, (_, index) => ['item_id', 'uom_id', 'qty', 'batch_id', 'batch_number', 'expires_on', 'unit_cost_minor', 'unit_cost_currency', 'serials'].map((name) => `lines.${index}.${name}`)).flat()
