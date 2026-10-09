// The POS back office (docs/modules/pos.md, POS-12): permissions, the
// words for statuses and flags, and the place lists its filters offer.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'

/** Who may open each page (any one is enough; the API checks again at each location). */
export const SALE_VIEW = 'pos.sale.view'
export const SHIFT_VIEW = 'pos.shift.view'
export const SALE_REVIEW = 'pos.sale.review'
/** H2: who decides held records, per kind (HeldController::PERMISSIONS). */
export const HELD_PERMISSIONS = { void: 'pos.sale.void', refund: 'pos.sale.refund', cash_movement: 'pos.cash.move' }
export const HELD_VIEW = Object.values(HELD_PERMISSIONS)
/** NUM-01 */
export const NUMBERING_VIEW = ['core.numbering.view', 'core.numbering.edit']

export const SALE_STATUSES = ['completed', 'voided']
export const SHIFT_STATUSES = ['open', 'closed']
export const SALE_TONES = { completed: 'success', voided: 'neutral' }
export const SHIFT_TONES = { open: 'info', closed: 'neutral' }
export const RECORD_TONES = { applied: 'success', held: 'warning', rejected: 'neutral' }

/**
 * POS-09: what the server noticed about an uploaded record but kept (the
 * device wins). Codes from SaleUploads, RefundUploads, VoidUploads,
 * CashMovementUploads, Approval and payment settlements (payout_failed,
 * payout_recovered: a refund paid out after it was flagged failed, so the
 * money may have gone out twice); an unknown code shows as itself.
 */
export const FLAG_CODES = [
  'price_differs',
  'price_unknown',
  'list_price_missing',
  'tax_differs',
  'rate_differs',
  'change_rate_differs',
  'refund_rate_differs',
  'base_rate_from_till',
  'discount_unauthorised',
  'price_override_unauthorised',
  'cashier_not_permitted',
  'actor_unverified',
  'override_unverified',
  'override_offline',
  'received_after_close',
  'shift_missing',
  'clock_ahead',
  'payout_failed',
  'payout_recovered',
]

/** Flags meaning a manager's approval came from the till, not the server (AUTH-08). */
export const DEVICE_CLAIM_FLAGS = ['override_unverified', 'override_offline']

/** The distinct flag codes of a record, in the order first seen. */
export const flagCodes = (flags) => [...new Set((flags ?? []).map((flag) => flag.code))]

/** A flag code in words. */
export const flagLabel = (t, code) => t(`pos.flags.${code}`, { defaultValue: code })

/** A payment method type in words (paymentMethods.types). */
export const methodLabel = (t, type) => t(`paymentMethods.types.${type}`, { defaultValue: type })

/**
 * The companies, branches and locations the user sees (RBAC-04), for the
 * lists' place filters. Each list is empty when the user sees none of that
 * level (a cashier sees only their location).
 */
export function usePlaces() {
  const list = (resource) => ({ queryKey: [resource, 'pos-filters'], queryFn: () => api.get(`${resource}?status=all&per_page=200`), staleTime: 60_000 })
  const companies = useQuery(list('companies'))
  const branches = useQuery(list('branches'))
  const locations = useQuery(list('locations'))
  return {
    companies: companies.data?.data ?? [],
    branches: branches.data?.data ?? [],
    locations: locations.data?.data ?? [],
  }
}

/** Place filters for a ListView: company, branch and location, as far as the user sees them. */
export function placeFilterFields(t, { companies, branches, locations }) {
  const field = (name, records, all) =>
    records.length
      ? [{ name, label: t(`pos.filters.${name}`), options: [{ value: '', label: t(all) }, ...records.map((record) => ({ value: record.id, label: record.name }))] }]
      : []
  return [
    ...field('company', companies, 'pos.filters.allCompanies'),
    ...field('branch', branches, 'pos.filters.allBranches'),
    ...field('location', locations, 'pos.filters.allLocations'),
  ]
}
