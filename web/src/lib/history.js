// MD-07: reading audit entries for the history panel: action labels,
// the fields an entry changed, and values for display.
import { formatInteger, formatMoney, formatWhen } from './format'

// Bookkeeping columns: never shown as a changed field (archive and restore have their own action label).
const SKIP = new Set(['id', 'tenant_id', 'created_at', 'updated_at', 'archived_at'])

// Action verbs (the last part of `core.item.update`) with their own label; any other reads "Changed".
const ACTIONS = new Set([
  'create',
  'update',
  'archive',
  'restore',
  'delete',
  'image_add',
  'image_delete',
  'images_reorder',
  'units_update',
  'barcodes_update',
  'permissions_update',
  'codes_update',
  'currencies_update',
  'secrets_change',
  'apply_pack',
  'pack_update',
  'deactivate',
  'reactivate',
  'invite',
  'invitation_accept',
  'invitation_revoke',
  'sign_out_everywhere',
  'pair',
])

// Field names with a translated label (history.fields.*); others are shown humanised.
export const FIELDS = new Set([
  'name',
  'code',
  'type',
  'kind',
  'category_id',
  'base_uom_id',
  'tax_category_id',
  'company_id',
  'branch_id',
  'parent_id',
  'legal_name',
  'tax_id',
  'roles',
  'tags',
  'phones',
  'emails',
  'addresses',
  'currency',
  'payment_terms_days',
  'credit_limit_minor',
  'credit_limit_currency',
  'price_list_id',
  'uoms',
  'barcodes',
  'images',
  'colour',
  'email',
  'phone',
  'status',
  'locale',
  'country',
  'timezone',
  'base_currency',
  'permissions',
  'active',
  'description',
  'address',
  'owner_user_id',
  'custom',
])

/** "core.item.units_update" → its label; unknown actions fall back to "Changed". */
export function actionLabel(t, action) {
  const verb = String(action ?? '').split('.').pop()
  return ACTIONS.has(verb) ? t(`history.actions.${verb}`) : t('history.actions.changed')
}

export const humanise = (key) => {
  const text = String(key).replace(/_id$/, '').replace(/_/g, ' ').trim()
  return text.charAt(0).toUpperCase() + text.slice(1)
}

const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null)

/** The fields an entry changed: union of before and after, unchanged ones and bookkeeping left out. */
export function changedFields(before, after) {
  const keys = [...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})])].filter((key) => !SKIP.has(key))
  const empty = (value) =>
    value === null || value === undefined || value === '' || (typeof value === 'object' && Object.keys(value).length === 0)
  // A new record lists only the values it was given.
  const changed = keys.filter((key) => (!before ? !empty(after?.[key]) : !after || !same(before[key], after[key])))
  // A money amount and its currency read as one value ("KES 12,450.00").
  return changed.filter((key) => !(key.endsWith('_currency') && changed.includes(key.replace(/_currency$/, '_minor'))))
}

/**
 * One value for display: a page's own `format`, else booleans, money in
 * minor units (with the snapshot's currency), dates (in `timeZone`),
 * lists and records; anything unknown as text.
 */
export function formatHistoryValue(key, value, snapshot, { t, locale, timeZone, fields = {} }) {
  if (fields[key]?.format) return fields[key].format(value, snapshot)
  if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) return t('history.none')
  if (typeof value === 'boolean') return value ? t('history.yes') : t('history.no')
  if (key.endsWith('_minor')) {
    const currency = snapshot?.[key.replace(/_minor$/, '_currency')] ?? snapshot?.currency
    return currency ? formatMoney(value, currency, locale) : formatInteger(value, locale)
  }
  if (typeof value === 'number') return Number.isInteger(value) ? formatInteger(value, locale) : String(value)
  if (typeof value === 'string') return formatWhen(value, locale, timeZone) ?? value
  const describe = (entry) =>
    entry !== null && typeof entry === 'object'
      ? Object.values(entry)
          .filter((part) => part !== null && part !== '' && typeof part !== 'object')
          .map((part) => (typeof part === 'boolean' ? (part ? t('history.yes') : t('history.no')) : String(part)))
          .join(' · ')
      : String(entry)
  return Array.isArray(value) ? value.map(describe).join(', ') : describe(value)
}
