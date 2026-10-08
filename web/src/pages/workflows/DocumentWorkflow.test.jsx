import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api, ApiError } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { apiError, mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { COMPANIES } from '@/test/workflows'
import { returnTargets } from './documentFlow'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const URL = 'document-workflows/core.credit_limit_change/clc-1'

const STATUS = {
  id: 'wf-1',
  document_type: 'core.credit_limit_change',
  document_id: 'clc-1',
  status: 'running',
  outcome: null,
  version: { id: 'v-1', number: 3 },
  started_at: '2026-10-07T14:00:00Z',
  started_by: { id: 'u-1', name: 'Mary Manager' },
  completed_at: null,
  cancelled_at: null,
  cancelled_by: null,
  cancel_reason: null,
  current: [
    {
      token_id: 't-1',
      node_id: 'approve',
      name: 'Accountant approves',
      type: 'approval',
      status: 'active',
      entered_at: '2026-10-07T14:00:00Z',
      due_at: null,
      overdue: false,
      seconds_in_stage: 7500,
      holders: { roles: [], users: [{ id: 'u-2', name: 'Ann Accountant' }], permission: null, approval_id: 'apr-1' },
      can_move: false,
    },
  ],
  history: [
    { type: 'started', node_id: null, node_name: null, user: { id: 'u-1', name: 'Mary Manager' }, reason: null, data: {}, occurred_at: '2026-10-07T14:00:00Z' },
    { type: 'entered', node_id: 'approve', node_name: 'Accountant approves', user: null, reason: null, data: {}, occurred_at: '2026-10-07T14:00:00Z' },
  ],
  created_documents: [],
  document: { type_label: 'Credit limit change', number: 'CLC-000001', title: 'Duka Moja Ltd', company_id: 'c-2', link: '/contacts/credit-limit-changes?change=clc-1' },
}

describe('Document workflow status page (WF-10)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('names the document and shows its step, holder, time waiting, approval, own page and history', async () => {
    mockRoutes(api, [[URL, { data: STATUS }]], { companies: COMPANIES })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    expect(await screen.findByRole('heading', { name: 'Credit limit change CLC-000001' })).toBeInTheDocument()
    expect(screen.getByText('Duka Moja Ltd')).toBeInTheDocument()
    expect(screen.getByText('In progress')).toBeInTheDocument()
    expect(screen.getByText('Version 3')).toBeInTheDocument()
    expect(screen.getByText('Held by Ann Accountant')).toBeInTheDocument()
    expect(screen.getByText('In this step for 2 h 5 min')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open the approval' })).toHaveAttribute('href', '/approvals/apr-1')
    expect(screen.getByRole('link', { name: 'Open the document' })).toHaveAttribute('href', '/contacts/credit-limit-changes?change=clc-1')

    const history = screen.getByRole('list', { name: 'History' })
    expect(within(history).getByText('Reached Accountant approves')).toBeInTheDocument()
    // Times in the document's company zone (Kinshasa: 14:00 UTC is 15:00), named when it differs from the browser.
    const browser = Intl.DateTimeFormat().resolvedOptions().timeZone
    const time = browser === 'Africa/Kinshasa' ? /^7 Oct 2026, 15:00$/ : /^7 Oct 2026, 15:00 (WAT|GMT\+1)$/
    expect(await within(history).findAllByText(time)).toHaveLength(2)
  })

  it('shows times in the zone the API sends, even when the viewer cannot list the company (L10N-03)', async () => {
    const zoned = { ...STATUS, document: { ...STATUS.document, company_id: 'c-unlisted', timezone: 'Africa/Kinshasa' } }
    mockRoutes(api, [[URL, { data: zoned }]], { companies: [] })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    const history = await screen.findByRole('list', { name: 'History' })
    const browser = Intl.DateTimeFormat().resolvedOptions().timeZone
    const time = browser === 'Africa/Kinshasa' ? /^7 Oct 2026, 15:00$/ : /^7 Oct 2026, 15:00 (WAT|GMT\+1)$/
    expect(await within(history).findAllByText(time)).toHaveLength(2)
  })

  it('falls back to the short id and offers no document link when the type has neither', async () => {
    const bare = { ...STATUS, current: [], document: { type_label: 'Purchase requisition', number: null, title: null, company_id: null, link: null } }
    mockRoutes(api, [['document-workflows/procurement.requisition/0192abcd-0000-7000-8000-000000000001', { data: bare }]], { companies: COMPANIES })
    renderApp('/document-workflows/procurement.requisition/0192abcd-0000-7000-8000-000000000001')

    expect(await screen.findByRole('heading', { name: 'Purchase requisition 0192abcd' })).toBeInTheDocument()
    expect(screen.getByText('No step is open.')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Open the document' })).not.toBeInTheDocument()
  })

  it('shows the standard not-found and no-access states', async () => {
    mockRoutes(api, [[URL, apiError(404, 'not_found', 'Not found.')]], { companies: COMPANIES })
    const { unmount } = renderApp('/document-workflows/core.credit_limit_change/clc-1')
    expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
    unmount()

    mockRoutes(api, [[URL, apiError(403, 'forbidden', 'Not allowed.')]], { companies: COMPANIES })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')
    expect(await screen.findByRole('heading', { name: /access/i })).toBeInTheDocument()
  })
})

// WF-11: a document at a plain stage the viewer may move on, after a completed "Draft" stage.
const AT_STAGE = {
  ...STATUS,
  current: [{ ...STATUS.current[0], token_id: 't-2', node_id: 'check', name: 'Stock check', type: 'stage', holders: { roles: [], users: [{ id: 'u-1', name: 'Mary Manager' }], permission: null }, can_move: true }],
  history: [
    STATUS.history[0],
    { type: 'entered', node_id: 'draft', node_name: 'Draft', user: null, reason: null, data: {}, occurred_at: '2026-10-07T14:00:00Z' },
    { type: 'left', node_id: 'draft', node_name: 'Draft', user: { id: 'u-1', name: 'Mary Manager' }, reason: null, data: { how: 'completed' }, occurred_at: '2026-10-07T14:10:00Z' },
    { type: 'entered', node_id: 'check', node_name: 'Stock check', user: null, reason: null, data: {}, occurred_at: '2026-10-07T14:10:00Z' },
  ],
}

describe('Document workflow actions (WF-11)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('offers no actions to someone who cannot move an open stage, and links an approval step to its approval', async () => {
    mockRoutes(api, [[URL, { data: STATUS }]], { companies: COMPANIES })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    expect(await screen.findByRole('link', { name: 'Open the approval' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Move on/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Return to an earlier stage' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancel the flow' })).not.toBeInTheDocument()
  })

  it('never offers Move on for an approval step, even when the API allows manual completion', async () => {
    mockRoutes(api, [[URL, { data: { ...STATUS, current: [{ ...STATUS.current[0], can_move: true }] } }]], { companies: COMPANIES })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    expect(await screen.findByRole('link', { name: 'Open the approval' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Move on/ })).not.toBeInTheDocument()
  })

  it('moves a stage on and shows the new status', async () => {
    mockRoutes(api, [[URL, { data: AT_STAGE }]], { companies: COMPANIES })
    api.post.mockResolvedValue({ data: { ...AT_STAGE, status: 'completed', current: [], completed_at: '2026-10-07T15:00:00Z' } })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Move on from Stock check' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith(`${URL}/move`, { node: 'check' }))
    expect(await screen.findByText('No step is open.')).toBeInTheDocument()
    expect(screen.getAllByText('Completed').length).toBeGreaterThan(0)
  })

  it('shows the rules that stopped a move', async () => {
    mockRoutes(api, [[URL, { data: AT_STAGE }]], { companies: COMPANIES })
    api.post.mockRejectedValue(new ApiError({ status: 422, code: 'exit_blocked', message: 'Stock check cannot be left yet.', data: { reasons: ['Attach the delivery note.'] } }))
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Move on from Stock check' }))
    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Stock check cannot be left yet.')
    expect(within(alert).getByText('Attach the delivery note.')).toBeInTheDocument()
  })

  it('returns the document to a stage it passed, with a required reason', async () => {
    mockRoutes(api, [[URL, { data: AT_STAGE }]], { companies: COMPANIES })
    api.post.mockResolvedValue({ data: { ...AT_STAGE, current: [{ ...AT_STAGE.current[0], node_id: 'draft', name: 'Draft' }] } })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Return to an earlier stage' }))
    const confirm = screen.getByRole('button', { name: 'Return the document' })
    // The only earlier stage is chosen already; the reason is required.
    expect(screen.getByLabelText(/Return to/)).toHaveTextContent('Draft')
    expect(confirm).toBeDisabled()
    fireEvent.change(screen.getByLabelText(/Reason for returning/), { target: { value: 'Prices changed' } })
    fireEvent.click(confirm)

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(`${URL}/return`, { node: 'draft', reason: 'Prices changed' }))
    await waitFor(() => expect(screen.queryByLabelText(/Reason for returning/)).not.toBeInTheDocument())
    expect(screen.getByText('Draft', { selector: 'span.font-medium' })).toBeInTheDocument()
  })

  it('cancels the flow after an in-page confirmation with a reason', async () => {
    mockRoutes(api, [[URL, { data: AT_STAGE }]], { companies: COMPANIES })
    api.post.mockResolvedValue({ data: { ...AT_STAGE, status: 'cancelled', current: [], cancelled_at: '2026-10-07T15:00:00Z', cancel_reason: 'Customer withdrew' } })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Cancel the flow' }))
    expect(screen.getByRole('heading', { name: 'Cancel this flow?' })).toBeInTheDocument()
    const confirm = screen.getAllByRole('button', { name: 'Cancel the flow' }).at(-1)
    expect(confirm).toBeDisabled()
    fireEvent.change(screen.getByLabelText(/Reason for cancelling/), { target: { value: 'Customer withdrew' } })
    fireEvent.click(confirm)

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(`${URL}/cancel`, { reason: 'Customer withdrew' }))
    expect(await screen.findByText(/Customer withdrew/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancel the flow' })).not.toBeInTheDocument()
  })

  it('keeps the flow when the confirmation is dismissed', async () => {
    mockRoutes(api, [[URL, { data: AT_STAGE }]], { companies: COMPANIES })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Cancel the flow' }))
    fireEvent.click(screen.getByRole('button', { name: 'Keep it as it is' }))
    expect(screen.queryByLabelText(/Reason for cancelling/)).not.toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it("uses the API's return targets and permissions when it sends them", async () => {
    const flow = { ...AT_STAGE, can_cancel: false, can_return: true, return_targets: [{ node_id: 'intake', name: 'Intake' }, { node_id: 'draft', name: 'Draft' }] }
    expect(returnTargets(flow)).toEqual([
      { value: 'intake', label: 'Intake' },
      { value: 'draft', label: 'Draft' },
    ])
    mockRoutes(api, [[URL, { data: flow }]], { companies: COMPANIES })
    api.post.mockResolvedValue({ data: flow })
    renderApp('/document-workflows/core.credit_limit_change/clc-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Return to an earlier stage' }))
    expect(screen.queryByRole('button', { name: 'Cancel the flow' })).not.toBeInTheDocument()
    chooseOption(/Return to/, 'Intake')
    fireEvent.change(screen.getByLabelText(/Reason for returning/), { target: { value: 'Missing ID' } })
    fireEvent.click(screen.getByRole('button', { name: 'Return the document' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith(`${URL}/return`, { node: 'intake', reason: 'Missing ID' }))
  })
})
