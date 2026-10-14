// HR's form helpers and hooks that are not components (so the parts files
// export components only): mutations that refresh HR's lists, date and time
// text, the bodies of the personal, leave and employee forms.
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { formErrors } from '@/api/formErrors'
import { formatCompanyClock, formatCompanyTime } from '@/lib/companyTime'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { hrKey, withoutBlanks } from './hrData'

/** A calendar date as text (for cells that join several values). */
export function useDay() {
  const locale = useLocale()
  return (value) => (value ? formatCalendarDate(value, locale) : '—')
}

/** L10N-03: an instant's time of day in its company's zone ("08:02 EAT" when the zone differs from the browser's). */
export function useClock() {
  const locale = useLocale()
  const companyOf = useCompanyOfRecord()
  return (value, companyId) => (value ? formatCompanyClock(value, locale, companyOf(companyId)) : '—')
}

/** L10N-03: an instant's date and time in its company's zone. */
export function useWhen() {
  const locale = useLocale()
  const companyOf = useCompanyOfRecord()
  return (value, companyId) => (value ? formatCompanyTime(value, locale, companyOf(companyId)) : '—')
}

/**
 * A mutation that refreshes HR's lists and says so: `mutate(body)` runs
 * `request(body)`; `errors(fields)` splits its error for a form.
 */
export function useHrMutation(request, { success, onDone, keys = [hrKey] } = {}) {
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: request,
    onSuccess: async (response, variables) => {
      const message = typeof success === 'function' ? success(response, variables) : success
      if (message) toast.success(message)
      await Promise.all(keys.map((queryKey) => queryClient.invalidateQueries({ queryKey })))
      onDone?.(response, variables)
    },
  })
  return { ...mutation, errors: (fields = []) => formErrors(mutation.error, fields) }
}

/** A signed download link handed out by the API (HR-27): opened by the browser as a download. */
export function openLink(url) {
  const link = document.createElement('a')
  link.href = url
  link.rel = 'noopener'
  document.body.append(link)
  link.click()
  link.remove()
}

/** HR-02: the address fields of the record (`address` jsonb). */
export const ADDRESS_FIELDS = ['line1', 'line2', 'city', 'region', 'postal_code', 'country']

/** The emergency contacts as the API takes them (blank optional values left out). */
export const contactsBody = (contacts) =>
  contacts.map((row) => Object.fromEntries(Object.entries(row).filter(([key, text]) => key === 'name' || key === 'phone' || String(text ?? '').trim() !== '').map(([key, text]) => [key, String(text ?? '').trim()])))

/** The personal form's starting values from an employee. */
export const personalForm = (employee) => ({
  personal_email: employee.personal_email ?? '',
  personal_phone: employee.personal_phone ?? '',
  address: Object.fromEntries(ADDRESS_FIELDS.map((key) => [key, employee.address?.[key] ?? ''])),
  emergency_contacts: (employee.emergency_contacts ?? []).map((row) => ({ name: '', relationship: '', phone: '', alt_phone: '', email: '', ...row })),
})

/** The personal form as the API takes it. */
export function personalBody(form) {
  const address = Object.fromEntries(
    ADDRESS_FIELDS.map((key) => {
      const text = String(form.address[key] ?? '').trim()
      return [key, text ? (key === 'country' ? text.toUpperCase() : text) : null]
    }),
  )
  return {
    personal_email: form.personal_email.trim() || null,
    personal_phone: form.personal_phone.trim() || null,
    address: Object.values(address).some(Boolean) ? address : null,
    emergency_contacts: contactsBody(form.emergency_contacts),
  }
}

/** The fields the personal form shows errors for. */
export const PERSONAL_FIELDS = ['personal_email', 'personal_phone', ...ADDRESS_FIELDS.map((key) => `address.${key}`), ...[0, 1, 2, 3, 4].flatMap((index) => ['name', 'relationship', 'phone', 'alt_phone', 'email'].map((key) => `emergency_contacts.${index}.${key}`))]

export const blankLeave = () => ({ leave_type_id: '', start_date: '', end_date: '', start_half: false, end_half: false, reason: '' })
export const LEAVE_FIELDS = ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'start_half', 'end_half', 'reason']
export const leaveBody = (form) => ({ ...form, reason: form.reason.trim() || null })

export const EMPLOYEE_FIELDS = ['first_name', 'middle_name', 'last_name', 'gender', 'hire_date', 'branch_id', 'location_id', 'department_id', 'position_id', 'reports_to_id', 'clock_methods', 'work_email', 'work_phone', 'biometric_ref', 'company_id']

/** The form's starting values (an employee's when editing). */
export const employeeForm = (employee = {}) => ({
  first_name: employee.first_name ?? '',
  middle_name: employee.middle_name ?? '',
  last_name: employee.last_name ?? '',
  gender: employee.gender ?? '',
  hire_date: employee.hire_date ?? '',
  branch_id: employee.branch?.id ?? '',
  location_id: employee.location?.id ?? '',
  department_id: employee.department?.id ?? '',
  position_id: employee.position?.id ?? '',
  reports_to: employee.reports_to ?? null,
  clock_methods: employee.clock_methods ?? [],
  work_email: employee.work_email ?? '',
  work_phone: employee.work_phone ?? '',
  biometric_ref: employee.biometric_ref ?? '',
})

/** The form as the API takes it. */
export function employeeBody(form) {
  const { reports_to: manager, ...rest } = form
  return withoutBlanks({ ...rest, reports_to_id: manager?.id ?? null })
}
