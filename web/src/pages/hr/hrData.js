// The HR back office and self-service (docs/modules/hr.md, Screens):
// permissions, statuses and their tones, the module's enums, the places,
// departments, positions, shifts and leave types its pickers offer, and
// small date helpers for weeks and months.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { useCompanySelection } from '@/layouts/companySelection'

/** RBAC-09: who may open or act on each page (the API checks again at the employee's place, HR-38). */
export const PERM = {
  employeeView: 'hr.employee.view',
  employeeCreate: 'hr.employee.create',
  employeeEdit: 'hr.employee.edit',
  employeeTerminate: 'hr.employee.terminate',
  employeeLink: 'hr.employee.link',
  contractView: 'hr.contract.view',
  contractManage: 'hr.contract.manage',
  positionView: 'hr.position.view',
  positionManage: 'hr.position.manage',
  leaveView: 'hr.leave.view',
  leaveRequest: 'hr.leave.request',
  leaveManage: 'hr.leave.manage',
  rosterView: 'hr.roster.view',
  rosterManage: 'hr.roster.manage',
  attendanceView: 'hr.attendance.view',
  attendanceManage: 'hr.attendance.manage',
  attendanceClose: 'hr.attendance.close',
  deviceManage: 'hr.device.manage',
  documentView: 'hr.document.view',
  documentManage: 'hr.document.manage',
  checklistView: 'hr.checklist.view',
  checklistManage: 'hr.checklist.manage',
  disciplinaryView: 'hr.disciplinary.view',
  disciplinaryManage: 'hr.disciplinary.manage',
  appraisalView: 'hr.appraisal.view',
  appraisalManage: 'hr.appraisal.manage',
}

/** The pages of each section (in-page tabs) and who may open them; the sidebar names the section. */
export const SECTIONS = {
  leave: [
    { key: 'requests', to: '/hr/leave/requests', permission: PERM.leaveView },
    { key: 'balances', to: '/hr/leave/balances', permission: PERM.leaveView },
    { key: 'calendar', to: '/hr/leave/calendar', permission: PERM.leaveView },
    { key: 'types', to: '/hr/leave/types', permission: PERM.leaveView },
  ],
  rosters: [
    { key: 'week', to: '/hr/rosters/week', permission: [PERM.rosterView, PERM.rosterManage] },
    { key: 'list', to: '/hr/rosters/list', permission: [PERM.rosterView, PERM.rosterManage] },
    { key: 'setup', to: '/hr/rosters/setup', permission: [PERM.rosterView, PERM.rosterManage] },
    { key: 'swaps', to: '/hr/rosters/swaps', permission: [PERM.rosterView, PERM.rosterManage] },
  ],
  attendance: [
    { key: 'timesheets', to: '/hr/attendance/timesheets', permission: PERM.attendanceView },
    { key: 'punches', to: '/hr/attendance/punches', permission: PERM.attendanceView },
    { key: 'periods', to: '/hr/attendance/periods', permission: PERM.attendanceView },
    { key: 'rules', to: '/hr/attendance/rules', permission: PERM.attendanceView },
    { key: 'devices', to: '/hr/attendance/devices', permission: PERM.deviceManage },
  ],
}

/** The self-service pages (HR-31): the link to an employee is the authorisation. */
export const MY_PAGES = ['profile', 'leave', 'roster', 'attendance', 'swaps', 'appraisals']

/** Status as a dot and a word: waiting is amber, in progress is blue, done is green, refused or missing is red, closed is grey. */
export const STATUS_TONES = {
  active: 'success',
  terminated: 'neutral',
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
  declined: 'neutral',
  awaiting_peer: 'warning',
  planned: 'info',
  present: 'success',
  absent: 'danger',
  leave: 'info',
  holiday: 'info',
  incomplete: 'warning',
  open: 'info',
  closed: 'neutral',
  completed: 'success',
  draft: 'neutral',
  self_review: 'warning',
  manager_review: 'warning',
  acknowledged: 'success',
  suspended: 'warning',
  retired: 'neutral',
  ended: 'neutral',
  archived: 'neutral',
  days_needed: 'warning',
  not_approved: 'neutral',
  voided: 'neutral',
  period_closed: 'warning',
  pin_set: 'success',
  pin_none: 'neutral',
}

/** HR-25: a timesheet day's exceptions. */
export const EXCEPTION_TONES = { late: 'warning', early_leave: 'warning', missing_in: 'warning', missing_out: 'warning', absent: 'danger', unplanned: 'info', no_time_rule: 'warning' }

export const EMPLOYEE_STATUSES = ['active', 'terminated']
export const GENDERS = ['female', 'male', 'other']
export const TERMINATION_REASONS = ['resignation', 'dismissal', 'end_of_contract', 'retirement', 'redundancy', 'death', 'other']
export const CLOCK_METHODS = ['web', 'till', 'device']
export const PAY_ACCOUNT_KINDS = ['bank', 'mobile_money']
export const CONTRACT_TYPES = ['permanent', 'fixed_term', 'casual', 'internship', 'consultant']
export const PAY_BASES = ['monthly', 'daily', 'hourly']
export const DOCUMENT_CATEGORIES = ['contract', 'identity', 'certificate', 'medical', 'disciplinary', 'other']
export const LEAVE_STATUSES = ['pending', 'approved', 'rejected', 'cancelled']
export const ACCRUALS = ['none', 'annual', 'monthly']
export const COUNT_BASES = ['working_days', 'calendar_days']
export const SWAP_STATUSES = ['awaiting_peer', 'pending', 'approved', 'rejected', 'declined', 'cancelled']
export const DAY_STATUSES = ['present', 'absent', 'leave', 'holiday', 'incomplete']
export const WEEK_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
export const CHECKLIST_KINDS = ['onboarding', 'offboarding']
export const CASE_CATEGORIES = ['verbal_warning', 'written_warning', 'final_warning', 'suspension', 'dismissal', 'other']
export const CASE_STATUSES = ['open', 'closed']
export const APPRAISAL_STATUSES = ['self_review', 'manager_review', 'completed', 'acknowledged']
export const RATINGS = ['1', '2', '3', '4', '5']

export const statusLabel = (t, status) => t(`hr.statuses.${status}`, { defaultValue: status })
/** A label of one of the enums above: `hr.<group>.<value>`. */
export const enumLabel = (t, group, value) => (value ? t(`hr.${group}.${value}`, { defaultValue: value }) : '')
export const enumOptions = (t, group, values) => values.map((value) => ({ value, label: enumLabel(t, group, value) }))

/** Query keys of the module, so a change refreshes every HR list. */
export const hrKey = ['hr']
export const meKey = ['hr', 'me']

/** "EMP-00001 · Grace Achieng": an employee reference for pickers and rows. */
export const employeeLabel = (employee) => (employee ? [employee.number, employee.name].filter(Boolean).join(' · ') : '')

/** "07:30 → 16:00" or the shift's code alone. */
export const shiftLabel = (shift) => (shift ? [shift.code, shift.name].filter(Boolean).join(' · ') : '')

/**
 * The company HR's setup pages work on (positions, leave types, shifts,
 * rotations, rosters, time rules, periods, devices, templates, cycles:
 * HR-38): the switcher's company, else the user's first.
 */
export function useHrCompany() {
  const { company, companies } = useCompanySelection()
  return company ?? companies[0] ?? null
}

/** The companies, branches and locations the user sees (RBAC-04); archived ones stay for filters. */
export function usePlaces() {
  const list = (resource) => ({ queryKey: [resource, 'hr-places'], queryFn: () => api.get(`${resource}?status=all&per_page=200`), staleTime: 60_000, retry: false })
  const companies = useQuery(list('companies'))
  const branches = useQuery(list('branches'))
  const locations = useQuery(list('locations'))
  return {
    companies: companies.data?.data ?? [],
    branches: branches.data?.data ?? [],
    locations: locations.data?.data ?? [],
  }
}

/** A company's active branches, and a branch's active locations (pickers). */
export const branchesOf = (places, companyId) => places.branches.filter((branch) => branch.company_id === companyId && !branch.archived_at)
export const locationsOf = (places, branchId) => places.locations.filter((location) => (location.branch_id ?? location.branch?.id) === branchId && !location.archived_at)

const pickerQuery = (key, path, enabled) => ({ queryKey: [...hrKey, 'picker', ...key], queryFn: () => api.get(path), enabled, staleTime: 30_000, retry: false })

/** MD-05: a company's active departments. */
export function useDepartments(companyId) {
  const query = useQuery({ ...pickerQuery(['departments', companyId], `companies/${companyId}/departments?per_page=200`, Boolean(companyId)), queryKey: ['dimensions', 'departments', 'hr', companyId] })
  return (query.data?.data ?? []).filter((row) => !row.archived_at)
}

/** HR-08: a company's active positions. */
export function usePositions(companyId) {
  const query = useQuery(pickerQuery(['positions', companyId], `hr/positions?company=${companyId}&per_page=200`, Boolean(companyId)))
  return query.data?.data ?? []
}

/** HR-16: a company's active shifts. */
export function useShifts(companyId) {
  const query = useQuery(pickerQuery(['shifts', companyId], `hr/shifts?company=${companyId}&per_page=200`, Boolean(companyId)))
  return query.data?.data ?? []
}

/** HR-16: a company's active rotations. */
export function useRotations(companyId) {
  const query = useQuery(pickerQuery(['rotations', companyId], `hr/rotations?company=${companyId}&per_page=200`, Boolean(companyId)))
  return query.data?.data ?? []
}

/** HR-12: a company's active leave types. */
export function useLeaveTypes(companyId) {
  const query = useQuery(pickerQuery(['leave-types', companyId], `hr/leave-types?company=${companyId}&per_page=200`, Boolean(companyId)))
  return query.data?.data ?? []
}

/** CUR-01: the tenant's active currencies (codes); the company's base currency when they cannot be read. */
export function useCurrencyCodes(company) {
  const query = useQuery({ queryKey: ['tenant-currencies'], queryFn: () => api.get('tenant/currencies'), retry: false, staleTime: 60_000 })
  const codes = (query.data?.data ?? []).filter((currency) => currency.active).map((currency) => currency.code)
  return codes.length ? codes : [company?.base_currency].filter(Boolean)
}

/**
 * HR-31: the signed-in user's own employee record (GET hr/me). An empty
 * answer (204, decision 30) means the user is not linked: `linked` is
 * false and `notLinked` true (an older 404 `not_an_employee` reads the same).
 */
export function useHrMe({ enabled = true } = {}) {
  const query = useQuery({ queryKey: meKey, queryFn: () => api.get('hr/me'), enabled, retry: false, staleTime: 60_000 })
  const me = query.data?.data ?? null
  return { query, me, linked: query.isSuccess && me !== null, notLinked: (query.isSuccess && me === null) || query.error?.code === 'not_an_employee' || query.error?.status === 404 }
}

/** Today in the browser's calendar ("2026-10-14"); the API reads the company's day. */
export function today() {
  const now = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/** A calendar date ("2026-10-14") moved by `days`, without the browser's time zone. */
export function addDays(date, days) {
  const [year, month, day] = String(date).split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10)
}

/** The Monday of the ISO week of a calendar date. */
export function weekStart(date) {
  const [year, month, day] = String(date).split('-').map(Number)
  const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay()
  return addDays(date, -((weekday + 6) % 7))
}

/** The `count` calendar dates from `from`. */
export const daysFrom = (from, count) => Array.from({ length: count }, (_, index) => addDays(from, index))

/** The first and last day of the month of a calendar date. */
export function monthRange(date) {
  const [year, month] = String(date).split('-').map(Number)
  const first = `${year}-${String(month).padStart(2, '0')}-01`
  const last = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10)
  return { first, last }
}

/** Worked minutes as "7:30" (hours and minutes, tabular); a dash for none. */
export function formatMinutes(minutes) {
  if (minutes === null || minutes === undefined) return '—'
  const total = Math.max(0, Number(minutes) || 0)
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`
}

/** Whether a decimal string is a valid number of days ("12", "12.5", "-1.25"). */
export const isDays = (value, { signed = false } = {}) => new RegExp(`^${signed ? '-?' : ''}\\d{1,3}(\\.\\d{1,2})?$`).test(String(value ?? '').trim())

/** A body without empty strings (the API takes null or a missing key for "none"). */
export const withoutBlanks = (body) => Object.fromEntries(Object.entries(body).map(([key, value]) => [key, typeof value === 'string' && value.trim() === '' ? null : value]))
