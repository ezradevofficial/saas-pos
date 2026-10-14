// STR-02 to STR-06, STR-12: a requisition (new, issue part of it), an
// issue (acknowledge, return), and the consumption report's grouping.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, optionTexts, waitForOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Hotels', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const named = (id, name) => ({ id, name })
const page = (data, meta = {}) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length, ...meta } })
const EA = { id: 'u-ea', code: 'EA', name: 'Each' }
const ITEM = { id: 'i-1', code: 'SOAP', name: 'Soap', type: 'stock', base_uom_id: 'u-ea', uoms: [] }
const LOCATIONS = [
  { id: 'l-1', name: 'Main store', type: 'store', branch_id: 'br-1', branch: named('br-1', 'Westlands'), archived_at: null },
  { id: 'l-2', name: 'Front desk', type: 'outlet', branch_id: 'br-1', branch: named('br-1', 'Westlands'), archived_at: null },
]
const KITCHEN = { id: 'd-1', code: 'KIT', name: 'Kitchen', archived_at: null }

const REQUISITION = {
  id: 'r-1',
  number: 'REQ-WL-2026-00001',
  status: 'approved',
  company: named('c-1', 'Amani Hotels'),
  branch: named('br-1', 'Westlands'),
  location: named('l-1', 'Main store'),
  department: { id: 'd-1', code: 'KIT', name: 'Kitchen' },
  cost_centre: null,
  needed_by: null,
  note: null,
  requested_by: named('u-2', 'Joseph Mwangi'),
  lines_count: 1,
  created_at: '2026-10-10T06:00:00Z',
  lines: [{ id: 'rl-1', line_no: 1, item: { id: 'i-1', code: 'SOAP', name: 'Soap' }, uom: EA, qty: '10', qty_base: '10', qty_issued: '0', qty_open: '10', note: null }],
  issues: [],
  can: { cancel: true, issue: true, close: true },
}

const ISSUE = {
  id: 's-1',
  number: 'ISS-WL-2026-00001',
  status: 'issued',
  ledger: 'stores',
  requisition: { id: 'r-1', number: 'REQ-WL-2026-00001' },
  company: named('c-1', 'Amani Hotels'),
  branch: named('br-1', 'Westlands'),
  location: named('l-1', 'Main store'),
  department: { id: 'd-1', code: 'KIT', name: 'Kitchen' },
  cost_centre: null,
  note: null,
  issued_by: named('u-3', 'Grace Achieng'),
  issued_at: '2026-10-10T07:00:00Z',
  acknowledged_by: null,
  acknowledged_at: null,
  valued: false,
  value: null,
  lines_count: 1,
  lines: [{ id: 'sl-1', line_no: 1, requisition_line_id: 'rl-1', item: { id: 'i-1', code: 'SOAP', name: 'Soap' }, uom: EA, qty: '4', qty_base: '4', qty_returned_base: '0', value: null }],
  returns: [],
  can: { acknowledge: true, return: true },
}

const PERMISSIONS = tenantWide(['core.company.view', 'stores.requisition.view', 'stores.requisition.create', 'stores.issue.view', 'stores.issue.create', 'stores.issue.acknowledge', 'stores.return.create', 'stores.report.view'])

function setup() {
  mockRoutes(
    api,
    [
      ['stores/requisitions/r-1', { data: REQUISITION }],
      [/^stores\/requisitions\?/, page([REQUISITION])],
      ['stores/issues/s-1', { data: ISSUE }],
      [/^stores\/consumption\?/, page([{ id: 'x', group: { id: 'd-1', code: 'KIT', name: 'Kitchen' }, company: named('c-1', 'Amani Hotels'), item: { id: 'i-1', code: 'SOAP', name: 'Soap' }, unit: EA, issued_qty: '4', returned_qty: '1', net_qty: '3', valued: false, issued_value: null, returned_value: null, net_value: null }], { totals: [] })],
      ['stores/status', { data: { ledger: 'stores', inventory_active: false } }],
      [/^document-workflows\//, { data: null }],
      [/^companies\/c-1\/departments\?/, { data: [KITCHEN] }],
      [/^companies\/c-1\/cost-centres\?/, { data: [] }],
      [/^items\?/, page([ITEM])],
      [/^uoms/, page([EA])],
      [/^companies\?/, { data: [COMPANY] }],
      [/^branches\?/, { data: [{ id: 'br-1', name: 'Westlands', company_id: 'c-1', archived_at: null }] }],
      [/^locations\?/, { data: LOCATIONS }],
    ],
    { permissions: PERMISSIONS, modules: ['core', 'stores'], companies: [COMPANY] },
  )
}

describe('Stores (STR-02 to STR-06, STR-12)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('asks a store for goods for a department, offering stores only', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...REQUISITION, status: 'pending' } })
    const { router } = renderApp('/stores/requisitions/new')
    await waitForOption(await screen.findByRole('combobox', { name: /^Store/ }), 'Main store · Westlands')
    expect(optionTexts(screen.getByRole('combobox', { name: /^Store/ }))).not.toContain('Front desk · Westlands')
    chooseOption(screen.getByRole('combobox', { name: /^Store/ }), 'Main store · Westlands')
    await waitForOption(screen.getByLabelText(/^Department/), 'Kitchen · KIT')
    chooseOption(screen.getByLabelText(/^Department/), 'Kitchen · KIT')
    await waitForOption(screen.getByLabelText(/^Item/), 'Soap · SOAP')
    chooseOption(screen.getByLabelText(/^Item/), 'Soap · SOAP')
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '10' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send for approval' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('stores/requisitions', { location_id: 'l-1', department_id: 'd-1', lines: [{ item_id: 'i-1', uom_id: 'u-ea', qty: '10' }] }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/stores/requisitions/r-1'))
  })

  it('issues part of an approved requisition', async () => {
    setup()
    api.post.mockResolvedValue({ data: ISSUE })
    const { router } = renderApp('/stores/requisitions/r-1')
    expect(await screen.findByRole('heading', { name: 'REQ-WL-2026-00001' })).toBeInTheDocument()
    expect(screen.getByText('Approved').closest('[data-tone]')).toHaveAttribute('data-tone', 'info')
    fireEvent.change(screen.getByLabelText(/^Issue \(EA\)/), { target: { value: '4' } })
    fireEvent.click(screen.getByRole('button', { name: 'Issue goods' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('stores/requisitions/r-1/issues', { lines: [{ requisition_line_id: 'rl-1', qty: '4' }] }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/stores/issues/s-1'))
  })

  it('acknowledges an issue with the decisive action and returns part of it', async () => {
    setup()
    api.post.mockResolvedValueOnce({ data: { ...ISSUE, status: 'acknowledged', can: { acknowledge: false, return: true } } })
    renderApp('/stores/issues/s-1')
    expect(await screen.findByRole('heading', { name: 'ISS-WL-2026-00001' })).toBeInTheDocument()
    const acknowledge = screen.getByRole('button', { name: 'Acknowledge receipt' })
    expect(acknowledge).toHaveAttribute('data-ds-variant', 'pay')
    fireEvent.click(acknowledge)
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Acknowledge receipt' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('stores/issues/s-1/acknowledge', {}))
    expect(await screen.findByText('Receipt acknowledged')).toBeInTheDocument()

    api.post.mockResolvedValueOnce({ data: { id: 'n-1', number: 'SRN-WL-2026-00001' } })
    fireEvent.click(screen.getByRole('button', { name: 'Return goods' }))
    fireEvent.change(await screen.findByLabelText(/^Return \(EA\)/), { target: { value: '1' } })
    chooseOption(screen.getByLabelText(/^Reason/), 'Unused')
    fireEvent.click(screen.getByRole('button', { name: 'Return to store' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('stores/issues/s-1/returns', { reason: 'unused', lines: [{ issue_line_id: 'sl-1', qty: '1' }] }))
  })

  it('reports consumption by department and switches the grouping', async () => {
    setup()
    renderApp('/stores/consumption')
    const table = await screen.findByRole('table')
    expect(await within(table).findByText('Kitchen')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^stores\/consumption\?.*group=department/))
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Month' }))
    fireEvent.click(screen.getByRole('tab', { name: 'Month' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^stores\/consumption\?.*group=period/)))
  })
})
