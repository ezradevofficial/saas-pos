// The Van sales back office (docs/modules/van-sales.md): permissions, the
// words and tones of statuses, query keys and the small lists the pages need.
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'

/** VAN-26: who may open each page (the API checks again at the van's location). */
export const VAN_VIEW = 'van_sales.van.view'
export const VAN_MANAGE = 'van_sales.van.manage'
export const ROUTE_VIEW = 'van_sales.route.view'
export const ROUTE_MANAGE = 'van_sales.route.manage'
export const LOAD_VIEW = 'van_sales.load.view'
export const LOAD_CREATE = 'van_sales.load.create'
export const LOAD_RELEASE = 'van_sales.load.release'
export const RUN_VIEW = 'van_sales.run.view'
export const VISIT_TRACK = 'van_sales.visit.track'
export const COLLECTION_VIEW = 'van_sales.collection.view'
export const COLLECTION_VOID = 'van_sales.collection.void'
export const CREDIT_VIEW = 'van_sales.credit.view'
export const CREDIT_REVIEW = 'van_sales.credit.review'
export const RECONCILIATION_VIEW = 'van_sales.reconciliation.view'
export const UNLOAD_RECEIVE = 'van_sales.unload.receive'
export const SETTINGS_EDIT = 'van_sales.settings.edit'
export const REPORT_VIEW = 'van_sales.report.view'

export const vanSalesKey = ['van-sales']

/** VAN-05 */
export const STOP_STATUSES = ['pending', 'visited', 'sold', 'no_sale', 'skipped']
/** VAN-07 */
export const LOAD_STATUSES = ['awaiting_approval', 'approved', 'released', 'confirmed', 'rejected', 'cancelled']
/** VAN-06 */
export const RUN_STATUSES = ['open', 'counting', 'closed']
/** VAN-24 */
export const GPS_MODES = ['off', 'optional']
/** ISO weekdays, Monday first (VAN-03). */
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7]

/** Status as a dot and a word. */
export const STATUS_TONES = {
  pending: 'neutral',
  visited: 'info',
  sold: 'success',
  no_sale: 'warning',
  skipped: 'neutral',
  awaiting_approval: 'warning',
  approved: 'info',
  released: 'info',
  confirmed: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
  open: 'info',
  counting: 'warning',
  closed: 'neutral',
  balanced: 'success',
  reviewed: 'success',
  voided: 'neutral',
  archived: 'neutral',
}

export const statusLabel = (t, status) => t(`vansales.statuses.${status}`, { defaultValue: status })

/** Whether a digit string or a decimal quantity is anything but zero. */
export const isNonZero = (value) => /[1-9]/.test(String(value ?? '0'))

/** A warning code of a sale or collection, in words. */
export const flagLabel = (t, flag) => t(`vansales.flags.${flag}`, { defaultValue: flag })

const ERROR_CODES = ['van_unknown', 'route_unknown', 'run_unknown', 'run_closed', 'customer_unknown', 'rep_unknown', 'location_unknown', 'load_not_open', 'load_not_released', 'load_confirmed', 'count_open', 'count_not_pending', 'unload_not_ready', 'inventory_required']

/** Words for the codes the API refuses with (docs/modules/van-sales.md, Errors). */
export function vanError(t, error) {
  return errorMessage(error, Object.fromEntries(ERROR_CODES.map((code) => [code, t(`vansales.errors.${code}`)])))
}

/** Active users, for the rep pickers. */
export function useUsers() {
  const query = useQuery({ queryKey: ['users', 'options', 'active'], queryFn: () => api.get('users?status=active&per_page=200'), staleTime: 60_000, retry: false })
  return query.data?.data ?? []
}

/** The vans (whole list). */
export function useVans() {
  const query = useQuery({ queryKey: [...vanSalesKey, 'vans'], queryFn: () => api.get('van-sales/vans'), staleTime: 30_000 })
  const vans = query.data?.data ?? []
  return { ...query, vans, active: vans.filter((van) => !van.archived_at) }
}

/** The routes (whole list). */
export function useRoutes() {
  const query = useQuery({ queryKey: [...vanSalesKey, 'routes'], queryFn: () => api.get('van-sales/routes'), staleTime: 30_000 })
  const routes = query.data?.data ?? []
  return { ...query, routes, active: routes.filter((route) => !route.archived_at) }
}

/** The devices paired at a location, for the van's device picker. */
export function useDevices(locationId) {
  const query = useQuery({ queryKey: ['devices', locationId], queryFn: () => api.get(`locations/${locationId}/devices?per_page=200`), enabled: Boolean(locationId), staleTime: 30_000, retry: false })
  return (query.data?.data ?? []).filter((device) => !device.archived_at)
}

/**
 * A paginated list: `filters` become query parameters and the page resets
 * when they change. Returns the query, the rows, the page and its setter.
 */
export function usePagedList(name, path, filters = {}, perPage = 25) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) if (value) params.set(key, value)
  const signature = params.toString()
  const [state, setState] = useState({ signature, page: 1 })
  const page = state.signature === signature ? state.page : 1
  params.set('per_page', String(perPage))
  params.set('page', String(page))
  const query = useQuery({ queryKey: [...vanSalesKey, name, params.toString()], queryFn: () => api.get(`${path}?${params.toString()}`), placeholderData: (previous) => previous })
  return { query, rows: query.data?.data ?? [], meta: query.data?.meta ?? null, page, setPage: (next) => setState({ signature, page: next }) }
}

/** A function from a list of ISO weekdays to words ("Mon, Wed"). */
export function useDayNames() {
  const { t } = useTranslation()
  return (days) => (days ?? []).map((day) => t(`vansales.days.${day}`)).join(', ')
}
