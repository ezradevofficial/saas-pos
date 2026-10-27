// The Restaurant back office (docs/modules/restaurant.md): permissions, the
// words and tones of statuses, query keys and the small lists every page
// needs (locations, item categories, items, people).
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'

/** REST-19: who may open each page (the API checks again at each location). */
export const FLOOR_VIEW = 'restaurant.floor.view'
export const FLOOR_MANAGE = 'restaurant.floor.manage'
export const STATION_VIEW = 'restaurant.station.view'
export const STATION_MANAGE = 'restaurant.station.manage'
export const MODIFIER_VIEW = 'restaurant.modifier.view'
export const MODIFIER_MANAGE = 'restaurant.modifier.manage'
export const ORDER_VIEW = 'restaurant.order.view'
export const ORDER_EDIT = 'restaurant.order.edit'
export const ORDER_TRANSFER = 'restaurant.order.transfer'
export const ORDER_MERGE = 'restaurant.order.merge'
export const TICKET_VIEW = 'restaurant.ticket.view'
export const TICKET_UPDATE = 'restaurant.ticket.update'
export const SETTINGS_EDIT = 'restaurant.settings.edit'
export const REPORT_VIEW = 'restaurant.report.view'
/** REST-11: voiding a sent line uses core's permission. */
export const SALE_VOID = 'pos.sale.void'

export const restaurantKey = ['restaurant']

/** REST-02: the manual statuses a table is set to, and the shapes it can have. */
export const MANUAL_STATUSES = ['free', 'reserved', 'cleaning', 'blocked']
export const TABLE_SHAPES = ['square', 'round', 'rect']
/** REST-08 */
export const STATION_KINDS = ['kitchen', 'bar', 'other']
/** REST-03, REST-04 */
export const ORDER_KINDS = ['table', 'tab']
export const ORDER_STATUSES = ['open', 'closed', 'cancelled', 'merged']
/** REST-14 */
export const SPLIT_MODES = ['full', 'item', 'seat', 'equal']
/** REST-09 */
export const TICKET_STATUSES = ['new', 'preparing', 'ready', 'served']

/** Status as a dot and a word. */
export const STATUS_TONES = {
  free: 'success',
  reserved: 'info',
  cleaning: 'warning',
  blocked: 'neutral',
  occupied: 'info',
  bill: 'warning',
  open: 'info',
  closed: 'neutral',
  cancelled: 'neutral',
  merged: 'neutral',
  new: 'warning',
  preparing: 'info',
  ready: 'success',
  served: 'neutral',
}

export const statusLabel = (t, status) => t(`restaurant.statuses.${status}`, { defaultValue: status })

/** The scopes a place sits in, nearest first, for a permission check (RBAC-04). */
export const placeChain = (location) => [
  { type: 'location', id: location?.id },
  { type: 'branch', id: location?.branch_id },
  { type: 'company', id: location?.company_id },
]

/** Whether the user holds a permission at `location` (the API checks again). */
export function useCanAt(location) {
  const { canWithin, isLoading } = usePermissions()
  return (permission) => !isLoading && Boolean(location?.id) && canWithin(permission, placeChain(location))
}

/** The locations the user sees, active ones. */
export function useLocations() {
  const query = useQuery({ queryKey: ['locations', 'restaurant'], queryFn: () => api.get('locations?per_page=200'), staleTime: 60_000 })
  return { ...query, locations: (query.data?.data ?? []).filter((entry) => !entry.archived_at) }
}

/** Core item categories, for routing and modifier groups. */
export function useItemCategories() {
  const query = useQuery({ queryKey: ['item-categories', 'restaurant'], queryFn: () => api.get('item-categories?per_page=200&sort=name'), staleTime: 60_000 })
  return query.data?.data ?? []
}

/** Items for pickers. */
export function useItems() {
  const query = useQuery({ queryKey: ['items', 'restaurant'], queryFn: () => api.get('items?per_page=200&sort=name'), staleTime: 60_000 })
  return query.data?.data ?? []
}

/** Active users, for waiters. */
export function useWaiters() {
  const query = useQuery({ queryKey: ['users', 'options', 'active'], queryFn: () => api.get('users?status=active&per_page=200'), staleTime: 60_000, retry: false })
  return query.data?.data ?? []
}

/** Whole minutes from an ISO time to now. */
export const ageMinutes = (value, now = Date.now()) => (value ? Math.max(0, Math.floor((now - Date.parse(value)) / 60000)) : 0)

/** A whole number typed in a field, or null when it is not one. */
export const toInt = (value) => (/^\d+$/.test(String(value ?? '').trim()) ? Number(String(value).trim()) : null)

/** The location in use: the chosen one, else the first. */
export function useLocationChoice() {
  const { locations, isPending, isError, error } = useLocations()
  const [chosen, setChosen] = useState('')
  const location = locations.find((entry) => entry.id === chosen) ?? locations[0] ?? null
  return { location, locations, choose: setChosen, isPending, isError, error }
}

/** The company in use: the chosen one, else the switcher's, else the first. */
export function useCompanyChoice() {
  const { companies } = useCompanies()
  const { company: switcherCompany } = useCompanySelection()
  const [chosen, setChosen] = useState('')
  const active = companies.filter((entry) => !entry.archived_at)
  const company = active.find((entry) => entry.id === chosen) ?? active.find((entry) => entry.id === switcherCompany?.id) ?? active[0] ?? null
  return { company, companies: active, choose: setChosen }
}

/** "5, 10, 15" into [5, 10, 15]; null when any part is not a number from 1 to 100. */
export function parsePresets(text) {
  const parts = String(text ?? '').split(/[,;\s]+/).filter(Boolean)
  const numbers = parts.map((part) => (/^\d{1,3}(\.\d{1,2})?$/.test(part) ? Number(part) : NaN))
  return numbers.every((value) => value > 0 && value <= 100) ? numbers : null
}

/** "Table 4" or "Tab Jane": the name of an order in lists and pickers. */
export function orderName(t, order) {
  return order.kind === 'tab' ? t('restaurant.orders.tabName', { name: order.tab_name || t('restaurant.orders.unnamed') }) : t('restaurant.orders.tableName', { name: order.table_name ?? '' })
}

/** Words for the codes a void, transfer or merge can be refused with (REST-11). */
export function orderError(t, error) {
  return errorMessage(error, {
    void_not_permitted: t('restaurant.orders.errors.voidNotPermitted'),
    void_limit_exceeded: t('restaurant.orders.errors.voidLimitExceeded'),
    override_required: t('restaurant.orders.errors.overrideRequired'),
    order_closed: t('restaurant.orders.errors.orderClosed'),
    waiter_unknown: t('restaurant.orders.errors.waiterUnknown'),
    line_changed: t('restaurant.orders.errors.lineChanged'),
    split_invalid: t('restaurant.orders.errors.splitInvalid'),
  })
}
