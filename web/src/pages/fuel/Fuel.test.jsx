// FUEL-02 to FUEL-38: the fuel station back office: prices (minor units, withdraw), variance review (pending list,
// the workflow state and who cannot approve), meter resets (own_reset), tank dips and reconciliation, deliveries,
// vouchers (issue, void), calibration charts, flagged sales, reports, and the module and permission gating of the menu.
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { Flags } from './FuelParts'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Lakeside Fuel', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, current_page: 1, per_page: 25 } })
const LOCATION = { id: 'loc-1', name: 'Westlands forecourt' }
const STATION = { id: 'st-1', company_id: 'c-1', branch_id: 'br-1', location: LOCATION, currency: 'KES', archived_at: null }
const PRODUCT_REF = { id: 'pr-1', item_id: 'i-1', name: 'Super petrol', fuel_type: 'petrol', grade: null }
const PRODUCT = { id: 'pr-1', company_id: 'c-1', item_id: 'i-1', name: 'Super petrol', fuel_type: 'petrol', grade: null, archived_at: null }
const TANK = {
  id: 'tk-1', station_id: 'st-1', location: LOCATION, code: 'T1', name: 'Tank one', product: PRODUCT_REF, capacity_litres: '20000.000', tolerance_litres: null,
  baseline_litres: '10000.000', baseline_at: '2026-10-20T05:00:00+00:00', book_litres: '9800.000', archived_at: null,
}
const NOZZLE = { id: 'nz-1', station_id: 'st-1', location: LOCATION, pump_id: 'pu-1', code: '1', label: 'P1 1', tank: { id: 'tk-1', code: 'T1', name: 'Tank one' }, product: PRODUCT_REF, last_reading_litres: '123456.500', last_reading_at: '2026-10-26T18:00:00+00:00', archived_at: null }
const PUMP = { id: 'pu-1', station_id: 'st-1', location: LOCATION, code: 'P1', name: 'Pump one', nozzles: [NOZZLE], archived_at: null }
const USER = (id, name) => ({ id, name })
const CURRENT = { id: 'p-1', station_id: 'st-1', location: LOCATION, product: PRODUCT_REF, price_minor: '18000', currency: 'KES', effective_from: '2026-10-01T05:00:00+00:00', reference: 'EPRA notice 9', entered_by: USER('u-2', 'Grace Wanjiru'), archived_at: null }
const SCHEDULED = { ...CURRENT, id: 'p-2', price_minor: '18500', effective_from: '2099-01-01T05:00:00+00:00', reference: 'EPRA notice 10' }
const SETTLEMENT = {
  id: 's-1', number: 'FS-WEST-2026-00001', shift_id: 'sh-1', location: LOCATION, attendant: USER('u-9', 'Peter Otieno'), status: 'pending_approval', version: 1, currency: 'KES',
  expected_minor: '1000000', collected_minor: '990000', variance_minor: '-10000', meter_litres: '55.000', recorded_litres: '54.000', litres_variance: '-1.000', flags: [{ code: 'litres_differ' }],
  decided_by: null, decided_at: null, reopened_at: null, created_at: '2026-10-26T20:00:00+00:00',
  tenders: [{ kind: 'cash', declared_minor: '990000', recorded_minor: '0', collected_minor: '990000' }, { kind: 'mobile_money', declared_minor: null, recorded_minor: '0', collected_minor: '0' }],
  document_type: 'fuel.shift_variance',
}
const RECONCILIATION = {
  id: 'r-1', number: 'FR-WEST-2026-00001', tank: { id: 'tk-1', code: 'T1', name: 'Tank one' }, location: LOCATION, dip_id: 'd-1', status: 'pending_approval', previous_litres: '10000.000', baseline_at: '2026-10-20T05:00:00+00:00',
  deliveries_litres: '0.000', sales_litres: '200.000', expected_litres: '9800.000', dip_litres: '9500.000', variance_litres: '-300.000', tolerance_litres: '50.000', flags: [], decided_by: null, decided_at: null, posted_at: null,
  created_at: '2026-10-26T20:00:00+00:00', document_type: 'fuel.tank_variance',
}
const FLOW = (approval = 'ap-1') => ({ data: { current: [{ token_id: 't-1', node_id: 'n-1', name: 'Station manager approval', holders: { users: [{ name: 'Grace Wanjiru' }], roles: [], approval_id: approval } }] } })
const VOUCHER = { id: 'v-1', company_id: 'c-1', code: 'FV-1234', customer: null, currency: 'KES', value_minor: '500000', balance_minor: '450000', fuel_type: null, expires_on: null, status: 'active', void_reason: null, voided_at: null, issued_by: USER('u-2', 'Grace Wanjiru'), created_at: '2026-10-20T05:00:00+00:00', redemptions: [{ sale_id: 'sa-1', location: LOCATION, amount_minor: '50000', currency: 'KES', redeemed_at: '2026-10-21T05:00:00+00:00', reversed_at: null, flags: [] }] }
const CHART = { id: 'ch-1', tank_id: 'tk-1', effective_from: '2026-09-01T05:00:00+00:00', note: null, created_by: USER('u-2', 'Grace Wanjiru'), points: [{ dip_cm: '0', litres: '0.000' }, { dip_cm: '100', litres: '10000.000' }] }
const SALE = {
  id: 'fs-1', sale_id: 'sa-1', sale_line_id: 'sl-1', location: LOCATION, shift_id: null, nozzle: { id: 'nz-1', code: '1', pump_code: 'P1', label: 'P1 1' }, tank: null, product: PRODUCT_REF, attendant: null, cashier: null,
  customer: null, vehicle_registration: null, odometer: null, litres: '20.000', unit_price_minor: '18000', total_minor: '360000', currency: 'KES', sold_at: '2026-10-26T09:00:00+00:00', voided_at: null,
  flags: [{ code: 'no_shift' }, { code: 'price_differs', expected_unit_price_minor: '18500' }],
}

const ALL = [
  'core.company.view', 'fuel.settings.view', 'fuel.settings.edit', 'fuel.setup.view', 'fuel.setup.manage', 'fuel.price.view', 'fuel.price.manage', 'fuel.sale.view', 'fuel.shift.view',
  'fuel.variance.view', 'fuel.variance.approve', 'fuel.meter.request_reset', 'fuel.meter.approve_reset', 'fuel.dip.view', 'fuel.dip.record', 'fuel.delivery.view', 'fuel.delivery.record',
  'fuel.vehicle.view', 'fuel.vehicle.manage', 'fuel.voucher.view', 'fuel.voucher.manage', 'fuel.report.view',
]

function setup(routes = [], permissions = ALL, modules = ['core', 'fuel']) {
  mockRoutes(
    api,
    [
      ...routes,
      ['fuel/stations', { data: [STATION] }],
      ['fuel/products', { data: [PRODUCT] }],
      [/^fuel\/tanks(\?.*)?$/, { data: [TANK] }],
      ['fuel/pumps', { data: [PUMP] }],
      ['fuel/nozzles', { data: [NOZZLE] }],
      ['document-workflows/fuel.shift_variance/s-1', FLOW()],
      ['document-workflows/fuel.tank_variance/r-1', FLOW('ap-2')],
    ],
    { permissions: tenantWide(permissions), modules, companies: [COMPANY] },
  )
}

describe('Fuel station back office', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('adds a price typed in major units as minor units and says it is never built in (FUEL-08)', async () => {
    setup([[/^fuel\/prices\?.*current=1/, page([CURRENT])], [/^fuel\/prices\?/, page([CURRENT, SCHEDULED])]])
    api.post.mockResolvedValue({ data: CURRENT })
    renderApp('/fuel/prices')
    expect(await screen.findByText(/None is built in/)).toBeInTheDocument()
    expect((await screen.findAllByText('KES 180.00')).length).toBeGreaterThan(0)
    fireEvent.click(screen.getByRole('button', { name: 'Add price' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add price' })
    chooseOption(within(dialog).getByLabelText(/^Station/), 'Westlands forecourt')
    chooseOption(within(dialog).getByLabelText(/^Product/), 'Super petrol')
    fireEvent.change(within(dialog).getByLabelText(/^Price per litre/), { target: { value: '184.50' } })
    fireEvent.change(within(dialog).getByLabelText(/^Reference/), { target: { value: 'EPRA notice 12' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save price' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fuel/prices', { location_id: 'loc-1', product_id: 'pr-1', price_minor: '18450', effective_from: null, reference: 'EPRA notice 12' }))
  })

  it('withdraws a scheduled price and offers no withdraw on one that started (FUEL-08)', async () => {
    setup([[/^fuel\/prices\?.*current=1/, page([CURRENT])], [/^fuel\/prices\?/, page([CURRENT, SCHEDULED])]])
    api.post.mockResolvedValue({ data: { ...SCHEDULED, archived_at: '2026-10-27T05:00:00+00:00' } })
    renderApp('/fuel/prices')
    const buttons = await screen.findAllByRole('button', { name: /^Withdraw the price of/ })
    expect(buttons).toHaveLength(1)
    expect(screen.getByText('Scheduled')).toBeInTheDocument()
    fireEvent.click(buttons[0])
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Withdraw' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fuel/prices/p-2/archive'))
  })

  it('lists the settlements waiting for approval first (FUEL-13)', async () => {
    setup([[/^fuel\/settlements\?/, page([SETTLEMENT])]])
    renderApp('/fuel/settlements')
    expect(await screen.findByText('FS-WEST-2026-00001')).toBeInTheDocument()
    expect(screen.getByText('KES -100.00')).toBeInTheDocument()
    expect(screen.getByText('Litres differ from the meter')).toBeInTheDocument()
    expect(api.get.mock.calls.some(([path]) => String(path).startsWith('fuel/settlements?') && String(path).includes('status=pending_approval'))).toBe(true)
  })

  it('shows a settlement with its tenders, the workflow state and who cannot approve (FUEL-14)', async () => {
    setup([['fuel/settlements/s-1', { data: SETTLEMENT }]])
    renderApp('/fuel/settlements/s-1')
    expect(await screen.findByRole('heading', { name: 'FS-WEST-2026-00001' })).toBeInTheDocument()
    expect(screen.getByText('A station manager approves this')).toBeInTheDocument()
    expect(screen.getByText(/can’t approve it, whatever their roles/)).toBeInTheDocument()
    expect(await screen.findByText('Station manager approval')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open the approval' })).toHaveAttribute('href', '/approvals/ap-1')
    expect(screen.getByText('Cash')).toBeInTheDocument()
    expect(screen.getAllByText('KES 9,900.00').length).toBeGreaterThan(0)
  })

  it('marks a reopened settlement (FUEL-15)', async () => {
    setup([['fuel/settlements/s-1', { data: { ...SETTLEMENT, status: 'approved', version: 2, reopened_at: '2026-10-27T05:00:00+00:00', flags: [{ code: 'reopened' }] } }]])
    renderApp('/fuel/settlements/s-1')
    expect(await screen.findByText('Reopened (version 2)')).toBeInTheDocument()
  })

  it('shows a tank reconciliation with its workflow state (FUEL-26)', async () => {
    setup([['fuel/reconciliations/r-1', { data: RECONCILIATION }]])
    renderApp('/fuel/reconciliations/r-1')
    expect(await screen.findByRole('heading', { name: 'FR-WEST-2026-00001' })).toBeInTheDocument()
    expect(await screen.findByText('Station manager approval')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open the approval' })).toHaveAttribute('href', '/approvals/ap-2')
    expect(screen.getByText(/The person who took the dip can’t approve it/)).toBeInTheDocument()
  })

  it('shows the API’s own words when a requester tries to approve their own meter reset (FUEL-17)', async () => {
    const reset = { id: 'mr-1', nozzle: { id: 'nz-1', code: '1', pump_code: 'P1', label: 'P1 1' }, location: LOCATION, shift_id: null, reading_before: '999999.000', reading_after: '0.000', reason: 'Meter replaced', status: 'pending', requested_by: USER('u-1', 'Amina Otieno'), requested_at: '2026-10-27T05:00:00+00:00', decided_by: null, decided_at: null, actor_verified: true }
    setup([[/^fuel\/meter-resets\?/, page([reset])]])
    api.post.mockRejectedValue(apiError(403, 'own_reset', 'You can’t decide a meter reset you asked for. Ask another manager.'))
    renderApp('/fuel/meter-resets')
    fireEvent.click(await screen.findByRole('button', { name: 'Approve the reset of P1 1' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fuel/meter-resets/mr-1/approve'))
    expect(await screen.findByText('You can’t decide a meter reset you asked for. Ask another manager.')).toBeInTheDocument()
  })

  it('requests a meter reset with the readings before and after (FUEL-17)', async () => {
    setup([[/^fuel\/meter-resets\?/, page([])], [/^fuel\/shifts\?/, page([])]])
    api.post.mockResolvedValue({ data: {} })
    renderApp('/fuel/meter-resets')
    fireEvent.click(await screen.findByRole('button', { name: 'Request reset' }))
    const dialog = await screen.findByRole('dialog', { name: 'Request reset' })
    await waitForOption(within(dialog).getByLabelText(/^Nozzle/), 'P1 1 · Westlands forecourt')
    chooseOption(within(dialog).getByLabelText(/^Nozzle/), 'P1 1 · Westlands forecourt')
    fireEvent.change(within(dialog).getByLabelText(/^Reading before/), { target: { value: '999,999.5' } })
    fireEvent.change(within(dialog).getByLabelText(/^Reading after/), { target: { value: '0' } })
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Meter replaced' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send request' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fuel/meter-resets', { nozzle_id: 'nz-1', shift_id: null, reading_before: '999999.5', reading_after: '0', reason: 'Meter replaced' }))
  })

  it('records a dip and shows the litres and the reconciliation result (FUEL-23, FUEL-24)', async () => {
    setup([[/^fuel\/dips\?/, page([])]])
    api.post.mockResolvedValue({ data: { id: 'd-1', tank: { id: 'tk-1', code: 'T1', name: 'Tank one' }, kind: 'routine', dip_cm: '95.5', litres: '9500.000', reconciliation: RECONCILIATION } })
    renderApp('/fuel/dips')
    fireEvent.click(await screen.findByRole('button', { name: 'Record a dip' }))
    const dialog = await screen.findByRole('dialog', { name: 'Record a dip' })
    chooseOption(within(dialog).getByLabelText(/^Tank/), 'T1 · Tank one')
    fireEvent.change(within(dialog).getByLabelText(/^Dip/), { target: { value: '95.5' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Record dip' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fuel/dips', { tank_id: 'tk-1', dip_cm: '95.5', water_cm: null, taken_at: null }))
    const result = await screen.findByRole('dialog', { name: 'Dip recorded' })
    expect(within(result).getAllByText('9,500.000 L').length).toBeGreaterThan(0)
    expect(within(result).getByText('Waiting for approval')).toBeInTheDocument()
    expect(within(result).getByText(/outside tolerance, so the dip waits for a station manager/)).toBeInTheDocument()
    expect(within(result).getByText('-300.000 L')).toBeInTheDocument()
    expect(within(result).getByRole('link', { name: 'Open the reconciliation' })).toHaveAttribute('href', '/fuel/reconciliations/r-1')
  })

  it('says the first dip of a tank only sets the baseline (FUEL-24)', async () => {
    setup([[/^fuel\/dips\?/, page([])]])
    api.post.mockResolvedValue({ data: { id: 'd-1', tank: { id: 'tk-1', code: 'T1', name: 'Tank one' }, kind: 'routine', dip_cm: '95.5', litres: '9500.000', reconciliation: { ...RECONCILIATION, status: 'baseline' } } })
    renderApp('/fuel/dips')
    fireEvent.click(await screen.findByRole('button', { name: 'Record a dip' }))
    const dialog = await screen.findByRole('dialog', { name: 'Record a dip' })
    chooseOption(within(dialog).getByLabelText(/^Tank/), 'T1 · Tank one')
    fireEvent.change(within(dialog).getByLabelText(/^Dip/), { target: { value: '95.5' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Record dip' }))
    expect(await screen.findByText(/only sets the baseline/)).toBeInTheDocument()
  })

  it('records a delivery in minor units and shows received against the note and the shortage (FUEL-25)', async () => {
    setup([[/^fuel\/deliveries\?/, page([])]])
    api.post.mockResolvedValue({
      data: {
        id: 'dl-1', number: 'FD-WEST-2026-00001', tank: { id: 'tk-1', code: 'T1', name: 'Tank one' }, location: LOCATION, supplier: null, note_number: 'DN-77', note_litres: '5000.000', before_litres: '2000.000', after_litres: '6950.000',
        sold_during_litres: '0.000', received_litres: '4950.000', shortage_litres: '50.000', unit_cost_minor: '16050', cost_minor: '79447500', currency: 'KES', received_at: '2026-10-27T05:00:00+00:00', flags: [{ code: 'delivery_short' }], recorded_by: USER('u-1', 'Amina Otieno'),
      },
    })
    renderApp('/fuel/deliveries')
    fireEvent.click(await screen.findByRole('button', { name: 'Record a delivery' }))
    const dialog = await screen.findByRole('dialog', { name: 'Record a delivery' })
    chooseOption(within(dialog).getByLabelText(/^Tank/), 'T1 · Tank one')
    fireEvent.change(within(dialog).getByLabelText(/^Delivery note number/), { target: { value: 'DN-77' } })
    fireEvent.change(within(dialog).getByLabelText(/^Litres on the note/), { target: { value: '5000' } })
    fireEvent.change(within(dialog).getByLabelText(/^Dip before/), { target: { value: '40' } })
    fireEvent.change(within(dialog).getByLabelText(/^Dip after/), { target: { value: '95.5' } })
    fireEvent.change(await within(dialog).findByLabelText(/^Cost per litre/), { target: { value: '160.50' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Record delivery' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('fuel/deliveries', { tank_id: 'tk-1', supplier_id: null, note_number: 'DN-77', note_litres: '5000', before_dip_cm: '40', after_dip_cm: '95.5', unit_cost_minor: '16050', currency: 'KES' }),
    )
    expect(await screen.findByText('Delivery FD-WEST-2026-00001 recorded.')).toBeInTheDocument()
    expect(screen.getByText('50.000 L short against the note')).toBeInTheDocument()
    expect(screen.getAllByText('Short delivery').length).toBeGreaterThan(0)
    expect(screen.getByText('4,950.000 L')).toBeInTheDocument()
  })

  it('issues a voucher with its value in minor units (FUEL-21)', async () => {
    setup([[/^fuel\/vouchers\?/, page([])]])
    api.post.mockResolvedValue({ data: VOUCHER })
    renderApp('/fuel/vouchers')
    fireEvent.click(await screen.findByRole('button', { name: 'New voucher' }))
    const dialog = await screen.findByRole('dialog', { name: 'New voucher' })
    fireEvent.change(within(dialog).getByLabelText(/^Value/), { target: { value: '5,000.00' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Issue voucher' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fuel/vouchers', { company_id: 'c-1', value_minor: '500000', currency: 'KES', code: null, party_id: null, fuel_type: null, expires_on: null }))
    expect(await screen.findByText('Voucher FV-1234 issued.')).toBeInTheDocument()
  })

  it('shows a voucher\u2019s expiry as a date in the user\u2019s language (L10N-01)', async () => {
    setup([[/^fuel\/vouchers\?/, page([{ ...VOUCHER, expires_on: '2027-03-31' }])]])
    renderApp('/fuel/vouchers')
    expect(await screen.findByText('31 Mar 2027')).toBeInTheDocument()
    expect(screen.queryByText('2027-03-31')).not.toBeInTheDocument()
  })

  it('voids a voucher with a reason and keeps its redemptions listed (FUEL-21)', async () => {
    setup([['fuel/vouchers/v-1', { data: VOUCHER }]])
    api.post.mockResolvedValue({ data: { ...VOUCHER, status: 'void', void_reason: 'Lost card' } })
    renderApp('/fuel/vouchers/v-1')
    expect(await screen.findByRole('heading', { name: 'FV-1234' })).toBeInTheDocument()
    expect(screen.getByText('KES 4,500.00')).toBeInTheDocument()
    expect(screen.getByText('KES 500.00')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Void voucher' }))
    const dialog = await screen.findByRole('dialog', { name: 'Void voucher FV-1234?' })
    const confirm = within(dialog).getByRole('button', { name: 'Void voucher' })
    expect(confirm).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Lost card' } })
    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fuel/vouchers/v-1/void', { reason: 'Lost card' }))
  })

  it('adds a calibration chart as rows of dip and litres, and previews a dip (FUEL-06)', async () => {
    setup([
      ['fuel/tanks/tk-1', { data: TANK }],
      ['fuel/tanks/tk-1/strapping', { data: [CHART] }],
      [/^fuel\/tanks\/tk-1\/dip-convert/, { data: { tank_id: 'tk-1', dip_cm: '50', litres: '5000.000', chart_id: 'ch-1' } }],
    ])
    api.post.mockResolvedValue({ data: CHART })
    renderApp('/fuel/tanks/tk-1')
    expect(await screen.findByRole('heading', { name: 'T1 · Tank one' })).toBeInTheDocument()
    expect(screen.getAllByText('9,800.000 L').length).toBeGreaterThan(0)
    fireEvent.change(screen.getByLabelText('Dip'), { target: { value: '50' } })
    fireEvent.click(screen.getByRole('button', { name: 'Convert' }))
    expect(await screen.findByText('5,000.000 L')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('fuel/tanks/tk-1/dip-convert?dip_cm=50')

    fireEvent.click(screen.getByRole('button', { name: 'Add calibration chart' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add calibration chart' })
    const save = within(dialog).getByRole('button', { name: 'Save chart' })
    expect(save).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Dip 1/), { target: { value: '0' } })
    fireEvent.change(within(dialog).getByLabelText(/^Litres 1/), { target: { value: '0' } })
    fireEvent.change(within(dialog).getByLabelText(/^Dip 2/), { target: { value: '100' } })
    fireEvent.change(within(dialog).getByLabelText(/^Litres 2/), { target: { value: '25000' } })
    expect(within(dialog).getByText('A row is above the tank’s capacity. Correct the litres or the tank’s capacity.')).toBeInTheDocument()
    expect(save).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Litres 2/), { target: { value: '10000' } })
    await waitFor(() => expect(save).toBeEnabled())
    fireEvent.click(save)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fuel/tanks/tk-1/strapping', { points: [{ dip_cm: '0', litres: '0' }, { dip_cm: '100', litres: '10000' }], note: null }))
  })

  it('shows flagged fuel sales as a dot and a word and filters to them (FUEL-18)', async () => {
    setup([[/^fuel\/sales\?/, page([SALE])]])
    renderApp('/fuel/sales')
    const flag = await screen.findByText('No shift')
    expect(flag.closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(screen.getByText('Price differs')).toBeInTheDocument()
    fireEvent.click(screen.getByLabelText('Flagged only'))
    await waitFor(() => expect(api.get.mock.calls.some(([path]) => String(path).includes('flagged=1'))).toBe(true))
  })

  it('shows the pumps with their nozzles, tanks and last meter reading (FUEL-07)', async () => {
    setup()
    renderApp('/fuel/setup?tab=pumps')
    expect(await screen.findByText('P1 · Pump one')).toBeInTheDocument()
    expect(screen.getByText('123,456.500 L')).toBeInTheDocument()
    expect(screen.getByText('T1 · Tank one')).toBeInTheDocument()
  })

  it('saves the company tolerances (FUEL-03)', async () => {
    setup([['companies/c-1/fuel-settings', { data: { company_id: 'c-1', cash_variance_tolerance_minor: '0', litre_tolerance: '0.000', loss_tolerance_percent: '0', loss_tolerance_litres: '0.000', test_tolerance_litres: '0.000', delivery_tolerance_percent: '0' } }]])
    api.put.mockResolvedValue({ data: { company_id: 'c-1', cash_variance_tolerance_minor: '10000', litre_tolerance: '0.000', loss_tolerance_percent: '0', loss_tolerance_litres: '0.000', test_tolerance_litres: '0.000', delivery_tolerance_percent: '0' } })
    renderApp('/fuel/setup?tab=settings')
    expect(await screen.findByText(/None is built in/)).toBeInTheDocument()
    fireEvent.change(await screen.findByLabelText(/^Cash variance tolerance/), { target: { value: '100.00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save tolerances' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('companies/c-1/fuel-settings', expect.objectContaining({ cash_variance_tolerance_minor: '10000', litre_tolerance: '0.000' })))
    expect(await screen.findByText('Tolerances saved.')).toBeInTheDocument()
  })

  it('shows the shift summary report with totals per currency (FUEL-34)', async () => {
    setup([
      [
        /^fuel\/reports\/shift-summary\?/,
        {
          data: [{ shift_id: 'sh-1', location: LOCATION, status: 'closed', opened_at: '2026-10-26T04:00:00+00:00', closed_at: '2026-10-26T14:00:00+00:00', currency: 'KES', litres: '55.000', expected_minor: '1000000', collected_minor: '990000', variance_minor: '-10000', attendants: 1, pending: 1 }],
          totals: [{ currency: 'KES', litres: '55.000', expected_minor: '1000000', collected_minor: '990000', variance_minor: '-10000' }],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        },
      ],
    ])
    renderApp('/fuel/reports/shift-summary')
    expect((await screen.findAllByText('Totals per currency')).length).toBeGreaterThan(0)
    expect(screen.getAllByText('KES 10,000.00').length).toBeGreaterThan(0)
    expect(screen.getAllByText('KES -100.00').length).toBeGreaterThan(0)
    expect(screen.getByRole('tab', { name: 'Fleet statement' })).toBeInTheDocument()
  })

  describe('menu gating (RBAC-08, RBAC-09)', () => {
    it('shows the Fuel station group to a tenant with the module and the permissions', async () => {
      setup([], ALL, ['core', 'fuel'])
      renderApp('/')
      expect(await screen.findByRole('link', { name: 'Fuel prices' })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Variance review' })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Forecourt set-up' })).toBeInTheDocument()
    })

    it('hides it without the module', async () => {
      setup([], ALL, ['core'])
      renderApp('/')
      await screen.findAllByRole('link')
      await waitFor(() => expect(screen.queryByRole('link', { name: 'Fuel prices' })).not.toBeInTheDocument())
    })

    it('shows only the items the role holds', async () => {
      setup([], ['core.company.view', 'fuel.shift.view', 'fuel.dip.view'], ['core', 'fuel'])
      renderApp('/')
      expect(await screen.findByRole('link', { name: 'Tank dips' })).toBeInTheDocument()
      expect(screen.queryByRole('link', { name: 'Fuel prices' })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: 'Fuel vouchers' })).not.toBeInTheDocument()
    })
  })
})

// FUEL-13, FUEL-18, L10N-02: every review flag a shift, settlement or sale can carry is worded, never shown as a code.
describe('review flags', () => {
  it('words the till session, attendant and settlement flags', () => {
    const codes = ['before_sign_in', 'session_stale', 'attendant_unverified', 'flagged_sales', 'test_above_tolerance', 'discount', 'vehicle_fuel_differs']
    render(<Flags flags={codes.map((code) => ({ code }))} />)
    for (const code of codes) expect(screen.queryByText(code)).not.toBeInTheDocument()
    expect(screen.getByText('Before sign-in')).toBeInTheDocument()
    expect(screen.getByText('Attendant not verified')).toBeInTheDocument()
    expect(screen.getByText('Flagged sales')).toBeInTheDocument()
  })
})
