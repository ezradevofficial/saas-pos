// INV-01, INV-02, INV-05, INV-11, INV-18, INV-19, RBAC-08, RBAC-09: the sidebar group,
// stock on hand with its filters, opening stock and the item stock detail.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { openFilters } from '@/test/filters'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const named = (id, name) => ({ id, name })
const page = (data, meta = {}) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length, ...meta } })
const ITEM = { id: 'i-1', code: 'SOAP-1', name: 'Soap 1 kg', type: 'stock', base_uom_id: 'u-pcs', uoms: [{ uom_id: 'u-box', code: 'BOX', factor: '12' }] }

const ROW = {
  id: 'b-1',
  item: { id: 'i-1', code: 'SOAP-1', name: 'Soap 1 kg' },
  base_uom: { id: 'u-pcs', code: 'PCS', name: 'Pieces' },
  company_id: 'c-1',
  location: named('l-1', 'Front store'),
  qty: '4.000000',
  reorder_level: '10.000000',
  reorder_qty: '24.000000',
  low: true,
  value: { amount_minor: '48000', currency: 'KES' },
}

const DETAIL = {
  item: { id: 'i-1', code: 'SOAP-1', name: 'Soap 1 kg', type: 'stock', base_uom: { id: 'u-pcs', code: 'PCS', name: 'Pieces' } },
  settings: { tracking: 'batch', track_expiry: true },
  variant: null,
  variants: [],
  balances: [
    { location: named('l-1', 'Front store'), qty: '4.000000', reorder_level: '10.000000', low: true },
    { location: named('l-2', 'Back store'), qty: '30.000000', reorder_level: null, low: false },
  ],
  on_hand: '34.000000',
  batches: [{ id: 'bt-1', batch_number: 'B-2026-01', expires_on: '2026-01-31', expired: true, balances: [{ location: named('l-1', 'Front store'), qty: '4.000000' }] }],
  serials: [],
  reorder_rules: [],
  costs: [{ company: named('c-1', 'Amani Retail'), qty: '34.000000', value: { amount_minor: '408000', currency: 'KES' } }],
  has_movements: true,
}

const PERMISSIONS = tenantWide(['core.company.view', 'inventory.stock.view', 'inventory.valuation.view', 'inventory.opening.create', 'inventory.item.configure'])

function setup({ permissions = PERMISSIONS, modules = ['core', 'inventory'] } = {}) {
  mockRoutes(
    api,
    [
      [/^inventory\/stock\?/, (path) => page(path.includes('flag=low') ? [ROW] : [ROW, { ...ROW, id: 'b-2', location: named('l-2', 'Back store'), qty: '30.000000', reorder_level: null, low: false }])],
      ['inventory/items/i-1', { data: DETAIL }],
      [/^inventory\/items\/i-1\/movements\?/, page([])],
      [/^items\?/, page([ITEM])],
      [/^branches\?/, { data: [] }],
      [/^locations\?/, { data: [{ id: 'l-1', name: 'Front store', branch: named('br-1', 'Westlands'), archived_at: null }] }],
    ],
    { permissions, modules, companies: [COMPANY] },
  )
}

describe('Inventory stock (INV-05, INV-19)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the Inventory group in the sidebar only while the module is active (RBAC-08)', async () => {
    setup()
    renderApp('/inventory/stock')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(await within(nav).findByRole('link', { name: 'Stock on hand' })).toHaveAttribute('href', '/inventory/stock')
    expect(within(nav).getByRole('link', { name: 'Movements' })).toBeInTheDocument()
    // RBAC-09: no transfer, count or label permission, so those pages are not offered.
    expect(within(nav).queryByRole('link', { name: 'Transfers' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Labels' })).not.toBeInTheDocument()
  })

  it('hides the Inventory group without the module', async () => {
    setup({ modules: ['core'] })
    renderApp('/')
    const nav = await screen.findByRole('navigation', { name: 'Main' })
    await within(nav).findByRole('link', { name: 'Dashboard' })
    expect(within(nav).queryByRole('link', { name: 'Stock on hand' })).not.toBeInTheDocument()
  })

  it('lists stock per item and location with the reorder level, low as a dot and a word, and the value', async () => {
    setup()
    renderApp('/inventory/stock')
    const table = await screen.findByRole('table', { name: 'Stock on hand' })
    const row = (await within(table).findByText('Front store')).closest('tr')
    expect(within(row).getByText('Soap 1 kg')).toBeInTheDocument()
    expect(within(row).getByText('SOAP-1')).toBeInTheDocument()
    expect(within(row).getByText('4')).toBeInTheDocument()
    expect(within(row).getByText('10')).toBeInTheDocument()
    expect(within(row).getByText('Low').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(row).getByText('480.00')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('inventory/stock?sort=code&per_page=25&page=1')
  })

  it('filters to low stock through the API (?flag=low)', async () => {
    setup()
    const { router } = renderApp('/inventory/stock')
    await screen.findByText('Back store')
    openFilters()
    chooseOption('Stock level', 'Low only')
    await waitFor(() => expect(router.state.location.search).toBe('?flag=low'))
    expect(api.get).toHaveBeenCalledWith('inventory/stock?flag=low&sort=code&per_page=25&page=1')
  })

  it('opens the item stock detail from a row', async () => {
    setup()
    const { router } = renderApp('/inventory/stock')
    fireEvent.click(await screen.findByText('Back store'))
    await waitFor(() => expect(router.state.location.pathname).toBe('/inventory/items/i-1'))
  })

  it('adds opening stock with the batch the item tracks (INV-18)', async () => {
    setup()
    api.post.mockResolvedValue({ data: { id: 'm-1' } })
    renderApp('/inventory/stock')
    fireEvent.click(await screen.findByRole('button', { name: 'Add opening stock' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add opening stock' })
    await waitForOption(within(dialog).getByLabelText(/^Item/), 'Soap 1 kg · SOAP-1')
    chooseOption(within(dialog).getByLabelText(/^Item/), 'Soap 1 kg · SOAP-1')
    chooseOption(within(dialog).getByLabelText(/^Location/), 'Front store · Westlands')
    fireEvent.change(within(dialog).getByLabelText(/^Quantity/), { target: { value: '12' } })
    fireEvent.change(within(dialog).getByLabelText(/^Unit cost/), { target: { value: '120.50' } })
    fireEvent.change(await within(dialog).findByLabelText(/^Batch number/), { target: { value: 'B-7' } })
    fireEvent.change(within(dialog).getByLabelText(/^Expiry date/), { target: { value: '2027-03-31' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add opening stock' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('inventory/opening-stock', {
        item_id: 'i-1',
        location_id: 'l-1',
        uom_id: 'u-pcs',
        qty: '12',
        unit_cost: '120.50',
        batch_number: 'B-7',
        expires_on: '2027-03-31',
      }),
    )
  })
})

describe('Item stock detail (INV-01, INV-02, INV-06)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows stock by location with low marked, and the cost per company for valuation holders', async () => {
    setup()
    renderApp('/inventory/items/i-1')
    expect(await screen.findByRole('heading', { name: 'Soap 1 kg' })).toBeInTheDocument()
    const table = screen.getByRole('table', { name: 'By location' })
    const front = within(table).getByText('Front store').closest('tr')
    expect(within(front).getByText('Low')).toBeInTheDocument()
    expect(within(screen.getByRole('table', { name: 'Cost per company' })).getByText('4,080.00')).toBeInTheDocument()
  })

  it('switches tabs: batches with expired marked, and tracking locked once the item has movements', async () => {
    setup()
    renderApp('/inventory/items/i-1')
    await screen.findByRole('heading', { name: 'Soap 1 kg' })

    fireEvent.mouseDown(screen.getByRole('tab', { name: /Batches/ }), { button: 0, ctrlKey: false })
    const batches = await screen.findByRole('table', { name: 'Batches' })
    expect(within(batches).getByText('B-2026-01')).toBeInTheDocument()
    expect(within(batches).getByText('Expired').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')

    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Movements' }), { button: 0, ctrlKey: false })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('inventory/items/i-1/movements?sort=-occurred_at&per_page=25&page=1'))

    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Settings' }), { button: 0, ctrlKey: false })
    expect(await screen.findByText('Tracking can no longer change')).toBeInTheDocument()
    expect(screen.getByLabelText('Tracking')).toBeDisabled()
    expect(screen.getByRole('switch', { name: 'Track expiry dates' })).toBeEnabled()
  })

  it('links the item as a variant of a main item with its attributes', async () => {
    setup()
    api.put.mockResolvedValue({ data: { parent: named('i-9', 'Shirt'), attributes: { size: 'M' } } })
    mockRoutes(api, [
      ['inventory/items/i-1', { data: { ...DETAIL, has_movements: false } }],
      [/^items\?/, page([ITEM, { ...ITEM, id: 'i-9', code: 'SHIRT', name: 'Shirt' }])],
      [/^(branches|locations)\?/, { data: [] }],
    ], { permissions: PERMISSIONS, modules: ['core', 'inventory'], companies: [COMPANY] })
    renderApp('/inventory/items/i-1?tab=settings')
    await screen.findByText('Variant link')
    await waitForOption('Main item', 'Shirt · SHIRT')
    chooseOption('Main item', 'Shirt · SHIRT')
    fireEvent.change(screen.getByLabelText('Attribute'), { target: { value: 'Size' } })
    fireEvent.change(screen.getByLabelText('Value'), { target: { value: 'M' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save variant link' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('inventory/items/i-1/variant', { parent_item_id: 'i-9', attributes: { Size: 'M' } }))
  })
})
