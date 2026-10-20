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

const BILLING = { id: 's-2', name: 'Otieno Billing', email: 'billing@example.com', role: 'finance', status: 'active', two_factor_enabled: true, abilities: ['platform.catalogue.view', 'platform.billing.view', 'platform.billing.manage'] }
const ACCOUNT = {
  tenant_id: 't-1', name: 'Duka Bora', country: 'KE', plan: 'starter', cycle: 'monthly', status: 'past_due',
  mrr: { amount_minor: 390000, currency: 'KES' }, trial_ends_at: '2026-11-15T08:00:00Z', current_period_end: '2026-12-01', grace_days: null, purge_due: [],
  alerts: [{ kind: 'overpayment', invoice: 'INV-2026-000003', amount_minor: 390000, currency: 'KES' }],
}
const CATALOGUE = {
  'platform/catalogue/modules': { data: [{ key: 'pos', name: { en: 'Point of sale', fr: 'Point de vente' } }, { key: 'crm', name: { en: 'Customers', fr: 'Clients' } }] },
  'platform/catalogue/bundles': { data: [{ key: 'starter', name: { en: 'Starter', fr: 'Démarrage' } }] },
}
const SUPPORT = { ...BILLING, role: 'support', abilities: ['platform.catalogue.view', 'platform.billing.view', 'platform.tenants.create'] }
const INVOICE = { id: 'oi-1', number: 'INV-2026-000004', kind: 'renewal', status: 'open', total: { amount_minor: 390000, currency: 'KES' }, period_start: '2026-12-01', period_end: '2027-01-01', due_at: '2026-12-01T00:00:00Z', paid_via: null }

describe('Platform console tenants (ADR 016)', () => {
  beforeEach(() => {
    resetSession()
    setPlatformToken('plat_x|y')
    platformApi.get.mockImplementation(async (path) => {
      if (path === 'platform/me') return { data: BILLING }
      if (CATALOGUE[path]) return CATALOGUE[path]
      if (path === 'platform/tenants') return { data: [{ ...ACCOUNT, plan: 'starter + crm' }] }
      if (path === 'platform/tenants/t-1') return { data: { account: ACCOUNT, subscription: { status: 'past_due', current_period_start: '2026-11-01', current_period_end: '2026-12-01' }, grace_days: null, invoices: [INVOICE] } }
      throw apiError(404, 'not_found', 'Not found.')
    })
  })

  it('lists tenants with plan, status, MRR, and opens one', async () => {
    renderApp('/platform/tenants')

    const table = await screen.findByRole('table', { name: 'Tenants' })
    expect(await within(table).findByText('Duka Bora')).toBeInTheDocument()
    expect(within(table).getByText('Past due')).toBeInTheDocument()
    // Catalogue names, never raw keys.
    expect(await within(table).findByText('Starter + Customers')).toBeInTheDocument()
    expect(within(table).queryByText(/starter/)).not.toBeInTheDocument()
    expect(within(table).getByText('Overpayment')).toBeInTheDocument()

    fireEvent.click(within(table).getByText('Duka Bora'))
    expect(await screen.findByRole('heading', { name: 'Duka Bora' })).toBeInTheDocument()
    expect(await screen.findByText('INV-2026-000004')).toBeInTheDocument()
    expect(await screen.findByText('Starter · Monthly')).toBeInTheDocument()
  })

  it('resends the Owner invitation while an assisted tenant waits for handover, and lists those waiting', async () => {
    platformApi.get.mockImplementation(async (path) => {
      if (path === 'platform/me') return { data: SUPPORT }
      if (CATALOGUE[path]) return CATALOGUE[path]
      if (path === 'platform/tenants') return { data: [ACCOUNT] }
      if (path === 'platform/tenants/awaiting-handover') return { data: [{ tenant_id: 't-1', business_name: 'Duka Bora', staff_name: 'Wanjiru Kamau', contact: 'j***@example.com', created_at: '2026-09-01T08:00:00Z' }] }
      if (path === 'platform/tenants/t-1') {
        return { data: { account: ACCOUNT, subscription: null, grace_days: null, invoices: [], assisted: { created_at: '2026-09-01T08:00:00Z', staff_name: 'Wanjiru Kamau', contact: 'j***@example.com', handed_over_at: null } } }
      }
      throw apiError(404, 'not_found', 'Not found.')
    })
    platformApi.post.mockResolvedValue({ data: { tenant_id: 't-1', slug: 'duka-bora', invitation_id: 'i-2', destination_masked: 'j***@example.com' } })
    renderApp('/platform/tenants')

    const waiting = await screen.findByRole('table', { name: 'Awaiting handover' })
    fireEvent.click(await within(waiting).findByText('Duka Bora'))

    expect(await screen.findByText('Waiting for the Owner to accept the invitation sent to j***@example.com.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Resend invitation' }))
    await waitFor(() => expect(platformApi.post).toHaveBeenCalledWith('platform/tenants/t-1/reinvite'))
    expect(await screen.findByText('A new invitation went to j***@example.com. The previous one no longer works.')).toBeInTheDocument()
  })

  it('offers no reinvite once the tenant is handed over', async () => {
    platformApi.get.mockImplementation(async (path) => {
      if (path === 'platform/me') return { data: SUPPORT }
      if (CATALOGUE[path]) return CATALOGUE[path]
      if (path === 'platform/tenants/t-1') {
        return { data: { account: ACCOUNT, subscription: null, grace_days: null, invoices: [INVOICE], assisted: { created_at: '2026-09-01T08:00:00Z', staff_name: 'Wanjiru Kamau', contact: 'j***@example.com', handed_over_at: '2026-09-02T08:00:00Z' } } }
      }
      throw apiError(404, 'not_found', 'Not found.')
    })
    renderApp('/platform/tenants/t-1')

    expect(await screen.findByText('INV-2026-000004')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Resend invitation' })).not.toBeInTheDocument()
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
      if (path === 'platform/me') return { data: { ...BILLING, role: 'support', abilities: ['platform.catalogue.view', 'platform.billing.view'] } }
      if (CATALOGUE[path]) return CATALOGUE[path]
      if (path === 'platform/tenants/t-1') return { data: { account: ACCOUNT, subscription: null, grace_days: null, invoices: [INVOICE] } }
      throw apiError(404, 'not_found', 'Not found.')
    })
    renderApp('/platform/tenants/t-1')

    expect(await screen.findByText('INV-2026-000004')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Mark invoice/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Grace period' })).not.toBeInTheDocument()
  })
})
