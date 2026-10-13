// INV-08, WF-10: the transfers list, a new transfer and a transfer's next actions from `can`.
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
const ITEM = { id: 'i-1', code: 'SOAP-1', name: 'Soap 1 kg', type: 'stock', base_uom_id: 'u-pcs', uoms: [] }
const SERIAL_ITEM = { id: 'i-2', code: 'PH-1', name: 'Phone', type: 'stock', base_uom_id: 'u-pcs', uoms: [] }
const LOCATIONS = [
  { id: 'l-1', name: 'Front store', branch: named('br-1', 'Westlands'), archived_at: null },
  { id: 'l-2', name: 'Warehouse', branch: named('br-1', 'Westlands'), archived_at: null },
]
const stockOf = (item, tracking) => ({
  data: { item: { ...item, base_uom: { id: 'u-pcs', code: 'PCS', name: 'Pieces' } }, settings: { tracking, track_expiry: false }, variant: null, variants: [], balances: [], on_hand: '0', batches: [], serials: [], reorder_rules: [], costs: [], has_movements: true },
})

const TRANSFER = {
  id: 't-1',
  number: 'TR-WL-2026-00001',
  status: 'in_transit',
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  from_location: named('l-2', 'Warehouse'),
  to_location: named('l-1', 'Front store'),
  note: null,
  requested_by: named('u-2', 'Joseph Mwangi'),
  value: { amount_minor: '120000', currency: 'KES' },
  lines_count: 1,
  created_at: '2026-10-08T09:00:00Z',
  dispatched_at: '2026-10-09T09:00:00Z',
  received_at: null,
  cancelled_at: null,
  decided_at: '2026-10-08T10:00:00Z',
  lines: [{ id: 'tl-1', line_no: 1, item: { id: 'i-1', code: 'SOAP-1', name: 'Soap 1 kg' }, uom: { id: 'u-pcs', code: 'PCS', name: 'Pieces' }, qty: '10.000000', qty_base: '10.000000', batch: null, serials: [], qty_received: null, qty_lost: null }],
  can: { dispatch: false, receive: true, cancel: false },
}

const PERMISSIONS = tenantWide(['core.company.view', 'inventory.transfer.view', 'inventory.transfer.create', 'inventory.transfer.receive'])

function setup(detail = TRANSFER) {
  mockRoutes(
    api,
    [
      [/^inventory\/transfers\?/, page([TRANSFER])],
      [/^inventory\/transfers\/t-\d$/, () => ({ data: detail })],
      ['inventory/items/i-1', stockOf(ITEM, 'none')],
      ['inventory/items/i-2', stockOf(SERIAL_ITEM, 'serial')],
      [/^items\?/, page([ITEM, SERIAL_ITEM])],
      [/^branches\?/, { data: [] }],
      [/^locations\?/, { data: LOCATIONS }],
    ],
    { permissions: PERMISSIONS, modules: ['core', 'inventory'], companies: [COMPANY] },
  )
}

describe('Inventory transfers (INV-08)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists transfers with number, status as a dot and a word, places and value', async () => {
    setup()
    renderApp('/inventory/transfers')
    const row = (await screen.findByText('TR-WL-2026-00001')).closest('tr')
    expect(within(row).getByText('In transit').closest('[data-tone]')).toHaveAttribute('data-tone', 'info')
    expect(within(row).getByText('Warehouse')).toBeInTheDocument()
    expect(within(row).getByText('Front store')).toBeInTheDocument()
    expect(within(row).getByText('1,200.00')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('inventory/transfers?sort=-created_at&per_page=25&page=1')
  })

  it('posts a new transfer with its lines, serials included for serial items', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...TRANSFER, id: 't-9', number: 'TR-WL-2026-00009', status: 'pending' } })
    const { router } = renderApp('/inventory/transfers/new')
    await waitForOption(await screen.findByLabelText(/^From location/), 'Warehouse · Westlands')
    chooseOption(screen.getByLabelText(/^From location/), 'Warehouse · Westlands')
    chooseOption(screen.getByLabelText(/^To location/), 'Front store · Westlands')

    await waitForOption(screen.getByLabelText(/^Item/), 'Soap 1 kg · SOAP-1')
    chooseOption(screen.getByLabelText(/^Item/), 'Soap 1 kg · SOAP-1')
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '10' } })

    fireEvent.click(screen.getByRole('button', { name: 'Add line' }))
    const second = screen.getByRole('group', { name: 'Line 2' })
    chooseOption(within(second).getByLabelText(/^Item/), 'Phone · PH-1')
    fireEvent.change(within(second).getByLabelText(/^Quantity/), { target: { value: '2' } })
    fireEvent.change(await within(second).findByLabelText(/^Serial numbers/), { target: { value: 'SN-1, SN-2' } })

    fireEvent.click(screen.getByRole('button', { name: 'Request transfer' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('inventory/transfers', {
        from_location_id: 'l-2',
        to_location_id: 'l-1',
        lines: [
          { item_id: 'i-1', uom_id: 'u-pcs', qty: '10' },
          { item_id: 'i-2', uom_id: 'u-pcs', qty: '2', serials: ['SN-1', 'SN-2'] },
        ],
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/inventory/transfers/t-9'))
  })

  it('refuses the same location for both ends before sending', async () => {
    setup()
    renderApp('/inventory/transfers/new')
    await waitForOption(await screen.findByLabelText(/^From location/), 'Warehouse · Westlands')
    chooseOption(screen.getByLabelText(/^From location/), 'Warehouse · Westlands')
    chooseOption(screen.getByLabelText(/^To location/), 'Warehouse · Westlands')
    expect(screen.getByText('Choose a location other than the source.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Request transfer' }))
    expect(api.post).not.toHaveBeenCalled()
  })

  it('offers only the actions the API allows: Receive here, not Dispatch or Cancel', async () => {
    setup()
    renderApp('/inventory/transfers/t-1')
    expect(await screen.findByRole('heading', { name: 'TR-WL-2026-00001' })).toBeInTheDocument()
    const receive = screen.getByRole('button', { name: 'Receive' })
    expect(receive).toHaveAttribute('data-ds-variant', 'pay')
    expect(screen.queryByRole('button', { name: 'Dispatch' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancel transfer' })).not.toBeInTheDocument()
    // The stages: requested, approval and dispatch done; receipt is where it is.
    expect(screen.getByText('Receipt').closest('li')).toHaveAttribute('data-state', 'current')
  })

  it('receives less than was sent with a reason for the loss', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...TRANSFER, status: 'received', can: { dispatch: false, receive: false, cancel: false } } })
    renderApp('/inventory/transfers/t-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Receive' }))
    const dialog = await screen.findByRole('dialog', { name: 'Receive transfer TR-WL-2026-00001' })
    fireEvent.change(within(dialog).getByLabelText(/^Soap 1 kg received/), { target: { value: '8' } })
    const confirm = within(dialog).getByRole('button', { name: 'Receive stock' })
    expect(confirm).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Reason for the loss/), { target: { value: 'Two broke on the way' } })
    fireEvent.click(confirm)
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('inventory/transfers/t-1/receive', { lines: [{ id: 'tl-1', qty_received: '8' }], loss_reason: 'Two broke on the way' }),
    )
  })

  it('dispatches an approved transfer and cancels with a reason when the API allows', async () => {
    setup({ ...TRANSFER, id: 't-2', status: 'approved', dispatched_at: null, can: { dispatch: true, receive: false, cancel: true } })
    api.post.mockResolvedValue({ data: { ...TRANSFER, id: 't-2' } })
    renderApp('/inventory/transfers/t-2')
    fireEvent.click(await screen.findByRole('button', { name: 'Dispatch' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('inventory/transfers/t-2/dispatch'))
    expect(screen.queryByRole('button', { name: 'Receive' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Cancel transfer' }))
    const dialog = await screen.findByRole('dialog', { name: 'Cancel transfer TR-WL-2026-00001' })
    fireEvent.change(within(dialog).getByLabelText(/^Reason for cancelling/), { target: { value: 'Not needed' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel document' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('inventory/transfers/t-2/cancel', { reason: 'Not needed' }))
  })
})
