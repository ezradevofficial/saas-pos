import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { apiError, CD_COMPANY, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const EDITOR = tenantWide(['core.company.view', 'core.currency.view', 'core.party.view', 'core.party.create', 'core.party.edit', 'core.party.archive', 'core.price_list.view'])
const CURRENCIES = [
  { id: 'tc-1', code: 'CDF', decimals: 0, active: true },
  { id: 'tc-2', code: 'USD', decimals: 2, active: true },
]
const PARTY = {
  id: 'p-1',
  company_id: null,
  shared: true,
  kind: 'organisation',
  name: 'Kin Traders',
  legal_name: 'Kin Traders SARL',
  tax_id: 'A1234567B',
  roles: ['customer'],
  tags: ['wholesale'],
  phones: [{ number: '+243810000001', label: 'Mobile' }],
  emails: [{ address: 'orders@kintraders.example', label: null }],
  addresses: [],
  currency: 'USD',
  payment_terms_days: 30,
  credit_limit: { amount_minor: '500000', currency: 'USD' },
  price_list_id: null,
  archived_at: null,
}

function parties({ permissions = EDITOR, party = PARTY, modes = { customers: 'shared', suppliers: 'shared' }, extra = [] } = {}) {
  mockRoutes(
    api,
    [
      ...extra,
      ['master-data/settings', { data: Object.entries(modes).map(([data_type, mode]) => ({ data_type, mode })) }],
      ['tenant/currencies', { data: CURRENCIES }],
      [/^companies\/c-1\/price-lists/, { data: [{ id: 'pl-1', name: 'Wholesale', archived_at: null }] }],
      [/^parties\?/, { data: [party], meta: { current_page: 1, last_page: 1 } }],
      [`parties/${party.id}`, { data: party }],
      [/^history\/party\//, { data: [], meta: { current_page: 1, last_page: 1 } }],
    ],
    { permissions, companies: [CD_COMPANY] },
  )
}

const listCalls = () => api.get.mock.calls.map(([path]) => path).filter((path) => path.startsWith('parties?'))

describe('Customers and suppliers', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists customers with phone, email, tags and credit limit, searching by phone after typing stops', async () => {
    parties()
    renderApp('/contacts/customers')
    const table = await screen.findByRole('table', { name: 'Customers' })
    const row = (await within(table).findByText('Kin Traders')).closest('tr')
    expect(within(row).getByText('Kin Traders SARL')).toBeInTheDocument()
    expect(within(row).getByText('+243810000001')).toBeInTheDocument()
    expect(within(row).getByText('wholesale')).toBeInTheDocument()
    expect(within(row).getByText('USD')).toBeInTheDocument()
    expect(within(row).getByText('5,000.00')).toBeInTheDocument()
    expect(listCalls()[0]).toBe('parties?role=customer&status=active&per_page=25&page=1')

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: '0810' } })
    fireEvent.change(screen.getByLabelText('Tag'), { target: { value: 'vip' } })
    await waitFor(() => expect(listCalls().at(-1)).toBe('parties?role=customer&status=active&tag=vip&search=0810&per_page=25&page=1'))
  })

  it('lists suppliers with their own filter', async () => {
    parties()
    renderApp('/contacts/suppliers')
    await screen.findByRole('table', { name: 'Suppliers' })
    expect(listCalls()[0]).toBe('parties?role=supplier&status=active&per_page=25&page=1')
    expect(screen.getByRole('button', { name: 'Add supplier' })).toBeInTheDocument()
  })

  it('creates a customer with phones, an email and a credit limit in major units, then warns of a possible duplicate', async () => {
    parties({ party: { ...PARTY, id: 'p-2' } })
    api.post.mockResolvedValue({
      data: { ...PARTY, id: 'p-2', name: 'Kin Traders Ltd' },
      meta: { possible_duplicates: [{ id: 'p-1', name: 'Kin Traders', reason: 'phone' }] },
    })
    const { router } = renderApp('/contacts/customers/new')

    chooseOption(await screen.findByLabelText(/^Kind/), 'Organisation')
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Kin Traders Ltd' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add phone number' }))
    fireEvent.change(screen.getByLabelText('Phone 1'), { target: { value: '0810 000 001' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add email address' }))
    fireEvent.change(screen.getByLabelText('Email 1'), { target: { value: 'buy@kin.example' } })
    const creditCurrency = screen.getByLabelText('Credit limit currency')
    await waitForOption(creditCurrency, 'USD')
    chooseOption(creditCurrency, 'USD')
    fireEvent.change(screen.getByLabelText('Credit limit'), { target: { value: '12,450.5' } })
    fireEvent.change(screen.getByLabelText('Payment terms'), { target: { value: '30' } })
    fireEvent.change(screen.getByLabelText('Tags'), { target: { value: 'wholesale, VIP , wholesale' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create customer' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('parties', {
        kind: 'organisation',
        name: 'Kin Traders Ltd',
        legal_name: null,
        tax_id: null,
        roles: ['customer'],
        phones: [{ number: '0810 000 001', label: null }],
        emails: [{ address: 'buy@kin.example', label: null }],
        addresses: [],
        currency: null,
        payment_terms_days: 30,
        credit_limit: '12450.50',
        credit_limit_currency: 'USD',
        price_list_id: null,
        tags: ['wholesale', 'VIP'],
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/contacts/customers/p-2'))
    expect(await screen.findByText('This contact may already exist')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Kin Traders' })).toHaveAttribute('href', '/contacts/customers/p-1')
    expect(screen.getByText('same phone number')).toBeInTheDocument()
  })

  it('asks which company keeps the party when a role change ties it to one (company_change_needs_confirmation)', async () => {
    parties({ modes: { customers: 'shared', suppliers: 'per_company' } })
    api.patch.mockResolvedValue({ data: { ...PARTY, roles: ['customer', 'supplier'], company_id: 'c-1' }, meta: { possible_duplicates: [] } })
    renderApp('/contacts/customers/p-1')
    fireEvent.click(await screen.findByLabelText('Supplier'))
    const company = await within(screen.getByRole('main')).findByLabelText(/^Company/)
    expect(screen.getByText('With these roles the contact is kept per company. Choose which company keeps it.')).toBeInTheDocument()
    await waitFor(() => expect(company).toHaveValue('c-1'))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('parties/p-1', expect.objectContaining({ roles: ['customer', 'supplier'], company_id: 'c-1' })))
  })

  it('asks to confirm sharing when a role change makes the party shared', async () => {
    parties({ party: { ...PARTY, company_id: 'c-1', roles: ['customer', 'supplier'] }, modes: { customers: 'shared', suppliers: 'per_company' } })
    api.patch.mockResolvedValue({ data: { ...PARTY }, meta: { possible_duplicates: [] } })
    renderApp('/contacts/customers/p-1')
    fireEvent.click(await screen.findByLabelText('Supplier'))
    expect(await screen.findByText('With these roles the contact is shared by every company.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByText('Confirm that every company may see this contact, or keep its roles.')).toBeInTheDocument()
    expect(api.patch).not.toHaveBeenCalled()
    fireEvent.click(screen.getByLabelText('Share this contact with every company'))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('parties/p-1', expect.objectContaining({ roles: ['customer'], company_id: null })))
  })

  it('shows the API’s company confirmation under a company field when the modes changed meanwhile', async () => {
    parties()
    api.patch.mockRejectedValue(
      Object.assign(apiError(422, 'company_change_needs_confirmation', 'Choose the company.', { company_id: ['Choose the company for this contact.'] })),
    )
    renderApp('/contacts/customers/p-1')
    fireEvent.click(await screen.findByLabelText('Supplier'))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByText('Choose the company for this contact.')).toBeInTheDocument()
    expect(within(screen.getByRole('main')).getByLabelText(/^Company/)).toBeInTheDocument()
  })

  it('leaves out fields hidden by field rules and is read-only without edit permission (RBAC-05)', async () => {
    const hidden = { ...PARTY }
    delete hidden.credit_limit
    delete hidden.tax_id
    parties({ party: hidden, permissions: tenantWide(['core.party.view']) })
    renderApp('/contacts/customers/p-1')
    expect(await screen.findByLabelText(/^Name/)).toBeDisabled()
    expect(screen.queryByLabelText('Credit limit')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Tax ID')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument()
  })

  it('shows the party’s history with roles in words', async () => {
    parties({
      extra: [
        [
          'history/party/p-1?per_page=20&page=1',
          {
            data: [{ id: 'a-1', action: 'core.party.update', actor: { id: 'u-1', name: 'Amina Otieno' }, before: { roles: ['customer'] }, after: { roles: ['customer', 'supplier'] }, occurred_at: '2026-10-08T08:00:00Z' }],
            meta: { current_page: 1, last_page: 1 },
          },
        ],
      ],
    })
    renderApp('/contacts/customers/p-1')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'History' }))
    const list = await screen.findByRole('list', { name: 'History' })
    expect(within(list).getByText('Customer, Supplier')).toBeInTheDocument()
    expect(within(list).getByText('Roles')).toBeInTheDocument()
  })
})
