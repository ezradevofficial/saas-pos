import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { mockAutomation, ROLE_ID, RULE, RUN } from '@/test/automation'
import { renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const page = (rows) => ({ data: rows, meta: { total: rows.length, last_page: 1, from: 1, to: rows.length } })
const RULES = [/^automation-rules\?/, page([RULE, { ...RULE, id: 'r-3', name: 'Weekly digest', company_id: null, company_name: null, status: 'enabled', trigger_description: 'Every week on Monday at 08:00' }])]

const TEMPLATES = {
  data: [
    {
      key: 'remind_before_date',
      label: 'Remind before a date',
      description: 'Notify people some days before a date on the document.',
      document_types: [
        {
          key: 'procurement.requisition',
          label: 'Purchase requisition',
          parameters: [
            { name: 'field', kind: 'field', fields: ['needed_by'], default: 'needed_by' },
            { name: 'days', kind: 'days', default: 7 },
            { name: 'to', kind: 'recipients', default: ['field:owner'] },
          ],
        },
      ],
    },
  ],
}

describe('Automation rules list (AUTO-01..AUTO-07)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists rules with their trigger, company and status, and opens one', async () => {
    mockAutomation(api, { extra: [RULES] })
    const { router } = renderApp('/settings/automation-rules')
    const table = await screen.findByRole('table', { name: 'Automation rules' })
    expect(await within(table).findByText('Tell finance about big requisitions')).toBeInTheDocument()
    expect(within(table).getByText('When a Purchase requisition is created')).toBeInTheDocument()
    expect(within(table).getByText('Every week on Monday at 08:00')).toBeInTheDocument()
    expect(within(table).getByText('All companies')).toBeInTheDocument()
    expect(within(table).getByText('Disabled')).toBeInTheDocument()
    expect(within(table).getByText('Enabled')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Automation rules' })).toBeInTheDocument()

    fireEvent.click(within(table).getByText('Weekly digest'))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/automation-rules/r-3'))
  })

  it('filters by status and document type, and hides creation without the edit right', async () => {
    mockAutomation(api, { extra: [RULES], permissions: tenantWide(['core.automation.view']) })
    renderApp('/settings/automation-rules')
    await screen.findByRole('table', { name: 'Automation rules' })
    expect(screen.queryByRole('button', { name: 'Create rule' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Start from a template' })).not.toBeInTheDocument()
    chooseOption('Status', 'Archived')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^automation-rules\?status=archived/)))
    await waitFor(() => expect(screen.getByLabelText('Document type')).toBeEnabled())
    chooseOption('Document type', 'Purchase order')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/type=procurement\.order/)))
  })

  it('creates a switched-off rule from a template and opens it', async () => {
    mockAutomation(api, { extra: [RULES, ['automation-templates?type=procurement.requisition', TEMPLATES]] })
    api.post.mockResolvedValue({ data: { ...RULE, id: 'r-2' } })
    const { router } = renderApp('/settings/automation-rules')
    const open = await screen.findByRole('button', { name: 'Start from a template' })
    await waitFor(() => expect(open).toBeEnabled())
    fireEvent.click(open)
    const dialog = await screen.findByRole('dialog', { name: 'Start from a template' })
    fireEvent.click(await within(dialog).findByRole('radio', { name: /Remind before a date/ }))
    expect(within(dialog).getByText('Notify people some days before a date on the document.')).toBeInTheDocument()
    fireEvent.change(within(dialog).getByLabelText('Days before'), { target: { value: '30' } })
    fireEvent.click(await within(dialog).findByLabelText('Finance'))
    chooseOption(within(dialog).getByLabelText('Company'), 'Kin Market')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create rule from template' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('automation-templates/use', {
        template: 'remind_before_date',
        document_type: 'procurement.requisition',
        company_id: 'c-2',
        params: { field: 'needed_by', days: 30, to: [`role:${ROLE_ID}`, 'field:owner'] },
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/automation-rules/r-2'))
  })
})

describe('Automation run log (AUTO-05)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  const OUTCOMES = ['succeeded', 'skipped', 'failed', 'throttled', 'loop_blocked', 'retrying'].map((outcome, index) => ({ ...RUN, id: `run-${index + 1}`, outcome }))

  it('shows each outcome as a word and opens a run with its per-action results', async () => {
    mockAutomation(api, { extra: [[/^automation-runs\?/, page(OUTCOMES)], ['automation-runs/run-3', { data: RUN }], [/^automation-rules\?status=all/, page([RULE])]] })
    renderApp('/settings/automation-runs')
    const table = await screen.findByRole('table', { name: 'Automation runs' })
    for (const word of ['Succeeded', 'Skipped', 'Failed', 'Throttled', 'Loop blocked', 'Retrying']) {
      expect(await within(table).findByText(word)).toBeInTheDocument()
    }
    fireEvent.click(within(table).getByText('Failed'))
    const drawer = await screen.findByRole('dialog', { name: 'Run details' })
    expect(await within(drawer).findByText('Rolled back')).toBeInTheDocument()
    expect(within(drawer).getAllByText('The webhook answered 500.').length).toBeGreaterThan(0)
    expect(within(drawer).getByText('Call a webhook')).toBeInTheDocument()
    expect(within(drawer).getByText('3')).toBeInTheDocument()
  })

  it('filters by outcome and shows a rule’s runs on its Runs tab', async () => {
    mockAutomation(api, { extra: [[/^automation-runs\?/, page(OUTCOMES)]] })
    renderApp('/settings/automation-rules/r-1?tab=runs')
    await screen.findByRole('table', { name: 'Automation runs' })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^automation-runs\?rule=r-1/)))
    chooseOption('Outcome', 'Loop blocked')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^automation-runs\?rule=r-1&outcome=loop_blocked/)))
  })
})
