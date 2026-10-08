import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, CD_COMPANY, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { ratePairs } from './finance/rates'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const PERMISSIONS = tenantWide(['core.company.view', 'core.currency.view', 'core.exchange_rate.view', 'core.exchange_rate.override'])
const CURRENCIES = [
  { id: 'tc-1', code: 'CDF', name: 'Congolese franc', decimals: 0, cash_rounding_minor: 50, active: true },
  { id: 'tc-2', code: 'USD', name: 'US dollar', decimals: 2, cash_rounding_minor: 1, active: true },
]
const REFERENCE = { id: 'r-1', pair: 'USD/CDF', direction: 'direct', base: 'USD', quote: 'CDF', kind: 'reference', buy: null, sell: null, mid: '2850.00000000', effective_at: '2026-10-07T08:00:00+00:00', source: 'bcc' }
const SHOP = { ...REFERENCE, id: 'r-2', kind: 'shop', mid: '2900.00000000', buy: '2880.00000000', sell: '2920.00000000', source: 'manual', effective_at: '2026-10-07T09:00:00+00:00' }
const INVERSE = { ...REFERENCE, id: 'r-3', pair: 'CDF/USD', direction: 'inverse', base: 'CDF', quote: 'USD', mid: '0.00035088', source: 'manual', kind: 'shop' }

function rates({ shop = SHOP } = {}) {
  mockRoutes(
    api,
    [
      ['tenant/currencies', { data: CURRENCIES }],
      [/exchange-rates\?.*kind=reference/, { data: [REFERENCE] }],
      [/exchange-rates\?.*kind=shop/, { data: shop ? [shop] : [] }],
      [/exchange-rates\/current/, shop ? { data: { ...shop, value: shop.mid } } : apiError(422, 'rate_unavailable', 'No rate.')],
      [/exchange-rates\?pair/, { data: [shop, REFERENCE, INVERSE].filter(Boolean), meta: { last_page: 1 } }],
    ],
    { permissions: PERMISSIONS, companies: [CD_COMPANY] },
  )
}

describe('ExchangeRates', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists pairs with USD as the base, else the company base currency as the quote', () => {
    expect(ratePairs(['CDF', 'KES', 'USD'], 'CDF')).toEqual(['KES/CDF', 'USD/CDF', 'USD/KES'])
    expect(ratePairs(['CDF', 'USD'], 'USD')).toEqual(['USD/CDF'])
  })

  it('shows the reference and shop rates, which one is in use, and the history with its direction', async () => {
    rates()
    renderApp('/settings/exchange-rates')

    const shop = (await screen.findByRole('heading', { name: 'Shop rate' })).closest('[data-slot="card"]')
    expect(await within(shop).findByText('In use')).toBeInTheDocument()
    expect(within(shop).getByText('2,880')).toBeInTheDocument()
    expect(within(shop).getByText('2,920')).toBeInTheDocument()
    const reference = screen.getByRole('heading', { name: 'Reference rate' }).closest('[data-slot="card"]')
    expect(within(reference).queryByText('In use')).not.toBeInTheDocument()
    expect(within(reference).getByText(/Central Bank of the Congo/)).toBeInTheDocument()
    // Effective times in the company's time zone (Kinshasa, UTC+1).
    expect(within(reference).getByText(/7 Oct 2026, 09:00/)).toBeInTheDocument()

    const history = screen.getByRole('table', { name: 'Rate history for USD/CDF' })
    expect(within(history).getByText('Inverse')).toBeInTheDocument()
    expect(within(history).getAllByText('As listed').length).toBe(2)
    expect(within(history).getByText(/0\.00035088/)).toBeInTheDocument()
  })

  it('sets a shop rate and shows the tolerance warning as a warning, not an error', async () => {
    rates()
    api.post.mockResolvedValue({
      data: { ...SHOP, id: 'r-9', mid: '3190.00000000' },
      meta: { warning: { code: 'rate_tolerance_exceeded', message: 'x', previous_mid: '2900.00000000', change_percent: '10.0000', tolerance_percent: '5.00' } },
    })
    renderApp('/settings/exchange-rates')

    fireEvent.click(await screen.findByRole('button', { name: 'Set shop rate' }))
    const dialog = await screen.findByRole('dialog', { name: 'Set shop rate for USD/CDF' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save shop rate' }))
    expect(await within(dialog).findByText('Enter the rate.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    fireEvent.change(within(dialog).getByLabelText(/Rate \(1 USD in CDF\)/), { target: { value: '3,190' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save shop rate' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('companies/c-1/exchange-rates', { base: 'USD', quote: 'CDF', mid: '3190' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    const warning = await screen.findByText('This rate moved 10%, more than the 5% allowed')
    expect(warning.closest('[role="status"]')).toBeInTheDocument()
    expect(screen.getByText(/The previous rate was 1 USD = CDF 2,900/)).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('sends a chosen effective time as UTC from the company time zone', async () => {
    rates({ shop: null })
    api.post.mockResolvedValue({ data: SHOP })
    renderApp('/settings/exchange-rates')

    expect(await screen.findByText('No shop rate yet. Set one to use your own rate at the till.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Set shop rate' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/Rate \(1 USD in CDF\)/), { target: { value: '2900.5' } })
    fireEvent.click(within(dialog).getByLabelText('Effective now'))
    fireEvent.change(within(dialog).getByLabelText(/Effective from/), { target: { value: '2026-10-08T08:00' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save shop rate' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('companies/c-1/exchange-rates', { base: 'USD', quote: 'CDF', mid: '2900.5', effective_at: '2026-10-08T07:00:00.000Z' }),
    )
  })

  it('hides Set shop rate from a user who may only view rates', async () => {
    mockRoutes(
      api,
      [
        ['tenant/currencies', { data: CURRENCIES }],
        [/exchange-rates/, { data: [], meta: { last_page: 1 } }],
      ],
      { permissions: tenantWide(['core.company.view', 'core.currency.view', 'core.exchange_rate.view']), companies: [CD_COMPANY] },
    )
    renderApp('/settings/exchange-rates')
    expect(await screen.findByRole('heading', { name: 'Rates for USD/CDF' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Set shop rate' })).not.toBeInTheDocument()
  })
})
