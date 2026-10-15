// The Payroll back office and self-service (docs/modules/payroll.md,
// Screens): permissions, statuses and their tones, the module's enums, the
// pay groups and items its pickers offer, and small money helpers for the
// variance against the previous run (PAY-15).
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { useHrMutation } from '@/pages/hr/hrForms'

/** RBAC-09: who may open or act on each page (the API checks again at the employee's place or the company, PAY-27). */
export const PERM = {
  settingsView: 'payroll.settings.view',
  settingsManage: 'payroll.settings.manage',
  employeeView: 'payroll.employee.view',
  employeeManage: 'payroll.employee.manage',
  loanView: 'payroll.loan.view',
  loanManage: 'payroll.loan.manage',
  runView: 'payroll.run.view',
  runPrepare: 'payroll.run.prepare',
  runApprove: 'payroll.run.approve',
  runPost: 'payroll.run.post',
  payslipView: 'payroll.payslip.view',
  paymentExport: 'payroll.payment.export',
  returnView: 'payroll.return.view',
  returnGenerate: 'payroll.return.generate',
}

/** Query keys of the module, so a change refreshes every Payroll list. */
export const payrollKey = ['payroll']

/** PAY-14: the run's statuses, in order; cancelled stops a run before submission. */
export const RUN_STATUSES = ['draft', 'calculated', 'pending', 'approved', 'posted', 'cancelled']
export const RUN_STAGES = ['draft', 'calculated', 'pending', 'approved', 'posted']
export const RUN_KINDS = ['regular', 'correction']
export const FREQUENCIES = ['monthly', 'weekly', 'biweekly']
export const PAYMENT_METHODS = ['bank', 'mobile_money', 'cash']
export const ITEM_KINDS = ['earning', 'deduction']
export const RECURRENCES = ['recurring', 'once']
export const LOAN_KINDS = ['loan', 'advance']
export const LOAN_STATUSES = ['active', 'settled', 'cancelled']
export const CORRECTION_MODES = ['reverse', 'recalculate']
export const SETTLE_METHODS = ['external', 'next_run']
export const EMPLOYEE_STATUSES = ['active', 'terminated']

/** Status as a dot and a word: waiting is amber, in progress is blue, done is green, refused or missing is red, closed is grey. */
export const STATUS_TONES = {
  draft: 'neutral',
  calculated: 'info',
  pending: 'warning',
  approved: 'success',
  posted: 'success',
  cancelled: 'neutral',
  active: 'success',
  terminated: 'neutral',
  archived: 'neutral',
  settled: 'neutral',
  on_hold: 'warning',
  consumed: 'neutral',
  errors: 'danger',
  warnings: 'warning',
  ready: 'success',
  missing: 'danger',
  to_confirm: 'warning',
}

/** A label of one of the enums above: `payroll.<group>.<value>`. */
export const enumLabel = (t, group, value) => (value ? t(`payroll.${group}.${value}`, { defaultValue: value }) : '')
export const enumOptions = (t, group, values) => values.map((value) => ({ value, label: enumLabel(t, group, value) }))
export const statusLabel = (t, status) => enumLabel(t, 'statuses', status)

/** "EMP-00001 · Grace Achieng": an employee reference for pickers and rows. */
export const employeeLabel = (employee) => (employee ? [employee.number, employee.name].filter(Boolean).join(' · ') : '')

/** "MON · Monthly staff": a pay group or item reference. */
export const codeLabel = (row) => (row ? [row.code, row.name].filter(Boolean).join(' · ') : '')

const pickerQuery = (key, path, enabled) => ({ queryKey: [...payrollKey, 'picker', ...key], queryFn: () => api.get(path), enabled, staleTime: 30_000, retry: false })

/** PAY-03: a company's active pay groups. */
export function usePayGroups(companyId) {
  const query = useQuery(pickerQuery(['pay-groups', companyId ?? 'all'], `payroll/pay-groups?status=active&per_page=200${companyId ? `&company=${companyId}` : ''}`, true))
  return query.data?.data ?? []
}

/** PAY-04: a company's active pay items. */
export function usePayItems(companyId) {
  const query = useQuery(pickerQuery(['items', companyId], `payroll/items?status=active&per_page=200&company=${companyId}`, Boolean(companyId)))
  return query.data?.data ?? []
}

/**
 * PAY-15: the change from `before` to `now` (money `{amount_minor, currency}`)
 * as `{ diff, pct }`: the difference in minor units (a string) and the
 * percentage with two decimals (a string), null when there was nothing before.
 */
export function variance(now, before) {
  if (!now || !before) return null
  const a = BigInt(now.amount_minor)
  const b = BigInt(before.amount_minor)
  const diff = a - b
  if (b === 0n) return { diff: String(diff), pct: null }
  // Rounded half away from zero to two decimals, in integers (never floats on money).
  const scaled = (diff * 100000n) / b
  const rounded = scaled >= 0n ? (scaled + 5n) / 10n : (scaled - 5n) / 10n
  const sign = rounded < 0n ? '-' : ''
  const absolute = rounded < 0n ? -rounded : rounded
  return { diff: String(diff), pct: `${sign}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}` }
}

/** A percentage given as a decimal string ("4.25", "-10.00") in the UI language, signed. */
export function formatPct(value, locale) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  return new Intl.NumberFormat(locale, { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 2, signDisplay: 'exceptZero' }).format(number / 100)
}

/** The stage a run has reached (a cancelled run stops where it was). */
export function stageOf(run) {
  if (run.status === 'cancelled') return run.calculated_at ? 'calculated' : 'draft'
  return RUN_STAGES.includes(run.status) ? run.status : 'draft'
}

/** Today in the browser's calendar ("2026-10-14"); the API reads the company's day. */
export function today() {
  const now = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/** A body without empty strings (the API takes null for "none"). */
export const withoutBlanks = (body) => Object.fromEntries(Object.entries(body).map(([key, value]) => [key, typeof value === 'string' && value.trim() === '' ? null : value]))

/** PAY-20, PAY-21: a file whose layout is unconfirmed comes named `draft-…`. */
export const isDraftFile = (filename) => /^draft-/.test(String(filename ?? ''))

/** A mutation that refreshes Payroll's lists and says so (HR's helper, with Payroll's keys). */
export const usePayrollMutation = (request, options = {}) => useHrMutation(request, { keys: [payrollKey], ...options })
