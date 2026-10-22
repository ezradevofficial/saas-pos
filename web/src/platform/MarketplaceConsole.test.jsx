import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { apiError, renderApp, resetSession } from '@/test/renderApp'
import { platformApi, setPlatformToken } from './platformClient'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

vi.mock('./platformClient', async (importOriginal) => ({
  ...(await importOriginal()),
  platformApi: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), download: vi.fn() },
}))

const REVIEWER = { id: 's-2', name: 'Kioko', email: 'kioko@example.com', role: 'compliance', status: 'active', two_factor_enabled: true, abilities: ['platform.marketplace.view', 'platform.marketplace.review'] }
const FINANCE = { ...REVIEWER, role: 'finance', abilities: ['platform.marketplace.view', 'platform.marketplace.payouts'] }
const SUMMARY = { id: 'app-1', slug: 'stock-sync', status: 'active', name: 'Stock sync', developer_name: 'Acme Labs', latest_version: 2, latest_status: 'submitted', listed_version: 1 }
const VERSION = (version, status, scopes) => ({ id: `v-${version}`, version, status, name: 'Stock sync', scopes, webhook_events: [], redirect_uris: [], pricing: { model: 'free' }, webhook_url: null, description: 'Syncs items.', submitted_at: '2026-10-16T08:00:00Z' })
const DETAIL = {
  ...SUMMARY,
  developer: { id: 'd-1', name: 'Acme Labs', email: 'dev@acme.example' },
  client: { id: 'c-1', confidential: true, redirect_uris: ['https://sync.example.com/cb'], revoked: false },
  versions: [VERSION(2, 'submitted', ['core.item.read', 'inventory.stock.read']), VERSION(1, 'approved', ['core.item.read'])],
  changes: { scopes: { added: ['inventory.stock.read'], removed: [] }, webhook_events: { added: [], removed: [] }, redirect_uris: { added: [], removed: [] }, pricing_changed: false, needs_consent: true },
}

function mockConsole(staff, extra = {}) {
  platformApi.get.mockImplementation(async (path) => {
    if (path === 'platform/me') return { data: staff }
    if (path in extra) return extra[path]
    throw apiError(404, 'not_found', 'Not found.')
  })
}

// ADM-05, MKT-03 in the console.
describe('Marketplace console', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    setPlatformToken('plat_x|y')
  })

  it('shows what a submitted version adds and approves it', async () => {
    mockConsole(REVIEWER, { 'platform/marketplace/apps?status=submitted': { data: [SUMMARY] }, 'platform/marketplace/apps/app-1': { data: DETAIL } })
    platformApi.post.mockResolvedValue({ data: DETAIL })
    renderApp('/platform/marketplace')

    fireEvent.click(await screen.findByText('Stock sync'))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('Added: inventory.stock.read')).toBeInTheDocument()
    expect(within(dialog).getByText(/installs keep what they had/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Approve' }))
    const approve = (await screen.findAllByRole('dialog')).at(-1)
    fireEvent.click(within(approve).getByRole('button', { name: 'Approve' }))
    await waitFor(() => expect(platformApi.post).toHaveBeenCalledWith('platform/marketplace/versions/v-2/approve', { note: null, submitted_at: '2026-10-16T08:00:00Z' }))
  })

  it('says so when the version changed since the reviewer opened it', async () => {
    mockConsole(REVIEWER, { 'platform/marketplace/apps?status=submitted': { data: [SUMMARY] }, 'platform/marketplace/apps/app-1': { data: DETAIL } })
    platformApi.post.mockRejectedValue(apiError(409, 'version_changed', 'This version changed since you opened it. Review it again.'))
    renderApp('/platform/marketplace')

    fireEvent.click(await screen.findByText('Stock sync'))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Approve' }))
    const approve = (await screen.findAllByRole('dialog')).at(-1)
    fireEvent.click(within(approve).getByRole('button', { name: 'Approve' }))
    expect(await within(approve).findByText('This version changed since you opened it. Review it again.')).toBeInTheDocument()
  })

  it('records a payout with its reference', async () => {
    mockConsole(FINANCE, { 'platform/marketplace/payouts': { data: [{ developer_id: 'd-1', currency: 'KES', earned_minor: 40000, paid_minor: 0, balance_minor: 40000, last_payout_at: null, developer: { id: 'd-1', name: 'Acme Labs', email: 'dev@acme.example' } }] } })
    platformApi.post.mockResolvedValue({ data: { id: 'p-1', kind: 'payout' } })
    renderApp('/platform/payouts')

    fireEvent.click(await screen.findByRole('button', { name: 'Record payout' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^Reference/), { target: { value: 'BANK-001' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Mark paid' }))
    await waitFor(() => expect(platformApi.post).toHaveBeenCalledWith('platform/marketplace/developers/d-1/payouts', { currency: 'KES', amount_minor: 40000, reference: 'BANK-001' }))
  })
})
