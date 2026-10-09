// Custom fields (CF-01, CF-02, CF-03, RBAC-05): the schema of an entity's
// fields for the signed-in user, and the conversions between the API's
// value shapes and what a form control holds. A field's label, help and
// option labels are the tenant's own words, stored once and shown as typed.
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '@/api/client'
import { formatCalendarDate, formatDateTime, localDateTimeIn, zonedToUtc } from './dates'
import { formatDecimal } from './money'

export const CUSTOM_FIELD_TYPES = ['text', 'long_text', 'number', 'money', 'date', 'datetime', 'boolean', 'select', 'multi_select', 'file', 'lookup', 'formula']
export const FORMULA_TYPES = ['number', 'text', 'boolean']
/** Types a list can sort by (`sort=cf_<key>`): one comparable value per record. */
export const SORTABLE_TYPES = ['text', 'number', 'money', 'date', 'datetime', 'boolean', 'select']
/** Types that may be unique, and that take min/max (value bounds or length bounds). */
export const UNIQUE_TYPES = ['text', 'number', 'date']
export const BOUNDED_TYPES = ['number', 'text', 'long_text']
export const OPTION_TYPES = ['select', 'multi_select']
/** Types a definition may give a default value to. */
export const DEFAULTABLE_TYPES = ['text', 'long_text', 'number', 'money', 'date', 'datetime', 'boolean', 'select', 'multi_select']

// CF-01: what the API accepts for a custom file.
export const CUSTOM_FILE_TYPES = [
  'application/pdf',
  'image/jpeg',
  'image/png',
  'image/webp',
  'text/plain',
  'text/csv',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
]
export const CUSTOM_FILE_EXTENSIONS = ['.pdf', '.jpg', '.jpeg', '.png', '.webp', '.txt', '.csv', '.docx', '.xlsx']
export const MAX_CUSTOM_FILE_BYTES = 10 * 1024 * 1024

/** The fields of `entity` the user sees, in order (`GET custom-fields/schema`); none while loading or when refused. */
export function useCustomFieldSchema(entity, { enabled = true } = {}) {
  const query = useQuery({
    queryKey: ['custom-fields', 'schema', entity],
    queryFn: () => api.get(`custom-fields/schema?entity=${encodeURIComponent(entity)}`),
    enabled: Boolean(entity) && enabled,
    staleTime: 60_000,
  })
  const fields = Array.isArray(query.data?.data) ? query.data.data : []
  return { ...query, fields }
}

/** Entities, types, lookup targets and the formula language (`GET custom-fields/meta`). */
export function useCustomFieldMeta() {
  const query = useQuery({ queryKey: ['custom-fields', 'meta'], queryFn: () => api.get('custom-fields/meta'), staleTime: 300_000 })
  const data = query.data?.data ?? {}
  return {
    ...query,
    entities: data.entities ?? [],
    types: data.types?.length ? data.types : CUSTOM_FIELD_TYPES,
    lookupTargets: data.lookup_targets ?? [],
    functions: data.formula?.functions ?? [],
    operators: data.formula?.operators ?? [],
  }
}

/** A field label typed by a person as a key: "Shelf life (days)" → "shelf_life_days". */
export function slugify(text, max = 40) {
  const slug = String(text ?? '')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^[^a-z]+/, '')
    .slice(0, max)
    .replace(/_+$/, '')
  return slug
}

/**
 * The API's value (as read) to what the form control holds:
 * text, number, date, select → string ('' for none); money → { amount_minor, currency };
 * datetime → a datetime-local string in `timeZone`; boolean → boolean;
 * multi_select → array; file, lookup → the object or null; formula → as read.
 */
export function toFormValue(field, value, timeZone) {
  switch (field.type) {
    case 'money':
      return value && typeof value === 'object' ? { amount_minor: value.amount_minor == null ? '' : String(value.amount_minor), currency: value.currency ?? '' } : { amount_minor: '', currency: '' }
    case 'datetime':
      return value ? localDateTimeIn(timeZone, new Date(value)) : ''
    case 'boolean':
      return value === true
    case 'multi_select':
      return Array.isArray(value) ? value.map(String) : []
    case 'file':
    case 'lookup':
      return value && typeof value === 'object' ? value : null
    case 'formula':
      return value ?? null
    default:
      return value == null ? '' : String(value)
  }
}

/**
 * What the form control holds to the API's write shape (null clears).
 * `undefined` means the value cannot be sent (a number not yet valid).
 */
export function toApiValue(field, value, timeZone) {
  switch (field.type) {
    case 'text':
    case 'long_text': {
      const text = String(value ?? '').trim()
      return text === '' ? null : text
    }
    case 'number':
      if (value === null) return undefined
      return value === '' || value === undefined ? null : plainDecimal(value)
    case 'money':
      if (!value || value.amount_minor === '' || value.amount_minor === undefined) return null
      if (value.amount_minor === null) return undefined
      return { amount_minor: String(value.amount_minor), currency: value.currency }
    case 'date':
    case 'select':
      return value ? String(value) : null
    case 'datetime':
      return value ? zonedToUtc(value, timeZone) : null
    case 'boolean':
      return Boolean(value)
    case 'multi_select':
      return Array.isArray(value) ? value : []
    case 'file':
    case 'lookup':
      return value?.id ?? null
    default:
      return value ?? null
  }
}

/** A decimal string without trailing fraction zeros ("1.50" → "1.5", "-2.0" → "-2"), so equal numbers compare equal. */
function plainDecimal(value) {
  const text = String(value).trim()
  return text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text
}

const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null)

/**
 * A form's custom values (CF-02): `values[key]` the current value of each
 * field of `fields` (the record's own until edited), `set(key, value)`,
 * `body()` the changed, editable keys in the API's write shape (null when
 * nothing changed: `custom` is then left out), `invalid` true while a
 * changed value cannot be sent, and `errorFields` the API error keys
 * (`custom.<key>`) for formErrors.
 */
export function useCustomValues(fields, record, timeZone) {
  const [edits, setEdits] = useState({})
  const stored = record?.custom ?? {}
  const initial = Object.fromEntries(fields.map((field) => [field.key, toFormValue(field, stored[field.key] ?? null, timeZone)]))
  const values = { ...initial, ...edits }
  const editable = fields.filter((field) => !field.readonly && field.type !== 'formula' && field.key in edits)

  const changes = editable.flatMap((field) => {
    const next = toApiValue(field, edits[field.key], timeZone)
    if (next !== undefined && same(next, toApiValue(field, initial[field.key], timeZone))) return []
    return [[field.key, next]]
  })

  return {
    values,
    set: (key, value) => setEdits((current) => ({ ...current, [key]: value })),
    invalid: changes.some(([, value]) => value === undefined),
    body: () => (changes.length ? Object.fromEntries(changes) : null),
    errorFields: fields.map((field) => `custom.${field.key}`),
  }
}

/** The custom part of formErrors' field messages: `{ key: message }`. */
export function customErrors(fieldMessages) {
  return Object.fromEntries(
    Object.entries(fieldMessages ?? {})
      .filter(([name]) => name.startsWith('custom.'))
      .map(([name, message]) => [name.slice('custom.'.length), message]),
  )
}

/** A formula's result (or any read-only value) in words. */
export function formulaText(field, value, { t, locale }) {
  if (value === null || value === undefined || value === '') return t('customFields.noValue')
  if (field.formula_type === 'boolean') return value === true || value === 'true' ? t('customFields.yes') : t('customFields.no')
  if (field.formula_type === 'number' && /^-?\d+(\.\d+)?$/.test(String(value))) return formatDecimal(String(value), locale)
  return String(value)
}

/** A custom value for reading (lists, read views): words, or a node for money. */
export function customValueText(field, value, { t, locale, timeZone }) {
  if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) return ''
  switch (field.type) {
    case 'number':
      return formatDecimal(String(value), locale)
    case 'date':
      return formatCalendarDate(value, locale) ?? ''
    case 'datetime':
      return formatDateTime(value, locale, timeZone) ?? ''
    case 'boolean':
      return value === true ? t('customFields.yes') : t('customFields.no')
    case 'select':
      return (field.options ?? []).find((option) => String(option.value) === String(value))?.label ?? String(value)
    case 'multi_select':
      return (Array.isArray(value) ? value : [value]).map((entry) => (field.options ?? []).find((option) => String(option.value) === String(entry))?.label ?? String(entry)).join(', ')
    case 'file':
      return value?.name ?? ''
    case 'lookup':
      return value?.label ?? ''
    case 'formula':
      return formulaText(field, value, { t, locale })
    default:
      return String(value)
  }
}
