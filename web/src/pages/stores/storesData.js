// The Stores back office (docs/modules/stores.md): permissions, statuses
// and their tones, movement kinds, return reasons, report groupings, and
// the stores, departments and cost centres its pickers and filters offer.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'

/** RBAC-09: who may open each page (any one is enough; the API checks again at each store). */
export const REQUISITION_VIEW = 'stores.requisition.view'
export const REQUISITION_CREATE = 'stores.requisition.create'
export const ISSUE_VIEW = 'stores.issue.view'
export const RETURN_VIEW = 'stores.return.view'
export const LEDGER_VIEW = 'stores.ledger.view'
export const STOCK_ADJUST = 'stores.stock.adjust'
export const REPORT_VIEW = 'stores.report.view'

export const REQUISITION_STATUSES = ['pending', 'approved', 'partially_issued', 'issued', 'closed', 'rejected', 'cancelled']
export const ISSUE_STATUSES = ['issued', 'acknowledged']

/** Status as a dot and a word: waiting is amber, in progress is blue, done is green, refused is red, closed is grey. */
export const STATUS_TONES = {
  pending: 'warning',
  approved: 'info',
  partially_issued: 'info',
  issued: 'success',
  acknowledged: 'success',
  closed: 'neutral',
  rejected: 'danger',
  cancelled: 'neutral',
}

/** STR-07: movement kinds (Movement::KINDS). */
export const MOVEMENT_KINDS = ['opening', 'receipt', 'adjustment_in', 'adjustment_out', 'issue', 'return']

/** STR-06: why goods come back (StoreReturn::REASONS). */
export const RETURN_REASONS = ['unused', 'excess', 'wrong_item', 'damaged', 'other']

/** STR-07: why a store's own stock is adjusted (StockEntries::REASONS). */
export const ENTRY_REASONS = ['count', 'damage', 'expiry', 'found', 'correction', 'other']

/** STR-12: what a consumption report groups by. */
export const GROUPS = ['department', 'cost_centre', 'item', 'period']

/** The location types that are stores (STR-02). */
export const STORE_TYPES = ['store', 'warehouse']

export const statusLabel = (t, status) => t(`stores.statuses.${status}`, { defaultValue: status })
export const kindLabel = (t, kind) => t(`stores.kinds.${kind}`, { defaultValue: kind })

/** Whether a decimal string is above zero (no floats). */
export const isPositive = (value) => /^\d+(\.\d+)?$/.test(String(value ?? '')) && /[1-9]/.test(String(value))

/**
 * The companies, branches and stores the user sees (RBAC-04): stores are
 * locations of a store type; archived ones stay in filters and are left
 * out of pickers (TEN-06).
 */
export function useStorePlaces() {
  const list = (resource) => ({ queryKey: [resource, 'stores-places'], queryFn: () => api.get(`${resource}?status=all&per_page=200`), staleTime: 60_000 })
  const companies = useQuery(list('companies'))
  const branches = useQuery(list('branches'))
  const locations = useQuery(list('locations'))
  const stores = (locations.data?.data ?? []).filter((location) => STORE_TYPES.includes(location.type))
  return {
    companies: companies.data?.data ?? [],
    branches: branches.data?.data ?? [],
    stores,
    activeStores: stores.filter((store) => !store.archived_at),
  }
}

/** The company a store belongs to (through its branch), or null. */
export function storeCompany({ branches }, store) {
  return branches.find((branch) => branch.id === (store?.branch_id ?? store?.branch?.id))?.company_id ?? null
}

/** MD-05: a company's active departments or cost centres (`kind`: departments | cost-centres). */
export function useDimensions(companyId, kind) {
  const query = useQuery({
    queryKey: ['dimensions', kind, 'stores', companyId],
    queryFn: () => api.get(`companies/${companyId}/${kind}?per_page=200`),
    enabled: Boolean(companyId),
    staleTime: 60_000,
    retry: false,
  })
  return (query.data?.data ?? []).filter((row) => !row.archived_at)
}

/** STR-07, STR-08: which ledger holds the stores' quantities now (Inventory when it is active). */
export function useStoresStatus() {
  const query = useQuery({ queryKey: ['stores', 'status'], queryFn: () => api.get('stores/status'), staleTime: 60_000, retry: false })
  return query.data?.data ?? null
}

/** "Main store · Westlands": a store with its branch, for pickers. */
export const storeLabel = (store) => (store.branch?.name ? `${store.name} · ${store.branch.name}` : store.name)

export const storeOptions = (stores) => stores.map((store) => ({ value: store.id, label: storeLabel(store) }))

/** A list's store filter (the stores the user sees). */
export function storeFilter(t, stores) {
  return stores.length ? [{ name: 'location', label: t('stores.filters.store'), options: [{ value: '', label: t('stores.filters.allStores') }, ...stores.map((store) => ({ value: store.id, label: store.name }))] }] : []
}

/** A document list's status filter. */
export function statusFilter(t, statuses) {
  return { name: 'status', label: t('stores.filters.status'), options: [{ value: '', label: t('stores.filters.allStatuses') }, ...statuses.map((status) => ({ value: status, label: statusLabel(t, status) }))] }
}

/** Query keys of the module's lists, so a change refreshes them. */
export const storesKey = ['stores']
