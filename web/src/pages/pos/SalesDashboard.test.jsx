import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const money = (amount_minor, currency = 'KES') => ({ amount_minor, currency })
const KE = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const CD = { id: 'c-2', name: 'Kin Market', country: 'CD', base_currency: 'CDF', timezone: 'Africa/Kinshasa', archived_at: null }

const insights = (reporting) => ({
  data: {
    period: { from: '2026-10-09', to: '2026-10-09' },
    reporting_currency: reporting,
    sales_count: 3,
    consolidated: reporting ? { total: money('2596', 'USD'), average_ticket: money('865', 'USD'), complete: false } : null,
    missing_rates: reporting ? ['c-2'] : [],
    companies: [
      {
        company: { id: 'c-1', name: 'Amani Retail' },
        base_currency: 'KES',
        timezone: 'Africa/Nairobi',
        sales_count: 3,
        total: money('337500'),
        tax: money('37500'),
        average_ticket: money('112500'),
        reporting: reporting ? { total: money('2596', 'USD'), average_ticket: money('865', 'USD'), rate: { base: 'USD', quote: 'KES', rate: '130.00000000' } } : null,
        branches: [
          { branch: { id: 'b-1', name: 'Westlands' }, sales_count: 2, total: money('225000'), locations: [{ location: { id: 'l-1', name: 'Front till' }, sales_count: 2, total: money('225000') }] },
        ],
      },
      { company: { id: 'c-2', name: 'Kin Market' }, base_currency: 'CDF', timezone: 'Africa/Kinshasa', sales_count: 0, total: money('0', 'CDF'), tax: money('0', 'CDF'), average_ticket: null, reporting: null, branches: [] },
    ],
    payments: [
      { method_type: 'cash', currency: 'KES', count: 3, amount: money('247500') },
      { method_type: 'mobile_money', currency: 'KES', count: 1, amount: money('100000') },
    ],
    change: [money('10000')],
    top_items: [{ item: { id: 'i-1', name: 'Soap' }, qty: '6', sales_count: 3, totals: [money('337500')] }],
  },
})

function setup() {
  mockRoutes(
    api,
    [
      [/^pos\/insights\?/, (path) => insights(path.includes('currency=USD') ? 'USD' : null)],
      ['tenant/currencies', { data: ['KES', 'CDF', 'USD'].map((code) => ({ code, active: true })) }],
      [/^(branches|locations)\?/, { data: [] }],
    ],
    { permissions: tenantWide(['core.company.view', 'pos.sale.view']), modules: ['core', 'pos'], companies: [KE, CD] },
  )
}

describe('POS sales overview (TEN-07)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
    // Friday 9 October 2026, mid-morning in Nairobi.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-09T07:00:00Z'))
  })

  afterEach(() => vi.useRealTimers())

  it('shows today’s consolidated sales by company, branch and location, payments and top items', async () => {
    setup()
    renderApp('/pos/dashboard')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('pos/insights?from=2026-10-09&to=2026-10-09'))

    const places = await screen.findByRole('table', { name: 'By company, branch and location' })
    expect(within(places).getByText('Amani Retail')).toBeInTheDocument()
    expect(within(places).getByText('Westlands')).toBeInTheDocument()
    expect(within(places).getByText('Front till')).toBeInTheDocument()
    expect(within(places).getByText('3,375.00')).toBeInTheDocument()
    // Two base currencies and no reporting currency chosen: ask for one.
    expect(screen.getByText('Your companies sell in different currencies. Choose a reporting currency to see one total.')).toBeInTheDocument()

    const payments = screen.getByRole('table', { name: 'Payments by method' })
    expect(within(payments).getByText('Mobile money')).toBeInTheDocument()
    expect(screen.getByText('Change given: KES 100.00')).toBeInTheDocument()
    const items = screen.getByRole('table', { name: 'Top items' })
    expect(within(items).getByText('Soap')).toBeInTheDocument()
    expect(within(items).getByText('KES 3,375.00')).toBeInTheDocument()
  })

  it('asks for this week from Monday and converts to a reporting currency, naming companies without a rate', async () => {
    setup()
    const { router } = renderApp('/pos/dashboard')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'This week' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('pos/insights?from=2026-10-05&to=2026-10-09'))

    chooseOption('Reporting currency', 'USD')
    await waitFor(() => expect(router.state.location.search).toBe('?period=week&currency=USD'))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('pos/insights?from=2026-10-05&to=2026-10-09&currency=USD'))
    expect(await screen.findByText(/Kin Market has no exchange rate to USD for this period/)).toBeInTheDocument()
    expect(screen.getByText('USD 25.96')).toBeInTheDocument()
    expect(screen.getByText('USD 8.65')).toBeInTheDocument()
    expect(within(screen.getByRole('table', { name: 'By company, branch and location' })).getByText('No rate')).toBeInTheDocument()
  })
})
