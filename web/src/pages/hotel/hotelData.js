// The Hotel back office (docs/modules/hotel.md): who may open each page, the
// words and tones of states, and the places the pickers offer.
import { useQuery } from '@tanstack/react-query'
import { createElement, useState } from 'react'
import { api } from '@/api/client'
import { TextField } from '@/components/ds'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'

/** RBAC-09: who may open each page and act (the API checks again at each branch). */
export const SETTINGS_VIEW = ['hotel.settings.view', 'hotel.settings.edit']
export const SETTINGS_EDIT = 'hotel.settings.edit'
export const ROOM_VIEW = 'hotel.room.view'
export const ROOM_MANAGE = 'hotel.room.manage'
export const RATE_VIEW = 'hotel.rate.view'
export const RATE_MANAGE = 'hotel.rate.manage'
export const RATE_OVERRIDE = 'hotel.rate.override'
export const GUEST_VIEW = 'hotel.guest.view'
export const GUEST_MANAGE = 'hotel.guest.manage'
export const GUEST_IDENTITY = 'hotel.guest.identity'
export const RESERVATION_VIEW = 'hotel.reservation.view'
export const RESERVATION_CREATE = 'hotel.reservation.create'
export const RESERVATION_EDIT = 'hotel.reservation.edit'
export const RESERVATION_CANCEL = 'hotel.reservation.cancel'
export const CHECKIN = 'hotel.stay.checkin'
export const CHECKOUT = 'hotel.stay.checkout'
export const MOVE = 'hotel.stay.move'
export const FOLIO_VIEW = 'hotel.folio.view'
export const FOLIO_CHARGE = 'hotel.folio.charge'
export const FOLIO_PAYMENT = 'hotel.folio.payment'
export const FOLIO_REFUND = 'hotel.folio.refund'
export const FOLIO_VOID = 'hotel.folio.void'
export const FOLIO_TRANSFER = 'hotel.folio.transfer'
export const FOLIO_SETTLE_OPEN = 'hotel.folio.settle_open'
export const FEE_WAIVE = 'hotel.fee.waive'
export const CHARGE_RESOLVE = 'hotel.charge.resolve'
export const HK_VIEW = 'hotel.housekeeping.view'
export const HK_ASSIGN = 'hotel.housekeeping.assign'
export const HK_UPDATE = 'hotel.housekeeping.update'
export const AUDIT_VIEW = 'hotel.audit.view'
export const AUDIT_RUN = 'hotel.audit.run'
export const REPORT_VIEW = 'hotel.report.view'

export const hotelKey = ['hotel']

export const BOARDS = ['room_only', 'bed_breakfast', 'half_board']
export const ROOM_STATUSES = ['vacant_clean', 'vacant_dirty', 'occupied', 'out_of_order']
export const STAY_STATUSES = ['reserved', 'checked_in', 'checked_out', 'cancelled', 'no_show']
export const CHARGE_KINDS = ['none', 'nights', 'percent', 'fixed']
export const TASK_KINDS = ['checkout_clean', 'stayover', 'inspection', 'deep_clean', 'maintenance']
export const SOURCES = ['direct', 'phone', 'walk_in', 'online', 'agent', 'other']
export const ID_TYPES = ['national_id', 'passport', 'driving_licence', 'other']
export const RESOLUTIONS = ['moved', 'billed_other', 'written_off']
/** 1 = Monday ... 7 = Sunday (ISO). */
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7]

/** Status as a dot and a word. */
export const STATE_TONES = {
  reserved: 'info',
  checked_in: 'success',
  checked_out: 'neutral',
  cancelled: 'neutral',
  no_show: 'danger',
  open: 'warning',
  settled: 'neutral',
  pending: 'warning',
  in_progress: 'info',
  done: 'success',
  inspected: 'neutral',
  resolved: 'neutral',
  completed: 'success',
  failed: 'danger',
  vacant_clean: 'success',
  vacant_dirty: 'warning',
  occupied: 'info',
  out_of_order: 'danger',
}

/** Whether a string is a whole number of at least one digit. */
export const isWhole = (value) => /^\d+$/.test(String(value ?? ''))

/** The message of a field from a 422, else undefined. */
export const fieldError = (error, field) => {
  const messages = error?.status === 422 ? error.errors?.[field] : null
  return messages ? (Array.isArray(messages) ? messages[0] : String(messages)) : undefined
}

/** A calendar day ("2026-10-27") moved by `days`, without time zones. */
export function addDays(day, days) {
  const [year, month, date] = String(day).split('-').map(Number)
  const moved = new Date(Date.UTC(year, month - 1, date + days))
  return moved.toISOString().slice(0, 10)
}

/** Whole nights between two calendar days. */
export function nightsBetween(from, to) {
  if (!from || !to) return 0
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000)
}

/** The days of [from, to) as calendar days. */
export function daysOf(from, to) {
  const count = Math.max(0, nightsBetween(from, to))
  return Array.from({ length: count }, (_, index) => addDays(from, index))
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
  return { name, label, options: [{ value: '', label: t('hotel.filters.any') }, ...values.map((value) => ({ value, label: t(`${prefix}.${value}`) }))] }
}

/** The active branches the user sees (properties), with their company. */
export function useHotelPlaces() {
  const query = useQuery({ queryKey: ['branches', 'hotel-places'], queryFn: () => api.get('branches?status=all&per_page=200'), staleTime: 60_000 })
  const { companies } = useCompanies()
  const branches = (query.data?.data ?? []).filter((branch) => !branch.archived_at)
  return { branches, companies, loading: query.isPending }
}

/** The company a settings-like page works on: the one chosen on the page, else the switcher's, else the first active. */
export function useHotelCompanyChoice() {
  const { company: switcher } = useCompanySelection()
  const active = useCompanies().companies.filter((entry) => !entry.archived_at)
  const [chosen, setChosen] = useState('')
  const company = active.find((entry) => entry.id === chosen) ?? switcher ?? active[0] ?? null
  return { company, companies: active, choose: setChosen }
}

/**
 * The property a page works on: the one chosen on the page, else the first
 * branch of the company in the switcher, else the first branch. A property is
 * a branch (docs/modules/hotel.md, Scope).
 */
export function useBranchChoice() {
  const { branches, companies, loading } = useHotelPlaces()
  const { company: switcher } = useCompanySelection()
  const [chosen, setChosen] = useState('')
  const inCompany = switcher ? branches.filter((branch) => branch.company_id === switcher.id) : branches
  const branch = branches.find((entry) => entry.id === chosen) ?? inCompany[0] ?? branches[0] ?? null
  const company = companies.find((entry) => entry.id === branch?.company_id) ?? null
  return { branch, branches, company, companyId: branch?.company_id ?? null, currency: company?.base_currency ?? 'KES', timezone: company?.timezone, choose: setChosen, loading }
}

/** Payment methods that can take money into a folio, in the folio's currency (HOT-24). */
export function usePaymentMethods(companyId, currency) {
  const query = useQuery({
    queryKey: ['payment-methods', 'hotel', companyId],
    queryFn: () => api.get(`companies/${companyId}/payment-methods?per_page=200`),
    enabled: Boolean(companyId),
    staleTime: 60_000,
  })
  const usable = (query.data?.data ?? []).filter(
    (method) => method.active && !method.archived_at && ['cash', 'mobile_money', 'card', 'bank_transfer'].includes(method.type) && (!method.currency || !currency || method.currency === currency),
  )
  return { methods: usable, loading: query.isPending }
}

/** The company's active payment methods of one type (the room-charge method of the settings). */
export function usePaymentMethodsOfType(companyId, type) {
  const query = useQuery({
    queryKey: ['payment-methods', 'hotel', companyId],
    queryFn: () => api.get(`companies/${companyId}/payment-methods?per_page=200`),
    enabled: Boolean(companyId),
    staleTime: 60_000,
  })
  return (query.data?.data ?? []).filter((method) => method.type === type && !method.archived_at)
}

export const BLANK_GUEST = { mode: 'existing', guest_id: '', name: '', phone: '', email: '', nationality: '', id_type: '', id_number: '' }

/** The `guest_id` or inline `guest` a booking or walk-in sends. */
export function guestBody(value) {
  if (value.mode === 'existing') return { guest_id: value.guest_id }
  const entries = ['name', 'phone', 'email', 'nationality', 'id_type', 'id_number'].map((key) => [key, String(value[key]).trim()]).filter(([, text]) => text !== '')
  return { guest: Object.fromEntries(entries) }
}

/** Whether a guest has been chosen or a new one named. */
export const guestReady = (value) => (value.mode === 'existing' ? Boolean(value.guest_id) : Boolean(value.name.trim()))

/** The rooms of a property (shared by the rooms page, the front desk and housekeeping). */
export function useRooms(branchId) {
  const query = useQuery({ queryKey: [...hotelKey, 'rooms', branchId], queryFn: () => api.get(`hotel/rooms?branch=${branchId}`), enabled: Boolean(branchId), staleTime: 15_000 })
  return query.data?.data ?? []
}
