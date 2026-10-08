import { screen, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { COMPANIES } from '@/test/workflows'

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
