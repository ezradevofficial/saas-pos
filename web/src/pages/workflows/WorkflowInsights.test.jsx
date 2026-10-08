import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { closeFilters, openFilters } from '@/test/filters'
import { apiError, mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { COMPANIES, WORKFLOW_PERMISSIONS } from '@/test/workflows'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const stage = (node_id, name, values) => ({ node_id, name, kind: 'stage', now: 0, entered: 0, left: 0, median_seconds: null, p90_seconds: null, overdue: 0, slowest: false, ...values })

const INSIGHTS = {
  data: [
    {
      workflow_id: 'w-1',
      document_type: 'procurement.requisition',
      document_type_label: 'Purchase requisition',
      company_id: 'c-1',
      company_name: 'Amani Retail Ltd',
      version: 3,
      stages: [
        stage('budget', 'Check budget', { now: 1, entered: 12, left: 11, median_seconds: 1800, p90_seconds: 5400 }),
        { ...stage('manager', 'Branch manager approves', { now: 4, entered: 11, left: 7, median_seconds: 97200, p90_seconds: 190800, overdue: 2, slowest: true }), kind: 'approval' },
      ],
    },
  ],
  meta: {
    from: '2026-09-09',
    to: '2026-10-08',
    timezone: 'UTC',
    time_basis: 'elapsed',
    types: [
      { key: 'core.credit_limit_change', label: 'Credit limit change' },
      { key: 'procurement.requisition', label: 'Purchase requisition' },
    ],
  },
}

const insightCalls = () => api.get.mock.calls.map(([path]) => path).filter((path) => path.startsWith('workflow-insights'))

function render(answer = INSIGHTS) {
  mockRoutes(api, [[/^workflow-insights/, answer]], { permissions: WORKFLOW_PERMISSIONS, companies: COMPANIES })
  return renderApp('/settings/workflows/insights')
}

describe('Stage volumes (WF-10)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows each stage’s volumes and times, and marks the slowest stage', async () => {
    render()
    const table = await screen.findByRole('table', { name: 'Purchase requisition · Amani Retail Ltd' })
    expect(screen.getByRole('heading', { name: 'Stage volumes' })).toBeInTheDocument()
    expect(screen.getByText('9 Sept 2026 to 8 Oct 2026')).toBeInTheDocument()
    expect(screen.getByText(/elapsed time from entering a stage to leaving it, not business hours/)).toBeInTheDocument()

    const headers = within(table).getAllByRole('columnheader').map((cell) => cell.textContent)
    expect(headers).toEqual(['Stage', 'Now', 'Entered', 'Left', 'Median time', 'Slowest 10%', 'Overdue'])

    const [, budget, manager] = within(table).getAllByRole('row')
    expect(within(budget).getAllByRole('cell').map((cell) => cell.textContent)).toEqual(['Check budget', '1', '12', '11', '30 min', '1 h 30 min', '0'])
    expect(within(manager).getAllByRole('cell').map((cell) => cell.textContent)).toEqual(['Branch manager approvesApprovalSlowest', '4', '11', '7', '1 d 3 h', '2 d 5 h', '2'])
    // The bottleneck: a dot and a word, not a filled pill; numbers in tabular figures.
    expect(within(manager).getByText('Slowest').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(budget).queryByText('Slowest')).toBeNull()
    expect(within(manager).getAllByRole('cell')[1]).toHaveClass('tabular-nums')
    expect(insightCalls()).toEqual(['workflow-insights'])
  })

  it('filters by type, company and period from the filter drawer', async () => {
    render()
    await screen.findByRole('table', { name: 'Purchase requisition · Amani Retail Ltd' })

    const drawer = openFilters()
    chooseOption(within(drawer).getByLabelText('Document type'), 'Purchase requisition')
    await waitFor(() => expect(insightCalls().at(-1)).toBe('workflow-insights?type=procurement.requisition'))
    chooseOption(within(drawer).getByLabelText('Company'), 'Kin Market')
    fireEvent.change(within(drawer).getByLabelText('From'), { target: { value: '2026-10-01' } })
    await waitFor(() => expect(insightCalls().at(-1)).toBe('workflow-insights?type=procurement.requisition&company=c-2&from=2026-10-01'))
    await closeFilters()

    const chips = screen.getByRole('list', { name: 'Active filters' })
    expect(within(chips).getByText('From: 1 Oct 2026')).toBeInTheDocument()
    expect(within(chips).getByText('Company: Kin Market')).toBeInTheDocument()
  })

  it('says what went wrong with a refused period, and shows no access when the API refuses', async () => {
    const { unmount } = render(apiError(422, 'validation_failed', 'Choose a period of at most 366 days.', { to: ['Choose a period of at most 366 days.'] }))
    expect(await screen.findByText('Choose a period of at most 366 days.')).toBeInTheDocument()
    unmount()

    render(apiError(403, 'forbidden', 'Not allowed.'))
    expect(await screen.findByRole('heading', { name: /access/i })).toBeInTheDocument()
  })

  it('says when there is nothing to show', async () => {
    render({ data: [], meta: { ...INSIGHTS.meta } })
    expect(await screen.findByText('No live workflows to show. Publish a workflow, or change the filters.')).toBeInTheDocument()
  })
})
