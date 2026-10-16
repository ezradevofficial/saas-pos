// FA-04, FA-05, FA-12, FA-17, FA-20: the register and an asset's detail
// (activation with the decisive action, disposal request), a month's
// depreciation preview and post, and the tax book refused while the
// country pack's rates are unconfirmed.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Hotels', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const named = (id, name) => ({ id, name })
const page = (data, meta = {}) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length, ...meta } })
const kes = (minor) => ({ amount_minor: minor, currency: 'KES' })

const ASSET = {
  id: 'a-1',
  number: 'FA-WL-00001',
  name: 'Delivery van',
  status: 'draft',
  archived_at: null,
  source: 'manual',
  company: named('c-1', 'Amani Hotels'),
  branch: named('br-1', 'Westlands'),
  location: named('l-1', 'Head office'),
  category: { id: 'cat-1', code: 'VEH', name: 'Vehicles' },
  custodian: null,
  cost_centre: null,
  serial_number: 'KDA 123X',
  acquired_on: '2026-01-01',
  in_service_on: null,
  cost: kes('120000000'),
  base_cost: null,
  book_cost: kes('0'),
  accumulated: kes('0'),
  impairment: kes('0'),
  surplus: kes('0'),
  residual: kes('0'),
  book_value: kes('0'),
  method: 'straight_line',
  useful_life_months: 60,
  can: { edit: true, activate: true, cancel: true, estimates: false, transfer: false, dispose: false, revalue: false, maintain: true, print: true },
}
const ACTIVE = { ...ASSET, status: 'active', in_service_on: '2026-01-01', base_cost: kes('120000000'), book_cost: kes('120000000'), book_value: kes('120000000'),
  can: { ...ASSET.can, activate: false, cancel: false, estimates: true, transfer: true, dispose: true, revalue: true } }

const PERMISSIONS = tenantWide(['core.company.view', 'fixed_assets.asset.view', 'fixed_assets.asset.manage', 'fixed_assets.depreciation.view', 'fixed_assets.depreciation.post',
  'fixed_assets.disposal.request', 'fixed_assets.report.view', 'fixed_assets.tax.view', 'fixed_assets.value.view'])

function setup(asset = ASSET) {
  mockRoutes(
    api,
    [
      ['fixed-assets/assets/a-1', { data: asset }],
      [/^fixed-assets\/assets\?/, page([asset])],
      [/^fixed-assets\/assets\/a-1\/schedule/, { data: { posted: [], projected: [{ from: '2026-01-01', to: '2026-01-31', amount: '1832421', accumulated: '1832421', book_value: '118167579' }], projectable: true, currency: 'KES' } }],
      [/^fixed-assets\/categories\?/, page([{ id: 'cat-1', code: 'VEH', name: 'Vehicles', method: 'straight_line', useful_life_months: 60, residual_percent: '10.0000', archived_at: null }])],
      [/^fixed-assets\/runs\/preview\?/, { data: { period: '2026-01', period_end: '2026-01-31', convention: 'days', total: kes('1832421'), assets_count: 1, closed_through: null, next_period: null,
        lines: [{ asset: { id: 'a-1', number: 'FA-WL-00001', name: 'Delivery van' }, from: '2026-01-01', to: '2026-01-31', units: '31', amount: kes('1832421'), accumulated: kes('1832421'), book_value: kes('118167579') }] } }],
      [/^fixed-assets\/runs\?/, page([])],
      [/^fixed-assets\/companies\/c-1\/tax-book/, { status: 'unconfirmed', code: 'tax_rates_unconfirmed', message: 'The tax book cannot be used yet: the rates of motor_vehicles are not confirmed in the country pack.', data: [] }],
      [/^fixed-assets\/reports\/net-book-value/, { data: [{ category: { id: 'cat-1', name: 'Vehicles' }, count: 1, cost: kes('120000000'), accumulated: kes('0'), impairment: kes('0'), book_value: kes('120000000') }] }],
      [/^companies\?/, { data: [COMPANY] }],
      [/^me\/companies/, { data: [COMPANY] }],
      [/^locations\?/, { data: [{ id: 'l-1', name: 'Head office', branch: named('br-1', 'Westlands'), archived_at: null }] }],
      [/^tenant\/currencies/, { data: [{ code: 'KES', active: true }] }],
      [/^fixed-assets\/custodians/, { data: [] }],
    ],
    { permissions: PERMISSIONS, modules: ['core', 'fixed_assets'], companies: [COMPANY] },
  )
}

describe('Fixed assets (FA-04, FA-05, FA-12, FA-17, FA-20)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists the register with each asset status as a dot and a word', async () => {
    setup()
    renderApp('/fixed-assets/assets')
    const table = await screen.findByRole('table')
    expect(await within(table).findByText('FA-WL-00001')).toBeInTheDocument()
    expect(within(table).getByText('Draft').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
  })

  it('starts a new asset in the company base currency on a direct load (CUR-01)', async () => {
    setup()
    renderApp('/fixed-assets/assets/new')
    const cost = await screen.findByRole('textbox', { name: 'Cost' })
    await waitFor(() => expect(cost.parentElement).toHaveTextContent('KES'))
    expect(cost.parentElement).not.toHaveTextContent('CDF')
  })

  it('activates a draft with the decisive action', async () => {
    setup()
    api.post.mockResolvedValue({ data: ACTIVE })
    renderApp('/fixed-assets/assets/a-1')
    expect(await screen.findByRole('heading', { name: 'Delivery van' })).toBeInTheDocument()
    const activate = screen.getByRole('button', { name: 'Activate' })
    expect(activate).toHaveAttribute('data-ds-variant', 'pay')
    fireEvent.click(activate)
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Activate' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fixed-assets/assets/a-1/activate', { in_service_on: '2026-01-01' }))
  })

  it('requests a scrapping without proceeds', async () => {
    setup(ACTIVE)
    api.post.mockResolvedValue({ data: { id: 'd-1', number: 'FAD-WL-2026-00001' } })
    renderApp('/fixed-assets/assets/a-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Dispose' }))
    const dialog = await screen.findByRole('dialog')
    chooseOption(within(dialog).getByLabelText(/^Kind/), 'Scrap')
    fireEvent.change(within(dialog).getByLabelText(/^Disposal date/), { target: { value: '2026-10-01' } })
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Crashed' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Request disposal' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fixed-assets/assets/a-1/disposals', { kind: 'scrap', disposal_date: '2026-10-01', reason: 'Crashed' }))
  })

  it('sends sale proceeds as a digit string of minor units', async () => {
    // Review #16: never through a JavaScript number, which loses digits past 2^53.
    setup(ACTIVE)
    api.post.mockResolvedValue({ data: { id: 'd-1', number: 'FAD-WL-2026-00001' } })
    renderApp('/fixed-assets/assets/a-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Dispose' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^Disposal date/), { target: { value: '2026-10-01' } })
    fireEvent.change(within(dialog).getByLabelText(/^Proceeds/), { target: { value: '90,071,992,547,409.93' } })
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Sold' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Request disposal' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fixed-assets/assets/a-1/disposals',
      expect.objectContaining({ kind: 'sale', proceeds_minor: '9007199254740993' })))
  })

  it('previews a month of depreciation before posting it', async () => {
    setup(ACTIVE)
    api.post.mockResolvedValue({ data: { id: 'r-1', period: '2026-01' } })
    renderApp('/fixed-assets/runs')
    fireEvent.change(await screen.findByLabelText(/^Month/), { target: { value: '2026-01' } })
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('fixed-assets/runs/preview?company=c-1&period=2026-01'))
    expect(api.post).not.toHaveBeenCalled()
    fireEvent.click(await screen.findByRole('button', { name: 'Post 2026-01' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fixed-assets/runs', { company_id: 'c-1', period: '2026-01' }))
  })

  it('says the tax book cannot be used while the rates are unconfirmed', async () => {
    setup(ACTIVE)
    renderApp('/fixed-assets/reports')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Tax book' }))
    fireEvent.click(screen.getByRole('tab', { name: 'Tax book' }))
    expect(await screen.findByText(/rates of motor_vehicles are not confirmed/)).toBeInTheDocument()
  })
})
