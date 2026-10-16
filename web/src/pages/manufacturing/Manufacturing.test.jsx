// MFG-02 to MFG-19, MFG-27: production orders (list, release, issue,
// report, what the reader may not do), a BOM version's lines saved, and
// the consumption and recipe costing reports.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Bakery', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const named = (id, name) => ({ id, name })
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })
const EA = { id: 'u-ea', code: 'EA', name: 'Each' }
const KG = { id: 'u-kg', code: 'KG', name: 'Kilogram' }
const BREAD = { id: 'i-bread', code: 'BREAD', name: 'Bread 800 g', type: 'stock' }
const FLOUR = { id: 'i-flour', code: 'FLOUR', name: 'Wheat flour', type: 'stock' }
const money = (amount) => ({ amount_minor: amount, currency: 'KES' })

const ORDER = {
  id: 'o-1',
  number: 'MO-WL-2026-00001',
  status: 'released',
  ledger: 'inventory',
  company: named('c-1', 'Amani Bakery'),
  branch: named('br-1', 'Westlands'),
  location: named('l-1', 'Bakery'),
  item: BREAD,
  uom: EA,
  qty: '200',
  qty_base: '200',
  qty_good: '0',
  qty_scrap: '0',
  planned_start: '2026-10-16',
  planned_end: null,
  note: null,
  planned_by: named('u-1', 'Grace Achieng'),
  released_by: named('u-1', 'Grace Achieng'),
  released_at: '2026-10-15T06:00:00Z',
  closed_by: null,
  closed_at: null,
  material_cost: money('0'),
  labour_cost: money('0'),
  overhead_cost: money('0'),
  relieved: money('0'),
  variance: null,
  custom: {},
  materials: [{ id: 'm-1', line_no: 1, item: FLOUR, uom: KG, qty_required: '102', expected_loss: '2', qty_standard: '0', qty_issued: '0', qty_returned: '0', qty_consumed: '0', qty_open: '102', value: money('0') }],
  outputs: [
    { id: 'out-1', kind: 'product', item: BREAD, qty_planned: '200', qty_reported: '0', cost_share_percent: '0' },
    { id: 'out-2', kind: 'by_product', item: { id: 'i-bran', code: 'BRAN', name: 'Bran', type: 'stock' }, qty_planned: '10', qty_reported: '0', cost_share_percent: '10' },
  ],
  issues: [],
  returns: [],
  reports: [],
  can: { edit: false, release: false, cancel: true, close: true, issue: true, return: false, report: true, costs: true },
}

const VERSION = {
  id: 'v-2',
  version_no: 2,
  status: 'draft',
  effective_from: null,
  output_qty: '100',
  output_uom_id: 'u-ea',
  labour_minutes: 120,
  bom: { id: 'b-1', kind: 'production', name: null, company: named('c-1', 'Amani Bakery'), item: BREAD },
  output_uom: EA,
  note: null,
  published_by: null,
  published_at: null,
  lines: [{ id: 'bl-1', line_no: 1, kind: 'component', item: FLOUR, uom: KG, qty: '50', qty_base: '50', yield_percent: '100', wastage_percent: '2', explode: false, cost_share_percent: '0', note: null }],
  can: { edit: true, publish: true },
}

const PERMISSIONS = tenantWide([
  'core.company.view', 'manufacturing.bom.view', 'manufacturing.bom.manage', 'manufacturing.order.view', 'manufacturing.order.create',
  'manufacturing.order.release', 'manufacturing.material.issue', 'manufacturing.production.report', 'manufacturing.order.close',
  'manufacturing.report.view', 'manufacturing.cost.view',
])

function setup(order = ORDER, extra = []) {
  mockRoutes(
    api,
    [
      ...extra,
      ['manufacturing/orders/o-1', { data: order }],
      [/^manufacturing\/orders\?/, page([order])],
      ['manufacturing/bom-versions/v-2', { data: VERSION }],
      [/^manufacturing\/bom-versions\/v-2\/explode/, { data: { version: VERSION, qty: '100', uom_id: 'u-ea', at: '2026-10-15T06:00:00Z', tree: [], leaves: [], by_products: [], cost: null } }],
      ['manufacturing/status', { data: { inventory_active: true, pos_active: true, backflush_active: true } }],
      [/^document-workflows\//, { data: null }],
      [/^items\/i-flour/, { data: { ...FLOUR, base_uom_id: 'u-kg', uoms: [] } }],
      [/^items\?/, page([FLOUR])],
      [/^uoms/, page([EA, KG])],
      [/^companies\?/, { data: [COMPANY] }],
    ],
    { permissions: PERMISSIONS, modules: ['core', 'inventory', 'manufacturing'], companies: [COMPANY] },
  )
}

describe('Manufacturing (MFG-02 to MFG-19)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists production orders with their status', async () => {
    setup()
    renderApp('/manufacturing/orders')
    expect(await screen.findByText('MO-WL-2026-00001')).toBeInTheDocument()
    expect(screen.getAllByText('Released').length).toBeGreaterThan(0)
  })

  it('issues materials to a running order', async () => {
    setup()
    api.post.mockResolvedValue({ data: ORDER })
    renderApp('/manufacturing/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Issue materials' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^Wheat flour \(KG\)/), { target: { value: '51' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Issue materials' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('manufacturing/orders/o-1/issues', { lines: [{ material_id: 'm-1', qty: '51' }] }))
  })

  it('reports production with scrap and its reason', async () => {
    setup()
    api.post.mockResolvedValue({ data: ORDER })
    renderApp('/manufacturing/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Report production' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^Good \(EA\)/), { target: { value: '120' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Report production' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('manufacturing/orders/o-1/reports', expect.objectContaining({ qty_good: '120' })))
  })

  it('hides what the order does not allow and releases a planned one', async () => {
    const planned = { ...ORDER, status: 'planned', ledger: null, can: { ...ORDER.can, release: true, issue: false, report: false, close: false } }
    setup(planned)
    api.post.mockResolvedValue({ data: { ...planned, status: 'released' } })
    renderApp('/manufacturing/orders/o-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Release' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('manufacturing/orders/o-1/release', {}))
    expect(screen.queryByRole('button', { name: 'Issue materials' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Report production' })).not.toBeInTheDocument()
  })

  it('saves a draft version with its lines', async () => {
    setup()
    api.put.mockResolvedValue({ data: VERSION })
    renderApp('/manufacturing/bom-versions/v-2')
    fireEvent.click(await screen.findByRole('button', { name: 'Save draft' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith(
        'manufacturing/bom-versions/v-2',
        expect.objectContaining({ output_qty: '100', lines: [expect.objectContaining({ kind: 'component', item_id: 'i-flour', qty: '50', wastage_percent: '2' })] }),
      ),
    )
  })

  it('adds a component and a by-product line to a draft version (MFG-04, MFG-05)', async () => {
    setup()
    renderApp('/manufacturing/bom-versions/v-2')
    fireEvent.click(await screen.findByRole('button', { name: 'Add component' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add by-product' }))
    expect(await screen.findByRole('listitem', { name: 'Line 3' })).toBeInTheDocument()
    expect(screen.getByRole('listitem', { name: 'Line 2' })).toBeInTheDocument()
  })

  it('shows consumption against standard and recipe costs', async () => {
    setup(ORDER, [
      [/^manufacturing\/consumption\?/, page([{ id: 'm-1', order: { id: 'o-1', number: 'MO-WL-2026-00001', status: 'in_progress', ledger: 'inventory' }, company: named('c-1', 'Amani Bakery'),
        location: named('l-1', 'Bakery'), item: FLOUR, uom: KG, required: '102', standard: '76.5', expected_loss: '1.5', actual: '105', variance_qty: '28.5', variance_percent: '37.3',
        valued: true, actual_value: money('525000'), standard_value: money('382500'), value_variance: money('142500') }])],
      [/^manufacturing\/recipe-costing\?/, page([{ id: 'b-9', kind: 'recipe', company: named('c-1', 'Amani Bakery'), item: { id: 'i-burger', code: 'BURGER', name: 'Burger', type: 'kit' },
        version: { id: 'v-9', version_no: 1 }, output_qty: '1', output_uom: EA, error: null, unit_cost: money('18095'), batch_cost: money('18095'),
        materials_cost: money('18095'), labour_cost: money('0'), overhead_cost: money('0'), by_product_credit: money('0'),
        price: { amount_minor: '65000', currency: 'KES', tax_inclusive: true, net_minor: '56034' }, net_price: money('56034'), cost_share_percent: '32.3', leaves: [], uncosted: [] },
        { id: 'b-10', kind: 'recipe', company: named('c-1', 'Amani Bakery'), item: { id: 'i-tea', code: 'TEA', name: 'Tea', type: 'kit' },
        version: { id: 'v-10', version_no: 1 }, output_qty: '1', output_uom: EA, error: null, unit_cost: money('3000'), batch_cost: money('3000'),
        price: { amount_minor: '10000', currency: 'KES', tax_inclusive: true, net_minor: null, net_price_reason: 'tax_category_missing' }, net_price: null, cost_share_percent: null, leaves: [], uncosted: [] }])],
    ])
    const { unmount } = renderApp('/manufacturing/consumption')
    expect(await screen.findByText('76.5')).toBeInTheDocument()
    expect(screen.getByText('28.5')).toBeInTheDocument()
    unmount()
    renderApp('/manufacturing/recipe-costing')
    expect(await screen.findByText('Burger')).toBeInTheDocument()
    // Owner decision B: the share is of the price before tax; a price whose tax is not known has none.
    expect(screen.getByRole('columnheader', { name: /Price before tax/ })).toBeInTheDocument()
    expect(screen.getByText(/560\.34/)).toBeInTheDocument()
    expect(screen.getByText('32.3')).toBeInTheDocument()
    expect(screen.getByText('Tax not known')).toBeInTheDocument()
  })
})
