// ECOM-05 to ECOM-13: the storefront: its own client (the store by host, never a
// back-office token), the catalogue, the cart priced by the server, and a checkout
// that sends an idempotency key and the total the customer saw.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockApi, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const STORE = {
  handle: 'duka', name: 'Duka Online', currency: 'KES', contact_phone: '+254700000001', contact_email: null, whatsapp: null, share: { url: 'https://acme.example/shop/duka', whatsapp: 'https://wa.me/?text=x' },
  min_order_minor: '0', delivery_enabled: true, pickup_enabled: false, pickup_locations: [], zones: [{ id: 'z-1', name: 'Westlands', areas: [], fee_minor: '20000', free_over_minor: null, eta: null }],
  payment_kinds: ['mpesa', 'cod'], categories: [], signed_in: false,
}
const WIDGET = {
  id: 'p-1', slug: 'widget', name: 'Widget', code: 'W', description: 'A fine widget.', featured: true, category: null, currency: 'KES', price_minor: '100000', tax_inclusive: false,
  orderable: true, unavailable_reason: null, availability: { state: 'low', left: '3' }, images: [], share: { url: 'u', whatsapp: 'https://wa.me/?text=Widget' },
}
const QUOTE = {
  currency: 'KES', tax_inclusive: false, fulfilment: 'delivery', zone_id: 'z-1', pickup_location_id: null,
  lines: [{ line_no: 1, product_id: 'p-1', item_id: 'i-1', name: 'Widget', qty: '1', unit_price_minor: '100000', gross_minor: '100000', discount_minor: '0', net_minor: '100000', tax_minor: '16000', total_minor: '116000', tax_rate: '16', promotion: null }],
  totals: { subtotal_minor: '100000', discount_minor: '0', tax_minor: '16000', delivery_fee_minor: '20000', delivery_tax_minor: '0', total_minor: '136000' },
  delivery_minor: '20000', coupon: null, promotions: [], loyalty: { points: null, points_minor: '0', gift_card_minor: '0' }, loyalty_paid_minor: '0', due_minor: '136000', problems: [],
}

let calls = []

function json(body, status = 200) {
  return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }))
}

function mockShop(extra = {}) {
  calls = []
  vi.stubGlobal('fetch', vi.fn((url, init = {}) => {
    const path = String(url).replace(/^.*\/api\/v1\/shop\/duka/, '')
    calls.push({ path, init })
    if (path === '' && (init.method ?? 'GET') === 'GET') return json({ data: STORE })
    if (path.startsWith('/products?')) return json({ data: [WIDGET], meta: { total: 1, current_page: 1, last_page: 1 } })
    if (path === '/products/widget') return json({ data: WIDGET })
    if (path === '/quote') return json({ data: QUOTE })
    if (extra[path]) return extra[path](init)
    return json({ message: 'Not found', code: 'not_found' }, 404)
  }))
}

describe('Storefront (ECOM-05 to ECOM-13)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    mockApi(api, { permissions: [], modules: ['core'] })
    window.localStorage.clear()
    window.sessionStorage.clear()
  })

  afterEach(() => vi.unstubAllGlobals())

  it('shows the store and its products, naming the store by host and never sending a back-office token', async () => {
    signedIn('back-office-token')
    mockShop()
    renderApp('/shop/duka')
    expect(await screen.findByRole('heading', { name: 'Duka Online' })).toBeInTheDocument()
    const card = (await screen.findByRole('link', { name: 'Widget' })).closest('li')
    expect(within(card).getByText('Only 3 left').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(card).getByText('1,000.00')).toBeInTheDocument()
    for (const call of calls) {
      expect(call.init.headers['X-Shop-Host']).toBe(window.location.host)
      expect(call.init.headers.Authorization).toBeUndefined()
      expect(call.init.credentials).toBe('omit')
    }
  })

  it('prices the cart on the server and checks out with an idempotency key and the total it showed', async () => {
    let placed = null
    mockShop({
      '/orders': (init) => {
        placed = init
        return json({ data: { number: 'WEB-A-2026-00001', status: 'placed', payment_status: 'cod', tracking_token: 'a'.repeat(40), payment: null } }, 201)
      },
      [`/orders/WEB-A-2026-00001?t=${'a'.repeat(40)}`]: () => json({ data: { number: 'WEB-A-2026-00001', status: 'placed', payment_status: 'cod', payment_kind: 'cod', fulfilment: 'delivery', currency: 'KES',
        totals: { total_minor: '136000', delivery_minor: '20000' }, lines: [{ name: 'Widget', qty: '1.000000', total_minor: '116000' }], history: [{ status: 'placed', at: '2026-11-30T09:00:00Z' }], rider: null } }),
    })
    const { router } = renderApp('/shop/duka')
    fireEvent.click(await screen.findByRole('button', { name: 'Add Widget to cart' }))
    await router.navigate('/shop/duka/cart')
    expect(await screen.findByText('1,360.00')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Go to checkout' }))

    fireEvent.change(await screen.findByLabelText(/^Address/), { target: { value: 'Mpaka Road 12' } })
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Wanjiku Kamau' } })
    fireEvent.change(screen.getByLabelText(/^Phone/), { target: { value: '0712345678' } })
    fireEvent.click(screen.getByLabelText(/Cash on delivery/))
    const place = await screen.findByRole('button', { name: 'Place order and pay KES 1,360.00' })
    await waitFor(() => expect(place).toBeEnabled())
    fireEvent.click(place)

    await waitFor(() => expect(placed).not.toBeNull())
    const body = JSON.parse(placed.body)
    expect(placed.headers['Idempotency-Key']).toMatch(/^web-[0-9a-f]{32}$/)
    expect(body.expected_total_minor).toBe('136000')
    expect(body.lines).toEqual([{ product_id: 'p-1', qty: '1' }])
    expect(body.unit_price_minor).toBeUndefined()
    expect(await screen.findByRole('heading', { name: 'Order WEB-A-2026-00001' })).toBeInTheDocument()
    expect(window.localStorage.getItem('app.shop.cart.duka')).toBe('[]')
  })

  it('says when the store is not open', async () => {
    vi.stubGlobal('fetch', vi.fn(() => json({ message: 'Not found', code: 'not_found' }, 404)))
    renderApp('/shop/nowhere')
    expect(await screen.findByText('This store is not open. Check the address.')).toBeInTheDocument()
  })
})
