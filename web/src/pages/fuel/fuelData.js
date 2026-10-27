// The Fuel station back office (docs/modules/fuel-station.md): who may open each
// page, the words and tones of states, and the data every page shares.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'

/** RBAC-09: who may open each page and act (any one is enough; the API checks again at each station). */
export const SETTINGS_VIEW = ['fuel.settings.view', 'fuel.settings.edit']
export const SETTINGS_EDIT = 'fuel.settings.edit'
export const SETUP_VIEW = 'fuel.setup.view'
export const SETUP_MANAGE = 'fuel.setup.manage'
export const PRICE_VIEW = 'fuel.price.view'
export const PRICE_MANAGE = 'fuel.price.manage'
export const SALE_VIEW = 'fuel.sale.view'
export const SHIFT_VIEW = 'fuel.shift.view'
export const VARIANCE_VIEW = 'fuel.variance.view'
export const RESET_REQUEST = 'fuel.meter.request_reset'
export const RESET_APPROVE = 'fuel.meter.approve_reset'
export const DIP_VIEW = 'fuel.dip.view'
export const DIP_RECORD = 'fuel.dip.record'
export const DELIVERY_VIEW = 'fuel.delivery.view'
export const DELIVERY_RECORD = 'fuel.delivery.record'
export const VEHICLE_VIEW = 'fuel.vehicle.view'
export const VEHICLE_MANAGE = 'fuel.vehicle.manage'
export const VOUCHER_VIEW = 'fuel.voucher.view'
export const VOUCHER_MANAGE = 'fuel.voucher.manage'
export const REPORT_VIEW = 'fuel.report.view'

export const fuelKey = ['fuel']

/** FUEL-04: the fuel types (Product::TYPES). */
export const FUEL_TYPES = ['petrol', 'diesel', 'kerosene', 'lpg', 'other']
export const SETTLEMENT_STATUSES = ['pending_approval', 'auto_accepted', 'approved', 'rejected']
export const RECONCILIATION_STATUSES = ['baseline', 'accepted', 'pending_approval', 'approved', 'rejected']
export const RESET_STATUSES = ['pending', 'approved', 'rejected']
export const SHIFT_STATUSES = ['open', 'closed']
export const VOUCHER_STATUSES = ['active', 'void']
export const DIP_KINDS = ['routine', 'shift_open', 'shift_close', 'delivery_before', 'delivery_after']
export const REPORTS = ['shift-summary', 'attendant-variance', 'nozzle-meter-history', 'tank-reconciliation', 'sales-by-product', 'fleet-statement']

/** Status as a dot and a word. */
export const STATE_TONES = {
  open: 'info',
  closed: 'neutral',
  pending_approval: 'warning',
  pending: 'warning',
  auto_accepted: 'success',
  accepted: 'success',
  approved: 'success',
  rejected: 'danger',
  baseline: 'neutral',
  active: 'success',
  blocked: 'danger',
  void: 'neutral',
}

/** The message of a field from a 422, else undefined. */
export const fieldError = (error, field) => {
  const messages = error?.status === 422 ? error.errors?.[field] : null
  return messages ? (Array.isArray(messages) ? messages[0] : String(messages)) : undefined
}

/** FUEL-17: a refusal the API words itself (a 403 with a fuel code, such as `own_reset`) shows its own sentence; others use the app's wording. */
export const apiMessage = (error) => (error?.status === 403 && ['own_reset', 'place_forbidden', 'not_permitted'].includes(error.code) && error.message ? error.message : errorMessage(error))

/** The forecourts: a station is a location marked as a forecourt (FUEL-02). */
export function useStations() {
  const query = useQuery({ queryKey: [...fuelKey, 'stations'], queryFn: () => api.get('fuel/stations'), staleTime: 30_000 })
  const all = query.data?.data ?? []
  return { all, stations: all.filter((station) => !station.archived_at), loading: query.isPending }
}

/** Station options for a picker: the value is the station's location id, which every endpoint takes as `location_id`. */
export const stationOptions = (stations) => stations.filter((station) => station.location).map((station) => ({ value: station.location.id, label: station.location.name }))

/** The currency of the station at a location, or '' when not known. */
export const currencyAt = (stations, locationId) => stations.find((station) => station.location?.id === locationId)?.currency ?? ''

/** The fuel products of the tenant's companies. */
export function useProducts() {
  const query = useQuery({ queryKey: [...fuelKey, 'products'], queryFn: () => api.get('fuel/products'), staleTime: 30_000 })
  const all = query.data?.data ?? []
  return { all, products: all.filter((product) => !product.archived_at), loading: query.isPending }
}

/** "Super petrol · V-Power": a product's name with its grade. */
export const productLabel = (product) => (product ? [product.name, product.grade].filter(Boolean).join(' · ') : '')

/** The tanks, all or of one station. */
export function useTanks(locationId) {
  const query = useQuery({
    queryKey: [...fuelKey, 'tanks', locationId ?? 'all'],
    queryFn: () => api.get(`fuel/tanks${locationId ? `?location_id=${locationId}` : ''}`),
    staleTime: 30_000,
  })
  const all = query.data?.data ?? []
  return { all, tanks: all.filter((tank) => !tank.archived_at), loading: query.isPending }
}

/** The flag codes of a record (the API sends objects with a `code`; plain strings are read too). */
export const flagCodes = (flags) => [...new Set((flags ?? []).map((flag) => (typeof flag === 'string' ? flag : flag?.code)).filter(Boolean))]

/** A negative litre or money figure is a loss or a shortage. */
export const isNegative = (value) => String(value ?? '').trim().startsWith('-')
