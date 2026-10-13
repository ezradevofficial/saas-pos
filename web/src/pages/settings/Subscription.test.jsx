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
