// Payroll screens (docs/modules/payroll.md, Screens): the runs list
// (PAY-14, PAY-25), a calculation refused for missing statutory values
// (PAY-08), totals and variances against the previous run (PAY-15), the
// statutory pack's missing values in settings (PAY-08) and self-service
// "My payslips" (PAY-24).
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api, ApiError } from '@/api/client'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import en from '@/locales/en.json'
import fr from '@/locales/fr.json'
import { CORRECTION_MODES, EMPLOYEE_STATUSES, FREQUENCIES, ITEM_KINDS, LOAN_KINDS, LOAN_STATUSES, PAYMENT_METHODS, PERM, RECURRENCES, RUN_KINDS, RUN_STAGES, RUN_STATUSES, SETTLE_METHODS, variance } from './payrollData'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Hotels', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const page = (data, meta = {}) => ({ data, meta: { total: data.length, last_page: 1, current_page: 1, from: 1, to: data.length, ...meta } })
const money = (amount_minor, currency = 'KES') => ({ amount_minor, currency })
const GRACE = { id: 'pe-1', number: 'EMP-00001', name: 'Grace Achieng' }
const JOSEPH = { id: 'pe-2', number: 'EMP-00002', name: 'Joseph Mwangi' }

const RUN = {
  id: 'r-1',
  number: 'PR-2026-00003',
  company: { id: 'c-1', name: 'Amani Hotels' },
  company_id: 'c-1',
  pay_group: { id: 'pg-1', name: 'Monthly staff', code: 'MON' },
  kind: 'regular',
  corrects_run_id: null,
  correction_mode: null,
  period_start: '2026-10-01',
  period_end: '2026-10-31',
  pay_date: '2026-10-31',
  status: 'draft',
  employees: 0,
  lines_with_errors: 0,
  missing: null,
  note: null,
  prepared_by: null,
  calculated_at: null,
  submitted_by: null,
  submitted_at: null,
  approved_at: null,
  rejected_at: null,
  posted_by: null,
  posted_at: null,
  cancelled_at: null,
}

function setup(routes, permissions, { modules = ['core', 'payroll'] } = {}) {
  mockRoutes(api, [...routes, [/^config\//, apiError(404, 'not_found', 'Not found.')]], { permissions: tenantWide(['core.company.view', ...permissions]), modules, companies: [COMPANY] })
}

describe('Payroll screens', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists runs with their status and pay group (PAY-14, PAY-25)', async () => {
    const posted = { ...RUN, status: 'posted', employees: 12, totals: { currencies: [], base: { gross: money('120000000'), net: money('98000000'), employer: money('5000000') }, previous_run_id: null, gone: [] } }
    setup([[/^payroll\/runs\?/, page([posted])], [/^payroll\/pay-groups\?/, page([])]], [PERM.runView, PERM.runPrepare])
    renderApp('/payroll/runs')
    const table = await screen.findByRole('table')
    expect(await within(table).findByText('PR-2026-00003')).toBeInTheDocument()
    expect(within(table).getByText('MON · Monthly staff')).toBeInTheDocument()
    expect(within(table).getByText('Posted').closest('[data-tone]')).toHaveAttribute('data-tone', 'success')
    expect(within(table).getByText('980,000.00')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^payroll\/runs\?status=all&/))
    expect(screen.getByRole('button', { name: 'New run' })).toBeInTheDocument()
    // The sidebar has the Payroll group.
    expect(screen.getAllByRole('link', { name: 'Runs' }).length).toBeGreaterThan(0)
  })

  it('shows what the pack lacks when calculating is refused (PAY-08)', async () => {
    setup([['payroll/runs/r-1', { data: RUN }]], [PERM.runView, PERM.runPrepare])
    const message = 'This run can’t be calculated: statutory values for KE are missing (SHIF (KE): employee minimum). Ask the platform team to confirm them in the country pack.'
    api.post.mockRejectedValue(new ApiError({ status: 422, code: 'statutory_rates_missing', message, data: { missing: [{ component: 'shif', fields: ['employee_min_minor'] }] } }))
    renderApp('/payroll/runs/r-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Calculate' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('payroll/runs/r-1/calculate', {}))
    const alert = await screen.findByRole('alert')
    expect(within(alert).getByText('Statutory values are missing')).toBeInTheDocument()
    expect(within(alert).getByText(message)).toBeInTheDocument()
    expect(within(alert).getByText('shif: employee_min_minor')).toBeInTheDocument()
    // Without the post permission and before approval there is nothing to post.
    expect(screen.queryByRole('button', { name: 'Post run' })).not.toBeInTheDocument()
  })

  it('shows totals by currency, the variance against the previous run and lines with errors (PAY-15)', async () => {
    const totals = {
      currencies: [
        {
          currency: 'KES',
          employees: 2,
          gross: money('10000000'),
          statutory: money('1500000'),
          deductions: money('200000'),
          loans: money('300000'),
          net: money('8000000'),
          employer: money('400000'),
          previous: { employees: 2, gross: money('9000000'), net: money('7500000') },
        },
      ],
      base: { gross: money('10000000'), net: money('8000000'), employer: money('400000') },
      previous_run_id: 'r-0',
      gone: [{ number: 'EMP-00009', name: 'Peter Otieno' }],
    }
    const run = { ...RUN, status: 'calculated', employees: 2, lines_with_errors: 1, totals, prepared_by: { id: 'u-1', name: 'Amina Otieno' }, calculated_at: '2026-10-28T06:00:00Z' }
    const lines = [
      { id: 'l-1', employee: GRACE, department: null, pay_currency: 'KES', errors: [], warnings: [], gross: money('6000000'), statutory: money('900000'), deductions: money('0'), loans: money('300000'), net: money('4800000'), employer: money('240000'), variance: { status: 'changed', previous_gross: money('5500000'), previous_net: money('4500000'), gross_diff: money('500000'), net_diff: money('300000'), gross_pct: '9.09', net_pct: '6.67' }, earnings: [], deduction_lines: [], statutory_lines: [], loan_lines: [], fx: [] },
      { id: 'l-2', employee: JOSEPH, department: null, pay_currency: 'KES', errors: [{ code: 'rate_missing', message: 'An exchange rate for the pay date is missing. Add it in exchange rates.' }], warnings: [], gross: money('4000000'), statutory: money('600000'), deductions: money('200000'), loans: money('0'), net: money('3200000'), employer: money('160000'), variance: { status: 'new' }, earnings: [], deduction_lines: [], statutory_lines: [], loan_lines: [], fx: [] },
    ]
    setup([['payroll/runs/r-1', { data: run }], [/^payroll\/runs\/r-1\/lines/, page(lines)], [/^document-workflows\//, apiError(404, 'not_found', 'Not found.')]], [PERM.runView, PERM.runPrepare, PERM.payView])
    renderApp('/payroll/runs/r-1')

    const totalsTable = await screen.findByRole('table', { name: 'Totals by currency' })
    const row = within(totalsTable).getAllByRole('row')[1]
    // Net 80,000.00 against 75,000.00: +5,000.00, +6.67%; gross +11.11%.
    expect(row).toHaveTextContent('80,000.00')
    expect(row).toHaveTextContent('75,000.00')
    expect(row).toHaveTextContent('5,000.00')
    expect(row).toHaveTextContent('+6.67%')
    expect(row).toHaveTextContent('+11.11%')
    expect(screen.getByText('EMP-00009 · Peter Otieno')).toBeInTheDocument()

    const linesTable = await screen.findByRole('table', { name: 'Lines' })
    expect(await within(linesTable).findByText('Joseph Mwangi')).toBeInTheDocument()
    expect(within(linesTable).getByText('1 error').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')
    expect(within(linesTable).getByText('New')).toBeInTheDocument()
    expect(within(linesTable).getByText('+6.67%')).toBeInTheDocument()

    // A line with errors blocks submission (PAY-14).
    expect(screen.getByRole('button', { name: 'Submit for approval' })).toBeDisabled()
    expect(screen.getByText('1 line has errors. Fix it and calculate again before you submit.')).toBeInTheDocument()

    // A line opens its breakdown, errors first.
    fireEvent.click(within(linesTable).getByText('Joseph Mwangi'))
    const dialog = await screen.findByRole('dialog', { name: 'EMP-00002 · Joseph Mwangi' })
    expect(within(dialog).getByText('An exchange rate for the pay date is missing. Add it in exchange rates.')).toBeInTheDocument()
  })

  it('shows an approver department totals, the variance summary and what to review, not the lines (payroll.md decisions 29, 33, 36, 40)', async () => {
    const totals = {
      currencies: [{ currency: 'KES', employees: 4, gross: money('20000000'), statutory: money('0'), deductions: money('0'), loans: money('0'), net: money('16000000'), employer: money('0'), previous: null }],
      base: null,
      previous_run_id: 'r-0',
      gone: [],
      departments: [
        { department: { id: 'd-1', name: 'Sales' }, name: 'Sales', currency: 'KES', employees: 3, gross: money('15000000'), net: money('12000000'), employer: money('0') },
        { department: null, name: null, currency: 'KES', employees: 1, gross: money('5000000'), net: money('4000000'), employer: money('0') },
      ],
      variance: { new: 1, gone: 0, changed: 2, changed_over_10_pct: 1 },
    }
    const review = {
      attendance_open: [{ start_date: '2026-10-01', end_date: '2026-10-31' }],
      late_changes: [{ number: 'EMP-00001', name: 'Grace Wanjiru', run_number: 'PR-2026-00001' }],
      no_pay_group: [{ number: 'EMP-00007', name: 'Baraka Kip', hire_date: '2026-10-02' }],
    }
    const run = { ...RUN, status: 'pending', employees: 4, totals, review }
    setup([['payroll/runs/r-1', { data: run }], [/^document-workflows\//, apiError(404, 'not_found', 'Not found.')]], [PERM.runView, PERM.runApprove])
    renderApp('/payroll/runs/r-1')

    const departments = await screen.findByRole('table', { name: 'Totals by department' })
    expect(within(departments).getByText('Sales')).toBeInTheDocument()
    expect(within(departments).getByText('Company level')).toBeInTheDocument()
    expect(screen.getByText('Against the last posted run: 1 new, 0 gone, 2 with a different net pay (1 by more than 10%).')).toBeInTheDocument()
    expect(screen.getByText('An attendance period overlapping this run is still open. Its time is paid by a later run, as arrears, once it closes.')).toBeInTheDocument()
    expect(screen.getByText('HR changed leave or employment dates of 1 employee after run PR-2026-00001 was posted. Check their pay and make a correction if it was wrong.')).toBeInTheDocument()
    expect(screen.getByText('EMP-00007 · Baraka Kip')).toBeInTheDocument()
    expect(screen.getByText('Each employee’s pay is shown only to people who can view pay. Judge this run by its totals above.')).toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Lines' })).not.toBeInTheDocument()
    expect(api.get.mock.calls.some(([url]) => String(url).startsWith('payroll/runs/r-1/lines'))).toBe(false)
  })

  it('closes the correction dialog once the correction run opens (PAY-18)', async () => {
    const posted = { ...RUN, status: 'posted', totals: { currencies: [], base: null, previous_run_id: null, gone: [] } }
    const correction = { ...RUN, id: 'r-2', number: 'PR-2026-00004', kind: 'correction', corrects_run_id: 'r-1', correction_mode: 'reversal' }
    setup([['payroll/runs/r-1', { data: posted }], ['payroll/runs/r-2', { data: correction }]], [PERM.runView, PERM.runPrepare])
    api.post.mockResolvedValue({ data: correction })
    renderApp('/payroll/runs/r-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Correct run' }))
    const dialog = await screen.findByRole('dialog', { name: 'Correct run PR-2026-00003' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create correction run' }))
    expect(await screen.findByRole('heading', { level: 1, name: 'PR-2026-00004' })).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('payroll/runs/r-1/corrections', expect.any(Object))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('lists the statutory values the pack lacks and says runs are refused (PAY-08)', async () => {
    const pack = {
      country: 'KE',
      available: true,
      currency: 'KES',
      non_monthly: null,
      components: [
        { code: 'paye', name: 'PAYE', kind: 'income_tax', method: 'bands', required: true, when: null, in_force: true, effective_from: '2026-01-01', missing: [{ field: 'bands', name: 'bands' }, { field: 'rounding', name: 'rounding rule' }] },
        { code: 'insurance_relief', name: 'Insurance relief', kind: 'relief', method: 'relief_rate', required: false, when: 'insurance_premiums', in_force: false, effective_from: null, missing: [{ field: 'period', name: 'period in force' }] },
      ],
      missing: 'PAYE (KE): bands, rounding rule; Insurance relief (KE): period in force',
      ready: false,
      todo: ['paye: the monthly PAYE bands.'],
      returns: [{ key: 'p10', name: 'PAYE return', needs_confirmation: true }],
    }
    setup([['payroll/statutory/c-1', { data: pack }], ['payroll/settings/c-1', { data: { company_id: 'c-1', overtime_multiplier: null, night_multiplier: null, holiday_multiplier: null, rest_day_multiplier: null, monthly_hours: null, monthly_days: null, premiums_taxable: true, premiums_contributory: true } }]], [PERM.settingsView])
    const { unmount } = renderApp('/payroll/settings')
    // On every tab: the warning and the way to the statutory rates.
    expect(await screen.findByText('Statutory rates are not confirmed yet')).toBeInTheDocument()
    expect(await screen.findByLabelText('Overtime multiplier')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Save settings' })).not.toBeInTheDocument()
    unmount()

    renderApp('/payroll/settings?tab=statutory')
    const table = await screen.findByRole('table', { name: 'Statutory rates in force' })
    const paye = within(table).getByRole('row', { name: /PAYE/ })
    expect(within(paye).getByText('2 values missing').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')
    expect(within(paye).getByText('bands, rounding rule')).toBeInTheDocument()
    expect(within(table).getByText('When an employee pays insurance premiums')).toBeInTheDocument()
    expect(screen.getByText('PAYE (KE): bands, rounding rule; Insurance relief (KE): period in force')).toBeInTheDocument()
    expect(screen.getByText(/Runs for this company are refused until the platform team confirms/)).toBeInTheDocument()
    expect(screen.getByText('Layout to be confirmed')).toBeInTheDocument()
  })

  it('lists my payslips and shows My payslips in the sidebar (PAY-24)', async () => {
    const payslip = { id: 'ps-1', number: 'PS-2026-00011', run: { id: 'r-1', number: 'PR-2026-00003', kind: 'regular', period_start: '2026-10-01', period_end: '2026-10-31', pay_date: '2026-10-31' }, employee: GRACE, issued_at: '2026-10-31T09:00:00Z', gross: money('6000000'), net: money('4800000') }
    setup([['hr/me', { data: { id: 'e-1', number: 'EMP-00001', name: 'Grace Achieng' } }], [/^payroll\/me\/payslips\?/, page([payslip])]], [])
    api.download.mockResolvedValue({ blob: new Blob(['%PDF']), filename: 'PS-2026-00011.pdf' })
    window.URL.createObjectURL = vi.fn(() => 'blob:payslip')
    window.URL.revokeObjectURL = vi.fn()
    renderApp('/payroll/me/payslips')
    const table = await screen.findByRole('table')
    expect(await within(table).findByText('PS-2026-00011')).toBeInTheDocument()
    expect(within(table).getByText('48,000.00')).toBeInTheDocument()
    expect((await screen.findAllByRole('link', { name: 'My payslips' })).length).toBeGreaterThan(0)
    fireEvent.click(within(table).getByRole('button', { name: 'PDF' }))
    await waitFor(() => expect(api.download).toHaveBeenCalledWith('payroll/me/payslips/ps-1/pdf'))
    // Self-service never reads the back office's list.
    expect(api.get).not.toHaveBeenCalledWith(expect.stringMatching(/^payroll\/payslips/))
  })

  it('tells a user who is not linked to an employee what to ask for (PAY-24)', async () => {
    setup([['hr/me', apiError(404, 'not_an_employee', 'You are not linked to an employee.')], [/^payroll\/me\/payslips/, apiError(403, 'not_an_employee', 'Your account isn’t linked to an employee.')]], [])
    renderApp('/payroll/me/payslips')
    expect(await screen.findByText('Your account is not linked to an employee record')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'My payslips' })).not.toBeInTheDocument()
  })

  it('opens every Payroll page without errors', async () => {
    const loan = { id: 'lo-1', employee: GRACE, company_id: 'c-1', kind: 'loan', reference: 'LN-1', currency: 'KES', principal: money('5000000'), instalment: money('500000'), balance: money('4500000'), disbursed_on: '2026-09-01', starts_on: '2026-09-30', settle_next_run: false, status: 'active', note: null, entries: [{ id: 'en-1', kind: 'disbursement', amount: money('5000000'), run_id: null, entry_date: '2026-09-01', note: null }] }
    const employee = { ...GRACE, employee_id: 'e-1', status: 'active', company: { id: 'c-1', name: 'Amani Hotels' }, branch: null, location: null, department: null, hire_date: '2026-01-05', termination_date: null, pay_group: null, pay_currency: 'KES', payment_method: 'bank', dependants: 0, children: 0, on_hold: false, synced_at: null, contract: { type: 'permanent', pay_basis: 'monthly', pay: money('6000000'), hours_per_week: null, start_date: '2026-01-05', end_date: null } }
    const payslip = { id: 'ps-1', number: 'PS-2026-00011', run: { id: 'r-1', number: 'PR-2026-00003', kind: 'regular', period_start: '2026-10-01', period_end: '2026-10-31', pay_date: '2026-10-31' }, employee: GRACE, issued_at: '2026-10-31T09:00:00Z', line: { id: 'l-1', employee: GRACE, errors: [], warnings: [], gross: money('6000000'), net: money('4800000'), earnings: [{ code: 'basic', name: 'Basic pay', source: 'basic', amount: money('6000000'), taxable: true, contributory: true }], statutory_lines: [], deduction_lines: [], loan_lines: [], fx: [] } }
    setup(
      [
        ['payroll/loans/lo-1', { data: loan }],
        ['payroll/employees/pe-1', { data: employee }],
        ['payroll/payslips/ps-1', { data: payslip }],
        ['payroll/statutory/c-1', { data: { country: 'KE', available: false } }],
        ['payroll/settings/c-1', { data: { company_id: 'c-1', overtime_multiplier: '1.5', night_multiplier: null, holiday_multiplier: '2', rest_day_multiplier: null, monthly_hours: '225', monthly_days: '26', premiums_taxable: true, premiums_contributory: true } }],
        [/^payroll\/returns\/types/, { data: [{ key: 'p10', name: 'PAYE return', period: 'month', components: ['paye'], needs_confirmation: true }] }],
        [/^payroll\//, page([])],
        [/^tenant\/currencies/, { data: [] }],
      ],
      Object.values(PERM),
    )
    const pages = [
      ['/payroll/runs', 'Payroll runs'],
      ['/payroll/employees', 'Pay setup'],
      ['/payroll/employees/pe-1', 'Grace Achieng'],
      ['/payroll/loans', 'Loans and advances'],
      ['/payroll/loans/lo-1', 'LN-1'],
      ['/payroll/payslips', 'Payslips'],
      ['/payroll/payslips/ps-1', 'PS-2026-00011'],
      ['/payroll/returns', 'Statutory returns'],
      ['/payroll/settings', 'Payroll settings'],
    ]
    for (const [path, heading] of pages) {
      const { unmount } = renderApp(path)
      expect(await screen.findByRole('heading', { level: 1, name: heading })).toBeInTheDocument()
      unmount()
    }
    for (const tab of ['payGroups', 'items', 'statutory']) {
      const { unmount } = renderApp(`/payroll/settings?tab=${tab}`)
      expect(await screen.findByRole('heading', { level: 1, name: 'Payroll settings' })).toBeInTheDocument()
      unmount()
    }
  })

  it('has en and fr words for every value the API sends (L10N-02)', () => {
    // api/modules/Payroll/Models (Run, Loan, LoanEntry, PayrollEmployee, PayGroup, Item, EmployeeItem) and Runs/RunCalculator.php (earning sources).
    const sets = {
      statuses: [...RUN_STATUSES, ...LOAN_STATUSES, ...EMPLOYEE_STATUSES, 'archived', 'on_hold', 'consumed'],
      stages: RUN_STAGES,
      kinds: RUN_KINDS,
      frequencies: FREQUENCIES,
      methods: PAYMENT_METHODS,
      itemKinds: ITEM_KINDS,
      recurrences: RECURRENCES,
      loanKinds: LOAN_KINDS,
      entryKinds: ['disbursement', 'repayment', 'settlement', 'reversal'],
      correctionModes: CORRECTION_MODES,
      settleMethods: SETTLE_METHODS,
      sources: ['basic', 'leave', 'attendance', 'item', 'statutory'],
    }
    for (const messages of [en, fr]) {
      for (const [group, values] of Object.entries(sets)) {
        for (const value of values) expect(messages.payroll[group][value], `payroll.${group}.${value}`).toBeTruthy()
      }
    }
  })

  it('works out variances in integers, rounded to two decimals (PAY-15)', () => {
    expect(variance(money('8000000'), money('7500000'))).toEqual({ diff: '500000', pct: '6.67' })
    expect(variance(money('7000000'), money('7500000'))).toEqual({ diff: '-500000', pct: '-6.67' })
    expect(variance(money('100'), money('0'))).toEqual({ diff: '100', pct: null })
    expect(variance(null, money('1'))).toBeNull()
  })
})
