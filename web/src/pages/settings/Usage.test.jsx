import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import i18n from '@/i18n'
import { ALL_CORE, mockRoutes, OWNER, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const unit = (overrides) => ({ unit: 'outlet', count: 1, allowed: null, over: 0, state: 'unlimited', overage: null, extra_cost: null, ...overrides })

const OVER = {
  units: [
    unit({ unit: 'outlet', count: 2, allowed: 2, state: 'within' }),
    unit({
      unit: 'device',
      count: 3,
      allowed: 2,
      over: 1,
      state: 'over',
      overage: { started_at: '2026-10-12T03:00:00Z', blocks_at: '2026-10-19T03:00:00Z', days_left: 4 },
      extra_cost: { amount_minor: '50000', currency: 'KES' },
    }),
    unit({ unit: 'user', count: 5, allowed: 3, over: 2, state: 'blocked', overage: { started_at: '2026-10-01T03:00:00Z', blocks_at: '2026-10-08T03:00:00Z', days_left: 0 } }),
    unit({ unit: 'employee', count: 0 }),
  ],
  extra_cost: { amount_minor: '50000', currency: 'KES' },
  window_days: 7,
  upgrade_url: '/settings/subscription',
  metered_at: '2026-10-15T03:00:00Z',
}

const WITHIN = { ...OVER, units: [unit({ count: 1, allowed: 2, state: 'within' }), unit({ unit: 'device' }), unit({ unit: 'user' }), unit({ unit: 'employee' })], extra_cost: null }

function serve(usage, { owner = true, user = OWNER } = {}) {
  mockRoutes(api, [
    ['me', { data: user }],
    ['me/permissions', { permissions: ALL_CORE, modules: ['core'], owner }],
    ['usage', { data: usage }],
  ])
}

describe('Usage (LIM-01, LIM-02, LIM-03)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
  })

  it('shows each unit against the plan with its status, the extra cost and Upgrade', async () => {
    serve(OVER)
    const { router } = renderApp('/settings/usage')

    const card = await screen.findByRole('heading', { name: 'Usage against your plan' })
    const list = card.closest('[data-slot="card"]') ?? document.body
    expect(await within(list).findByText('Within plan')).toBeInTheDocument()
    expect(within(list).getByText('Over plan')).toBeInTheDocument()
    expect(within(list).getByText('Adding paused')).toBeInTheDocument()
    expect(within(list).getByText('No limit')).toBeInTheDocument()
    expect(within(list).getByText(/3 of 2 · 1 over · 4 days left to upgrade/)).toBeInTheDocument()
    expect(within(list).getByText(/5 of 3 · 2 over · Adding more is paused until you upgrade/)).toBeInTheDocument()
    expect(within(list).getByText('0, no limit')).toBeInTheDocument()
    // Status is a dot plus a word.
    expect(within(list).getByText('Over plan').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(list).getAllByText('KES').length).toBeGreaterThan(0)

    fireEvent.click(within(list).getByRole('button', { name: 'Upgrade' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/subscription'))
  })

  it('is for Owners only: no page, no menu entry and no request for others', async () => {
    serve(OVER, { owner: false })
    renderApp('/settings/usage')

    expect(await screen.findByRole('heading', { name: 'You do not have access to this page' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Usage' })).not.toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('usage')
  })
})

describe('Over-limit banner (LIM-02, LIM-03)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
  })

  it('tells the Owner what is over, by how much, the extra cost and the days left, with Upgrade', async () => {
    serve(OVER)
    const { router } = renderApp('/')

    const banner = await screen.findByRole('region', { name: 'Your organisation is above its plan' })
    expect(within(banner).getByText('POS devices: 3 of 2, 1 over')).toBeInTheDocument()
    expect(within(banner).getByText('4 days left to upgrade')).toBeInTheDocument()
    expect(within(banner).getByText('Users: 5 of 3, 2 over')).toBeInTheDocument()
    expect(within(banner).getByText('Adding paused')).toBeInTheDocument()
    expect(within(banner).getByText('Extra cost KES 500.00 a month, charged on your next invoice.')).toBeInTheDocument()
    expect(within(banner).queryByText(/Outlets/)).not.toBeInTheDocument()
    // The menu has the Usage entry for an Owner.
    expect(screen.getAllByRole('link', { name: 'Usage' }).length).toBeGreaterThan(0)

    fireEvent.click(within(banner).getByRole('button', { name: 'Upgrade' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/subscription'))
  })

  it('is not shown within the plan, nor to anyone but an Owner', async () => {
    serve(WITHIN)
    renderApp('/')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('usage'))
    expect(screen.queryByRole('region', { name: 'Your organisation is above its plan' })).not.toBeInTheDocument()
  })

  it('never asks a non-Owner for usage', async () => {
    serve(OVER, { owner: false })
    renderApp('/')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('me/permissions'))
    expect(screen.queryByRole('region', { name: 'Your organisation is above its plan' })).not.toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('usage')
  })

  it('speaks French', async () => {
    serve(OVER, { user: { ...OWNER, locale: 'fr' } })
    renderApp('/')

    const banner = await screen.findByRole('region', { name: 'Votre organisation dépasse son abonnement' })
    expect(within(banner).getByText('Appareils de caisse : 3 sur 2, 1 en plus')).toBeInTheDocument()
    expect(within(banner).getByRole('button', { name: 'Mettre à niveau' })).toBeInTheDocument()
    await i18n.changeLanguage('en')
  })
})
