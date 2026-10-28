// VAN-02 to VAN-07, VAN-12, VAN-17 to VAN-24: vans, routes and stops, load-out,
// runs, the count review, credit review and settings, with the API client
// mocked and answers shaped as docs/modules/van-sales.md gives them.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Foods', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const BRANCH = { id: 'br-1', name: 'Westlands', company_id: 'c-1', archived_at: null }
const WAREHOUSE = { id: 'l-wh', name: 'Main warehouse', branch_id: 'br-1', company_id: 'c-1', archived_at: null }
const VAN_LOCATION = { id: 'l-van', name: 'Van KDA 123A', branch_id: 'br-1', company_id: 'c-1', archived_at: null }
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })

const VAN = { id: 'v-1', company_id: 'c-1', branch_id: 'br-1', location_id: 'l-van', location_name: 'Van KDA 123A', name: 'Van 1', plate: 'KDA 123A', rep_id: 'u-2', rep_name: 'Peter Mwangi', device_id: 'd-1', device_name: 'Van tablet', archived_at: null }
const ROUTE = { id: 'r-1', company_id: 'c-1', branch_id: 'br-1', van_id: 'v-1', van_name: 'Van 1', name: 'Eastlands', visit_days: [1, 3], stop_count: 2, archived_at: null }
const ROUTE_DETAIL = { ...ROUTE, stops: [{ id: 's-1', customer_id: 'p-1', customer_name: 'Mama Njeri', position: 1, visit_days: [] }, { id: 's-2', customer_id: 'p-2', customer_name: 'Baba Otieno', position: 2, visit_days: [3] }] }
const USERS = [{ id: 'u-1', name: 'Amina Otieno' }, { id: 'u-2', name: 'Peter Mwangi' }]
const ITEMS = [{ id: 'i-1', name: 'Soap 1 kg', code: 'SOAP-1', type: 'stock' }]

const LOAD = { id: 'ld-1', van_id: 'v-1', van_name: 'Van 1', run_id: null, from_location_id: 'l-wh', from_location_name: 'Main warehouse', number: 'LD-0001', status: 'approved', note: null, requested_by_name: 'Amina Otieno', requested_at: '2026-10-27T05:00:00Z', released_at: null, confirmed_at: null, lines: [{ id: 'll-1', item_id: 'i-1', item_name: 'Soap 1 kg', qty: '24', qty_confirmed: null }] }

const RECONCILIATION = {
  id: 'rc-1', run_id: 'run-1', run_date: '2026-10-27', van_id: 'v-1', van_name: 'Van 1', rep_name: 'Peter Mwangi', attempt: 1, status: 'pending', submitted_at: '2026-10-27T17:00:00Z', decided_at: null, decided_by_name: null, note: null,
  stock_variance_lines: 1, cash_variance_base_minor: '-50000',
  lines: [
    { item_id: 'i-1', item_name: 'Soap 1 kg', load_qty: '24', sold_qty: '18', returned_qty: '3', damaged_qty: '1', variance_qty: '2' },
    { item_id: 'i-2', item_name: 'Rice 2 kg', load_qty: '10', sold_qty: '10', returned_qty: '0', damaged_qty: '0', variance_qty: '0' },
  ],
  tenders: [
    { kind: 'cash', currency: 'KES', expected_minor: '1250000', counted_minor: '1200000', variance_minor: '-50000' },
    { kind: 'mobile_money', currency: 'KES', expected_minor: '300000', counted_minor: '300000', variance_minor: '0' },
  ],
  unload: { status: 'requested', number: 'TR-0007' },
}

const REVIEW = { id: 'cr-1', sale_id: 'sale-1', run_id: 'run-1', van_name: 'Van 1', rep_name: 'Peter Mwangi', customer_id: 'p-1', customer_name: 'Mama Njeri', currency: 'KES', total_minor: '500000', credit_minor: '500000', limit_minor: '300000', balance_before_minor: '100000', flags: ['over_limit', 'credit_offline'], sold_at: '2026-10-27T09:30:00Z', review_status: 'pending', reviewed_by_name: null, reviewed_at: null, review_note: null }

const RUN = { id: 'run-1', van_id: 'v-1', van_name: 'Van 1', route_id: 'r-1', route_name: 'Eastlands', rep_id: 'u-2', rep_name: 'Peter Mwangi', run_date: '2026-10-27', status: 'counting', started_at: '2026-10-27T05:30:00Z', closed_at: null, visit_count: 2, sold_visit_count: 1, sales_count: 1, sales_totals: [{ currency: 'KES', total_minor: '500000' }], reconciliation_status: 'pending' }
const RUN_DETAIL = {
  ...RUN,
  visits: [{ id: 'vi-1', stop_id: 's-1', customer_id: 'p-1', customer_name: 'Mama Njeri', status: 'sold', no_sale_reason: null, note: null, arrived_at: '2026-10-27T06:00:00Z', left_at: '2026-10-27T06:20:00Z' }, { id: 'vi-2', stop_id: 's-2', customer_id: 'p-2', customer_name: 'Baba Otieno', status: 'no_sale', no_sale_reason: 'closed', note: null, arrived_at: null, left_at: null }],
  sales: [{ id: 'vs-1', sale_id: 'sale-1', visit_id: 'vi-1', customer_id: 'p-1', customer_name: 'Mama Njeri', currency: 'KES', total_minor: '500000', credit_minor: '500000', status: 'completed', flags: ['over_limit'], review_status: 'pending', sold_at: '2026-10-27T06:10:00Z' }],
  collections: [],
  loads: [LOAD],
  reconciliation: { ...RECONCILIATION, id: 'rc-1' },
}
const POSITIONS = [{ visit_id: 'vi-1', customer_name: 'Mama Njeri', lat: -1.2921, lng: 36.8219, accuracy_m: 12, at: '2026-10-27T06:00:00Z' }]
const SETTINGS = { company_id: 'c-1', gps_checkin: 'off', gps_retention_days: 30, flag_offline_credit: true }

const ALL = [
  'core.company.view', 'van_sales.van.view', 'van_sales.van.manage', 'van_sales.route.view', 'van_sales.route.manage', 'van_sales.load.view', 'van_sales.load.create', 'van_sales.load.release',
  'van_sales.run.view', 'van_sales.visit.track', 'van_sales.collection.view', 'van_sales.collection.void', 'van_sales.credit.view', 'van_sales.credit.review', 'van_sales.reconciliation.view',
  'van_sales.unload.receive', 'van_sales.settings.edit', 'van_sales.report.view',
]

function setup({ without = [], extra = [] } = {}) {
  mockRoutes(
    api,
    [
      ...extra,
      ['van-sales/vans', { data: [VAN] }],
      ['van-sales/routes', { data: [ROUTE] }],
      ['van-sales/routes/r-1', { data: ROUTE_DETAIL }],
      ['van-sales/loads', { data: [LOAD] }],
      ['van-sales/loads/ld-1', { data: LOAD }],
      [/^van-sales\/runs\?/, page([RUN])],
      ['van-sales/runs/run-1', { data: RUN_DETAIL }],
      ['van-sales/runs/run-1/positions', { data: POSITIONS }],
      [/^van-sales\/reconciliations\?/, page([RECONCILIATION])],
      ['van-sales/reconciliations/rc-1', { data: RECONCILIATION }],
      [/^van-sales\/credit-reviews\?/, page([REVIEW])],
      ['van-sales/companies/c-1/settings', { data: SETTINGS }],
      [/^branches\?/, page([BRANCH])],
      [/^locations\?/, page([WAREHOUSE, VAN_LOCATION])],
      ['locations/l-van/devices?per_page=200', page([{ id: 'd-1', name: 'Van tablet', archived_at: null }])],
      [/^users\?/, page(USERS)],
      [/^items\?/, page(ITEMS)],
      [/^parties\?/, page([{ id: 'p-3', name: 'Kioski Wanjiru', archived_at: null }])],
      ['tenant/currencies', { data: [] }],
    ],
    { permissions: tenantWide(ALL.filter((name) => !without.includes(name))), modules: ['core', 'pos', 'van_sales'], companies: [COMPANY] },
  )
}

describe('Van sales', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  describe('vans (VAN-02)', () => {
    it('lists vans with their rep, location and device', async () => {
      setup()
      renderApp('/van-sales/vans')
      expect(await screen.findByText('Van 1')).toBeInTheDocument()
      expect(screen.getByText('KDA 123A')).toBeInTheDocument()
      expect(screen.getByText('Peter Mwangi')).toBeInTheDocument()
      expect(screen.getByText('Van tablet')).toBeInTheDocument()
    })

    it('adds a van with its branch and rep', async () => {
      setup()
      api.post.mockResolvedValue({ data: VAN })
      renderApp('/van-sales/vans')
      await screen.findByText('Van 1')
      fireEvent.click(screen.getByRole('button', { name: 'Add van' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Van 2' } })
      fireEvent.change(within(dialog).getByLabelText('Plate'), { target: { value: 'KDB 456B' } })
      chooseOption(within(dialog).getByLabelText('Branch'), 'Westlands')
      chooseOption(within(dialog).getByLabelText('Rep'), 'Amina Otieno')
      fireEvent.click(within(dialog).getByRole('button', { name: 'Save van' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('van-sales/vans', { branch_id: 'br-1', name: 'Van 2', plate: 'KDB 456B', rep_id: 'u-1', device_id: null }))
    })

    it('hides setting up from a reader', async () => {
      setup({ without: ['van_sales.van.manage'] })
      renderApp('/van-sales/vans')
      await screen.findByText('Van 1')
      expect(screen.queryByRole('button', { name: 'Add van' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Archive Van 1' })).not.toBeInTheDocument()
    })
  })

  describe('routes and stops (VAN-03, VAN-04, VAN-05)', () => {
    it('lists routes with their visit days and stop count', async () => {
      setup()
      renderApp('/van-sales/routes')
      expect(await screen.findByText('Eastlands')).toBeInTheDocument()
      expect(screen.getByText('Mon, Wed')).toBeInTheDocument()
    })

    it('adds a route with its visit days', async () => {
      setup()
      api.post.mockResolvedValue({ data: ROUTE })
      renderApp('/van-sales/routes')
      await screen.findByText('Eastlands')
      fireEvent.click(screen.getByRole('button', { name: 'Add route' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Southlands' } })
      chooseOption(within(dialog).getByLabelText('Branch'), 'Westlands')
      fireEvent.click(within(dialog).getByLabelText('Tue'))
      fireEvent.click(within(dialog).getByLabelText('Fri'))
      fireEvent.click(within(dialog).getByRole('button', { name: 'Save route' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('van-sales/routes', { company_id: 'c-1', branch_id: 'br-1', name: 'Southlands', visit_days: [2, 5], van_id: null }))
    })

    it('reorders, adds and saves the stops in order', async () => {
      setup()
      api.put.mockResolvedValue({ data: ROUTE_DETAIL })
      renderApp('/van-sales/routes/r-1')
      expect(await screen.findByText('Mama Njeri')).toBeInTheDocument()
      fireEvent.click(screen.getByRole('button', { name: 'Move Mama Njeri down' }))
      chooseOption(screen.getByLabelText('Add a customer'), 'Kioski Wanjiru')
      fireEvent.click(screen.getByRole('button', { name: 'Add stop' }))
      fireEvent.click(screen.getByRole('button', { name: 'Remove Baba Otieno' }))
      fireEvent.click(screen.getByRole('button', { name: 'Save stops' }))
      await waitFor(() => expect(api.put).toHaveBeenCalledWith('van-sales/routes/r-1/stops', { stops: [{ customer_id: 'p-1' }, { customer_id: 'p-3' }] }))
    })

    it('numbers the stops from 1 and disables moving the first one up (VAN-04)', async () => {
      setup()
      renderApp('/van-sales/routes/r-1')
      expect(await screen.findByText('Mama Njeri')).toBeInTheDocument()
      const rows = screen.getAllByRole('row').slice(1)
      expect(within(rows[0]).getAllByRole('cell')[0]).toHaveTextContent('1')
      expect(within(rows[1]).getAllByRole('cell')[0]).toHaveTextContent('2')
      expect(screen.getByRole('button', { name: 'Move Mama Njeri up' })).toBeDisabled()
      expect(screen.getByRole('button', { name: 'Move Baba Otieno down' })).toBeDisabled()
    })

    it('keeps a stop with its own days when saving', async () => {
      setup()
      api.put.mockResolvedValue({ data: ROUTE_DETAIL })
      renderApp('/van-sales/routes/r-1')
      await screen.findByText('Mama Njeri')
      fireEvent.click(screen.getByRole('button', { name: 'Save stops' }))
      await waitFor(() => expect(api.put).toHaveBeenCalledWith('van-sales/routes/r-1/stops', { stops: [{ customer_id: 'p-1' }, { customer_id: 'p-2', visit_days: [3] }] }))
    })

    it('shows the stops due with their state for the day', async () => {
      setup({ extra: [[/^van-sales\/plan\?/, { data: { date: '2026-10-27', routes: [{ route_id: 'r-1', name: 'Eastlands', stops: [{ stop_id: 's-1', customer_id: 'p-1', customer_name: 'Mama Njeri', position: 1, status: 'sold', visit_id: 'vi-1' }, { stop_id: 's-2', customer_id: 'p-2', customer_name: 'Baba Otieno', position: 2, status: 'pending', visit_id: null }] }] } }]] })
      renderApp('/van-sales/plan')
      expect(await screen.findByText('Mama Njeri')).toBeInTheDocument()
      expect(screen.getByText('Sold')).toBeInTheDocument()
      expect(screen.getByText('Pending')).toBeInTheDocument()
    })
  })

  describe('load-out (VAN-07)', () => {
    it('lists load sheets with the status as a dot and a word', async () => {
      setup()
      renderApp('/van-sales/loads')
      expect(await screen.findByText('LD-0001')).toBeInTheDocument()
      expect(screen.getByText('Approved')).toBeInTheDocument()
    })

    it('releases an approved load for a storekeeper', async () => {
      setup()
      api.post.mockResolvedValue({ data: { ...LOAD, status: 'released' } })
      renderApp('/van-sales/loads')
      fireEvent.click(await screen.findByRole('button', { name: 'Open load LD-0001' }))
      const dialog = await screen.findByRole('dialog')
      expect(await within(dialog).findByText('Soap 1 kg')).toBeInTheDocument()
      fireEvent.click(within(dialog).getByRole('button', { name: 'Release load' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('van-sales/loads/ld-1/release', {}))
    })

    it('hides release without the permission and cancels with a reason', async () => {
      setup({ without: ['van_sales.load.release'] })
      api.post.mockResolvedValue({ data: { ...LOAD, status: 'cancelled' } })
      renderApp('/van-sales/loads')
      fireEvent.click(await screen.findByRole('button', { name: 'Open load LD-0001' }))
      const dialog = await screen.findByRole('dialog')
      await within(dialog).findByText('Soap 1 kg')
      expect(within(dialog).queryByRole('button', { name: 'Release load' })).not.toBeInTheDocument()
      fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel load' }))
      const reason = await screen.findByLabelText(/^Reason/)
      fireEvent.change(reason, { target: { value: 'Van in the garage' } })
      const dialogs = screen.getAllByRole('dialog')
      fireEvent.click(within(dialogs[dialogs.length - 1]).getByRole('button', { name: 'Cancel load' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('van-sales/loads/ld-1/cancel', { reason: 'Van in the garage' }))
    })

    it('requests a load with item lines', async () => {
      setup()
      api.post.mockResolvedValue({ data: LOAD })
      renderApp('/van-sales/loads')
      await screen.findByText('LD-0001')
      fireEvent.click(screen.getByRole('button', { name: 'Request load' }))
      const dialog = await screen.findByRole('dialog')
      await waitForOption(within(dialog).getByLabelText('Van'), 'Van 1')
      chooseOption(within(dialog).getByLabelText('Van'), 'Van 1')
      chooseOption(within(dialog).getByLabelText('Warehouse'), 'Main warehouse')
      chooseOption(within(dialog).getByLabelText('Item 1'), 'Soap 1 kg · SOAP-1')
      fireEvent.change(within(dialog).getByLabelText('Quantity 1'), { target: { value: '12' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Request load' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('van-sales/loads', { van_id: 'v-1', from_location_id: 'l-wh', lines: [{ item_id: 'i-1', qty: '12' }] }))
    })
  })

  describe('runs (VAN-06, VAN-24)', () => {
    it('lists runs and opens one with its visits, sales and count', async () => {
      setup()
      renderApp('/van-sales/runs')
      fireEvent.click(await screen.findByText('Eastlands'))
      expect(await screen.findByText('Baba Otieno')).toBeInTheDocument()
      expect(screen.getByText('Closed')).toBeInTheDocument()
      expect(screen.getByText('Over the limit')).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Open the count' })).toHaveAttribute('href', '/van-sales/reconciliations/rc-1')
    })

    it('shows positions only to users who may track visits, and only on request', async () => {
      setup()
      renderApp('/van-sales/runs/run-1')
      await screen.findByText('Baba Otieno')
      expect(screen.getByText(/personal data/)).toBeInTheDocument()
      expect(api.get).not.toHaveBeenCalledWith('van-sales/runs/run-1/positions')
      fireEvent.click(screen.getByRole('button', { name: 'Show positions' }))
      expect(await screen.findByText('-1.2921')).toBeInTheDocument()
    })

    it('hides the positions card without the tracking permission', async () => {
      setup({ without: ['van_sales.visit.track'] })
      renderApp('/van-sales/runs/run-1')
      await screen.findByText('Baba Otieno')
      expect(screen.queryByRole('button', { name: 'Show positions' })).not.toBeInTheDocument()
    })
  })

  describe('count review (VAN-19 to VAN-23)', () => {
    it('lists counts with the cash variance', async () => {
      setup()
      renderApp('/van-sales/reconciliations')
      expect(await screen.findByText('Peter Mwangi')).toBeInTheDocument()
      expect(screen.getByText('Pending')).toBeInTheDocument()
    })

    it('shows the equation, the variances and the link to the approvals inbox', async () => {
      setup()
      renderApp('/van-sales/reconciliations/rc-1')
      expect(await screen.findByText(/Load = sold \+ returned \+ damaged \+ variance/)).toBeInTheDocument()
      const stock = screen.getByRole('table', { name: 'Stock' })
      const soap = within(stock).getByText('Soap 1 kg').closest('tr')
      expect(within(soap).getAllByRole('cell').map((cell) => cell.textContent)).toEqual(['Soap 1 kg', '24', '18', '3', '1', '2'])
      const cash = screen.getByRole('table', { name: 'Cash and mobile money' })
      expect(within(cash).getByText(/12,500\.00/)).toBeInTheDocument()
      expect(within(cash).getByText(/12,000\.00/)).toBeInTheDocument()
      expect(within(cash).getByText(/-500\.00/)).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Open the approvals inbox' })).toHaveAttribute('href', '/approvals')
    })

    it('receives the unload with the counted quantities', async () => {
      setup()
      api.post.mockResolvedValue({ data: RECONCILIATION })
      renderApp('/van-sales/reconciliations/rc-1')
      fireEvent.click(await screen.findByRole('button', { name: 'Receive unload' }))
      const dialog = await screen.findByRole('dialog')
      const field = within(dialog).getByLabelText('Soap 1 kg (sent back 3)')
      fireEvent.change(field, { target: { value: '2' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Receive stock' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('van-sales/reconciliations/rc-1/unload/receive', { lines: [{ item_id: 'i-1', qty_received: '2' }] }))
    })

    it('hides the unload from users without the permission', async () => {
      setup({ without: ['van_sales.unload.receive'] })
      renderApp('/van-sales/reconciliations/rc-1')
      await screen.findByText('TR-0007', { exact: false })
      expect(screen.queryByRole('button', { name: 'Receive unload' })).not.toBeInTheDocument()
    })
  })

  describe('credit (VAN-11, VAN-12, VAN-13)', () => {
    it('lists balances against limits', async () => {
      setup({ extra: [['van-sales/accounts', { data: [{ customer_id: 'p-1', customer_name: 'Mama Njeri', credit_limit_minor: '300000', credit_limit_currency: 'KES', balances: [{ currency: 'KES', amount_minor: '250000' }] }] }]] })
      renderApp('/van-sales/accounts')
      const row = (await screen.findByText('Mama Njeri')).closest('tr')
      expect(within(row).getByText(/2,500\.00/)).toBeInTheDocument()
      expect(within(row).getByText(/3,000\.00/)).toBeInTheDocument()
    })

    it('queues flagged credit sales and records a review with a note', async () => {
      setup()
      api.post.mockResolvedValue({ data: { ...REVIEW, review_status: 'reviewed' } })
      renderApp('/van-sales/credit-reviews')
      expect(await screen.findByText('Over the limit')).toBeInTheDocument()
      expect(screen.getByText('Decided offline')).toBeInTheDocument()
      fireEvent.click(screen.getByRole('button', { name: 'Review the sale to Mama Njeri' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Note'), { target: { value: 'Called the customer' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Mark as reviewed' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('van-sales/credit-reviews/cr-1/review', { note: 'Called the customer' }))
    })
  })

  describe('collections and settings (VAN-17, VAN-24)', () => {
    it('voids a collection with a reason', async () => {
      const collection = { id: 'co-1', run_id: 'run-1', customer_id: 'p-1', customer_name: 'Mama Njeri', currency: 'KES', amount_minor: '100000', method_type: 'cash', receipt_number: 'R-0099', provider_reference: null, collected_at: '2026-10-27T10:00:00Z', status: 'recorded', flags: ['exceeds_balance'], void_reason: null }
      setup({ extra: [[/^van-sales\/collections\?/, page([collection])]] })
      api.post.mockResolvedValue({ data: { ...collection, status: 'voided' } })
      renderApp('/van-sales/collections')
      expect(await screen.findByText('R-0099')).toBeInTheDocument()
      expect(screen.getByText('More than the balance')).toBeInTheDocument()
      fireEvent.click(screen.getByRole('button', { name: 'Void collection R-0099' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Wrong customer' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Void collection' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('van-sales/collections/co-1/void', { reason: 'Wrong customer' }))
    })

    it('saves the GPS mode, the retention and the offline credit flag', async () => {
      setup()
      api.put.mockResolvedValue({ data: { ...SETTINGS, gps_checkin: 'optional', gps_retention_days: 14 } })
      renderApp('/van-sales/settings')
      const select = await screen.findByLabelText('Check-in')
      chooseOption(select, 'Optional')
      fireEvent.change(screen.getByLabelText('Keep positions for (days)'), { target: { value: '14' } })
      fireEvent.click(screen.getByRole('switch'))
      fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))
      await waitFor(() => expect(api.put).toHaveBeenCalledWith('van-sales/companies/c-1/settings', { gps_checkin: 'optional', gps_retention_days: 14, flag_offline_credit: false }))
    })

    it('refuses a retention outside 1 to 90 days', async () => {
      setup()
      renderApp('/van-sales/settings')
      fireEvent.change(await screen.findByLabelText('Keep positions for (days)'), { target: { value: '120' } })
      expect(screen.getByText('Enter a whole number from 1 to 90.')).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Save settings' })).toBeDisabled()
    })
  })
})
