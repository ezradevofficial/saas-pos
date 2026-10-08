import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { closeFilters, openFilters } from '@/test/filters'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

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

  it('lets users without set_directly lower the limit, with a hint (WF-01)', async () => {
    routes({ permissions: EDITOR })
    api.patch.mockResolvedValue({ data: PARTY, meta: { possible_duplicates: [] } })
    renderApp('/contacts/customers/p-1')

    const field = await screen.findByLabelText('Credit limit')
    expect(screen.getByText('To raise or remove the limit, request a change.')).toBeInTheDocument()
    fireEvent.change(field, { target: { value: '100,000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    expect(api.patch.mock.calls[0][1]).toMatchObject({ credit_limit: '100000.00', credit_limit_currency: 'KES' })
  })

  it('shows a refused raise under the field with Request a change, which opens the request dialog', async () => {
    const message = 'Raising or removing this credit limit, or changing its currency, needs approval. Lower it, or use “Request a change” on the customer’s page.'
    routes({ permissions: tenantWide(['core.company.view', 'core.currency.view', 'core.party.view', 'core.party.edit', 'core.credit_limit.request']) })
    api.patch.mockRejectedValue(apiError(422, 'credit_limit_needs_request', message, { credit_limit: [message] }))
    renderApp('/contacts/customers/p-1')

    fireEvent.change(await screen.findByLabelText('Credit limit'), { target: { value: '900,000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByText(message)).toBeInTheDocument()

    const buttons = screen.getAllByRole('button', { name: 'Request a change' })
    fireEvent.click(buttons.at(-1))
    expect(await screen.findByRole('dialog', { name: 'Request a credit limit change for Duka Moja Ltd' })).toBeInTheDocument()
  })

  it('lets users who may set the limit directly raise it without a hint', async () => {
    routes({ permissions: tenantWide(['core.company.view', 'core.currency.view', 'core.party.view', 'core.party.edit', 'core.credit_limit.set_directly']) })
    renderApp('/contacts/customers/p-1')
    expect(await screen.findByLabelText('Credit limit')).toBeInTheDocument()
    expect(screen.queryByText('To raise or remove the limit, request a change.')).not.toBeInTheDocument()
  })

  it('needs set_directly tenant-wide for a shared customer (M2)', async () => {
    routes({ permissions: [...tenantWide(['core.company.view', 'core.currency.view', 'core.party.view', 'core.party.edit']), { name: 'core.credit_limit.set_directly', scopes: [{ type: 'company', id: 'c-1' }] }] })
    renderApp('/contacts/customers/p-1')
    expect(await screen.findByText('To raise or remove the limit, request a change.')).toBeInTheDocument()
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

    // The status filter sits in the drawer and shows as a chip once chosen.
    openFilters()
    chooseOption('Status', 'Rejected')
    await waitFor(() => expect(calls().at(-1)).toBe('credit-limit-changes?status=rejected&per_page=25&page=1'))
    await closeFilters()
    expect(screen.getByText('Status: Rejected')).toBeInTheDocument()
  })
})
