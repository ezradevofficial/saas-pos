import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { chooseOption } from '@/test/combobox'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const STEPS = ['company', 'currencies', 'branches', 'taxes', 'payment_methods', 'items', 'users', 'devices']

function state(overrides = {}) {
  return {
    stage: 'business',
    country: 'KE',
    business_name: 'Duka Bora',
    slug: 'duka-bora',
    business_type: null,
    pos_mode: null,
    selection: null,
    applied: null,
    steps: STEPS.map((key) => ({ key, status: 'todo', detected: false })),
    trial: { state: 'none', ends_at: null, days_left: 0 },
    sample: null,
    assisted: null,
    business_types: [
      { key: 'retail', name: 'Retail shop', description: 'Boutiques, hardware, electronics' },
      { key: 'pharmacy', name: 'Pharmacy or agrovet', description: 'Batches, expiry dates' },
    ],
    module_groups: { sell: ['pos', 'inventory', 'loyalty'], operate: ['stores'], finance_people: ['hr'] },
    ...overrides,
  }
}

const CATALOGUE = {
  data: {
    country: 'KE',
    currency: 'KES',
    modules: [
      { key: 'pos', kind: 'module', unit: 'outlet', name: 'Point of sale', includes: { pos_device: 2 }, amount: 250000 },
      { key: 'pos_device', kind: 'extra', unit: 'device', name: 'Extra POS device', includes: {}, amount: 50000 },
      { key: 'inventory', kind: 'module', unit: 'outlet', name: 'Inventory', includes: {}, amount: 150000 },
      { key: 'loyalty', kind: 'module', unit: 'outlet', name: 'Loyalty, promotions and credit', includes: {}, amount: 100000 },
      { key: 'stores', kind: 'module', unit: 'outlet', name: 'Stores', includes: {}, amount: 100000 },
      { key: 'hr', kind: 'module', unit: 'employee', name: 'HR, shifts and attendance', includes: {}, amount: 10000 },
    ],
    bundles: [{ key: 'starter', name: 'Starter', modules: ['pos', 'inventory', 'loyalty'], quoted: false, amount: 390000 }],
  },
}

const PERMISSIONS = tenantWide(['core.onboarding.view', 'core.onboarding.edit', 'core.onboarding.modules', 'core.company.view'])

function setUp(current, extra = []) {
  mockRoutes(api, [['onboarding', () => ({ data: current() })], ['onboarding/trial', () => ({ data: current().trial })], ['onboarding/checklist', { data: { visible: false, items: [] } }], [/^catalogue\?country=/, CATALOGUE], ...extra], {
    permissions: PERMISSIONS,
  })
}

describe('Onboarding screens', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('sends a new owner from home to the business type, then applies the chosen type (ONB-02)', async () => {
    let current = state()
    setUp(() => current)
    api.put.mockImplementation(async (path, body) => {
      current = state({ stage: 'modules', business_type: body.business_type, selection: { modules: ['pos'], quantities: { outlet: 2 }, cycle: 'monthly' } })
      return { data: current }
    })
    const { router } = renderApp('/')

    expect(await screen.findByRole('heading', { level: 1, name: 'What kind of business do you run?' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/onboarding/business')
    expect(screen.getByRole('button', { name: 'Continue to modules' })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: /Pharmacy or agrovet/ }))
    expect(screen.getByRole('button', { name: /Pharmacy or agrovet/ })).toHaveAttribute('aria-pressed', 'true')
    chooseOption('Number of outlets', '2 to 5')
    fireEvent.click(screen.getByRole('button', { name: 'Continue to modules' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/onboarding/modules'))
    expect(api.put).toHaveBeenCalledWith('onboarding/business', { business_type: 'pharmacy', outlets: 2 })
  })

  it('prices the selection live, offers the cheaper bundle and starts the trial (ONB-03, ONB-04)', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const current = state({ stage: 'modules', business_type: 'retail', selection: { modules: ['pos', 'inventory', 'loyalty'], quantities: { outlet: 1, device: 2, employee: 5 }, cycle: 'monthly', bundle: null } })
    setUp(() => current)
    api.post.mockImplementation(async (path, body) => {
      if (path !== 'catalogue/quote') throw new Error(path)
      if (body.bundle === 'starter') {
        return { data: { currency: 'KES', quoted: false, lines: [{ type: 'bundle', key: 'starter', amount: 390000 }], total: body.cycle === 'annual' ? 3900000 : 390000, suggestion: null } }
      }
      return {
        data: {
          currency: 'KES',
          quoted: false,
          lines: body.modules.map((key) => ({ type: 'module', key, amount: { pos: 250000, inventory: 150000, loyalty: 100000, stores: 100000 }[key] })),
          total: 500000,
          suggestion: { bundle: 'starter', total: 390000, saving: 110000, currency: 'KES' },
        },
      }
    })
    api.put.mockResolvedValue({ data: { ...current, stage: 'setup', trial: { state: 'trial', days_left: 14 } } })
    const { router } = renderApp('/onboarding/modules')

    expect(await screen.findByRole('heading', { level: 1, name: 'Pick the modules you need' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Point of sale/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByText('KES 2,500.00 per outlet')).toBeInTheDocument()

    await act(async () => vi.advanceTimersByTime(500))
    expect(await screen.findByText('Starter covers this for KES 3,900.00, saving KES 1,100.00.')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('catalogue/quote', expect.objectContaining({ country: 'KE', cycle: 'monthly', modules: ['pos', 'inventory', 'loyalty'] }))

    // Debounced: several quick changes ask once.
    const calls = api.post.mock.calls.length
    fireEvent.click(screen.getByRole('button', { name: 'More: Outlets' }))
    fireEvent.click(screen.getByRole('button', { name: 'Fewer: Outlets' }))
    await act(async () => vi.advanceTimersByTime(500))
    expect(api.post.mock.calls.length - calls).toBeLessThanOrEqual(1)

    fireEvent.click(screen.getByRole('button', { name: 'Use Starter' }))
    fireEvent.click(screen.getByRole('button', { name: 'Yearly · 2 months free' }))
    await act(async () => vi.advanceTimersByTime(500))
    expect(await screen.findByText('Starter bundle applied')).toBeInTheDocument()
    expect(screen.getByText('Per year')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Start free trial' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/onboarding/setup'))
    expect(api.put).toHaveBeenCalledWith('onboarding/modules', expect.objectContaining({ bundle: 'starter', cycle: 'annual', country: 'KE' }))
    vi.useRealTimers()
  })

  it('shows a refused selection and the bundle that fits it', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const current = state({ stage: 'modules', selection: { modules: ['pos'], quantities: { outlet: 1 }, cycle: 'monthly' } })
    setUp(() => current)
    api.post.mockRejectedValue(
      Object.assign(apiError(422, 'addon_requires_bundle', 'Brand Pack comes only with a bundle that includes it.'), { data: { suggestion: { bundle: 'starter', total: 390000, saving: 50000, currency: 'KES' } } }),
    )
    renderApp('/onboarding/modules')
    await screen.findByRole('heading', { level: 1, name: 'Pick the modules you need' })
    await act(async () => vi.advanceTimersByTime(500))
    expect(await screen.findByText('Brand Pack comes only with a bundle that includes it.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Use Starter' })).toBeInTheDocument()
    vi.useRealTimers()
  })

  it('lists the setup steps, skips one and resumes it (ONB-05)', async () => {
    let current = state({ stage: 'setup', pos_mode: 'retail', trial: { state: 'trial', days_left: 13 } })
    setUp(() => current, [['currencies', { data: [] }], ['tenant/currencies', { data: [] }]])
    api.post.mockImplementation(async (path, body) => {
      if (path !== 'onboarding/steps') throw new Error(path)
      current = { ...current, steps: current.steps.map((step) => (step.key === body.step ? { ...step, status: body.status === 'todo' ? 'todo' : body.status } : step)) }
      return { data: current }
    })
    const { router } = renderApp('/onboarding/setup')

    expect(await screen.findByRole('heading', { level: 1, name: 'Get ready for your first sale' })).toBeInTheDocument()
    expect(screen.getByText('Trial · 13 days left')).toBeInTheDocument()
    expect(screen.getByText('Company details and KRA PIN')).toBeInTheDocument()
    expect(screen.getByText('0 of 8 done')).toBeInTheDocument()
    expect(screen.getByRole('progressbar', { name: 'Setup progress' })).toHaveAttribute('aria-valuenow', '0')

    router.navigate('/onboarding/setup/items')
    expect(await screen.findByRole('heading', { level: 1, name: 'Your first items' })).toBeInTheDocument()
    // IMP-01: without core.import.run the import link is replaced by who to ask.
    expect(screen.getByText(/Ask someone who can import data/)).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Import items' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Add an item' })).toHaveAttribute('href', '/catalogue/items/new')

    fireEvent.click(screen.getByRole('button', { name: 'Skip for now' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/onboarding/setup/users'))
    expect(api.post).toHaveBeenCalledWith('onboarding/steps', { step: 'items', status: 'skipped' })

    router.navigate('/onboarding/setup/items')
    await screen.findByRole('button', { name: 'Mark as to do' })
    // The skip refetches permissions and the trial, which can re-render the
    // page under the first button found: click whichever one is current.
    await waitFor(() => {
      const reopen = screen.queryByRole('button', { name: 'Mark as to do' })
      if (reopen) fireEvent.click(reopen)
      expect(api.post).toHaveBeenCalledWith('onboarding/steps', { step: 'items', status: 'todo' })
    })
  })

  it.each([
    ['currencies', 'Currencies and exchange rates'],
    ['branches', 'Branches and outlets'],
    ['taxes', 'Taxes'],
    ['payment_methods', 'Payment methods'],
  ])('embeds the %s page without a second h1 or its own browser title (ONB-05)', async (step, title) => {
    const current = state({ stage: 'setup' })
    mockRoutes(api, [['onboarding', () => ({ data: current })], ['onboarding/trial', () => ({ data: current.trial })], ['onboarding/checklist', { data: { visible: false, items: [] } }]], {
      permissions: tenantWide(['core.onboarding.view', 'core.onboarding.edit', 'core.company.view', 'core.company.edit', 'core.currency.view', 'core.tax.view', 'core.payment_method.view']),
    })
    renderApp(`/onboarding/setup/${step}`)

    expect(await screen.findByRole('heading', { level: 1, name: title })).toBeInTheDocument()
    await act(() => new Promise((resolve) => setTimeout(resolve, 100)))
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(document.title.startsWith(`${title} · `) || document.title === title).toBe(true)
  })

  it('links the items step to the import wizard for people who may import (ONB-05, IMP-01)', async () => {
    const current = state({ stage: 'setup' })
    mockRoutes(api, [['onboarding', () => ({ data: current })], ['onboarding/trial', () => ({ data: current.trial })], ['onboarding/checklist', { data: { visible: false, items: [] } }]], {
      permissions: tenantWide(['core.onboarding.view', 'core.onboarding.edit', 'core.import.run']),
    })
    renderApp('/onboarding/setup/items')

    expect(await screen.findByRole('link', { name: 'Import items' })).toHaveAttribute('href', '/settings/imports/new?type=items')
    expect(screen.queryByText(/Ask someone who can import data/)).not.toBeInTheDocument()
  })

  it('loads and removes sample data, and shows why removal is refused (ONB-06)', async () => {
    let current = state({ stage: 'setup' })
    setUp(() => current)
    api.post.mockImplementation(async (path) => {
      if (path === 'onboarding/sample-data') {
        current = { ...current, sample: { batch_id: 'b-1', counts: { item: 12, party: 3 } } }
        return { data: current }
      }
      if (path === 'onboarding/sample-data/remove') throw apiError(422, 'sample_in_use', 'Sample data can’t be removed: 1 of your own records use it. Change those records first, then try again.')
      throw new Error(path)
    })
    renderApp('/onboarding/setup')

    fireEvent.click(await screen.findByRole('button', { name: 'Load sample data' }))
    expect(await screen.findByText('Sample data is loaded: 12 products and 3 customers.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Remove sample data' }))
    expect(await screen.findByText(/Sample data can’t be removed/)).toBeInTheDocument()
  })

  it('keeps the module picker for the Owner (an Admin runs the setup only)', async () => {
    mockRoutes(api, [['onboarding', { data: state({ stage: 'setup' }) }], ['onboarding/trial', { data: { state: 'trial', days_left: 9 } }]], {
      permissions: tenantWide(['core.onboarding.view', 'core.onboarding.edit']),
    })
    renderApp('/onboarding/modules')
    expect(await screen.findByText(/access/i)).toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith(expect.stringMatching(/^catalogue/))
  })

  it('says plainly when the trial has ended and modules can no longer be picked', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const current = state({ stage: 'setup', selection: { modules: ['pos'], quantities: { outlet: 1 }, cycle: 'monthly' }, trial: { state: 'ended', days_left: 0 } })
    setUp(() => current)
    api.post.mockResolvedValue({ data: { currency: 'KES', quoted: false, lines: [], total: 250000, suggestion: null } })
    api.put.mockRejectedValue(apiError(409, 'trial_ended', 'Your free trial has ended. Choose a plan to switch modules on again.'))
    renderApp('/onboarding/modules')
    fireEvent.click(await screen.findByRole('button', { name: 'Start free trial' }))
    expect(await screen.findByText('Your free trial has ended. Choose a plan to switch modules on again.')).toBeInTheDocument()
    expect(screen.getByText('Trial ended')).toBeInTheDocument()
    vi.useRealTimers()
  })

  it('needs the onboarding permission', async () => {
    mockRoutes(api, [], { permissions: tenantWide(['core.company.view']) })
    renderApp('/onboarding/business')
    expect(await screen.findByText(/don’t have access|You don't have access|access/i)).toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('onboarding')
  })
})

describe('Getting started and the trial (ONB-04, ONB-07)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the checklist on home from real state, its help panel, and hides it when dismissed', async () => {
    const checklist = {
      visible: true,
      dismissed: false,
      done: 1,
      total: 5,
      items: [
        { key: 'company', done: true, video_url: null },
        { key: 'tax_id', done: false, video_url: null },
        { key: 'item', done: false, video_url: 'https://videos.example.com/item' },
        { key: 'till', done: false, video_url: null },
        { key: 'first_sale', done: false, video_url: null },
      ],
    }
    mockRoutes(
      api,
      [
        ['onboarding', { data: state({ stage: 'setup', trial: { state: 'trial', days_left: 9 } }) }],
        ['onboarding/trial', { data: { state: 'trial', days_left: 9 } }],
        ['onboarding/checklist', { data: checklist }],
        [/^dashboard/, { data: { widgets: [] } }],
        [/^config/, { data: [] }],
      ],
      { permissions: PERMISSIONS },
    )
    api.post.mockResolvedValue({ data: { ...checklist, visible: false, dismissed: true } })
    renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Getting started' })).toBeInTheDocument()
    expect(screen.getByText('1 of 5 done')).toBeInTheDocument()
    expect(screen.getByText('Add your tax ID')).toBeInTheDocument()
    // ONB-04: the trial is visible on every page of the app.
    expect(screen.getByRole('status')).toHaveTextContent('Trial · 9 days left')

    fireEvent.click(screen.getByRole('button', { name: 'Show help' }))
    const panel = await screen.findByRole('dialog')
    expect(within(panel).getByText('Make your first sale')).toBeInTheDocument()
    expect(within(panel).getByRole('link', { name: 'Watch the video' })).toHaveAttribute('href', 'https://videos.example.com/item')
    fireEvent.keyDown(panel, { key: 'Escape' })

    fireEvent.click(screen.getByRole('button', { name: 'Hide checklist' }))
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Getting started' })).not.toBeInTheDocument())
    expect(api.post).toHaveBeenCalledWith('onboarding/checklist/dismiss')
  })
})
