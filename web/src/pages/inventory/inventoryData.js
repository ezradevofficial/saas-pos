// The Inventory back office (docs/modules/inventory.md): permissions, the
// words and tones for statuses, movement kinds, flags and reasons, and the
// places, items and price lists its pickers and filters offer.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'

/** RBAC-09: who may open each page (any one is enough; the API checks again at each location). */
export const STOCK_VIEW = 'inventory.stock.view'
export const VALUATION_VIEW = 'inventory.valuation.view'
export const ITEM_CONFIGURE = 'inventory.item.configure'
export const OPENING_CREATE = 'inventory.opening.create'
export const REORDER_VIEW = ['inventory.reorder.view', 'inventory.reorder.edit']
export const REORDER_EDIT = 'inventory.reorder.edit'
export const TRANSFER_VIEW = 'inventory.transfer.view'
export const TRANSFER_CREATE = 'inventory.transfer.create'
export const ADJUSTMENT_VIEW = 'inventory.adjustment.view'
export const ADJUSTMENT_CREATE = 'inventory.adjustment.create'
export const COUNT_VIEW = 'inventory.count.view'
export const COUNT_CREATE = 'inventory.count.create'
export const SETTINGS_VIEW = ['inventory.stock.view', 'inventory.settings.edit']
export const SETTINGS_EDIT = 'inventory.settings.edit'
export const LABEL_PRINT = 'inventory.label.print'

export const TRANSFER_STATUSES = ['pending', 'approved', 'rejected', 'in_transit', 'received', 'cancelled']
export const ADJUSTMENT_STATUSES = ['pending', 'waiting', 'posted', 'rejected', 'cancelled', 'failed']
export const COUNT_STATUSES = ['counting', 'pending', 'posted', 'rejected', 'cancelled']

/** Status as a dot and a word: waiting is amber, done is green, refused or failed is red, closed is grey. */
export const STATUS_TONES = {
  pending: 'warning',
  waiting: 'warning',
  approved: 'info',
  in_transit: 'info',
  counting: 'info',
  received: 'success',
  posted: 'success',
  rejected: 'danger',
  failed: 'danger',
  cancelled: 'neutral',
}

/** INV-04: movement kinds (Movement::KINDS). */
export const MOVEMENT_KINDS = [
  'opening',
  'receipt',
  'adjustment_in',
  'adjustment_out',
  'count_gain',
  'count_loss',
  'transfer_out',
  'transfer_in',
  'transfer_loss',
  'sale',
  'sale_return',
  'sale_void',
  'opening_reversal',
]

/** INV-10: the fixed, translated list of adjustment reasons (Adjustment::REASONS). */
export const ADJUSTMENT_REASONS = ['damage', 'expiry', 'theft', 'found', 'correction', 'internal_use', 'other']

export const TRACKING = ['none', 'batch', 'serial']
export const COSTING_METHODS = ['weighted_average', 'fifo']

/** A status in words (an unknown one shows as itself). */
export const statusLabel = (t, status) => t(`inventory.statuses.${status}`, { defaultValue: status })
/** A movement kind in words. */
export const kindLabel = (t, kind) => t(`inventory.kinds.${kind}`, { defaultValue: kind })
/** A movement flag in words. */
export const flagLabel = (t, code) => t(`inventory.flags.${code}`, { defaultValue: code })

/** Whether a decimal string is above zero (no floats). */
export const isPositive = (value) => /^\d+(\.\d+)?$/.test(String(value ?? '')) && /[1-9]/.test(String(value))

const SCALE = 6
const toMicro = (value) => {
  const text = String(value ?? '0').trim() || '0'
  const negative = text.startsWith('-')
  const [whole, fraction = ''] = text.replace(/^-/, '').split('.')
  const micro = BigInt((whole || '0') + fraction.padEnd(SCALE, '0').slice(0, SCALE))
  return negative ? -micro : micro
}

/** a − b for decimal strings (up to 6 decimals), exact: "12.5" − "14" → "-1.5". */
export function subtractDecimal(a, b) {
  const difference = toMicro(a) - toMicro(b)
  const negative = difference < 0n
  const absolute = negative ? -difference : difference
  const scale = 10n ** BigInt(SCALE)
  const fraction = String(absolute % scale).padStart(SCALE, '0').replace(/0+$/, '')
  return `${negative ? '-' : ''}${absolute / scale}${fraction ? `.${fraction}` : ''}`
}

/** A decimal string with its sign flipped ("2" → "-2"). */
export const negate = (value) => (String(value).startsWith('-') ? String(value).slice(1) : `-${value}`)

/**
 * The companies, branches and locations the user sees (RBAC-04), for the
 * filters and the location pickers; archived ones are kept for filters
 * and left out of pickers (TEN-06).
 */
export function usePlaces() {
  const list = (resource) => ({ queryKey: [resource, 'inventory-places'], queryFn: () => api.get(`${resource}?status=all&per_page=200`), staleTime: 60_000 })
  const companies = useQuery(list('companies'))
  const branches = useQuery(list('branches'))
  const locations = useQuery(list('locations'))
  const all = locations.data?.data ?? []
  return {
    companies: companies.data?.data ?? [],
    branches: branches.data?.data ?? [],
    locations: all,
    activeLocations: all.filter((location) => !location.archived_at),
    loading: locations.isPending,
  }
}

/** Place filters for a ListView: company, branch and location, as far as the user sees them. */
export function placeFilterFields(t, { companies, branches, locations }, names = ['company', 'branch', 'location']) {
  const field = (name, records) =>
    names.includes(name) && records.length
      ? [{ name, label: t(`inventory.filters.${name}`), options: [{ value: '', label: t(`inventory.filters.all.${name}`) }, ...records.map((record) => ({ value: record.id, label: record.name }))] }]
      : []
  return [...field('company', companies), ...field('branch', branches), ...field('location', locations)]
}

/** "Front store · Westlands": a location with its branch, for pickers. */
export const locationLabel = (location) => (location.branch?.name ? `${location.name} · ${location.branch.name}` : location.name)

/** Location picker options (active locations only). */
export const locationOptions = (locations) => locations.map((location) => ({ value: location.id, label: locationLabel(location) }))

/** "Soap 1 kg · SOAP-1": an item for pickers. */
export const itemLabel = (item) => (item ? `${item.name} · ${item.code}` : '')

/** INV-01, INV-06, INV-07: an item's stock detail (tracking, batches, serials), read when an item is picked. */
export function useItemStock(itemId) {
  return useQuery({
    queryKey: ['inventory', 'item', itemId],
    queryFn: () => api.get(`inventory/items/${itemId}`),
    enabled: Boolean(itemId),
    staleTime: 30_000,
  })
}

/** TPL-01, MD-03: a company's price lists, for labels. */
export function usePriceLists(companyId) {
  const query = useQuery({
    queryKey: ['price-lists', 'inventory', companyId],
    queryFn: () => api.get(`companies/${companyId}/price-lists?per_page=200`),
    enabled: Boolean(companyId),
    staleTime: 60_000,
    retry: false,
  })
  return { ...query, all: (query.data?.data ?? []).filter((list) => !list.archived_at) }
}

/** Query keys of the module's lists, so a change refreshes them. */
export const inventoryKey = ['inventory']
