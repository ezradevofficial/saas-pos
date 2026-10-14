// The Procurement back office (docs/modules/procurement.md): permissions,
// statuses and their tones, and the suppliers, tax codes, dimensions,
// currencies and users its forms offer. Places come from the core lists
// (companies, branches, locations), as in Inventory.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { localDateTimeIn, zonedToUtc } from '@/lib/dates'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { formatDecimal } from '@/lib/money'

/** RBAC-09: who may open each page (any one is enough; the API checks again at each branch or location, PRC-22). */
export const REQUISITION_VIEW = 'procurement.requisition.view'
export const REQUISITION_CREATE = 'procurement.requisition.create'
export const RFQ_VIEW = 'procurement.rfq.view'
export const RFQ_CREATE = 'procurement.rfq.create'
export const TENDER_VIEW = 'procurement.tender.view'
export const TENDER_CREATE = 'procurement.tender.create'
export const TENDER_AWARD = 'procurement.tender.award'
export const PO_VIEW = 'procurement.po.view'
export const PO_CREATE = 'procurement.po.create'
export const RECEIPT_VIEW = 'procurement.receipt.view'
export const RECEIPT_CREATE = 'procurement.receipt.create'
export const INVOICE_VIEW = 'procurement.invoice.view'
export const INVOICE_CREATE = 'procurement.invoice.create'
export const LANDED_COST_VIEW = 'procurement.landed_cost.view'
export const LANDED_COST_CREATE = 'procurement.landed_cost.create'
export const PERFORMANCE_VIEW = 'procurement.performance.view'
export const SETTINGS_EDIT = 'procurement.settings.edit'
/** PRC-01: the tolerances are read by PO and invoice readers, edited with settings.edit. */
export const SETTINGS_VIEW = ['procurement.po.view', 'procurement.invoice.view', 'procurement.settings.edit']

/** The statuses each document may have (the API's model STATUSES). */
export const STATUSES = {
  requisition: ['draft', 'pending', 'approved', 'rejected', 'cancelled', 'ordered'],
  rfq: ['draft', 'issued', 'closed', 'cancelled'],
  tender: ['draft', 'published', 'closed', 'opened', 'evaluated', 'awarded', 'cancelled'],
  order: ['draft', 'pending', 'approved', 'partially_received', 'received', 'closed', 'rejected', 'cancelled'],
  receipt: ['draft', 'posted', 'cancelled'],
  invoice: ['draft', 'matched', 'exception', 'approved', 'cancelled'],
  landedCost: ['draft', 'posted', 'cancelled'],
}

/** PRC-15: a supplier invoice line's three-way match result (InvoiceLine). */
export const MATCH_STATUSES = ['matched', 'price_variance', 'qty_exceeds_received', 'not_received']
/** PRC-17: how a landed cost is split (LandedCost::METHODS). */
export const LANDED_COST_METHODS = ['value', 'quantity', 'weight']
/** PRC-06: who may bid on a tender. */
export const TENDER_KINDS = ['open', 'invited']
/** PRC-09: a bid's own status (Bid) and, after the award, its outcome. */
export const BID_STATUSES = ['submitted', 'withdrawn', 'opened', 'tampered']
export const BID_OUTCOMES = ['awarded', 'regretted', 'disqualified']
/** PRC-04: a quote's status (Quote). */
export const QUOTE_STATUSES = ['received', 'awarded', 'not_awarded']

/** Status as a dot and a word: waiting is amber, under way is teal, done is green, refused is red, closed is grey. */
export const STATUS_TONES = {
  draft: 'neutral',
  pending: 'warning',
  approved: 'info',
  issued: 'info',
  published: 'info',
  closed: 'neutral',
  opened: 'info',
  evaluated: 'info',
  awarded: 'success',
  ordered: 'success',
  partially_received: 'warning',
  received: 'success',
  posted: 'success',
  matched: 'success',
  exception: 'danger',
  rejected: 'danger',
  cancelled: 'neutral',
  submitted: 'info',
  withdrawn: 'neutral',
  tampered: 'danger',
  regretted: 'neutral',
  disqualified: 'danger',
  not_awarded: 'neutral',
  price_variance: 'warning',
  qty_exceeds_received: 'danger',
  not_received: 'danger',
}

/** A status in words (an unknown one shows as itself). */
export const statusLabel = (t, status) => t(`procurement.statuses.${status}`, { defaultValue: status })

/** Query keys of the module, so a change refreshes its lists and details. */
export const procurementKey = ['procurement']

/**
 * The branches the user sees with their company (`company_id`), and the
 * company records (base currency, time zone), for pickers and filters.
 */
export function usePlaces() {
  const list = (resource) => ({ queryKey: [resource, 'procurement-places'], queryFn: () => api.get(`${resource}?status=all&per_page=200`), staleTime: 60_000 })
  const companies = useQuery(list('companies'))
  const branches = useQuery(list('branches'))
  const locations = useQuery(list('locations'))
  const allBranches = branches.data?.data ?? []
  const allLocations = locations.data?.data ?? []
  return {
    companies: companies.data?.data ?? [],
    branches: allBranches,
    activeBranches: allBranches.filter((branch) => !branch.archived_at),
    locations: allLocations,
    activeLocations: allLocations.filter((location) => !location.archived_at),
    loading: branches.isPending,
  }
}

/** The company id of a branch, or null. */
export const companyOfBranch = (places, branchId) => places.branches.find((branch) => branch.id === branchId)?.company_id ?? null

/** A company record ({base_currency, timezone}) by id, or null. */
export const companyById = (places, companyId) => places.companies.find((company) => company.id === companyId) ?? null

/** The active locations of a branch (or of every branch of a company with `companyId`). */
export function locationsOf(places, { branchId, companyId }) {
  return places.activeLocations.filter((location) => {
    const branch = location.branch_id ?? location.branch?.id
    if (branchId) return branch === branchId
    return places.branches.find((entry) => entry.id === branch)?.company_id === companyId
  })
}

/** Place filters for a ListView: company, branch and location, as far as the user sees them. */
export function placeFilterFields(t, places, names = ['company', 'branch']) {
  const records = { company: places.companies, branch: places.branches, location: places.locations }
  return names
    .filter((name) => records[name].length)
    .map((name) => ({
      name,
      label: t(`procurement.filters.${name}`),
      options: [{ value: '', label: t(`procurement.filters.all.${name}`) }, ...records[name].map((record) => ({ value: record.id, label: record.name }))],
    }))
}

/** MD-01: suppliers (core parties with the supplier role), active first. */
export function useSuppliers() {
  const query = useQuery({ queryKey: ['parties', 'supplier', 'procurement'], queryFn: () => api.get('parties?role=supplier&per_page=200'), staleTime: 60_000, retry: false })
  const all = query.data?.data ?? []
  return { ...query, all, active: all.filter((party) => !party.archived_at) }
}

/** MD-03: a company's tax codes (a code without a rate in force shows "Rate needed"). */
export function useTaxCodes(companyId) {
  const query = useQuery({
    queryKey: ['tax-codes', 'procurement', companyId],
    queryFn: () => api.get(`companies/${companyId}/tax-codes?per_page=200`),
    enabled: Boolean(companyId),
    staleTime: 60_000,
    retry: false,
  })
  return { ...query, active: (query.data?.data ?? []).filter((code) => !code.archived_at) }
}

/** MD-05: a company's departments, cost centres or projects (`path`: departments, cost-centres, projects). */
export function useDimension(companyId, path) {
  const query = useQuery({
    queryKey: ['dimensions', path, companyId],
    queryFn: () => api.get(`companies/${companyId}/${path}?per_page=200`),
    enabled: Boolean(companyId),
    staleTime: 60_000,
    retry: false,
  })
  return (query.data?.data ?? []).filter((record) => !record.archived_at)
}

/** The tenant users (tender committees); empty for a reader who may not list users. */
export function useUsers(enabled = true) {
  const query = useQuery({ queryKey: ['users', 'procurement-committee'], queryFn: () => api.get('users?status=active&per_page=200'), enabled, staleTime: 60_000, retry: false })
  return query.data?.data ?? []
}

/** PRC-16: the currencies a document may be in (the tenant's active ones) and the company's base as the default. */
export function useCurrencies(companyId) {
  const { currency, options } = useMoneyDefaults(companyId ?? undefined)
  return { base: currency, options: options.map((code) => ({ value: code, label: code })) }
}

/** Options of named records ({id, name}) for a picker. */
export const namedOptions = (records) => records.map((record) => ({ value: record.id, label: record.name }))

/** PRC-02 → PRC-03, PRC-06, PRC-13: the approved requisition named by `?requisition=` (a new RFQ, tender or PO starts from it). */
export function useSourceRequisition(requisitionId) {
  const query = useQuery({
    queryKey: [...procurementKey, 'requisition', requisitionId],
    queryFn: () => api.get(`procurement/requisitions/${requisitionId}`),
    enabled: Boolean(requisitionId),
  })
  return { ...query, requisition: query.data?.data ?? null, waiting: Boolean(requisitionId) && query.isPending }
}

/** An instant (ISO) as a datetime-local value in a time zone, or "". */
export const toLocalInput = (iso, timeZone) => (iso ? localDateTimeIn(timeZone, new Date(iso)) : '')

/** A datetime-local value typed in a time zone (the company's) as a UTC ISO string for the API, or null. */
export const fromLocalInput = (local, timeZone) => (local ? zonedToUtc(local, timeZone) : null)

/** Basis points (PRC-11, PRC-18: 10000 = 100 %) as a percentage with two decimals ("87.65 %"), or a dash. */
export function basisPoints(bp, locale) {
  if (bp === null || bp === undefined) return '—'
  const value = Number(bp)
  const negative = value < 0
  const absolute = Math.abs(value)
  const text = `${negative ? '-' : ''}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`
  return `${formatDecimal(text, locale, { minDecimals: 2 })} %`
}
