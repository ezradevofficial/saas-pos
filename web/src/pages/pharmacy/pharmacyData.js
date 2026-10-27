// The Pharmacy back office (docs/modules/pharmacy.md): who may open each
// page, the words and tones of states, and the places the pickers offer.
import { useQuery } from '@tanstack/react-query'
import { createElement, useState } from 'react'
import { api } from '@/api/client'
import { TextField } from '@/components/ds'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'

/** RBAC-09: who may open each page (any one is enough; the API checks again at each location). */
export const LOT_VIEW = 'pharmacy.lot.view'
export const LOT_RECEIVE = 'pharmacy.lot.receive'
export const LOT_TRANSFER = 'pharmacy.lot.transfer'
export const LOT_QUARANTINE = 'pharmacy.lot.quarantine'
export const WRITEOFF_VIEW = 'pharmacy.writeoff.view'
export const WRITEOFF_CREATE = 'pharmacy.writeoff.create'
export const WRITEOFF_CANCEL = 'pharmacy.writeoff.cancel'
export const RECALL_VIEW = 'pharmacy.recall.view'
export const RECALL_ISSUE = 'pharmacy.recall.issue'
export const RECALL_LIFT = 'pharmacy.recall.lift'
export const PRESCRIPTION_VIEW = 'pharmacy.prescription.view'
export const PRESCRIPTION_CREATE = 'pharmacy.prescription.create'
export const PRESCRIPTION_CANCEL = 'pharmacy.prescription.cancel'
export const PRESCRIPTION_IMAGE = 'pharmacy.prescription.image'
export const REGISTER_VIEW = 'pharmacy.register.view'
export const REGISTER_PRINT = 'pharmacy.register.print'
export const REGISTER_ADJUST = 'pharmacy.register.adjust'
export const REPORT_VIEW = 'pharmacy.report.view'
export const DISPENSE_REVIEW = 'pharmacy.dispense.review'
export const SETTINGS_VIEW = ['pharmacy.settings.view', 'pharmacy.settings.edit']
export const SETTINGS_EDIT = 'pharmacy.settings.edit'
export const ITEM_VIEW = 'pharmacy.item.view'
export const ITEM_CONFIGURE = 'pharmacy.item.configure'

export const pharmacyKey = ['pharmacy']

/** PHA-03: the classes of an item (ItemProfile::CLASSES) and its kinds. */
export const ITEM_CLASSES = ['otc', 'prescription_only', 'controlled', 'poison', 'agrochemical']
export const PRODUCT_KINDS = ['human', 'veterinary', 'agrochemical', 'other']
export const LOT_STATES = ['expired', 'recalled', 'quarantined', 'near_expiry', 'active']
export const WRITE_OFF_STATUSES = ['pending', 'posted', 'rejected', 'cancelled', 'failed']
export const WRITE_OFF_REASONS = ['expired', 'damaged', 'recalled', 'other']
export const REVIEW_STATES = ['open', 'reviewed', 'none']

/** Status as a dot and a word: expired and recalled are red, waiting amber, fine green. */
export const STATE_TONES = {
  expired: 'danger',
  recalled: 'danger',
  quarantined: 'warning',
  near_expiry: 'warning',
  active: 'success',
  valid: 'success',
  cancelled: 'neutral',
  pending: 'warning',
  posted: 'success',
  rejected: 'danger',
  failed: 'danger',
  open: 'warning',
  reviewed: 'success',
  none: 'neutral',
  lifted: 'neutral',
}

/** Whether a decimal string is above zero (no floats). */
export const isPositive = (value) => /^\d+(\.\d+)?$/.test(String(value ?? '')) && /[1-9]/.test(String(value))

/** The companies, branches and locations the user sees, for pickers and place filters. */
export function usePlaces() {
  const list = (resource) => ({ queryKey: [resource, 'pharmacy-places'], queryFn: () => api.get(`${resource}?status=all&per_page=200`), staleTime: 60_000 })
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

/** ADR 003: the base currency of a location's company, or null. */
export function locationCurrency({ companies, branches, locations }, locationId) {
  const location = locations.find((entry) => entry.id === locationId)
  const branch = branches.find((entry) => entry.id === (location?.branch_id ?? location?.branch?.id))
  return companies.find((entry) => entry.id === branch?.company_id)?.base_currency ?? null
}

/** "Front store · Westlands": a location with its branch, for pickers. */
export const locationLabel = (location) => (location.branch?.name ? `${location.name} · ${location.branch.name}` : location.name)

/** Location picker options (active locations only). */
export const locationOptions = (locations) => locations.map((location) => ({ value: location.id, label: locationLabel(location) }))

/** "Soap 1 kg · SOAP-1": an item for pickers. */
export const itemLabel = (item) => (item ? `${item.name} · ${item.code}` : '')

/** A location filter for a ListView, as far as the user sees places. */
export function locationFilter(t, { locations }) {
  return locations.length
    ? [{ name: 'location', label: t('pharmacy.filters.location'), options: [{ value: '', label: t('pharmacy.filters.allLocations') }, ...locations.map((location) => ({ value: location.id, label: location.name }))] }]
    : []
}

/** A date filter for a list's drawer (`from`, `to`: calendar days). */
export const dateField = (name, label) => ({
  name,
  label,
  render: ({ value, onChange }) => createElement(TextField, { type: 'date', label, value, onChange: (event) => onChange(event.target.value), className: 'w-full' }),
  valueLabel: (value) => value,
})

/** A list filter over a fixed set of words. */
export function choiceField(t, name, values, label, prefix) {
  return { name, label, options: [{ value: '', label: t('pharmacy.filters.any') }, ...values.map((value) => ({ value, label: t(`${prefix}.${value}`) }))] }
}

/** The message of a field from a 422, else undefined. */
export const fieldError = (error, field) => {
  const messages = error?.status === 422 ? error.errors?.[field] : null
  return messages ? (Array.isArray(messages) ? messages[0] : String(messages)) : undefined
}

/**
 * The company a settings page works on: the one chosen on the page, else the
 * switcher's, else the only (or first) active company.
 */
export function usePharmacyCompanyChoice() {
  const { company: switcher } = useCompanySelection()
  const active = useCompanies().companies.filter((entry) => !entry.archived_at)
  const [chosen, setChosen] = useState('')
  const company = active.find((entry) => entry.id === chosen) ?? switcher ?? active[0] ?? null
  return { company, companies: active, choose: setChosen }
}
