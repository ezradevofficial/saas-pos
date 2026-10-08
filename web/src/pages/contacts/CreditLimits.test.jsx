import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const KE_COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const PARTY = {
  id: 'p-1',
  company_id: null,
  shared: true,
  kind: 'organisation',
  name: 'Duka Moja Ltd',
  legal_name: null,
  tax_id: null,
  roles: ['customer'],
  tags: [],
  phones: [],
  emails: [],
  addresses: [],
  currency: 'KES',
  payment_terms_days: null,
  credit_limit: { amount_minor: '15000000', currency: 'KES' },
  price_list_id: null,
  archived_at: null,
}
const CHANGE = {
  id: 'clc-1',
  number: 'CLC-000001',
  party: { id: 'p-1', name: 'Duka Moja Ltd' },
  company: { id: 'c-1', name: 'Amani Retail' },
  current_limit: { amount_minor: '15000000', currency: 'KES' },
  requested_limit: { amount_minor: '25000000', currency: 'KES' },
  increase: { amount_minor: '10000000', currency: 'KES' },
  reason: 'Bigger orders',
  status: 'pending',
  requested_by: { id: 'u-2', name: 'Mary Manager' },
  decided_by: null,
  created_at: '2026-10-08T07:00:00Z',
  decided_at: null,
  applied_at: null,
  cancelled_at: null,
  can_cancel: true,
}
const WORKFLOW = {
  status: 'running',
  current: [
    { token_id: 'tk-1', node_id: 'approve', name: 'Accountant approves', type: 'approval', status: 'active', seconds_in_stage: 7500, holders: { roles: [], users: [{ id: 'u-3', name: 'Ann Accountant' }], permission: null } },
  ],
  history: [{ type: 'started', node_id: null, node_name: null, user: { id: 'u-2', name: 'Mary Manager' }, reason: null, occurred_at: '2026-10-08T07:00:00Z' }],
}

// A branch manager: views parties and requests credit limit changes; no direct setting.
const REQUESTER = tenantWide(['core.company.view', 'core.currency.view', 'core.party.view', 'core.credit_limit.request'])
const EDITOR = tenantWide(['core.company.view', 'core.currency.view', 'core.party.view', 'core.party.edit'])

function routes({ permissions = REQUESTER, party = PARTY, changes = [CHANGE] } = {}) {
  mockRoutes(
    api,
    [
      ['master-data/settings', { data: [{ data_type: 'customers', mode: 'shared' }] }],
      ['tenant/currencies', { data: [{ id: 'tc-1', code: 'KES', decimals: 2, active: true }, { id: 'tc-2', code: 'USD', decimals: 2, active: true }] }],
      [`parties/${party.id}`, { data: party }],
      [/^history\/party\//, { data: [], meta: { current_page: 1, last_page: 1 } }],
      [/^credit-limit-changes\?/, { data: changes, meta: { current_page: 1, last_page: 1, total: changes.length } }],
      [/^credit-limit-changes\/clc-1$/, { data: CHANGE, meta: { workflow: WORKFLOW, approval_id: 'apr-1' } }],
    ],
    { permissions, companies: [KE_COMPANY] },
  )
}

describe('Credit limit changes', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('requests a change from the customer page, sending the amount as a string of minor units (WF-01, ADR 003)', async () => {
    routes()
    api.post.mockResolvedValue({ data: { ...CHANGE }, meta: { workflow: WORKFLOW, approval_id: 'apr-1' } })
    renderApp('/contacts/customers/p-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Request a change' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('In KES, the currency of the current limit.')).toBeInTheDocument()

    // Nothing is sent without an amount and a reason.
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send for approval' }))
    expect(await within(dialog).findByText('Enter the new credit limit.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    fireEvent.change(within(dialog).getByLabelText('New credit limit'), { target: { value: '250,000.10' } })
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: ' Bigger orders ' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send for approval' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('credit-limit-changes', {
        party_id: 'p-1',
        requested_limit: { amount_minor: '25000010', currency: 'KES' },
        reason: 'Bigger orders',
      }),
    )
    expect(typeof api.post.mock.calls[0][1].requested_limit.amount_minor).toBe('string')
    expect(await screen.findByText('CLC-000001 was sent for approval.')).toBeInTheDocument()
  })

  it('shows the credit limit read-only with a hint to users who cannot set it directly, and sends no limit on save', async () => {
    routes({ permissions: EDITOR })
    api.patch.mockResolvedValue({ data: PARTY, meta: { possible_duplicates: [] } })
    renderApp('/contacts/customers/p-1')

    expect(await screen.findByText('Changes to the credit limit go through approval. Use Request a change above.')).toBeInTheDocument()
    expect(screen.queryByLabelText('Credit limit')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Request a change' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    expect(api.patch.mock.calls[0][1]).not.toHaveProperty('credit_limit')
  })

  it('lets users who may set the limit directly edit the field', async () => {
    routes({ permissions: tenantWide(['core.company.view', 'core.currency.view', 'core.party.view', 'core.party.edit', 'core.credit_limit.set_directly']) })
    renderApp('/contacts/customers/p-1')
    expect(await screen.findByLabelText('Credit limit')).toBeInTheDocument()
  })

  it("lists the customer's requests and opens one with its step, holder, time waiting and approval (WF-10)", async () => {
    routes()
    renderApp('/contacts/customers/p-1?tab=credit')

    const list = await screen.findByRole('list', { name: 'Credit limit changes' })
    expect(within(list).getByText('CLC-000001')).toBeInTheDocument()
    expect(within(list).getByText('Waiting for approval')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('credit-limit-changes?party=p-1&per_page=50')

    fireEvent.click(within(list).getByText('CLC-000001'))
    const dialog = await screen.findByRole('dialog', { name: 'Credit limit change CLC-000001' })
    expect(await within(dialog).findByText('Accountant approves')).toBeInTheDocument()
    expect(within(dialog).getByText('With: Ann Accountant')).toBeInTheDocument()
    expect(within(dialog).getByText('Waiting for 2 h 5 min')).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: 'Open the approval' })).toHaveAttribute('href', '/approvals/apr-1')
  })

  it('cancels a request with a reason', async () => {
    routes()
    api.post.mockResolvedValue({ data: { ...CHANGE, status: 'cancelled', can_cancel: false }, meta: { workflow: null, approval_id: null } })
    renderApp('/contacts/customers/p-1?tab=credit')

    fireEvent.click(within(await screen.findByRole('list', { name: 'Credit limit changes' })).getByText('CLC-000001'))
    const dialog = await screen.findByRole('dialog', { name: 'Credit limit change CLC-000001' })
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Cancel request' }))
    fireEvent.change(within(dialog).getByLabelText('Reason for cancelling'), { target: { value: 'Customer withdrew' } })
    fireEvent.click(within(dialog).getAllByRole('button', { name: 'Cancel request' }).at(-1))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('credit-limit-changes/clc-1/cancel', { reason: 'Customer withdrew' }))
    await waitFor(() => expect(within(dialog).queryByLabelText('Reason for cancelling')).not.toBeInTheDocument())
  })

  it('lists every request under Contacts with a status filter (EXP-01)', async () => {
    routes()
    renderApp('/contacts/credit-limit-changes')

    const table = await screen.findByRole('table', { name: 'Credit limit changes' })
    const row = (await within(table).findByText('CLC-000001')).closest('tr')
    expect(within(row).getByText('Duka Moja Ltd')).toBeInTheDocument()
    expect(within(row).getByText('Waiting for approval')).toBeInTheDocument()
    expect(within(row).getByText('Mary Manager')).toBeInTheDocument()
    const calls = () => api.get.mock.calls.map(([path]) => path).filter((path) => path.startsWith('credit-limit-changes?'))
    expect(calls()[0]).toBe('credit-limit-changes?per_page=25&page=1')
    expect(screen.getByRole('link', { name: 'Credit limit changes' })).toHaveAttribute('href', '/contacts/credit-limit-changes')
  })
})
