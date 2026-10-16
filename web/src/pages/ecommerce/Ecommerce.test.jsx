// ECOM-16 to ECOM-30: the E-commerce back office: navigation (RBAC-08), the orders list and an
// order's actions, the delivery board, rider cash-ups and the WhatsApp inbox.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { COMPANY, named, page } from '@/test/crm'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const ALL = tenantWide([
  'core.company.view',
  'core.user.view',
  ...['store.view', 'store.manage', 'order.view', 'order.create', 'order.manage', 'order.cancel', 'order.refund', 'delivery.view', 'delivery.assign', 'rider.manage',
    'cashup.view', 'cashup.manage', 'whatsapp.view', 'whatsapp.reply', 'customer.view', 'report.view'].map((name) => `ecommerce.${name}`),
])

const SUMMARY = {
  id: 'o-1', number: 'WEB-A-2026-00001', store: named('s-1', 'Duka Online'), company: { id: 'c-1' }, location: named('l-1', 'Outlet A'), channel: 'web', fulfilment: 'delivery',
  status: 'placed', payment_status: 'cod', payment_kind: 'cod', contact_name: 'Wanjiku Kamau', contact_phone: '+254712345678', currency: 'KES', total_minor: '136000', due_minor: '136000',
  placed_at: '2026-11-30T09:00:00Z', flags: [],
}
const ORDER = {
  ...SUMMARY,
  totals: { subtotal_minor: '100000', discount_minor: '0', tax_minor: '16000', delivery_fee_minor: '20000', delivery_tax_minor: '0', total_minor: '136000', loyalty_paid_minor: '0', due_minor: '136000' },
  address: { line: 'Mpaka Road 12', area: 'Westlands' }, zone: named('z-1', 'Westlands'), pickup_location: null,
  lines: [{ id: 'ln-1', line_no: 1, name: 'Widget', qty: '1.000000', unit_price_minor: '100000', gross_minor: '100000', discount_minor: '0', tax_minor: '16000', total_minor: '116000', tax_rate: '16.0000' }],
  payments: [{ id: 'p-1', kind: 'cod', currency: 'KES', amount_minor: '136000', status: 'pending', provider_reference: null }],
  history: [{ from: null, to: 'placed', payment_status: 'cod', note: null, user: null, at: '2026-11-30T09:00:00Z' }],
  delivery: null, times: {},
}

function mockEcommerce(routes = [], { permissions = ALL, modules = ['core', 'ecommerce'] } = {}) {
  mockRoutes(api, [...routes, [/^companies\?/, { data: [COMPANY] }], [/^ecommerce\/stores\?/, page([{ id: 's-1', name: 'Duka Online' }])], [/^ecommerce\/riders/, { data: [{ id: 'r-1', name: 'Juma Rider', active: true, archived_at: null }] }]], { permissions, modules, companies: [COMPANY] })
}

describe('Online store navigation (RBAC-08)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the Online store group while the module is active', async () => {
    mockEcommerce([[/^ecommerce\/orders\?/, page([])]])
    renderApp('/ecommerce/orders')
    const nav = await screen.findByRole('navigation', { name: /main/i })
    expect(await within(nav).findByRole('link', { name: 'Deliveries' })).toHaveAttribute('href', '/ecommerce/deliveries')
    expect(within(nav).getByRole('link', { name: 'WhatsApp inbox' })).toHaveAttribute('href', '/ecommerce/inbox')
  })

  it('hides the group without the module', async () => {
    mockRoutes(api, [[/^companies\?/, { data: [COMPANY] }]], { permissions: ALL, modules: ['core'] })
    renderApp('/')
    await screen.findAllByText('Dashboard')
    expect(screen.queryByRole('link', { name: 'Online orders' })).not.toBeInTheDocument()
  })
})

describe('Online orders (ECOM-16 to ECOM-22)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists orders with their status and payment as a dot and a word, and the total with its currency first', async () => {
    mockEcommerce([[/^ecommerce\/orders\?/, page([SUMMARY])]])
    renderApp('/ecommerce/orders')
    const row = (await screen.findByText('WEB-A-2026-00001')).closest('tr')
    expect(within(row).getByText('Placed').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(row).getByText('Cash on delivery')).toBeInTheDocument()
    expect(within(row).getByText('KES')).toBeInTheDocument()
    expect(within(row).getByText('1,360.00')).toBeInTheDocument()
  })

  it('accepts a cash on delivery order', async () => {
    mockEcommerce([['ecommerce/orders/o-1', { data: ORDER }]])
    api.post.mockResolvedValue({ data: { ...ORDER, status: 'accepted' } })
    renderApp('/ecommerce/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Accept' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/orders/o-1/accept', {}))
    expect(await screen.findByText('Order accepted.')).toBeInTheDocument()
  })

  it('asks for a reason before cancelling', async () => {
    mockEcommerce([['ecommerce/orders/o-1', { data: ORDER }]])
    api.post.mockResolvedValue({ data: { ...ORDER, status: 'cancelled' } })
    renderApp('/ecommerce/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel order' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByRole('button', { name: 'Cancel the order' })).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: 'Customer asked' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel the order' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/orders/o-1/cancel', { reason: 'Customer asked' }))
  })

  it('hides the actions from a reader without the permissions', async () => {
    mockEcommerce([['ecommerce/orders/o-1', { data: ORDER }]], { permissions: tenantWide(['core.company.view', 'ecommerce.order.view']) })
    renderApp('/ecommerce/orders/o-1')
    await screen.findByText('Mpaka Road 12, Westlands')
    expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancel order' })).not.toBeInTheDocument()
  })
})

describe('Deliveries and cash-ups (ECOM-27, ECOM-30)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('assigns a rider from the board', async () => {
    const delivery = { id: 'd-1', order_id: 'o-1', order: { number: 'WEB-A-2026-00001', contact_name: 'Wanjiku Kamau', address: { line: 'Mpaka Road 12' }, zone: named('z-1', 'Westlands') }, status: 'unassigned', rider: null, currency: 'KES', cod_due_minor: '136000', last_location: null }
    mockEcommerce([[/^ecommerce\/deliveries/, page([delivery])]])
    api.post.mockResolvedValue({ data: { ...delivery, status: 'assigned' } })
    renderApp('/ecommerce/deliveries')
    const card = (await screen.findByText('WEB-A-2026-00001')).closest('section')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('ecommerce/riders'))
    chooseOption(within(card).getByLabelText('Rider'), 'Juma Rider')
    fireEvent.click(within(card).getByRole('button', { name: 'Assign' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/deliveries/d-1/assign', { rider_id: 'r-1' }))
  })

  it('closes a rider cash-up with the cash counted', async () => {
    mockEcommerce([
      ['ecommerce/cashups/pending', { data: [{ rider_id: 'r-1', rider: named('r-1', 'Juma Rider'), currency: 'KES', deliveries: 2, expected_minor: '272000' }] }],
      [/^ecommerce\/cashups\?/, page([])],
    ])
    api.post.mockResolvedValue({ data: { id: 'cu-1' } })
    renderApp('/ecommerce/cashups')
    fireEvent.click(await screen.findByRole('button', { name: 'Cash up' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Close the cash-up' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/cashups', expect.objectContaining({ rider_id: 'r-1', currency: 'KES', counted_minor: '272000' })))
  })
})

describe('WhatsApp inbox (ECOM-25)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows a conversation and sends a reply', async () => {
    const conversation = { id: 'cv-1', store: named('s-1', 'Duka Online'), phone: '+254712345678', name: 'Wanjiku', status: 'open', order: null, last_message: 'Two widgets please' }
    mockEcommerce([
      [/^ecommerce\/conversations\?/, page([conversation])],
      ['ecommerce/conversations/cv-1', { data: { ...conversation, messages: [{ id: 'm-1', direction: 'in', body: 'Two widgets please', status: 'received', sent_by: null, at: '2026-11-30T08:00:00Z' }] } }],
    ])
    api.post.mockResolvedValue({ data: conversation })
    renderApp('/ecommerce/inbox')
    expect(await screen.findAllByText('Two widgets please')).not.toHaveLength(0)
    fireEvent.change(await screen.findByLabelText('Reply'), { target: { value: 'Sure.' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/conversations/cv-1/reply', { text: 'Sure.' }))
  })
})
