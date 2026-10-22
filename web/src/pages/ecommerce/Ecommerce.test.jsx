// ECOM-16 to ECOM-30: the E-commerce back office: navigation (RBAC-08), the orders list and an
// order's actions, the delivery board, rider cash-ups and the WhatsApp inbox.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { COMPANY, named, page } from '@/test/crm'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

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

  it('shows money held beyond the order and offers its refund (ECOM-19)', async () => {
    const paid = {
      ...ORDER, status: 'accepted', payment_status: 'paid', payment_kind: 'mpesa', flags: [{ code: 'overpaid', detail: { payment: 'p-2' } }],
      payments: [{ id: 'p-1', kind: 'mpesa', currency: 'KES', amount_minor: '136000', status: 'succeeded', provider_reference: 'QJK1' },
        { id: 'p-2', kind: 'mpesa', currency: 'KES', amount_minor: '136000', status: 'refund_due', provider_reference: 'QJK2' }],
    }
    mockEcommerce([['ecommerce/orders/o-1', { data: paid }]])
    renderApp('/ecommerce/orders/o-1')
    expect(await screen.findByText('Paid twice: the extra payment is held for refund')).toBeInTheDocument()
    expect(screen.getByText(/Held for refund/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Record refund' })).toBeInTheDocument()
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

const BOARD_ROW = {
  id: 'd-1', order_id: 'o-1', order: { number: 'WEB-A-2026-00001', contact_name: 'Wanjiku Kamau', address: { line: 'Mpaka Road 12' }, zone: named('z-1', 'Westlands') },
  status: 'picked_up', rider: named('r-1', 'Juma Rider'), currency: 'KES', cod_due_minor: '0', last_location: null, can_track: false, proof_photo: null,
}

describe('Delivery tracking and proof photos (ECOM-27 to ECOM-29)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('hides the Track action without the tracking permission at the delivery', async () => {
    mockEcommerce([[/^ecommerce\/deliveries/, page([BOARD_ROW])]])
    renderApp('/ecommerce/deliveries')
    await screen.findByText('WEB-A-2026-00001')
    expect(screen.queryByRole('button', { name: /^Track/ })).not.toBeInTheDocument()
  })

  it('shows the last position, the trail and that it is cleared when the delivery ends', async () => {
    const last = { latitude: '-1.2700000', longitude: '36.8100000', at: '2026-11-30T09:20:00Z' }
    const track = { id: 'd-1', status: 'picked_up', active: true, last_location: last, trail: [{ latitude: '-1.2600000', longitude: '36.8000000', at: '2026-11-30T09:10:00Z' }, last] }
    mockEcommerce([
      ['ecommerce/deliveries/d-1/track', { data: track }],
      [/^ecommerce\/deliveries/, page([{ ...BOARD_ROW, can_track: true, last_location: last }])],
    ])
    renderApp('/ecommerce/deliveries')
    fireEvent.click(await screen.findByRole('button', { name: 'Track WEB-A-2026-00001' }))
    const dialog = await screen.findByRole('dialog', { name: 'Track WEB-A-2026-00001' })
    expect(await within(dialog).findByText('Under way')).toBeInTheDocument()
    expect(within(dialog).getAllByText('-1.2700000, 36.8100000')).not.toHaveLength(0)
    expect(within(dialog).getByRole('img', { name: 'Route of the last 2 reports' })).toBeInTheDocument()
    expect(within(dialog).getByText(/cleared when the delivery ends/)).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('ecommerce/deliveries/d-1/track')
  })

  it('opens the proof photo of a delivered order', async () => {
    const delivered = { ...ORDER, status: 'delivered', delivery: { id: 'd-1', status: 'delivered', rider: named('r-1', 'Juma Rider'), last_location: null, can_track: false, proof_photo: { url: 'https://files.example/proof.jpg?sig=1', mime: 'image/jpeg', at: '2026-11-30T10:00:00Z' } } }
    mockEcommerce([['ecommerce/orders/o-1', { data: delivered }]])
    renderApp('/ecommerce/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Open the proof photo' }))
    const dialog = await screen.findByRole('dialog', { name: 'Proof photo' })
    expect(within(dialog).getByRole('img', { name: 'Photo taken at the delivery of order WEB-A-2026-00001' })).toHaveAttribute('src', 'https://files.example/proof.jpg?sig=1')
  })
})

const DELIVERED = {
  ...ORDER, status: 'delivered', payment_status: 'cod_collected',
  lines: [{ ...ORDER.lines[0], qty: '2.000000', returned_qty: '0.000000', returnable_qty: '2.000000' }],
  returns: [],
}
const RETURN = {
  id: 'rt-1', number: 'WEB-A-2026-00001-R1', kind: 'return', reason: 'Too small', currency: 'KES',
  lines: [{ id: 'rl-1', direction: 'in', order_line_id: 'ln-1', product_id: 'p-1', name: 'Widget', qty: '1.000000', unit_price_minor: '38793', tax_minor: '6207', total_minor: '45000' }],
  returned_total_minor: '45000', exchange_total_minor: '0', refund_minor: '45000', collect_minor: '0', refund_status: 'due', collect_status: 'none',
  refund_reference: null, collect_reference: null, created_by: named('u-1', 'Amina Otieno'), created_at: '2026-12-01T09:00:00Z', refunded_at: null, collected_at: null,
}

describe('Partial returns and exchanges (ECOM-19)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('returns part of a delivered order and labels the confirm button from the server quote', async () => {
    let saved = DELIVERED
    mockEcommerce([['ecommerce/orders/o-1', () => ({ data: saved })]])
    api.post.mockImplementation(async (path) => {
      if (path === 'ecommerce/orders/o-1/returns/quote') return { data: { currency: 'KES', lines: [], returned_total_minor: '45000', exchange_total_minor: '0', refund_minor: '45000', collect_minor: '0' } }
      if (path === 'ecommerce/orders/o-1/returns') {
        saved = { ...DELIVERED, returns: [RETURN] }
        return { data: saved }
      }
      throw new Error(`unexpected ${path}`)
    })
    renderApp('/ecommerce/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Return or exchange' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^Widget/), { target: { value: '1' } })
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Too small' } })
    const confirm = await within(dialog).findByRole('button', { name: 'Refund KES 450.00' }, { timeout: 3000 })
    await waitFor(() => expect(confirm).toBeEnabled())
    const body = { reason: 'Too small', lines: [{ line_id: 'ln-1', qty: '1' }] }
    expect(api.post).toHaveBeenCalledWith('ecommerce/orders/o-1/returns/quote', body)
    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/orders/o-1/returns', body))
    expect(await screen.findByText('WEB-A-2026-00001-R1')).toBeInTheDocument()
  })

  it('exchanges for another product and asks to collect the difference', async () => {
    mockEcommerce([
      ['ecommerce/orders/o-1', { data: DELIVERED }],
      [/^ecommerce\/stores\/s-1\/products/, page([{ id: 'p-2', item: named('i-2', 'Gadget'), published: true, archived_at: null }])],
    ])
    api.post.mockResolvedValue({ data: { currency: 'KES', lines: [], returned_total_minor: '45000', exchange_total_minor: '57000', refund_minor: '0', collect_minor: '12000' } })
    renderApp('/ecommerce/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Return or exchange' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^Widget/), { target: { value: '1' } })
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Wrong size' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add an item' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('ecommerce/stores/s-1/products?per_page=200'))
    await waitFor(() => chooseOption(within(dialog).getByLabelText('Product'), 'Gadget'))
    expect(await within(dialog).findByRole('button', { name: 'Collect KES 120.00' }, { timeout: 3000 })).toBeInTheDocument()
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/orders/o-1/returns/quote', { reason: 'Wrong size', lines: [{ line_id: 'ln-1', qty: '1' }], exchange: [{ product_id: 'p-2', qty: '1' }] }))
  })

  it('records a refund by hand when the server asks for a reference', async () => {
    mockEcommerce([['ecommerce/orders/o-1', { data: { ...DELIVERED, returns: [RETURN] } }]])
    api.post
      .mockRejectedValueOnce(apiError(422, 'refund_reference_required', 'Pay this refund back by hand and type its reference.'))
      .mockResolvedValueOnce({ data: { ...DELIVERED, returns: [{ ...RETURN, refund_status: 'refunded', refund_reference: 'QK12' }] } })
    renderApp('/ecommerce/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Refund WEB-A-2026-00001-R1' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Refund KES 450.00' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/returns/rt-1/refund', {}))
    expect(await within(dialog).findByText('Pay this refund back by hand and type its reference.')).toBeInTheDocument()
    fireEvent.change(within(dialog).getByLabelText(/^Refund reference/), { target: { value: 'QK12' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Refund KES 450.00' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/returns/rt-1/refund', { reference: 'QK12' }))
    expect(await screen.findByText('Refund recorded.')).toBeInTheDocument()
  })

  it('collects an exchange difference with a payment method of the company', async () => {
    const exchange = { ...RETURN, id: 'rt-2', number: 'WEB-A-2026-00001-R2', kind: 'exchange', refund_minor: '0', collect_minor: '12000', refund_status: 'none', collect_status: 'due' }
    mockEcommerce([
      ['ecommerce/orders/o-1', { data: { ...DELIVERED, returns: [exchange] } }],
      ['companies/c-1/payment-methods', { data: [{ id: 'pm-1', name: 'Cash till', type: 'cash', active: true }] }],
    ])
    api.post.mockResolvedValue({ data: { ...DELIVERED, returns: [{ ...exchange, collect_status: 'collected' }] } })
    renderApp('/ecommerce/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Collect WEB-A-2026-00001-R2' }))
    expect(screen.queryByRole('button', { name: 'Mark returned' })).not.toBeInTheDocument()
    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: 'Collect KES 120.00' })
    expect(confirm).toBeDisabled()
    await waitFor(() => chooseOption(within(dialog).getByLabelText(/^Payment method/), 'Cash till'))
    fireEvent.change(within(dialog).getByLabelText(/^Payment reference/), { target: { value: 'R-77' } })
    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('ecommerce/returns/rt-2/collect', { payment_method_id: 'pm-1', reference: 'R-77' }))
  })

  it('hides Return or exchange from a reader without the manage permission', async () => {
    mockEcommerce([['ecommerce/orders/o-1', { data: DELIVERED }]], { permissions: tenantWide(['core.company.view', 'ecommerce.order.view']) })
    renderApp('/ecommerce/orders/o-1')
    await screen.findByText('Mpaka Road 12, Westlands')
    expect(screen.queryByRole('button', { name: 'Return or exchange' })).not.toBeInTheDocument()
  })
})

describe('WhatsApp order messages in store settings (ECOM-20)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('says when the provider is not configured', async () => {
    const store = {
      id: 's-1', name: 'Duka Online', handle: 'duka', status: 'live', currency: 'KES', company: named('c-1', 'Amani Retail'), branch: null, location: null, price_list_id: null,
      delivery_enabled: true, pickup_enabled: false, pickup_location_ids: [], min_order_minor: '0', reservation_minutes: 30, safety_buffer: '0', payment_methods: [],
      share: { url: 'https://acme.example/shop/duka', whatsapp: 'https://wa.me/?text=x' }, whatsapp_connected: false, whatsapp_notifications: 'not_configured',
    }
    mockEcommerce([['ecommerce/stores/s-1', { data: store }]])
    renderApp('/ecommerce/settings')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Links and WhatsApp' }))
    const badge = await screen.findByText('Not configured')
    expect(badge.closest('[data-tone]')).toHaveAttribute('data-tone', 'neutral')
    expect(screen.getByText(/customers get order updates by SMS and email only/)).toBeInTheDocument()
  })
})
