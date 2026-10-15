// HR screens (docs/modules/hr.md, Screens): the employee list and its
// filters (HR-35), the profile hiding what the API leaves out (HR-09),
// confirming a statutory leave type's days (HR-12), the roster week grid
// (HR-18), approving timesheet days (HR-25), a device token shown once
// (HR-24) and the self-service clock button (HR-31).
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, optionTexts, waitForOption } from '@/test/combobox'
import { openFilters } from '@/test/filters'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { addDays, PERM, today, weekStart } from './hrData'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Hotels', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const named = (id, name) => ({ id, name })
const page = (data, meta = {}) => ({ data, meta: { total: data.length, last_page: 1, current_page: 1, from: 1, to: data.length, ...meta } })
const GRACE = { id: 'e-1', number: 'EMP-00001', name: 'Grace Achieng' }
const JOSEPH = { id: 'e-2', number: 'EMP-00002', name: 'Joseph Mwangi' }

const EMPLOYEE = {
  ...GRACE,
  first_name: 'Grace',
  middle_name: null,
  last_name: 'Achieng',
  gender: 'female',
  company: named('c-1', 'Amani Hotels'),
  branch: named('br-1', 'Westlands'),
  location: null,
  department: { id: 'd-1', code: 'KIT', name: 'Kitchen' },
  position: { id: 'p-1', code: 'COOK', title: 'Cook' },
  reports_to: null,
  user: null,
  status: 'active',
  hire_date: '2026-01-05',
  termination_date: null,
  termination_reason: null,
  work_email: 'grace@example.com',
  work_phone: null,
  personal_email: null,
  personal_phone: '+254700000001',
  address: null,
  emergency_contacts: [{ name: 'Peter Achieng', relationship: 'Brother', phone: '+254700000002' }],
  clock_methods: ['till', 'web'],
  biometric_ref: null,
  custom: {},
  created_at: '2026-01-05T06:00:00Z',
}

const PLACES = [
  [/^companies\?/, { data: [COMPANY] }],
  [/^branches\?/, { data: [{ id: 'br-1', name: 'Westlands', company_id: 'c-1', archived_at: null }] }],
  [/^locations\?/, { data: [{ id: 'l-1', name: 'Front desk', branch_id: 'br-1', archived_at: null }] }],
]

function setup(routes, permissions, { modules = ['core', 'hr'] } = {}) {
  mockRoutes(api, [...routes, ...PLACES], { permissions: tenantWide(['core.company.view', ...permissions]), modules, companies: [COMPANY] })
}

describe('HR screens', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists active employees and filters them by status (HR-35)', async () => {
    setup([[/^hr\/employees\?/, page([EMPLOYEE])]], ['hr.employee.view', 'hr.employee.create'])
    renderApp('/hr/employees')
    const table = await screen.findByRole('table')
    expect(await within(table).findByText('Grace Achieng')).toBeInTheDocument()
    expect(within(table).getByText('Kitchen')).toBeInTheDocument()
    expect(within(table).getByText('Cook')).toBeInTheDocument()
    expect(within(table).getByText('Active').closest('[data-tone]')).toHaveAttribute('data-tone', 'success')
    expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^hr\/employees\?status=active&/))
    expect(screen.getByRole('button', { name: 'Hire employee' })).toBeInTheDocument()

    const drawer = openFilters()
    chooseOption(within(drawer).getByLabelText('Status'), 'Terminated')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^hr\/employees\?status=terminated&/)))
  })

  it('hides the sensitive tabs and fields the API leaves out (HR-09)', async () => {
    // A reader without the identity, bank, medical and disciplinary groups: the keys are absent.
    const access = Object.fromEntries(['identity', 'bank', 'pay', 'medical', 'disciplinary'].map((group) => [group, { view: false, edit: false }]))
    setup([['hr/employees/e-1', { data: { ...EMPLOYEE, has_clock_pin: false, access }, meta: { identifier_types: [] } }]], ['hr.employee.view'])
    renderApp('/hr/employees/e-1')
    expect(await screen.findByRole('heading', { name: 'Grace Achieng' })).toBeInTheDocument()
    const tabs = screen.getAllByRole('tab').map((tab) => tab.textContent)
    expect(tabs).toEqual(['Overview', 'Personal'])

    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Personal' }), { button: 0 })
    expect(await screen.findByText('Peter Achieng')).toBeInTheDocument()
    expect(screen.getByText('+254700000001')).toBeInTheDocument()
    expect(screen.queryByText('Date of birth')).not.toBeInTheDocument()
    expect(screen.queryByText('Medical notes')).not.toBeInTheDocument()
    // Without `hr.employee.edit` the record is read only; without `hr.employee.link` no user is linked.
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Link a user' })).not.toBeInTheDocument()
  })

  it('shows identifiers in full and the date of birth when the API includes them (HR-03, HR-09)', async () => {
    const access = { identity: { view: true, edit: true }, bank: { view: false, edit: false }, pay: { view: false, edit: false }, medical: { view: false, edit: false }, disciplinary: { view: false, edit: false } }
    const employee = {
      ...EMPLOYEE,
      date_of_birth: '1990-04-12',
      identifiers: [{ type: 'national_id', label: 'National ID', value: '12345678', masked: '•••• 5678' }],
      pay_accounts: [{ id: 'pa-1', kind: 'mobile_money', provider: 'M-Pesa', account_name: 'Grace Achieng', account_number: null, masked: '•••• 0001', currency: 'KES', is_primary: true, archived_at: null }],
      has_clock_pin: true,
      access,
    }
    setup([['hr/employees/e-1', { data: employee, meta: { identifier_types: [{ key: 'national_id', label: 'National ID', required: true }, { key: 'kra_pin', label: 'KRA PIN', required: true }] } }]], ['hr.employee.view', 'hr.identity.view', 'hr.identity.edit'])
    renderApp('/hr/employees/e-1?tab=identity')
    expect(await screen.findByText('12345678')).toBeInTheDocument()
    expect(screen.getByText('KRA PIN')).toBeInTheDocument()
    expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual(['Overview', 'Personal', 'Identity', 'Pay accounts'])

    // The pay accounts come masked from the profile: bank view is not held.
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Pay accounts' }), { button: 0 })
    expect(await screen.findByText('•••• 0001')).toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('hr/employees/e-1/pay-accounts')

    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Personal' }), { button: 0 })
    expect(await screen.findByText('Date of birth')).toBeInTheDocument()
  })

  it('confirms the days of a statutory leave type marked Days needed (HR-12)', async () => {
    const annual = { id: 'lt-1', company_id: 'c-1', code: 'ANNUAL', name: 'Annual leave', statutory_key: 'annual', statutory_label: 'Annual leave', paid: true, accrual: 'annual', entitlement_days: null, days_needed: true, count_basis: 'working_days', carry_over_max_days: null, allow_negative: false, requires_document: false, accrues_from: null, archived_at: null }
    setup([[/^hr\/leave-types\?/, page([annual])]], ['hr.leave.view', 'hr.leave.manage'])
    api.patch.mockResolvedValue({ data: { ...annual, entitlement_days: '21.00', days_needed: false } })
    renderApp('/hr/leave/types')
    const table = await screen.findByRole('table')
    expect(await within(table).findByText('Days needed')).toBeInTheDocument()
    expect(within(table).getByText('Days needed').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(screen.getByRole('button', { name: 'Add statutory types' })).toBeInTheDocument()

    fireEvent.click(within(table).getByRole('button', { name: 'Enter days' }))
    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: 'Confirm days' })
    fireEvent.change(within(dialog).getByLabelText(/^Days per year/), { target: { value: '21' } })
    // Days are never confirmed by accident: the check is required.
    expect(confirm).toBeDisabled()
    fireEvent.click(within(dialog).getByLabelText('I have checked these days against the law and our policy'))
    fireEvent.click(confirm)
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('hr/leave-types/lt-1', { entitlement_days: '21', confirm: true }))
  })

  it('lays the week out by employee and day, with holidays marked (HR-18)', async () => {
    const monday = weekStart(today())
    const shift = (id, employee, date, code, holiday = false) => ({
      id,
      employee,
      date,
      shift: { id: `s-${code}`, code, name: code },
      start_at: `${date}T05:00:00Z`,
      end_at: `${date}T13:00:00Z`,
      break_minutes: 30,
      source: 'generated',
      roster_id: 'r-1',
      status: 'planned',
      holiday,
    })
    setup(
      [
        [/^hr\/roster-shifts\?/, { data: [shift('rs-1', GRACE, monday, 'MORN'), shift('rs-2', JOSEPH, addDays(monday, 2), 'NIGHT', true)], meta: { from: monday, to: addDays(monday, 6) } }],
        [/^companies\/c-1\/departments/, { data: [] }],
      ],
      ['hr.roster.view'],
    )
    renderApp('/hr/rosters/week')
    const grid = await screen.findByRole('table', { name: 'Planned shifts of the week' })
    expect(api.get).toHaveBeenCalledWith(`hr/roster-shifts?from=${monday}&to=${addDays(monday, 6)}&company=c-1`)
    const grace = within(grid).getByRole('row', { name: /Grace Achieng/ })
    const joseph = within(grid).getByRole('row', { name: /Joseph Mwangi/ })
    const graceDays = within(grace).getAllByRole('cell')
    const josephDays = within(joseph).getAllByRole('cell')
    expect(graceDays).toHaveLength(7)
    expect(graceDays[0]).toHaveTextContent('MORN')
    expect(graceDays[2]).toHaveTextContent('')
    expect(josephDays[2]).toHaveTextContent('NIGHT')
    expect(josephDays[2]).toHaveTextContent('Public holiday')
    // The column of the holiday says so too.
    expect(within(grid).getAllByRole('columnheader')[3]).toHaveTextContent('Public holiday')
    // Read only without hr.roster.manage.
    expect(screen.queryByRole('button', { name: 'Add shift' })).not.toBeInTheDocument()
  })

  it('approves the selected timesheet days and shows exceptions as dots and words (HR-25)', async () => {
    const day = (id, employee, exceptions) => ({ id, employee, company_id: 'c-1', work_date: '2026-10-12', roster_shift_id: null, first_in: '2026-10-12T05:20:00Z', last_out: '2026-10-12T13:00:00Z', status: 'present', exceptions, approved_at: null, period_id: null, worked_minutes: 430, regular_minutes: 430, overtime_minutes: 0, night_minutes: 0, holiday_minutes: 0, rest_day_minutes: 0, late_minutes: 20, early_leave_minutes: 0 })
    setup([[/^hr\/timesheets\?/, page([day('ad-1', GRACE, ['late']), day('ad-2', JOSEPH, ['missing_in', 'no_time_rule'])])]], ['hr.attendance.view', 'hr.attendance.manage'])
    api.post.mockResolvedValue({ data: { approved: 1 } })
    renderApp('/hr/attendance/timesheets')
    const table = await screen.findByRole('table')
    // The column is called Late too: the exception is the badge.
    expect(await within(table).findByText('Late', { selector: '[data-tone]' })).toHaveAttribute('data-tone', 'warning')
    expect(within(table).getAllByText('7:10')).toHaveLength(2)
    expect(within(table).getByText('No clock-in', { selector: '[data-tone]' })).toHaveAttribute('data-tone', 'warning')
    expect(within(table).getByText('No time rule', { selector: '[data-tone]' })).toHaveAttribute('data-tone', 'warning')
    // L10N-03: 05:20 UTC is 08:20 in the company's zone (Africa/Nairobi).
    expect(within(table).getAllByText(/^08:20/)).toHaveLength(2)

    const approve = screen.getByRole('button', { name: 'Approve days' })
    expect(approve).toBeDisabled()
    fireEvent.click(within(table).getByLabelText(/^Select Grace Achieng on/))
    expect(screen.getByText('1 day selected')).toBeInTheDocument()
    fireEvent.click(approve)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hr/timesheets/approve', { day_ids: ['ad-1'] }))
    expect(await screen.findByText('1 day approved')).toBeInTheDocument()

    // HR-25: a day is never "rest" any more; the status filter does not offer it.
    const statuses = optionTexts(within(openFilters()).getByLabelText('Status'))
    expect(statuses).toContain('Incomplete')
    expect(statuses).not.toContain('Rest day')
  })

  it('shows a new device token once, and never again (HR-24)', async () => {
    setup([[/^hr\/attendance-devices\?/, page([])]], ['hr.device.manage'])
    api.post.mockResolvedValue({ data: { id: 'dev-1', company_id: 'c-1', location_id: 'l-1', name: 'Main gate', vendor: null, status: 'active', last_seen_at: null }, meta: { token: '7|plain-device-token' } })
    renderApp('/hr/attendance/devices')
    fireEvent.click(await screen.findByRole('button', { name: 'Add device' }))
    const dialog = await screen.findByRole('dialog')
    chooseOption(within(dialog).getByLabelText(/^Location/), 'Front desk')
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Main gate' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add device' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hr/attendance-devices', { location_id: 'l-1', name: 'Main gate', vendor: null }))

    const shown = await screen.findByRole('dialog', { name: 'Token of Main gate' })
    expect(within(shown).getByText('7|plain-device-token')).toBeInTheDocument()
    expect(within(shown).getByText('Copy this token into the device or its bridge now. It will not be shown again.')).toBeInTheDocument()
    fireEvent.click(within(shown).getByRole('button', { name: 'Done' }))
    await waitFor(() => expect(screen.queryByText('7|plain-device-token')).not.toBeInTheDocument())
  })

  it('clocks a linked employee in from self-service, and shows My HR (HR-31)', async () => {
    setup(
      [
        ['hr/me', { data: { ...EMPLOYEE, has_clock_pin: false } }],
        [/^hr\/me\/attendance\?/, { data: { days: [], punches: [], can_clock: true, next_kind: 'in' } }],
      ],
      [],
    )
    api.post.mockResolvedValue({ data: { id: 'pu-1', employee: GRACE, kind: 'in', occurred_at: '2026-10-14T05:02:00Z', source: 'web', flags: [] } })
    renderApp('/hr/me/attendance')
    const clockIn = await screen.findByRole('button', { name: 'Clock in' })
    // The one decisive action of the page.
    expect(clockIn).toHaveAttribute('data-ds-variant', 'pay')
    expect((await screen.findAllByRole('link', { name: 'My attendance' })).length).toBeGreaterThan(0)
    fireEvent.click(clockIn)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hr/me/clock', { kind: 'in' }))
  })

  it('tells a user who is not linked to an employee what to ask for, without My HR (HR-31)', async () => {
    setup([['hr/me', apiError(404, 'not_an_employee', 'You are not linked to an employee.')]], [])
    renderApp('/hr/me/attendance')
    expect(await screen.findByText('Your account is not linked to an employee record')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Clock in' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'My attendance' })).not.toBeInTheDocument()
  })

  it('opens every HR page and every profile tab without errors', async () => {
    const all = Object.fromEntries(['identity', 'bank', 'pay', 'medical', 'disciplinary'].map((group) => [group, { view: true, edit: true }]))
    const full = { ...EMPLOYEE, date_of_birth: '1990-04-12', medical_notes: 'Asthma', identifiers: [], pay_accounts: [], has_clock_pin: true, access: all }
    const roster = { id: 'r-1', company_id: 'c-1', name: 'Kitchen A', branch: named('br-1', 'Westlands'), department: null, rotation_id: 'ro-1', anchor_date: '2026-10-05', archived_at: null, members: [{ employee: GRACE, offset_days: 0, from_date: null, to_date: null }] }
    const record = { id: 'dc-1', number: 'DC-2026-00001', employee: GRACE, category: 'written_warning', incident_date: '2026-10-01', description: 'Late three times.', action_taken: null, status: 'open', outcome: null, closed_at: null, created_at: '2026-10-02T06:00:00Z' }
    const appraisal = { id: 'ap-1', cycle: { id: 'cy-1', name: '2026', status: 'open' }, employee: GRACE, reviewer: named('u-1', 'Amina Otieno'), goals: [{ title: 'Food cost', weight: 50, rating: 4, comment: null }], self_rating: 4, self_comments: 'Good year.', manager_rating: null, manager_comments: null, overall_rating: null, status: 'manager_review', completed_at: null, acknowledged_at: null }
    setup(
      [
        ['hr/employees/e-1', { data: full, meta: { identifier_types: [{ key: 'national_id', label: 'National ID', required: true }] } }],
        ['hr/employees/e-1/pay-accounts', { data: [] }],
        ['hr/rosters/r-1', { data: roster }],
        ['hr/disciplinary-cases/dc-1', { data: record }],
        ['hr/appraisals/ap-1', { data: appraisal }],
        ['hr/me', { data: { ...EMPLOYEE, identifiers: [], pay_accounts: [] } }],
        ['hr/me/leave', { data: { balances: [], requests: [] } }],
        [/^hr\/me\/attendance\?/, { data: { days: [], punches: [], can_clock: false } }],
        [/^hr\/leave-balances\?/, { data: [{ employee: GRACE, company_id: 'c-1', balances: [{ leave_type: { id: 'lt-1', code: 'ANNUAL', name: 'Annual leave' }, days_needed: false, tracked: true, entitled: '21.00', taken: '2.00', balance: '19.00', pending: '1.00', available: '18.00' }] }], meta: { as_of: '2026-10-14', current_page: 1, last_page: 1, per_page: 50, total: 1 } }],
        [/^hr\/leave-calendar\?/, { data: [{ id: 'lr-1', number: 'LV-2026-00001', employee: GRACE, leave_type: { id: 'lt-1', code: 'ANNUAL', name: 'Annual leave' }, start_date: `${today().slice(0, 8)}01`, end_date: `${today().slice(0, 8)}03`, days: '3.00', status: 'approved' }] }],
        [/^hr\//, page([])],
        [/^(users|tenant\/currencies|companies\/c-1\/departments)/, { data: [] }],
      ],
      Object.values(PERM),
    )
    const pages = [
      ['/hr/positions', 'Positions'],
      ['/hr/leave/requests', 'Leave'],
      ['/hr/leave/balances', 'Leave'],
      ['/hr/leave/calendar', 'Leave'],
      ['/hr/rosters/list', 'Rosters'],
      ['/hr/rosters/list/r-1', 'Kitchen A'],
      ['/hr/rosters/setup', 'Rosters'],
      ['/hr/rosters/swaps', 'Rosters'],
      ['/hr/attendance/punches', 'Attendance'],
      ['/hr/attendance/periods', 'Attendance'],
      ['/hr/attendance/rules', 'Attendance'],
      ['/hr/onboarding', 'Onboarding and offboarding'],
      ['/hr/disciplinary', 'Disciplinary cases'],
      ['/hr/disciplinary/dc-1', 'DC-2026-00001'],
      ['/hr/appraisals', 'Appraisals'],
      ['/hr/appraisals/ap-1', 'Grace Achieng'],
      ['/hr/employees/new', 'Hire employee'],
      ['/hr/me/profile', 'My profile'],
      ['/hr/me/leave', 'My leave'],
      ['/hr/me/roster', 'My roster'],
      ['/hr/me/swaps', 'My shift swaps'],
      ['/hr/me/appraisals', 'My appraisals'],
    ]
    for (const [path, heading] of pages) {
      const { unmount } = renderApp(path)
      expect(await screen.findByRole('heading', { level: 1, name: heading })).toBeInTheDocument()
      unmount()
    }
    const { unmount } = renderApp('/hr/leave')
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Requests', selected: true })).toBeInTheDocument())
    unmount()

    renderApp('/hr/employees/e-1')
    expect(await screen.findByRole('heading', { name: 'Grace Achieng' })).toBeInTheDocument()
    const names = screen.getAllByRole('tab').map((tab) => tab.textContent)
    expect(names).toEqual(['Overview', 'Personal', 'Identity', 'Pay accounts', 'Contracts', 'Leave', 'Attendance', 'Documents', 'Checklists', 'Disciplinary', 'Appraisals'])
    for (const name of names.slice(1)) {
      fireEvent.mouseDown(screen.getByRole('tab', { name }), { button: 0 })
      await waitFor(() => expect(screen.getByRole('tab', { name, selected: true })).toBeInTheDocument())
    }
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Personal' }), { button: 0 })
    expect(await screen.findByText('Asthma')).toBeInTheDocument()
  })

  it('offers Clock out when the API says the next punch is out (HR-22, HR-31)', async () => {
    const punched = { id: 'pu-1', employee: GRACE, company_id: 'c-1', kind: 'in', occurred_at: '2026-10-14T05:02:00Z', source: 'web', flags: [] }
    setup(
      [
        ['hr/me', { data: { ...EMPLOYEE, has_clock_pin: false } }],
        [/^hr\/me\/attendance\?/, { data: { days: [], punches: [punched], can_clock: true, next_kind: 'out' } }],
      ],
      [],
    )
    api.post.mockResolvedValue({ data: { ...punched, id: 'pu-2', kind: 'out', occurred_at: '2026-10-14T14:00:00Z' } })
    renderApp('/hr/me/attendance')
    fireEvent.click(await screen.findByRole('button', { name: 'Clock out' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hr/me/clock', { kind: 'out' }))
  })

  it('asks a colleague from the swap peers to swap shifts (HR-19, HR-31)', async () => {
    const date = addDays(today(), 3)
    const mine = { id: 'rs-1', employee: GRACE, date, shift: { id: 's-1', code: 'MORN', name: 'Morning' }, start_at: `${date}T05:00:00Z`, end_at: `${date}T13:00:00Z`, status: 'planned', holiday: false }
    const theirs = { id: 'rs-9', date: addDays(date, 1), shift: { id: 's-2', code: 'EVE', name: 'Evening' }, start_at: `${addDays(date, 1)}T13:00:00Z`, end_at: `${addDays(date, 1)}T21:00:00Z` }
    setup(
      [
        ['hr/me', { data: { ...EMPLOYEE, has_clock_pin: false } }],
        ['hr/me/swaps', { data: [] }],
        ['hr/me/swap-peers', { data: [{ ...JOSEPH, shifts: [theirs] }] }],
        [/^hr\/me\/roster\?/, { data: [mine] }],
      ],
      [],
    )
    api.post.mockResolvedValue({ data: { id: 'sw-1', status: 'awaiting_peer' } })
    renderApp('/hr/me/swaps')
    fireEvent.click(await screen.findByRole('button', { name: 'Ask for a swap' }))
    const dialog = await screen.findByRole('dialog')
    await waitForOption(within(dialog).getByLabelText(/^My shift/), /MORN/)
    chooseOption(within(dialog).getByLabelText(/^My shift/), /MORN/)
    await waitForOption(within(dialog).getByLabelText(/^Colleague(?!’)/), 'Joseph Mwangi · EMP-00002')
    chooseOption(within(dialog).getByLabelText(/^Colleague(?!’)/), 'Joseph Mwangi · EMP-00002')
    chooseOption(within(dialog).getByLabelText(/^Colleague’s shift/), /EVE/)
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send request' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hr/me/swaps', { roster_shift_id: 'rs-1', peer_employee_id: 'e-2', peer_roster_shift_id: 'rs-9', reason: null }))
    // Back-office lists are never needed for it.
    expect(api.get).not.toHaveBeenCalledWith(expect.stringMatching(/^hr\/(employees|roster-shifts)\?/))
  })

  it('counts a closed period’s employees and shows their totals in hours (HR-26)', async () => {
    const totals = [{ employee_id: 'e-1', days_present: 20, days_absent: 1, days_leave: 2, worked_minutes: 9630, regular_minutes: 9000, overtime_minutes: 630, night_minutes: 0, holiday_minutes: 480, rest_day_minutes: 0, late_minutes: 45, early_leave_minutes: 0 }]
    const period = { id: 'pe-1', company_id: 'c-1', start_date: '2026-09-01', end_date: '2026-09-30', status: 'closed', closed_at: '2026-10-01T06:00:00Z', totals }
    setup([[/^hr\/attendance-periods\?/, page([period])], [/^hr\/employees\?/, page([EMPLOYEE])]], ['hr.attendance.view'])
    renderApp('/hr/attendance/periods')
    const table = await screen.findByRole('table')
    const row = await within(table).findByRole('row', { name: /Closed/ })
    expect(within(row).getAllByRole('cell')[2]).toHaveTextContent('1')
    fireEvent.click(within(row).getByRole('button', { name: 'View totals' }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('Grace Achieng')).toBeInTheDocument()
    expect(within(dialog).getByText('160:30')).toBeInTheDocument()
    expect(within(dialog).getByText('10:30')).toBeInTheDocument()
    expect(within(dialog).getByText('0:45')).toBeInTheDocument()
  })

  it('links a user only with hr.employee.link and shows link_self under the user picker (HR-02, AUTH-12)', async () => {
    setup([], ['hr.employee.view', 'hr.employee.create'])
    const { unmount } = renderApp('/hr/employees/new')
    expect(await screen.findByRole('heading', { level: 1, name: 'Hire employee' })).toBeInTheDocument()
    expect(screen.queryByLabelText(/^User/)).not.toBeInTheDocument()
    unmount()

    const access = Object.fromEntries(['identity', 'bank', 'pay', 'medical', 'disciplinary'].map((group) => [group, { view: false, edit: false }]))
    setup(
      [
        ['hr/employees/e-1', { data: { ...EMPLOYEE, has_clock_pin: false, access }, meta: { identifier_types: [] } }],
        ['users?status=active&per_page=200', { data: [{ id: 'u-1', name: 'Amina Otieno', email: 'amina@example.com' }] }],
        [/^companies\/c-1\/departments/, { data: [] }],
        [/^hr\//, page([])],
      ],
      ['hr.employee.view', 'hr.employee.edit', 'hr.employee.link'],
    )
    api.patch.mockRejectedValue(apiError(422, 'link_self', 'You cannot link your own user to an employee record.'))
    renderApp('/hr/employees/e-1')
    expect(await screen.findByRole('button', { name: 'Link a user' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog')
    await waitForOption(within(dialog).getByLabelText(/^User/), 'Amina Otieno · amina@example.com')
    chooseOption(within(dialog).getByLabelText(/^User/), 'Amina Otieno · amina@example.com')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('hr/employees/e-1', expect.objectContaining({ user_id: 'u-1' })))
    const message = await within(dialog).findByText('You cannot link your own user to an employee record.')
    // Under the field, not in the form's alert.
    expect(message.id).toMatch(/-msg$/)
    expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument()
  })

  it('sends a manual punch with its company offset and shows window errors under the time (HR-22)', async () => {
    setup([[/^hr\/punches\?/, page([])], [/^hr\/employees\?/, page([EMPLOYEE])]], ['hr.attendance.view', 'hr.attendance.manage'])
    api.post.mockRejectedValue(apiError(422, 'punch_out_of_window', 'This punch is too far from the employee’s shift.'))
    renderApp('/hr/attendance/punches')
    fireEvent.click(await screen.findByRole('button', { name: 'Add punch' }))
    const dialog = await screen.findByRole('dialog')
    await waitForOption(within(dialog).getByLabelText(/^Employee/), 'EMP-00001 · Grace Achieng')
    chooseOption(within(dialog).getByLabelText(/^Employee/), 'EMP-00001 · Grace Achieng')
    fireEvent.change(within(dialog).getByLabelText(/^Date and time/), { target: { value: '2026-10-14T08:00' } })
    fireEvent.change(within(dialog).getByLabelText(/^Note/), { target: { value: 'Forgot to clock in' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add punch' }))
    // Africa/Nairobi is UTC+3: an explicit offset, never a naive local time.
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hr/punches', { employee_id: 'e-1', kind: 'in', occurred_at: '2026-10-14T08:00:00+03:00', note: 'Forgot to clock in' }))
    expect((await within(dialog).findByText('This punch is too far from the employee’s shift.')).id).toMatch(/-msg$/)
  })

  it('shows clock_pin_same_as_till under the PIN (HR-23)', async () => {
    const access = Object.fromEntries(['identity', 'bank', 'pay', 'medical', 'disciplinary'].map((group) => [group, { view: false, edit: false }]))
    setup([['hr/employees/e-1', { data: { ...EMPLOYEE, has_clock_pin: false, access }, meta: { identifier_types: [] } }], [/^hr\//, page([])]], ['hr.employee.view', 'hr.attendance.view', 'hr.attendance.manage'])
    api.put.mockRejectedValue(apiError(422, 'clock_pin_same_as_till', 'Choose a clock-in PIN different from the till sign-in PIN.'))
    renderApp('/hr/employees/e-1?tab=attendance')
    fireEvent.click(await screen.findByRole('button', { name: 'Set PIN' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^PIN/), { target: { value: '1234' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save PIN' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('hr/employees/e-1/clock-pin', { pin: '1234' }))
    expect((await within(dialog).findByText('Choose a clock-in PIN different from the till sign-in PIN.')).id).toMatch(/-msg$/)
  })

  it('shows reviewer_is_employee under the reviewer (HR-30)', async () => {
    const appraisal = { id: 'ap-1', cycle: { id: 'cy-1', name: '2026', status: 'open' }, employee: GRACE, reviewer: named('u-1', 'Amina Otieno'), goals: [], self_rating: null, self_comments: null, manager_rating: null, manager_comments: null, overall_rating: null, status: 'manager_review', completed_at: null, acknowledged_at: null }
    setup(
      [
        ['hr/appraisals/ap-1', { data: appraisal }],
        ['users?status=active&per_page=200', { data: [{ id: 'u-1', name: 'Amina Otieno' }, { id: 'u-9', name: 'Grace Achieng' }] }],
      ],
      ['hr.appraisal.view', 'hr.appraisal.manage'],
    )
    api.patch.mockRejectedValue(apiError(422, 'reviewer_is_employee', 'The reviewer cannot be the employee being appraised.'))
    renderApp('/hr/appraisals/ap-1')
    await waitForOption(await screen.findByLabelText(/^Reviewer/), 'Grace Achieng')
    chooseOption(screen.getByLabelText(/^Reviewer/), 'Grace Achieng')
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('hr/appraisals/ap-1', expect.objectContaining({ reviewer_id: 'u-9' })))
    expect((await screen.findByText('The reviewer cannot be the employee being appraised.')).id).toMatch(/-msg$/)
  })

  it('says why a period that has not ended cannot close (HR-26)', async () => {
    const period = { id: 'pe-2', company_id: 'c-1', start_date: '2026-10-01', end_date: '2026-10-31', status: 'open', closed_at: null, totals: null }
    setup([[/^hr\/attendance-periods\?/, page([period])]], ['hr.attendance.view', 'hr.attendance.close'])
    api.post.mockRejectedValue(apiError(422, 'period_not_ended', 'This period ends on 31 Oct 2026. Close it after that day.'))
    renderApp('/hr/attendance/periods')
    fireEvent.click(await screen.findByRole('button', { name: 'Close period' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Close period' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hr/attendance-periods/pe-2/close', {}))
    expect(await within(dialog).findByText('This period ends on 31 Oct 2026. Close it after that day.')).toBeInTheDocument()
  })
})
