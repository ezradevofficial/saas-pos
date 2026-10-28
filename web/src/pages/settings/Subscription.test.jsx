import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const SUBSCRIPTION = {
  id: 's-1', status: 'active', country: 'KE', currency: 'KES', cycle: 'monthly', bundle: 'starter',
  modules: ['inventory', 'loyalty', 'pos'], quantities: { outlet: 1 }, coupon_code: null,
  trial_ends_at: '2026-11-15T08:00:00Z', trial_days_left: null, past_due_since: null, read_only_on: null, read_only_since: null, pos_stops_at: null,
  current_period_start: '2026-11-01', current_period_end: '2026-12-01',
  period_amount: { amount_minor: 390000, currency: 'KES' }, monthly_amount: { amount_minor: 390000, currency: 'KES' },
  pending_change: null, next_invoice: { date: '2026-12-01', amount: { amount_minor: 390000, currency: 'KES' } },
  default_payment_method_id: 'm-1', wallets: ['mpesa_ke', 'airtel_ke'],
}
const CATALOGUE = {
  country: 'KE', currency: 'KES',
  modules: [
    { key: 'pos', kind: 'module', unit: 'outlet', name: 'Point of sale', includes: { pos_device: 2 } },
    { key: 'pos_device', kind: 'extra', unit: 'device', name: 'Extra POS device', includes: {} },
    { key: 'inventory', kind: 'module', unit: 'outlet', name: 'Inventory', includes: {} },
    { key: 'loyalty', kind: 'module', unit: 'outlet', name: 'Loyalty', includes: {} },
    { key: 'payroll', kind: 'module', unit: 'employee', name: 'Payroll', includes: {} },
  ],
  bundles: [{ key: 'starter', name: 'Starter', modules: ['pos', 'inventory', 'loyalty'], quoted: false }],
}
const INVOICE = {
  id: 'i-1', number: 'INV-2026-000002', kind: 'renewal', status: 'open', currency: 'KES',
  total: { amount_minor: 390000, currency: 'KES' }, period_start: '2026-12-01', period_end: '2027-01-01',
  issued_at: '2026-12-01T00:00:00Z', due_at: '2026-12-01T00:00:00Z', paid_at: null,
}
const METHOD = { id: 'm-1', kind: 'mobile_money', provider: 'mpesa_ke', label: '2547•••••678', is_default: true, auto_renew: true }
const QUOTE = {
  kind: 'upgrade', effective: 'now', effective_on: '2026-11-21', currency: 'KES', charge_now: 100000, period_days: 30, remaining_days: 10,
  current: { total: 390000, cycle: 'monthly' }, new: { total: 690000, cycle: 'monthly', coupon: null },
}

function routes(subscription = SUBSCRIPTION, status = { status: subscription.status }) {
  return [
    ['subscription', { data: subscription }],
    ['subscription/status', { data: status }],
    ['subscription/payment-methods', { data: [METHOD] }],
    [/^subscription\/invoices/, { data: [INVOICE], meta: { total: 1, from: 1, to: 1, current_page: 1, last_page: 1, per_page: 25 } }],
    [/^subscription\/payments\//, { data: { id: 'p-1', status: 'succeeded', label: METHOD.label, amount: INVOICE.total } }],
    ['catalogue?country=KE', { data: CATALOGUE }],
    [/^config\/list_view/, { data: [] }],
  ]
}

describe('Subscription page (SUB-02..SUB-06)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
    mockRoutes(api, routes(), { permissions: tenantWide(['core.subscription.view', 'core.subscription.manage']) })
  })

  it('shows the plan, the next invoice with its currency first, invoices and saved methods', async () => {
    renderApp('/settings/subscription')

    expect(await screen.findByRole('heading', { name: 'Subscription' })).toBeInTheDocument()
    expect((await screen.findAllByText('Starter')).length).toBeGreaterThan(0)
    expect(screen.getAllByText('Active').length).toBeGreaterThan(0)
    expect(screen.getAllByText((_, node) => node?.textContent === 'KES 3,900.00').length).toBeGreaterThan(0)
    expect(await screen.findByText('INV-2026-000002')).toBeInTheDocument()
    expect(screen.getByText('2547•••••678')).toBeInTheDocument()
    expect(screen.getByText('Default')).toBeInTheDocument()
  })

  it('quotes a change live, then confirms it with the amount shown', async () => {
    api.post.mockImplementation(async (path) => {
      if (path === 'subscription/quote') return { data: QUOTE }
      if (path === 'subscription/change') return { data: { quote: QUOTE, invoice: { number: 'INV-2026-000003' }, subscription: SUBSCRIPTION } }
      throw new Error(path)
    })
    renderApp('/settings/subscription')

    fireEvent.click(await screen.findByRole('checkbox', { name: 'Payroll' }))
    fireEvent.change(await screen.findByLabelText('Employees'), { target: { value: '20' } })

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('subscription/quote', expect.objectContaining({ modules: ['inventory', 'loyalty', 'payroll', 'pos'], quantities: { employee: 20, outlet: 1 } })), { timeout: 3000 })
    const confirm = await screen.findByRole('button', { name: 'Confirm and pay KES 1,000.00' })
    expect(screen.getByText(/10 days left of 30/)).toBeInTheDocument()
    await waitFor(() => expect(confirm).toBeEnabled())

    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('subscription/change', expect.objectContaining({ expected_charge: 100000, bundle: 'starter' })))
  })

  it('pays an open invoice with the saved M-Pesa wallet and follows it to paid', async () => {
    api.post.mockResolvedValue({ data: { id: 'p-1', status: 'pending', label: METHOD.label, redirect_url: null } })
    renderApp('/settings/subscription')

    fireEvent.click(await screen.findByRole('button', { name: 'Pay invoice INV-2026-000002' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Pay KES 3,900.00' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('subscription/invoices/i-1/pay', { payment_method_id: 'm-1' }))
    expect(await within(dialog).findByText('Payment received. Thank you.')).toBeInTheDocument()
  })

  it('hides the trial banner once the first invoice is paid, without a reload (ONB-04)', async () => {
    let trial = { state: 'trial', ends_at: '2026-11-15T08:00:00Z', days_left: 9 }
    const subscribed = { ...SUBSCRIPTION, status: 'trial' }
    mockRoutes(api, [['onboarding/trial', () => ({ data: trial })], ...routes(subscribed)], { permissions: tenantWide(['core.subscription.view', 'core.subscription.manage']) })
    api.post.mockImplementation(async () => {
      trial = { state: 'none', ends_at: null, days_left: 0 }
      return { data: { id: 'p-1', status: 'pending', label: METHOD.label, redirect_url: null } }
    })
    renderApp('/settings/subscription')

    expect(await screen.findByText('Trial · 9 days left')).toBeInTheDocument()
    fireEvent.click(await screen.findByRole('button', { name: 'Pay invoice INV-2026-000002' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Pay KES 3,900.00' }))

    expect(await screen.findByText('Payment received. Thank you.')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByText('Trial · 9 days left')).not.toBeInTheDocument())
  })

  it('clears "Your plan was changed." on the next action', async () => {
    let current = SUBSCRIPTION
    mockRoutes(api, [['subscription', () => ({ data: current })], ...routes()], { permissions: tenantWide(['core.subscription.view', 'core.subscription.manage']) })
    api.post.mockImplementation(async (path) => {
      if (path === 'subscription/quote') return { data: QUOTE }
      if (path === 'subscription/change') {
        current = { ...SUBSCRIPTION, modules: ['inventory', 'loyalty', 'payroll', 'pos'], quantities: { employee: 20, outlet: 1 } }
        return { data: { quote: QUOTE, invoice: null, subscription: current } }
      }
      throw new Error(path)
    })
    renderApp('/settings/subscription')

    fireEvent.click(await screen.findByRole('checkbox', { name: 'Payroll' }))
    fireEvent.change(await screen.findByLabelText('Employees'), { target: { value: '20' } })
    const confirm = await screen.findByRole('button', { name: 'Confirm and pay KES 1,000.00' }, { timeout: 3000 })
    await waitFor(() => expect(confirm).toBeEnabled())
    fireEvent.click(confirm)
    expect(await screen.findByText('Your plan was changed.')).toBeInTheDocument()

    // Changing a module and changing it back: the old message does not come back.
    fireEvent.click(screen.getByRole('checkbox', { name: 'Payroll' }))
    expect(screen.queryByText('Your plan was changed.')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Payroll' }))
    expect(screen.queryByText('Your plan was changed.')).not.toBeInTheDocument()
  })

  it('shows a placeholder, never raw module keys, until the catalogue loads', async () => {
    mockRoutes(api, [['catalogue?country=KE', () => new Promise(() => {})], ...routes()], { permissions: tenantWide(['core.subscription.view', 'core.subscription.manage']) })
    renderApp('/settings/subscription')

    expect(await screen.findByRole('status', { name: 'Loading the subscription' })).toBeInTheDocument()
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('catalogue?country=KE'))
    expect(screen.queryByText(/starter|inventory/)).not.toBeInTheDocument()
  })

  it('shows a trial subscribed early as a trial, with its first period from the trial end (SUB-03)', async () => {
    const early = { ...SUBSCRIPTION, status: 'trial', trial_days_left: 14, subscribed: true, first_period_start: '2026-11-15', current_period_start: '2026-11-15', current_period_end: '2026-12-15', next_invoice: { date: '2026-11-15', amount: { amount_minor: 390000, currency: 'KES' } } }
    const trial = { state: 'trial', ends_at: '2026-11-15T08:00:00Z', days_left: 14 }
    mockRoutes(api, [['onboarding/trial', { data: trial }], ...routes(early, { status: 'trial', subscribed: true, first_period_start: '2026-11-15' })], { permissions: tenantWide(['core.subscription.view', 'core.subscription.manage']) })
    renderApp('/settings/subscription')

    expect(await screen.findByText('Trial, subscribed')).toBeInTheDocument()
    // The trial bar keeps the days left and says the tenant has subscribed.
    expect(await screen.findByText('Trial · 14 days left')).toBeInTheDocument()
    expect(await screen.findByText('Subscribed: your first period starts on 15 Nov 2026.')).toBeInTheDocument()
    expect(screen.getByText('First period')).toBeInTheDocument()
    expect(screen.getByText('15 Nov 2026 to 15 Dec 2026')).toBeInTheDocument()
    expect(screen.getByText(/You’ve subscribed. Your trial runs until 15 Nov 2026/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Subscribe now' })).not.toBeInTheDocument()
    // The banner's status comes with the subscription: it is not requested again.
    expect(api.get.mock.calls.filter(([path]) => path === 'subscription/status')).toHaveLength(1)
  })

  it('never quotes, or offers to confirm, a plan the owner has not changed (SUB-02)', async () => {
    // The subscription stores no quantities; the editor shows one outlet. Untouched, that is no change.
    mockRoutes(api, routes({ ...SUBSCRIPTION, quantities: {} }), { permissions: tenantWide(['core.subscription.view', 'core.subscription.manage']) })
    api.post.mockImplementation(async (path) => {
      if (path === 'subscription/quote') return { data: { ...QUOTE, kind: 'none', effective: null, effective_on: null, charge_now: 0 } }
      throw new Error(path)
    })
    renderApp('/settings/subscription')

    expect(await screen.findByRole('checkbox', { name: 'Payroll' })).toBeInTheDocument()
    await new Promise((resolve) => setTimeout(resolve, 700))
    expect(api.post).not.toHaveBeenCalled()
    expect(screen.queryByRole('button', { name: 'Confirm change' })).not.toBeInTheDocument()

    // The API says nothing differs: the confirm stays disabled.
    fireEvent.click(screen.getByRole('checkbox', { name: 'Payroll' }))
    expect(await screen.findByText('This is your current plan.', {}, { timeout: 3000 })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirm change' })).toBeDisabled()
  })

  it('downloads no invoice by itself when the page loads after a payment', async () => {
    renderApp('/settings/subscription?payment=p-1')

    expect(await screen.findByText('Payment received. Thank you.')).toBeInTheDocument()
    expect(await screen.findByText('INV-2026-000002')).toBeInTheDocument()
    await new Promise((resolve) => setTimeout(resolve, 300))
    expect(api.download).not.toHaveBeenCalled()
  })

  it('hides plan changes and payments from those who may only see the subscription', async () => {
    mockRoutes(api, routes(), { permissions: tenantWide(['core.subscription.view']) })
    renderApp('/settings/subscription')

    expect(await screen.findByText('INV-2026-000002')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Change plan' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Pay invoice INV-2026-000002' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add card' })).not.toBeInTheDocument()
  })
})

describe('Subscription banner (SUB-05, ONB-04)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
  })

  it('leaves the trial to the trial banner and shows past due with the date it turns read-only', async () => {
    mockRoutes(api, routes(SUBSCRIPTION, { status: 'past_due', read_only_on: '2026-12-08T00:00:00Z' }), { permissions: tenantWide(['core.subscription.view']) })
    renderApp('/')

    const banner = await screen.findByRole('status', { name: 'Subscription status' })
    expect(within(banner).getByText('Past due')).toBeInTheDocument()
    expect(within(banner).getByText(/Pay it by 8 Dec 2026/)).toBeInTheDocument()
    expect(within(banner).getByRole('link', { name: 'Pay now' })).toHaveAttribute('href', '/settings/subscription')
  })

  it('says the account is read-only, without a link for those who cannot pay', async () => {
    mockRoutes(api, routes(SUBSCRIPTION, { status: 'read_only', pos_stops_at: '2026-12-11T06:00:00Z' }), { permissions: tenantWide(['core.item.view']) })
    renderApp('/')

    const banner = await screen.findByRole('status', { name: 'Subscription status' })
    expect(within(banner).getByText('Read-only')).toBeInTheDocument()
    expect(within(banner).getByText(/read-only until the overdue invoice is paid/)).toBeInTheDocument()
    expect(within(banner).queryByRole('link')).not.toBeInTheDocument()
  })

  it('stays out of the way for an active subscription', async () => {
    mockRoutes(api, routes(), { permissions: tenantWide(['core.subscription.view']) })
    renderApp('/')

    await screen.findAllByRole('navigation')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('subscription/status'))
    expect(screen.queryByText('Choose a plan')).not.toBeInTheDocument()
  })
})
