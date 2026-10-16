// CRM-05..CRM-16, CRM-26..CRM-30, CRM-01..CRM-06: leads, opportunities, the calendar, targets and sales settings.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { ALL, BRANCH, COMPANY, LEAD, mockCrm, named, OPPORTUNITY, page, PIPELINE } from '@/test/crm'
import { mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

/** A record the API answers with, changed by the action under test (lists and details refetch after an action). */
function record(initial) {
  let current = initial
  return { answer: () => ({ data: current }), becomes: (next) => async () => { current = next; return { data: next } } }
}

const leadsByStatus = (leads) => (path) => {
  const status = new URLSearchParams(path.split('?')[1]).get('status')
  return page(leads.filter((lead) => !status || lead.status === status))
}

describe('Sales navigation (RBAC-08)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the Sales group while the CRM module is active', async () => {
    mockCrm(api, [[/^crm\/leads\?/, page([])]])
    renderApp('/crm/leads')
    const nav = await screen.findByRole('navigation', { name: /main/i })
    expect(await within(nav).findByRole('link', { name: 'Opportunities' })).toHaveAttribute('href', '/crm/opportunities')
    expect(await within(nav).findByRole('link', { name: 'Sales settings' })).toHaveAttribute('href', '/crm/settings')
  })

  it('hides the Sales group without the module', async () => {
    mockRoutes(api, [[/^companies\?/, { data: [COMPANY] }]], { permissions: ALL, modules: ['core'] })
    renderApp('/')
    await screen.findAllByText('Dashboard')
    expect(screen.queryByRole('link', { name: 'Opportunities' })).not.toBeInTheDocument()
  })
})

describe('Leads (CRM-05, CRM-10..CRM-13)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists leads with their status as a dot and a word, owner and source', async () => {
    mockCrm(api, [[/^crm\/leads\?/, page([LEAD])]])
    renderApp('/crm/leads')
    const row = (await screen.findByText('Wanjiku Kamau')).closest('tr')
    expect(within(row).getByText('New').closest('[data-tone]')).toHaveAttribute('data-tone', 'info')
    expect(within(row).getByText('Amina Otieno')).toBeInTheDocument()
    expect(within(row).getByText('Walk-in')).toBeInTheDocument()
    expect(within(row).getByText('50,000.00')).toBeInTheDocument()
  })

  it('moves a lead on the board to the next status (CRM-13)', async () => {
    const other = { ...LEAD, id: 'l-2', name: 'Otieno Hardware', status: 'contacted' }
    mockCrm(api, [[/^crm\/leads\?/, leadsByStatus([LEAD, other])]])
    api.patch.mockResolvedValue({ data: { ...LEAD, status: 'contacted' } })
    renderApp('/crm/leads?view=board')
    const contacted = await screen.findByRole('region', { name: 'Contacted' })
    expect(within(contacted).getByText('Otieno Hardware')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: 'New' })).getByText('Wanjiku Kamau')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Move Wanjiku Kamau to Contacted' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('crm/leads/l-1', { status: 'contacted' }))
    expect(screen.queryByRole('button', { name: /Move Wanjiku Kamau to Converted/ })).not.toBeInTheDocument()
  })

  it('moves a lead by dragging its card to another column (CRM-13)', async () => {
    mockCrm(api, [[/^crm\/leads\?/, leadsByStatus([LEAD])]])
    api.patch.mockResolvedValue({ data: { ...LEAD, status: 'qualified' } })
    renderApp('/crm/leads?view=board')
    const card = (await screen.findByText('Wanjiku Kamau')).closest('li')
    fireEvent.dragStart(card, { dataTransfer: { setData: () => {}, effectAllowed: '' } })
    const qualified = screen.getByRole('region', { name: 'Qualified' })
    fireEvent.dragOver(qualified)
    fireEvent.drop(qualified)
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('crm/leads/l-1', { status: 'qualified' }))
  })

  it('creates a lead at a branch with its estimated value in minor units and an owner (CRM-05, CRM-10)', async () => {
    mockCrm(api)
    api.post.mockResolvedValue({ data: LEAD })
    renderApp('/crm/leads/new')
    await waitForOption(await screen.findByLabelText(/^Branch/), BRANCH.name)
    chooseOption(screen.getByLabelText(/^Branch/), BRANCH.name)
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Wanjiku Kamau' } })
    fireEvent.change(screen.getByLabelText('Organisation'), { target: { value: 'Kamau Stores' } })
    fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '+254700000001' } })
    await waitForOption(screen.getByLabelText('Source'), 'Walk-in')
    chooseOption(screen.getByLabelText('Source'), 'Walk-in')
    await waitForOption(screen.getByLabelText('Owner'), 'Joseph Mwangi')
    chooseOption(screen.getByLabelText('Owner'), 'Joseph Mwangi')
    fireEvent.change(screen.getByLabelText('Estimated value'), { target: { value: '50000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create lead' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith(
        'crm/leads',
        expect.objectContaining({
          company_id: 'c-1',
          branch_id: 'br-1',
          channel: 'manual',
          name: 'Wanjiku Kamau',
          organisation: 'Kamau Stores',
          phone: '+254700000001',
          source_id: 'src-1',
          estimated_value_minor: '5000000',
          currency: 'KES',
          owner_id: 'u-2',
        }),
      ),
    )
  })

  it('converts a lead to a new customer and an opportunity in the default pipeline (CRM-12)', async () => {
    const lead = record({ ...LEAD, status: 'qualified' })
    mockCrm(api, [['crm/leads/l-1', lead.answer]])
    api.post.mockImplementation(lead.becomes({ ...LEAD, status: 'converted', party: named('pt-9', 'Kamau Stores'), opportunity: named('o-9', 'Kamau Stores') }))
    renderApp('/crm/leads/l-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Convert' }))
    const dialog = await screen.findByRole('dialog', { name: 'Convert Wanjiku Kamau' })
    expect(within(dialog).getByLabelText(/^Customer name/)).toHaveValue('Kamau Stores')
    await waitFor(() => expect(within(dialog).getByLabelText('Stage')).toHaveTextContent('Qualification'))
    fireEvent.change(within(dialog).getByLabelText('Expected close date'), { target: { value: '2026-12-15' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Convert lead' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('crm/leads/l-1/convert', {
        party: { kind: 'organisation', name: 'Kamau Stores', company_id: 'c-1', phones: [{ number: '+254700000001' }], emails: [{ address: 'wanjiku@kamau.example' }] },
        opportunity: { name: 'Kamau Stores', pipeline_id: 'pl-1', stage_id: 'st-1', value_minor: '5000000', currency: 'KES', expected_close_on: '2026-12-15' },
      }),
    )
    expect(await screen.findByText('Converted to a customer')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open Kamau Stores' })).toHaveAttribute('href', '/contacts/customers/pt-9?tab=sales')
  })

  it('converts to an existing customer without an opportunity (CRM-12)', async () => {
    mockCrm(api, [['crm/leads/l-1', { data: LEAD }]])
    api.post.mockResolvedValue({ data: { ...LEAD, status: 'converted', party: named('pt-1', 'Duka Moja') } })
    renderApp('/crm/leads/l-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Convert' }))
    const dialog = await screen.findByRole('dialog', { name: 'Convert Wanjiku Kamau' })
    chooseOption(within(dialog).getByLabelText('Customer record'), 'Use an existing customer')
    await waitForOption(within(dialog).getByLabelText(/^Customer(\s\*)?$/), 'Duka Moja')
    chooseOption(within(dialog).getByLabelText(/^Customer(\s\*)?$/), 'Duka Moja')
    fireEvent.click(within(dialog).getByLabelText('Also create an opportunity'))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Convert lead' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/leads/l-1/convert', { party_id: 'pt-1' }))
  })

  it('disqualifies a lead with a reason', async () => {
    const lead = record(LEAD)
    mockCrm(api, [['crm/leads/l-1', lead.answer]])
    api.post.mockImplementation(lead.becomes({ ...LEAD, status: 'disqualified', disqualify_reason: 'No budget' }))
    renderApp('/crm/leads/l-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Disqualify' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByRole('button', { name: 'Disqualify' })).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'No budget' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Disqualify' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/leads/l-1/disqualify', { reason: 'No budget' }))
    expect(await screen.findByText('No budget')).toBeInTheDocument()
  })
})

describe('Opportunities (CRM-14..CRM-16)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  const SUMMARY = {
    pipeline: named('pl-1', 'Sales'),
    stages: [
      { id: 'st-1', name: 'Qualification', probability: 10, count: 1, values: [{ currency: 'KES', amount_minor: '12000000', weighted_minor: '1200000' }] },
      { id: 'st-2', name: 'Proposal', probability: 50, count: 0, values: [] },
      { id: 'st-3', name: 'Negotiation', probability: 75, count: 0, values: [] },
    ],
    won: { count: 2, values: [{ currency: 'KES', amount_minor: '3000000' }] },
    lost: { count: 1 },
  }

  it('shows the board by stage with each stage value and weighted value, and moves a card (CRM-16)', async () => {
    mockCrm(api, [
      ['crm/opportunities?pipeline=pl-1&status=open&per_page=200', page([OPPORTUNITY])],
      ['crm/pipeline-summary?pipeline=pl-1', { data: SUMMARY }],
    ])
    api.patch.mockResolvedValue({ data: { ...OPPORTUNITY, stage: { id: 'st-2', name: 'Proposal', probability: 50 } } })
    renderApp('/crm/opportunities')
    const qualification = await screen.findByRole('region', { name: /^Qualification/ })
    expect(await within(qualification).findByText('Duka Moja shelving')).toBeInTheDocument()
    expect(within(qualification).getAllByText('120,000.00').length).toBeGreaterThan(0)
    expect(within(qualification).getAllByText('12,000.00').length).toBeGreaterThan(0)
    expect(screen.getByText('2 won')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Move Duka Moja shelving to Proposal' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('crm/opportunities/o-1', { stage_id: 'st-2' }))
  })

  it('shows an opportunity with its stage, activities and quotes, and wins it with a reason (CRM-15)', async () => {
    const detail = {
      ...OPPORTUNITY,
      activities: [{ id: 'a-1', company: named('c-1', 'Amani Retail'), branch: named('br-1', 'Westlands'), owner: named('u-1', 'Amina Otieno'), kind: 'call', subject: 'Call about shelving', notes: null, starts_at: '2026-10-01T07:00:00Z', ends_at: null, remind_at: null, status: 'open', done_at: null, outcome: null, overdue: true, lead: null, opportunity: named('o-1', 'Duka Moja shelving'), quote: null, party: null, email: null, created_at: '2026-10-01T06:00:00Z', can: { edit: true } }],
      quotes: [{ id: 'q-1', number: 'QT-WL-2026-00001', status: 'sent', currency: 'KES', quote_date: '2026-10-10', totals: { subtotal_minor: '100000', discount_minor: '0', tax_minor: '16000', total_minor: '116000' } }],
    }
    const opportunity = record(detail)
    mockCrm(api, [['crm/opportunities/o-1', opportunity.answer]])
    api.post.mockImplementation(opportunity.becomes({ ...detail, status: 'won', reason: named('wr-1', 'Best price'), closed_on: '2026-10-15' }))
    renderApp('/crm/opportunities/o-1')
    expect(await screen.findByText('Call about shelving')).toBeInTheDocument()
    expect(screen.getByText('Overdue').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')
    expect(screen.getByRole('link', { name: 'QT-WL-2026-00001' })).toHaveAttribute('href', '/crm/quotes/q-1')
    expect(screen.getByRole('button', { name: 'New quote' })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Qualification').closest('li')).toHaveAttribute('aria-current', 'step'))
    fireEvent.click(screen.getByRole('button', { name: 'Mark as won' }))
    const dialog = await screen.findByRole('dialog', { name: 'Win Duka Moja shelving' })
    await waitForOption(within(dialog).getByLabelText('Won reason'), 'Best price')
    chooseOption(within(dialog).getByLabelText('Won reason'), 'Best price')
    fireEvent.change(within(dialog).getByLabelText('Closed on'), { target: { value: '2026-10-15' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Mark as won' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/opportunities/o-1/win', { reason_id: 'wr-1', closed_on: '2026-10-15' }))
    expect(await screen.findByRole('button', { name: 'Reopen' })).toBeInTheDocument()
  })

  it('needs a lost reason when the settings say so (CRM-15)', async () => {
    mockCrm(api, [['crm/opportunities/o-1', { data: { ...OPPORTUNITY, activities: [], quotes: [] } }]])
    api.post.mockResolvedValue({ data: { ...OPPORTUNITY, status: 'lost', activities: [], quotes: [] } })
    renderApp('/crm/opportunities/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Mark as lost' }))
    const dialog = await screen.findByRole('dialog', { name: 'Lose Duka Moja shelving' })
    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Mark as lost' })).toBeDisabled())
    await waitForOption(within(dialog).getByLabelText(/^Lost reason/), 'Too expensive')
    chooseOption(within(dialog).getByLabelText(/^Lost reason/), 'Too expensive')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Mark as lost' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/opportunities/o-1/lose', { reason_id: 'lr-1' }))
  })
})

describe('Calendar and targets (CRM-26..CRM-30)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows activities in the month and logs a new one in the company time zone (CRM-26, CRM-28)', async () => {
    const today = new Date().toISOString().slice(0, 10)
    const ACTIVITY = { id: 'a-1', company: named('c-1', 'Amani Retail'), branch: named('br-1', 'Westlands'), owner: named('u-1', 'Amina Otieno'), kind: 'meeting', subject: 'Visit Duka Moja', notes: null, starts_at: `${today}T07:00:00Z`, ends_at: null, remind_at: null, status: 'open', done_at: null, outcome: null, overdue: true, lead: null, opportunity: null, quote: null, party: named('pt-1', 'Duka Moja'), email: null, created_at: `${today}T06:00:00Z`, can: { edit: true } }
    mockCrm(api, [[/^crm\/activities\?/, page([ACTIVITY])]])
    api.post.mockResolvedValue({ data: ACTIVITY })
    renderApp('/crm/calendar')
    const entry = await screen.findByRole('button', { name: /Visit Duka Moja/ })
    expect(entry).toHaveTextContent('10:00')
    expect(within(entry).getByText('Overdue')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^crm\/activities\?from=\d{4}-\d{2}-\d{2}&to=\d{4}-\d{2}-\d{2}&per_page=200$/))

    fireEvent.click(screen.getByRole('button', { name: 'New activity' }))
    const dialog = await screen.findByRole('dialog', { name: 'New activity' })
    fireEvent.change(within(dialog).getByLabelText(/^Subject/), { target: { value: 'Call back' } })
    fireEvent.change(within(dialog).getByLabelText(/^Starts/), { target: { value: '2026-10-20T09:30' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add activity' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/activities', expect.objectContaining({ branch_id: 'br-1', kind: 'call', subject: 'Call back', starts_at: '2026-10-20T06:30:00.000Z' })))
  })

  it('completes an activity with an outcome from the week view (CRM-26)', async () => {
    const today = new Date().toISOString().slice(0, 10)
    const ACTIVITY = { id: 'a-1', company: named('c-1', 'Amani Retail'), branch: named('br-1', 'Westlands'), owner: null, kind: 'call', subject: 'Call Duka Moja', notes: null, starts_at: `${today}T09:00:00Z`, ends_at: null, remind_at: null, status: 'open', done_at: null, outcome: null, overdue: false, lead: null, opportunity: null, quote: null, party: null, email: null, created_at: `${today}T06:00:00Z`, can: { edit: true } }
    mockCrm(api, [[/^crm\/activities\?/, page([ACTIVITY])]])
    api.post.mockResolvedValue({ data: { ...ACTIVITY, status: 'done', outcome: 'Wants a quote' } })
    renderApp('/crm/calendar')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Week' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Complete' }))
    const dialog = await screen.findByRole('dialog', { name: 'Complete Call Duka Moja' })
    fireEvent.change(within(dialog).getByLabelText('Outcome'), { target: { value: 'Wants a quote' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Complete' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/activities/a-1/complete', { outcome: 'Wants a quote' }))
  })

  it('lists targets with progress and "Not available" when the metric cannot be read, and creates one (CRM-30)', async () => {
    const targets = [
      { id: 't-1', company: named('c-1', 'Amani Retail'), branch: null, subject_type: 'user', subject: named('u-1', 'Amina Otieno'), metric: 'won_value', period_start: '2026-10-01', period_end: '2026-12-31', currency: 'KES', amount_minor: '50000000', actual_minor: '12500000', percent: '25', available: true, archived_at: null, can: { manage: true } },
      { id: 't-2', company: named('c-1', 'Amani Retail'), branch: named('br-1', 'Westlands'), subject_type: 'branch', subject: named('br-1', 'Westlands'), metric: 'pos_sales', period_start: '2026-10-01', period_end: '2026-10-31', currency: 'KES', amount_minor: '100000000', actual_minor: null, percent: null, available: false, unavailable: 'not_permitted', archived_at: null, can: { manage: true } },
    ]
    mockCrm(api, [['crm/targets?company=c-1&per_page=200', { data: targets }]])
    api.post.mockResolvedValue({ data: targets[0] })
    renderApp('/crm/targets')
    const row = (await screen.findByText('Won opportunities')).closest('tr')
    expect(within(row).getByText('Amina Otieno')).toBeInTheDocument()
    expect(within(row).getByText('25 %')).toBeInTheDocument()
    expect(within(row).getByText('125,000.00')).toBeInTheDocument()
    const branchRow = screen.getByText('Point of sale sales').closest('tr')
    expect(within(branchRow).getByText('Not available')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'New target' }))
    const dialog = await screen.findByRole('dialog', { name: 'New target' })
    await waitForOption(within(dialog).getByLabelText(/^Person/), 'Joseph Mwangi')
    chooseOption(within(dialog).getByLabelText(/^Person/), 'Joseph Mwangi')
    fireEvent.change(within(dialog).getByLabelText(/^Period starts/), { target: { value: '2026-11-01' } })
    fireEvent.change(within(dialog).getByLabelText(/^Period ends/), { target: { value: '2026-11-30' } })
    fireEvent.change(within(dialog).getByLabelText(/^Target amount/), { target: { value: '200000' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save target' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('crm/targets', { company_id: 'c-1', subject_type: 'user', subject_id: 'u-2', metric: 'won_value', period_start: '2026-11-01', period_end: '2026-11-30', amount_minor: '20000000' }),
    )
  })
})

describe('Sales settings (CRM-01..CRM-06, CRM-11)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('saves the approval threshold in minor units of the base currency (CRM-01)', async () => {
    mockCrm(api)
    api.put.mockResolvedValue({ data: { ...SETTINGS_SAVED } })
    renderApp('/crm/settings')
    const threshold = await screen.findByLabelText('Quote approval above')
    expect(threshold).toHaveValue('100,000.00')
    fireEvent.change(threshold, { target: { value: '150000' } })
    fireEvent.change(screen.getByLabelText('Quote validity in days'), { target: { value: '14' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('crm/companies/c-1/settings', { quote_approval_minor: '15000000', quote_validity_days: 14, default_team_id: null, lost_reason_required: true }))
  })

  it('shows pipelines with their stages and adds a stage (CRM-02)', async () => {
    mockCrm(api)
    api.post.mockResolvedValue({ data: { ...PIPELINE } })
    renderApp('/crm/settings')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Pipelines' }))
    expect(await screen.findByText('Negotiation')).toBeInTheDocument()
    expect(screen.getByText('75 %')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Add stage' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add stage' })
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Contract' } })
    fireEvent.change(within(dialog).getByLabelText(/^Probability/), { target: { value: '90' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/pipelines/pl-1/stages', { name: 'Contract', probability: 90 }))
  })

  it('creates a lead form and shows its link once with the embed code (CRM-06)', async () => {
    mockCrm(api, [['crm/lead-forms?company=c-1', { data: [] }]])
    const url = 'https://api.example.test/api/v1/crm/forms/abc123'
    api.post.mockResolvedValue({ data: { id: 'lf-1', company: named('c-1', 'Amani Retail'), branch: named('br-1', 'Westlands'), name: 'Website', source: null, team: null, fields: { shown: ['name', 'email', 'phone'], required: ['name'] }, submissions: 0, last_submitted_at: null, archived_at: null, url } })
    renderApp('/crm/settings')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Lead forms' }))
    fireEvent.click(await screen.findByRole('button', { name: 'New lead form' }))
    const dialog = await screen.findByRole('dialog', { name: 'New lead form' })
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Website' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/lead-forms', { company_id: 'c-1', name: 'Website', branch_id: 'br-1', source_id: null, team_id: null, fields: { shown: ['name', 'email', 'phone'], required: ['name'] } }))
    const link = await screen.findByRole('dialog', { name: 'Link for Website' })
    expect(within(link).getByLabelText('Form link')).toHaveValue(url)
    expect(within(link).getByText(/<form method="post" action="https:\/\/api\.example\.test\/api\/v1\/crm\/forms\/abc123">/)).toBeInTheDocument()
    expect(within(link).getByText('It is shown only once. Making a new link stops the old one.')).toBeInTheDocument()
  })
})

const SETTINGS_SAVED = { company_id: 'c-1', currency: 'KES', quote_approval_minor: '15000000', quote_validity_days: 14, default_team: null, lost_reason_required: true, can: { manage: true } }

