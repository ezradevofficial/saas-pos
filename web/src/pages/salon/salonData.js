// The Salon back office (docs/modules/salon.md): permissions, statuses, query keys and the small lists the pages need.
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { useCompanyChoice } from '@/pages/restaurant/restaurantData'
import { usePlaces } from '@/pages/inventory/inventoryData'

/** SAL-28: who may open each page (the API checks again at the place). */
export const SERVICE_VIEW = 'salon.service.view'
export const SERVICE_MANAGE = 'salon.service.manage'
export const STAFF_VIEW = 'salon.staff.view'
export const STAFF_MANAGE = 'salon.staff.manage'
export const RESOURCE_MANAGE = 'salon.resource.manage'
export const ONLINE_VIEW = 'salon.online.view'
export const ONLINE_MANAGE = 'salon.online.manage'
export const SETTINGS_VIEW = 'salon.settings.view'
export const SETTINGS_EDIT = 'salon.settings.edit'
export const APPOINTMENT_VIEW = ['salon.appointment.view', 'salon.appointment.view_own']
export const APPOINTMENT_CREATE = 'salon.appointment.create'
export const APPOINTMENT_EDIT = 'salon.appointment.edit'
export const APPOINTMENT_CANCEL = 'salon.appointment.cancel'
export const APPOINTMENT_SERVE = 'salon.appointment.serve'
export const QUEUE_MANAGE = 'salon.queue.manage'
export const CLIENT_VIEW = 'salon.client.view'
export const CLIENT_MANAGE = 'salon.client.manage'
export const CLIENT_NOTES = 'salon.client.notes'
export const PACKAGE_VIEW = 'salon.package.view'
export const PACKAGE_ADJUST = 'salon.package.adjust'
export const DEPOSIT_TAKE = 'salon.deposit.take'
export const DEPOSIT_REFUND = 'salon.deposit.refund'
export const FEE_WAIVE = 'salon.fee.waive'
export const EXCEPTION_VIEW = 'salon.exception.view'
export const EXCEPTION_RESOLVE = 'salon.exception.resolve'
export const COMMISSION_VIEW = ['salon.commission.view', 'salon.commission.view_own']
export const COMMISSION_RULE = 'salon.commission.rule'
export const COMMISSION_BUILD = 'salon.commission.build'
export const COMMISSION_APPROVE = 'salon.commission.approve'
export const COMMISSION_EXPORT = 'salon.commission.export'
export const REPORT_VIEW = 'salon.report.view'

export const salonKey = ['salon']

/** SAL-07 */
export const APPOINTMENT_STATUSES = ['booked', 'checked_in', 'in_service', 'completed', 'paid', 'cancelled', 'no_show']
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7]
export const COLORS = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8']

/** Status as a dot and a word. */
export const STATUS_TONES = {
  booked: 'info',
  checked_in: 'info',
  in_service: 'warning',
  completed: 'success',
  paid: 'success',
  cancelled: 'neutral',
  no_show: 'danger',
  waiting: 'warning',
  seated: 'success',
  left: 'neutral',
  draft: 'neutral',
  submitted: 'warning',
  approved: 'info',
  rejected: 'danger',
  exported: 'success',
  active: 'success',
  exhausted: 'neutral',
  expired: 'warning',
  void: 'neutral',
  open: 'warning',
  resolved: 'success',
  pending: 'warning',
  settled: 'success',
  failed: 'danger',
  due: 'warning',
  waived: 'neutral',
  none: 'neutral',
  reported: 'neutral',
  archived: 'neutral',
}

export const statusLabel = (t, status) => t(`salon.statuses.${status}`, { defaultValue: status })

/**
 * The API's own sentence (already translated by the server) for a refusal, also for a 403 such as `self_approval`
 * that says what to do next; only the generic `forbidden` gets the web's wording.
 */
export const salonError = (error) => errorMessage(error, error?.code && error.code !== 'forbidden' && error.message ? { [error.code]: error.message } : {})

/** "HH:MM" of an instant in a time zone. */
export function timeIn(value, zone) {
  if (!value) return ''
  return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', ...(zone ? { timeZone: zone } : {}) }).format(new Date(value))
}

/** "YYYY-MM-DD" of an instant in a time zone. */
export function dayIn(value, zone) {
  return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', ...(zone ? { timeZone: zone } : {}) }).format(new Date(value))
}

export const today = (zone) => dayIn(new Date(), zone)

/** Query string with repeated `key[]` parameters for lists. */
export function queryString(params) {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (Array.isArray(value)) value.forEach((entry) => search.append(`${key}[]`, entry))
    else if (value !== undefined && value !== null && value !== '') search.set(key, String(value))
  }
  const text = search.toString()
  return text ? `?${text}` : ''
}

/** A company's rows of a list endpoint (whole list). */
function useList(name, path, companyId, enabled = true) {
  const query = useQuery({ queryKey: [...salonKey, name, companyId], queryFn: () => api.get(`${path}${queryString({ company_id: companyId })}`), enabled: Boolean(companyId) && enabled, staleTime: 30_000 })
  return { ...query, rows: query.data?.data ?? [] }
}

export const useServices = (companyId) => useList('services', 'salon/services', companyId)
export const useStaff = (companyId) => useList('staff', 'salon/staff', companyId)
export const useResources = (companyId) => useList('resources', 'salon/resources', companyId)
export const usePackageTypes = (companyId) => useList('package-types', 'salon/package-types', companyId)

/** The company and branch the page works on: the switcher's company, the first active branch of it. */
export function useSalonPlace() {
  const choice = useCompanyChoice()
  const places = usePlaces()
  const branches = places.branches.filter((branch) => !branch.archived_at && branch.company_id === choice.company?.id)
  const [chosen, setChosen] = useState('')
  const branch = branches.find((entry) => entry.id === chosen) ?? branches[0] ?? null

  return { ...choice, branches, branch, chooseBranch: setChosen }
}

/** The company's payment methods that can take a deposit. */
export function usePaymentMethods(companyId) {
  const query = useQuery({ queryKey: ['payment-methods', 'salon', companyId], queryFn: () => api.get(`companies/${companyId}/payment-methods?per_page=200`), enabled: Boolean(companyId), staleTime: 60_000 })
  return (query.data?.data ?? []).filter((method) => method.active && !method.archived_at && ['cash', 'mobile_money', 'card', 'bank_transfer'].includes(method.type))
}

/** Items of the company for the pickers (tip item, fee item, products). */
export function useItems(search = '') {
  const query = useQuery({ queryKey: ['items', 'salon', search], queryFn: () => api.get(`items?per_page=100${search ? `&search=${encodeURIComponent(search)}` : ''}`), staleTime: 60_000, retry: false })
  return query.data?.data ?? []
}
