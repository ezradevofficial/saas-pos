import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { apiError, renderApp, resetSession } from '@/test/renderApp'
import { chooseOption } from '@/test/combobox'
import { platformApi, setPlatformToken } from './platformClient'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

vi.mock('./platformClient', async (importOriginal) => ({
  ...(await importOriginal()),
  platformApi: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), download: vi.fn() },
}))

const ALL = [
  'platform.tenants.view', 'platform.tenants.owner_search', 'platform.tenants.manage', 'platform.tenants.create', 'platform.catalogue.view', 'platform.billing.view', 'platform.deals.manage',
  'platform.deals.approve', 'platform.support.request', 'platform.packs.view', 'platform.packs.manage', 'platform.packs.approve', 'platform.packs.publish',
  'platform.reports.view', 'platform.staff.manage',
]
const OWNER = { id: 's-1', name: 'Wanjiru Kamau', email: 'wanjiru@example.com', role: 'owner', status: 'active', two_factor_enabled: true, abilities: ALL }
const ACCOUNT = {
  tenant_id: 't-1', name: 'Duka Bora', slug: 'duka-bora', country: 'KE', plan: 'starter', bundle: 'starter', modules: ['pos'], cycle: 'monthly', status: 'trial',
  mrr: { amount_minor: 0, currency: 'KES' }, trial_ends_at: '2026-11-15T08:00:00Z', current_period_end: null, grace_days: null, purge_due: [], alerts: [], deal: null,
}
const DETAIL = {
  account: ACCOUNT, subscription: { status: 'trial', current_period_start: null }, grace_days: null, invoices: [], status: 'active', modules: ['pos'],
  usage: { day: '2026-11-02', units: [{ unit: 'device', count: 2, allowed: 2 }] },
  health: { last_activity_at: '2026-11-02T07:00:00Z', devices: 1, sync_lag_seconds: 7200, failed_jobs: 0, posting_exceptions: null },
}
const CATALOGUE = {
  'platform/catalogue/modules': { data: [{ key: 'pos', name: { en: 'Point of sale', fr: 'Point de vente' } }] },
  'platform/catalogue/bundles': { data: [{ key: 'starter', name: { en: 'Starter', fr: 'Démarrage' } }] },
}

function mockConsole(extra = {}, staff = OWNER) {
  platformApi.get.mockImplementation(async (path) => {
    if (path === 'platform/me') return { data: staff }
    if (CATALOGUE[path]) return CATALOGUE[path]
    if (path in extra) return extra[path]
    throw apiError(404, 'not_found', 'Not found.')
  })
}

// ADM-01, ADM-02, ADM-04, ADM-06, ADM-07, RBAC-13 in the console.
describe('Super-admin console', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    setPlatformToken('plat_x|y')
  })

  it('searches tenants by owner email and status', async () => {
    mockConsole({ 'platform/tenants': { data: [ACCOUNT] }, 'platform/tenants/awaiting-handover': { data: [] }, 'platform/tenants?owner_email=owner%40example.com&status=trial': { data: [ACCOUNT] } })
    renderApp('/platform/tenants')

    const search = await screen.findByRole('search', { name: 'Search tenants' })
    fireEvent.change(within(search).getByLabelText('Owner email'), { target: { value: ' owner@example.com ' } })
    chooseOption(within(search).getByLabelText('Status'), 'Trial')
    fireEvent.click(within(search).getByRole('button', { name: 'Search' }))

    await waitFor(() => expect(platformApi.get).toHaveBeenCalledWith('platform/tenants?owner_email=owner%40example.com&status=trial'))
  })

  it('shows usage and health and suspends a tenant with a reason', async () => {
    mockConsole({ 'platform/tenants/t-1': { data: DETAIL }, 'platform/tenants/t-1/deals': { data: [] } })
    platformApi.post.mockResolvedValue({ data: DETAIL })
    renderApp('/platform/tenants/t-1')

    expect(await screen.findByText('2 of 2')).toBeInTheDocument()
    expect(screen.getByText('2 hours ago')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Suspend' }))
    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: 'Suspend' })
    expect(confirm).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Chargeback fraud under review' } })
    fireEvent.click(confirm)

    await waitFor(() => expect(platformApi.post).toHaveBeenCalledWith('platform/tenants/t-1/suspend', { reason: 'Chargeback fraud under review' }))
  })

  it('drafts an Enterprise deal in minor units', async () => {
    mockConsole({ 'platform/tenants/t-1': { data: DETAIL }, 'platform/tenants/t-1/deals': { data: [] } })
    platformApi.post.mockResolvedValue({ data: {} })
    renderApp('/platform/tenants/t-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Draft a deal' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Monthly price'), { target: { value: '25000.50' } })
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Three-year term agreed with the customer' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send for approval' }))

    await waitFor(() =>
      expect(platformApi.post).toHaveBeenCalledWith('platform/tenants/t-1/deals', expect.objectContaining({ bundle: 'enterprise', lines: [{ type: 'bundle', key: 'enterprise', amount: 2500050 }], reason: 'Three-year term agreed with the customer' })),
    )
  })

  it('opens an approved support session with a one-time code in a new tab', async () => {
    const request = { id: 'r-1', tenant_id: 't-1', tenant_name: 'Duka Bora', staff: { id: 's-1', name: 'Wanjiru Kamau' }, mine: true, mode: 'read', hours: 4, include_sensitive: false, status: 'approved', active: true, expires_at: '2026-11-02T12:00:00Z' }
    mockConsole({ 'platform/support/requests': { data: [request] } })
    platformApi.post.mockResolvedValue({ data: { entry_code: 't-1.secret', request } })
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    renderApp('/platform/support')

    fireEvent.click(await screen.findByRole('button', { name: 'Open session' }))
    await waitFor(() => expect(platformApi.post).toHaveBeenCalledWith('platform/support/requests/r-1/session', {}))
    await waitFor(() => expect(open).toHaveBeenCalledWith(expect.stringMatching(/\/support\/enter#code=t-1\.secret$/), '_blank', 'noopener'))
    open.mockRestore()
  })

  it('shows a release with its errors and offers no approval while it has errors', async () => {
    const release = {
      id: 'p-1', country: 'KE', kind: 'payroll', version: 2, status: 'draft', effective_from: '2026-12-01', validation: { errors: ['Two periods of paye in components.*.periods overlap.'], warnings: [] },
      diff: { base: { source: 'shipped', version: null }, added: [], changed: ['components:paye'], removed: [] }, loaded_by: { id: 's-2', name: 'Otieno' }, approved_by: null, published_by: null,
    }
    mockConsole({ 'platform/packs': { data: [release] }, 'platform/packs/current': { data: [] }, 'platform/packs/p-1': { data: release } })
    renderApp('/platform/packs')

    fireEvent.click(await screen.findByText('Kenya · Payroll'))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('Two periods of paye in components.*.periods overlap.')).toBeInTheDocument()
    expect(within(dialog).getByText('components:paye')).toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
  })

  it('says a release can be approved only while it is a draft (Phase 8 walkthrough, ADM-04)', async () => {
    const release = {
      id: 'p-1', country: 'KE', kind: 'payroll', version: 1, status: 'approved', effective_from: '2026-12-01', validation: { errors: [], warnings: [] },
      diff: { base: { source: 'shipped', version: null }, added: [], changed: [], removed: [] }, loaded_by: { id: 's-2', name: 'Otieno' }, approved_by: { id: 's-3', name: 'Akinyi' }, published_by: null,
    }
    mockConsole({ 'platform/packs': { data: [release] }, 'platform/packs/current': { data: [] }, 'platform/packs/p-1': { data: release } })
    renderApp('/platform/packs')

    fireEvent.click(await screen.findByText('Kenya · Payroll'))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('No errors.')).toBeInTheDocument()
    expect(within(dialog).queryByText('No errors. It can be approved.')).not.toBeInTheDocument()
  })

  it('shows reports per currency and downloads a CSV', async () => {
    const summary = {
      from: '2026-11-01', to: '2026-11-30',
      mrr: { accounts: 1, by_currency: [{ key: 'all', currency: 'KES', accounts: 1, amount_minor: 390000 }], by_country: [], by_plan: [], by_module: [] },
      trials: { started: 3, converted: 2, conversion_percent: '66.7', by_country: [] },
      churn: { churned: 1, rate_percent: '50.0', mrr_lost: [] },
      revenue: { invoices: 2, by_currency: [], by_country: [{ key: 'KE', currency: 'KES', amount_minor: 780000 }], by_module: [], by_plan: [] },
      usage: [{ unit: 'device', tenants: 3, total: 7 }],
    }
    platformApi.get.mockImplementation(async (path) => (path === 'platform/me' ? { data: OWNER } : path.startsWith('platform/reports/summary') ? { data: summary } : CATALOGUE[path] ?? { data: [] }))
    renderApp('/platform/reports')

    expect(await screen.findByText('MRR, KES')).toBeInTheDocument()
    expect(screen.getByText('2 · 66.7 %')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Revenue' }))
    await waitFor(() => expect(platformApi.download).toHaveBeenCalledWith(expect.stringMatching(/^platform\/reports\/revenue\.csv\?from=/), expect.stringMatching(/^revenue-.*\.csv$/)))
  })

  it('shows only the areas a role allows', async () => {
    mockConsole({ 'platform/tenants': { data: [] } }, { ...OWNER, role: 'sales', abilities: ['platform.tenants.view', 'platform.catalogue.view', 'platform.reports.view'] })
    renderApp('/platform/tenants')

    const nav = await screen.findByRole('navigation', { name: 'Platform console' })
    expect(within(nav).getByRole('link', { name: 'Reports' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Staff' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Country packs' })).not.toBeInTheDocument()
  })

  it('invites a staff member and shows the one-time setup link', async () => {
    mockConsole({ 'platform/staff': { data: [{ id: 's-1', name: 'Wanjiru Kamau', email: 'wanjiru@example.com', role: 'owner', status: 'active', last_sign_in_at: null }] } })
    platformApi.post.mockResolvedValue({ data: { id: 's-9', setup_url: 'http://localhost:3008/platform/setup/abc', setup_expires_hours: 24 } })
    renderApp('/platform/staff')

    fireEvent.click(await screen.findByRole('button', { name: 'Invite staff' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Baraka' } })
    fireEvent.change(within(dialog).getByLabelText('Email'), { target: { value: 'baraka@example.com' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create account' }))

    await waitFor(() => expect(platformApi.post).toHaveBeenCalledWith('platform/staff', { name: 'Baraka', email: 'baraka@example.com', role: 'support' }))
    expect(await within(dialog).findByDisplayValue('http://localhost:3008/platform/setup/abc')).toBeInTheDocument()
  })
})
