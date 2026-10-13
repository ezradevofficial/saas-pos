import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { apiError, renderApp, resetSession } from '@/test/renderApp'
import { platformApi, setPlatformToken } from './platformClient'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

vi.mock('./platformClient', async (importOriginal) => ({
  ...(await importOriginal()),
  platformApi: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))

const BILLING = { id: 's-2', name: 'Otieno Billing', email: 'billing@example.com', role: 'billing', status: 'active', two_factor_enabled: true, abilities: ['catalogue.view', 'billing.view', 'billing.manage'] }
const ACCOUNT = {
  tenant_id: 't-1', name: 'Duka Bora', country: 'KE', plan: 'starter', cycle: 'monthly', status: 'past_due',
  mrr: { amount_minor: 390000, currency: 'KES' }, trial_ends_at: '2026-11-15T08:00:00Z', current_period_end: '2026-12-01', grace_days: null, purge_due: [],
}
const INVOICE = { id: 'oi-1', number: 'INV-2026-000004', kind: 'renewal', status: 'open', total: { amount_minor: 390000, currency: 'KES' }, period_start: '2026-12-01', period_end: '2027-01-01', due_at: '2026-12-01T00:00:00Z', paid_via: null }

describe('Platform console tenants (ADR 016)', () => {
  beforeEach(() => {
    resetSession()
    setPlatformToken('plat_x|y')
    platformApi.get.mockImplementation(async (path) => {
      if (path === 'platform/me') return { data: BILLING }
      if (path === 'platform/tenants') return { data: [ACCOUNT] }
      if (path === 'platform/tenants/t-1') return { data: { account: ACCOUNT, subscription: { status: 'past_due', current_period_start: '2026-11-01', current_period_end: '2026-12-01' }, grace_days: null, invoices: [INVOICE] } }
      throw apiError(404, 'not_found', 'Not found.')
    })
  })

  it('lists tenants with plan, status, MRR, and opens one', async () => {
    renderApp('/platform/tenants')

    const table = await screen.findByRole('table', { name: 'Tenants' })
    expect(await within(table).findByText('Duka Bora')).toBeInTheDocument()
    expect(within(table).getByText('Past due')).toBeInTheDocument()
    expect(within(table).getByText('starter')).toBeInTheDocument()

    fireEvent.click(within(table).getByText('Duka Bora'))
    expect(await screen.findByRole('heading', { name: 'Duka Bora' })).toBeInTheDocument()
    expect(await screen.findByText('INV-2026-000004')).toBeInTheDocument()
  })

  it('marks a bank transfer paid with its reference', async () => {
    platformApi.post.mockResolvedValue({ data: { ...INVOICE, status: 'paid', paid_via: 'bank_transfer' } })
    renderApp('/platform/tenants/t-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Mark invoice INV-2026-000004 paid' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Bank reference'), { target: { value: 'EQUITY-0091' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Mark paid' }))

    await waitFor(() => expect(platformApi.post).toHaveBeenCalledWith('platform/invoices/oi-1/mark-paid', { reference: 'EQUITY-0091' }))
  })

  it('offers no changes to support staff', async () => {
    platformApi.get.mockImplementation(async (path) => {
      if (path === 'platform/me') return { data: { ...BILLING, role: 'support', abilities: ['catalogue.view', 'billing.view'] } }
      if (path === 'platform/tenants/t-1') return { data: { account: ACCOUNT, subscription: null, grace_days: null, invoices: [INVOICE] } }
      throw apiError(404, 'not_found', 'Not found.')
    })
    renderApp('/platform/tenants/t-1')

    expect(await screen.findByText('INV-2026-000004')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Mark invoice/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Grace period' })).not.toBeInTheDocument()
  })
})
