import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockApi, renderApp, resetSession } from '@/test/renderApp'
import { getPlatformToken, PLATFORM_TOKEN_KEY, platformApi, setPlatformToken } from './platformClient'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

vi.mock('./platformClient', async (importOriginal) => ({
  ...(await importOriginal()),
  platformApi: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))

const STAFF = { id: 's-1', name: 'Wanjiru Kamau', email: 'wanjiru@example.com', role: 'owner', status: 'active', two_factor_enabled: true, abilities: ['catalogue.view', 'catalogue.manage'] }

const prices = (country, currency, amount, extra = {}) => ({ [country]: { current: { country, currency, amount, minimum_amount: null, extra_outlet_amount: null, effective_from: '2026-10-01', ...extra }, next: null } })

const MODULES = [
  { id: 'm-1', key: 'pos', kind: 'module', unit: 'outlet', name: { en: 'Point of sale', fr: 'Point de vente' }, includes: { pos_device: 2 }, archived_at: null, prices: { ...prices('KE', 'KES', 250000), ...prices('CD', 'USD', 2000) } },
  { id: 'm-2', key: 'hr', kind: 'module', unit: 'employee', name: { en: 'HR, shifts and attendance', fr: 'RH' }, includes: {}, archived_at: null, prices: { ...prices('KE', 'KES', 10000, { minimum_amount: 150000 }), ...prices('CD', 'USD', 100, { minimum_amount: 1200 }) } },
]

const PUBLIC = {
  data: {
    country: 'KE',
    currency: 'KES',
    modules: [
      { key: 'pos', kind: 'module', name: 'Point of sale' },
      { key: 'inventory', kind: 'module', name: 'Inventory' },
      { key: 'loyalty', kind: 'module', name: 'Loyalty, promotions and credit' },
    ],
    bundles: [{ key: 'starter', name: 'Starter', quoted: false }],
  },
}

function mockConsole(extra = {}) {
  platformApi.get.mockImplementation(async (path) => {
    if (path in extra) return extra[path]
    if (path === 'platform/me') return { data: STAFF }
    if (path === 'platform/catalogue/modules') return { data: MODULES }
    if (path === 'platform/catalogue/annual-discounts') return { data: [{ id: 'a-1', months_charged: 10, months_in_cycle: 12, status: 'in_effect' }] }
    if (path === 'platform/catalogue/bundles') return { data: [{ id: 'b-1', key: 'starter', name: { en: 'Starter', fr: 'Démarrage' }, modules: ['pos', 'inventory', 'loyalty'], addons: ['brand_pack'], quoted: false, archived_at: null, prices: prices('KE', 'KES', 390000) }] }
    if (path === 'platform/catalogue/coupons') return { data: [] }
    if (path.startsWith('catalogue?country=')) return PUBLIC
    throw apiError(404, 'not_found', 'Not found.')
  })
}

async function openTab(name) {
  const tab = await screen.findByRole('tab', { name })
  fireEvent.mouseDown(tab, { button: 0 })
  fireEvent.click(tab)
}

function fill(label, value) {
  fireEvent.change(screen.getByLabelText(label, { exact: false }), { target: { value } })
}

describe('Platform console', () => {
  beforeEach(() => {
    resetSession()
    setPlatformToken(null)
    vi.clearAllMocks()
    mockApi(api)
    mockConsole()
  })

  it('signs staff in with a password and then an authenticator code', async () => {
    platformApi.post.mockImplementation(async (path) => {
      if (path === 'platform/auth/sign-in') return { status: 'two_factor_required', challenge: 'enc-challenge' }
      if (path === 'platform/auth/two-factor') return { status: 'signed_in', token: 'plat_x|secret', user: STAFF }
      throw new Error(path)
    })
    const { router } = renderApp('/platform/sign-in')

    fill('Email', 'wanjiru@example.com')
    fill('Password', 'quiet-lantern-harbour-77')
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }))

    await screen.findByText('Enter the 6-digit code from your authenticator app.')
    expect(getPlatformToken()).toBeNull()
    fill('6-digit code', '123456')
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/platform/catalogue'))
    expect(platformApi.post).toHaveBeenCalledWith('platform/auth/two-factor', { challenge: 'enc-challenge', code: '123456' })
    expect(getPlatformToken()).toBe('plat_x|secret')
    // The staff token lives for this tab only, never in localStorage.
    expect(window.sessionStorage.getItem(PLATFORM_TOKEN_KEY)).toBe('plat_x|secret')
    expect(window.localStorage.getItem(PLATFORM_TOKEN_KEY)).toBeNull()
    // The tenant session is untouched.
    expect(api.post).not.toHaveBeenCalled()
    expect(await screen.findByRole('heading', { name: 'Catalogue' })).toBeInTheDocument()
  })

  it('shows the API message when the code is wrong', async () => {
    platformApi.post.mockImplementation(async (path) => {
      if (path === 'platform/auth/sign-in') return { status: 'two_factor_required', challenge: 'c' }
      throw apiError(422, 'invalid_code', 'This code isn’t valid.', { code: ['This code isn’t valid.'] })
    })
    renderApp('/platform/sign-in')
    fill('Email', 'wanjiru@example.com')
    fill('Password', 'x')
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await screen.findByText('Enter the 6-digit code from your authenticator app.')
    fill('6-digit code', '000000')
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
    expect(await screen.findByText('This code isn’t valid.')).toBeInTheDocument()
  })

  it('sends staff without two-factor to enrolment, and the console waits for it', async () => {
    setPlatformToken('plat_enrol|s')
    mockConsole({ 'platform/me': { data: { ...STAFF, status: 'pending', two_factor_enabled: false, abilities: [] } } })
    const { router } = renderApp('/platform/catalogue')
    await waitFor(() => expect(router.state.location.pathname).toBe('/platform/enrol'))
    expect(await screen.findByRole('heading', { name: 'Set up your authenticator app' })).toBeInTheDocument()
  })

  it('redirects to sign-in without a staff session', async () => {
    const { router } = renderApp('/platform/catalogue')
    await waitFor(() => expect(router.state.location.pathname).toBe('/platform/sign-in'))
  })

  it('lists modules with their prices per country and minimums', async () => {
    setPlatformToken('plat_full|s')
    renderApp('/platform/catalogue')

    const pos = (await screen.findByText('Point of sale')).closest('tr')
    const table = pos.closest('table')
    expect(within(pos).getByText('2,500.00')).toBeInTheDocument()
    expect(within(pos).getByText('20.00')).toBeInTheDocument()
    expect(within(pos).getByText('Includes 2 devices per outlet')).toBeInTheDocument()
    const hr = within(table).getByText('HR, shifts and attendance').closest('tr')
    expect(within(hr).getByText('Minimum KES 1,500.00')).toBeInTheDocument()
    expect(screen.getByText('Annual billing charges 10 months for 12.')).toBeInTheDocument()
  })

  it('adds a coupon', async () => {
    setPlatformToken('plat_full|s')
    platformApi.post.mockResolvedValue({ data: { id: 'c-1', code: 'LAUNCH10' } })
    renderApp('/platform/catalogue')

    await openTab('Coupons')
    fireEvent.click(await screen.findByRole('button', { name: 'New coupon' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Code', { exact: false }), { target: { value: 'LAUNCH10' } })
    fireEvent.change(within(dialog).getByLabelText('Percentage off', { exact: false }), { target: { value: '10' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create coupon' }))

    await waitFor(() =>
      expect(platformApi.post).toHaveBeenCalledWith(
        'platform/catalogue/coupons',
        expect.objectContaining({ code: 'LAUNCH10', kind: 'percent', percent: 10, applies_to_modules: [], expires_at: null, max_redemptions: null }),
      ),
    )
  })

  it('hides the new coupon and new price actions from support staff', async () => {
    setPlatformToken('plat_full|s')
    mockConsole({ 'platform/me': { data: { ...STAFF, role: 'support', abilities: ['catalogue.view'] } } })
    renderApp('/platform/catalogue')
    await screen.findByText('Point of sale')
    expect(screen.queryByRole('button', { name: 'New price' })).not.toBeInTheDocument()
    await openTab('Coupons')
    await screen.findByText('No coupons yet.')
    expect(screen.queryByRole('button', { name: 'New coupon' })).not.toBeInTheDocument()
  })

  it('previews a quote with the bundle suggestion', async () => {
    setPlatformToken('plat_full|s')
    platformApi.post.mockResolvedValue({
      data: {
        currency: 'KES',
        cycle: 'monthly',
        quoted: false,
        lines: [
          { type: 'module', key: 'pos', quantity: 1, included: 0, amount: 250000, minimum_applied: false },
          { type: 'module', key: 'inventory', quantity: 1, included: 0, amount: 150000, minimum_applied: false },
          { type: 'module', key: 'loyalty', quantity: 1, included: 0, amount: 100000, minimum_applied: false },
        ],
        subtotal: 500000,
        annual_discount: 0,
        coupon_discount: 0,
        total: 500000,
        coupon: null,
        suggestion: { bundle: 'starter', total: 390000, saving: 110000, currency: 'KES' },
      },
    })
    renderApp('/platform/catalogue')

    await openTab('Quote preview')
    fireEvent.click(await screen.findByRole('button', { name: 'Get quote' }))

    await waitFor(() => expect(platformApi.post).toHaveBeenCalledWith('catalogue/quote', expect.objectContaining({ country: 'KE', cycle: 'monthly', modules: ['pos', 'inventory', 'loyalty'], bundle: null })))
    expect(await screen.findByText('Starter covers this selection for less. It saves KES 1,100.00.')).toBeInTheDocument()
    expect(within(screen.getByTestId('quote-result')).getAllByText('5,000.00')).toHaveLength(2)
  })
})
