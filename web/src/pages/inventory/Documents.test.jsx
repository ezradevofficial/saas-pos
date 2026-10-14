// INV-09, INV-10: stock counts (start, count entry, save and submit) and adjustments (new, detail).
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const named = (id, name) => ({ id, name })
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })
const PCS = { id: 'u-pcs', code: 'PCS', name: 'Pieces' }
const ITEM = { id: 'i-1', code: 'MILK', name: 'Milk 1 l', type: 'stock', base_uom_id: 'u-pcs', uoms: [] }
const LOCATIONS = [{ id: 'l-1', name: 'Front store', branch_id: 'br-1', branch: named('br-1', 'Westlands'), archived_at: null }]

const COUNT = {
  id: 'k-1',
  number: 'CNT-WL-2026-00001',
  kind: 'cycle',
  status: 'counting',
  note: null,
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  location: named('l-1', 'Front store'),
  requested_by: named('u-2', 'Joseph Mwangi'),
  gain: null,
  loss: null,
  lines_count: 2,
  frozen_at: '2026-10-10T06:00:00Z',
  created_at: '2026-10-10T06:00:00Z',
  lines: [
    { id: 'cl-1', item: { id: 'i-1', code: 'MILK', name: 'Milk 1 l' }, base_uom: PCS, batch: null, expected_qty: '10.000000', counted_qty: null, variance_qty: null, variance_value: null },
    { id: 'cl-2', item: { id: 'i-3', code: 'BREAD', name: 'Bread' }, base_uom: PCS, batch: null, expected_qty: '5.000000', counted_qty: '5.000000', variance_qty: '0.000000', variance_value: null },
  ],
  can: { count: true, cancel: true },
}

const ADJUSTMENT = {
  id: 'a-1',
  number: 'ADJ-WL-2026-00001',
  status: 'failed',
  reason: 'damage',
  note: 'Fridge broke',
  failure: 'insufficient_stock',
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  location: named('l-1', 'Front store'),
  requested_by: named('u-2', 'Joseph Mwangi'),
  value_in: null,
  value_out: null,
  lines_count: 1,
  created_at: '2026-10-10T06:00:00Z',
  lines: [{ id: 'al-1', line_no: 1, item: { id: 'i-1', code: 'MILK', name: 'Milk 1 l' }, uom: PCS, qty: '-3.000000', qty_base: '-3.000000', batch: null, batch_number: null, expires_on: null, serials: [], unit_cost: null, value: null }],
  can: { cancel: false },
}

const PERMISSIONS = tenantWide(['core.company.view', 'inventory.count.view', 'inventory.count.create', 'inventory.count.count', 'inventory.adjustment.view', 'inventory.adjustment.create'])

function setup() {
  mockRoutes(
    api,
    [
      ['inventory/counts/k-1', { data: COUNT }],
      [/^inventory\/counts\?/, page([COUNT])],
      ['inventory/adjustments/a-1', { data: ADJUSTMENT }],
      [/^inventory\/adjustments\?/, page([ADJUSTMENT])],
      [
        'inventory/items/i-1',
        {
          data: { item: { ...ITEM, base_uom: PCS }, settings: { tracking: 'batch', track_expiry: true }, variant: null, variants: [], balances: [], on_hand: '0', batches: [], serials: [], reorder_rules: [], costs: [], has_movements: false },
        },
      ],
      [/^items\?/, page([ITEM])],
      [/^companies\?/, { data: [COMPANY] }],
      [/^branches\?/, { data: [{ id: 'br-1', name: 'Westlands', company_id: 'c-1', archived_at: null }] }],
      [/^locations\?/, { data: LOCATIONS }],
    ],
    { permissions: PERMISSIONS, modules: ['core', 'inventory'], companies: [COMPANY] },
  )
}

describe('Stock counts (INV-09)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('starts a cycle count of chosen items', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...COUNT } })
    const { router } = renderApp('/inventory/counts/new')
    await waitForOption(await screen.findByLabelText(/^Location/), 'Front store · Westlands')
    chooseOption(screen.getByLabelText(/^Location/), 'Front store · Westlands')
    chooseOption(screen.getByLabelText(/^Count type/), 'Cycle count')
    await waitForOption(screen.getByLabelText('Add an item'), 'Milk 1 l · MILK')
    chooseOption(screen.getByLabelText('Add an item'), 'Milk 1 l · MILK')
    expect(within(screen.getByRole('list', { name: 'Items to count' })).getByText('Milk 1 l · MILK')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Start count' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('inventory/counts', { location_id: 'l-1', kind: 'cycle', item_ids: ['i-1'] }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/inventory/counts/k-1'))
  })

  it('enters counts with the variance shown as typed, saves them, then submits', async () => {
    setup()
    api.put.mockResolvedValue({ data: { ...COUNT, lines: [{ ...COUNT.lines[0], counted_qty: '8.000000', variance_qty: '-2.000000' }, COUNT.lines[1]] } })
    api.post.mockResolvedValue({ data: { ...COUNT, status: 'pending', can: { count: false, cancel: true } } })
    renderApp('/inventory/counts/k-1')
    const table = await screen.findByRole('table', { name: 'Count entry' })
    const milk = within(table).getByText('Milk 1 l').closest('tr')
    fireEvent.change(within(milk).getByLabelText('Counted quantity of Milk 1 l'), { target: { value: '8' } })
    expect(within(milk).getByText('-2')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Save counts' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('inventory/counts/k-1/lines', { lines: [{ id: 'cl-1', counted_qty: '8' }] }))

    const submit = screen.getByRole('button', { name: 'Submit count' })
    expect(submit).toHaveAttribute('data-ds-variant', 'pay')
    fireEvent.click(submit)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('inventory/counts/k-1/submit'))
    expect(await screen.findByText('Count submitted. The variance goes for approval.')).toBeInTheDocument()
  })

  it('saves unsaved counts before submitting', async () => {
    setup()
    api.put.mockResolvedValue({ data: COUNT })
    api.post.mockResolvedValue({ data: { ...COUNT, status: 'posted', can: { count: false, cancel: false } } })
    renderApp('/inventory/counts/k-1')
    const table = await screen.findByRole('table', { name: 'Count entry' })
    fireEvent.change(within(table).getByLabelText('Counted quantity of Milk 1 l'), { target: { value: '10' } })
    fireEvent.click(screen.getByRole('button', { name: 'Submit count' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('inventory/counts/k-1/submit'))
    expect(api.put).toHaveBeenCalledWith('inventory/counts/k-1/lines', { lines: [{ id: 'cl-1', counted_qty: '10' }] })
  })
})

describe('Stock adjustments (INV-10)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('requests an increase of a batch item with its batch, expiry and unit cost (minor units in the base currency, ADR 003)', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...ADJUSTMENT, status: 'pending' } })
    const { router } = renderApp('/inventory/adjustments/new')
    await waitForOption(await screen.findByLabelText(/^Location/), 'Front store · Westlands')
    chooseOption(screen.getByLabelText(/^Location/), 'Front store · Westlands')
    chooseOption(screen.getByLabelText(/^Reason/), 'Found')
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Back of the store' } })
    await waitForOption(screen.getByLabelText(/^Item/), 'Milk 1 l · MILK')
    chooseOption(screen.getByLabelText(/^Item/), 'Milk 1 l · MILK')
    chooseOption(screen.getByLabelText('Change'), 'Increase stock')
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '6' } })
    fireEvent.change(screen.getByLabelText(/^Unit cost/), { target: { value: '55' } })
    fireEvent.change(await screen.findByLabelText(/^Batch number/), { target: { value: 'M-77' } })
    fireEvent.change(screen.getByLabelText(/^Expiry date/), { target: { value: '2026-11-30' } })
    fireEvent.click(screen.getByRole('button', { name: 'Request adjustment' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('inventory/adjustments', {
        location_id: 'l-1',
        reason: 'found',
        note: 'Back of the store',
        lines: [{ item_id: 'i-1', uom_id: 'u-pcs', qty: '6', batch_number: 'M-77', expires_on: '2026-11-30', unit_cost_minor: '5500', unit_cost_currency: 'KES' }],
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/inventory/adjustments/a-1'))
  })

  it('sends a decrease as a negative quantity', async () => {
    setup()
    api.post.mockResolvedValue({ data: ADJUSTMENT })
    renderApp('/inventory/adjustments/new')
    await waitForOption(await screen.findByLabelText(/^Location/), 'Front store · Westlands')
    chooseOption(screen.getByLabelText(/^Location/), 'Front store · Westlands')
    chooseOption(screen.getByLabelText(/^Reason/), 'Damaged')
    await waitForOption(screen.getByLabelText(/^Item/), 'Milk 1 l · MILK')
    chooseOption(screen.getByLabelText(/^Item/), 'Milk 1 l · MILK')
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '3' } })
    fireEvent.click(screen.getByRole('button', { name: 'Request adjustment' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('inventory/adjustments', { location_id: 'l-1', reason: 'damage', lines: [{ item_id: 'i-1', uom_id: 'u-pcs', qty: '-3' }] }),
    )
  })

  it('shows a failed adjustment with why, its signed lines and no values for a non-valuation reader', async () => {
    setup()
    renderApp('/inventory/adjustments/a-1')
    expect(await screen.findByRole('heading', { name: 'ADJ-WL-2026-00001' })).toBeInTheDocument()
    expect(screen.getByText('Not posted').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')
    expect(screen.getByText(/There was not enough stock when it was approved/)).toBeInTheDocument()
    const lines = screen.getByRole('table', { name: 'Lines' })
    expect(within(lines).getByText('-3')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancel adjustment' })).not.toBeInTheDocument()
  })
})
