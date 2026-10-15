// CRM-25: the customer 360 view on the customer page: CRM parts always, POS, Loyalty and Accounting only when the API sends them.
import { screen, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockCrm, named, OPPORTUNITY } from '@/test/crm'
import { renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const PARTY = { id: 'pt-1', company_id: null, shared: true, kind: 'organisation', name: 'Duka Moja', roles: ['customer'], tags: [], phones: [], emails: [], addresses: [], currency: 'KES', payment_terms_days: 0, price_list_id: null, archived_at: null }
const UPCOMING = { id: 'a-1', company: named('c-1', 'Amani Retail'), branch: named('br-1', 'Westlands'), owner: named('u-1', 'Amina Otieno'), kind: 'meeting', subject: 'Present the shelving plan', notes: null, starts_at: '2026-10-20T07:00:00Z', ends_at: null, remind_at: null, status: 'open', done_at: null, outcome: null, overdue: false, lead: null, opportunity: null, quote: null, party: named('pt-1', 'Duka Moja'), email: null, created_at: '2026-10-10T06:00:00Z', can: { edit: true } }
const CRM = {
  open_value: [{ currency: 'KES', amount_minor: '12000000' }],
  opportunities: [OPPORTUNITY, { ...OPPORTUNITY, id: 'o-2', name: 'Old deal', status: 'won' }],
  quotes: [{ id: 'q-1', number: 'QT-WL-2026-00001', status: 'sent', currency: 'KES', quote_date: '2026-10-10', totals: { subtotal_minor: '100000', discount_minor: '0', tax_minor: '16000', total_minor: '116000' } }],
  activities: { upcoming: [UPCOMING], recent: [] },
  leads: [],
}
const PERMISSIONS = tenantWide(['core.company.view', 'core.party.view', 'crm.lead.view', 'crm.opportunity.view', 'crm.quote.view', 'crm.activity.view'])

function setup(overview) {
  mockCrm(api, [['parties/pt-1', { data: PARTY }], [/^history\/party\//, { data: [], meta: { current_page: 1, last_page: 1 } }], ['master-data/settings', { data: [] }], ['crm/customers/pt-1/overview', { data: overview }]], { permissions: PERMISSIONS })
}

describe('Customer Sales tab (CRM-25)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the pipeline, quotes and next activity, and no POS, Loyalty or Accounting part when they are null', async () => {
    setup({ party: named('pt-1', 'Duka Moja'), crm: CRM, pos: null, loyalty: null, accounting: null })
    renderApp('/contacts/customers/pt-1?tab=sales')
    expect(await screen.findByRole('tab', { name: 'Sales' })).toHaveAttribute('data-state', 'active')
    expect(await screen.findByText('Open pipeline')).toBeInTheDocument()
    expect(screen.getAllByText('Present the shelving plan').length).toBeGreaterThan(0)
    expect(screen.getByRole('link', { name: 'Duka Moja shelving' })).toHaveAttribute('href', '/crm/opportunities/o-1')
    expect(screen.queryByRole('link', { name: 'Old deal' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'QT-WL-2026-00001' })).toHaveAttribute('href', '/crm/quotes/q-1')
    expect(screen.queryByText('Point of sale')).not.toBeInTheDocument()
    expect(screen.queryByText('Loyalty')).not.toBeInTheDocument()
    expect(screen.queryByText('Receivables')).not.toBeInTheDocument()
  })

  it('shows POS sales, loyalty balances and receivables when the API sends them', async () => {
    setup({
      party: named('pt-1', 'Duka Moja'),
      crm: CRM,
      pos: { count: 14, totals: [{ currency: 'KES', total_minor: '4520000' }], last_sold_at: '2026-10-12T09:00:00Z', recent: [{ id: 's-1', receipt_number: 'R-WL2-000042', sold_at: '2026-10-12T09:00:00Z', currency: 'KES', total_minor: '82500', status: 'completed' }] },
      loyalty: { points: [{ company: named('c-1', 'Amani Retail'), balance: 320 }], credit: [{ company: named('c-1', 'Amani Retail'), currency: 'KES', balance_minor: '110000', limit_minor: '300000' }] },
      accounting: { open: [{ currency: 'KES', open_minor: '250000' }], overdue: [{ currency: 'KES', open_minor: '50000' }], recent: [{ id: 'inv-1', number: 'INV-2026-00007', kind: 'invoice', issue_date: '2026-10-01', due_date: '2026-10-31', currency: 'KES', total_minor: '250000', open_minor: '250000', status: 'posted' }] },
    })
    renderApp('/contacts/customers/pt-1?tab=sales')
    const pos = (await screen.findByRole('heading', { name: 'Point of sale' })).closest('[data-slot="card"]')
    expect(within(pos).getByText('14')).toBeInTheDocument()
    expect(within(pos).getByText('45,200.00')).toBeInTheDocument()
    expect(within(pos).getByRole('link', { name: 'R-WL2-000042' })).toHaveAttribute('href', '/pos/sales/s-1')
    const loyalty = screen.getByRole('heading', { name: 'Loyalty' }).closest('[data-slot="card"]')
    expect(within(loyalty).getByText('320')).toBeInTheDocument()
    expect(within(loyalty).getByText('1,100.00')).toBeInTheDocument()
    const receivables = screen.getByRole('heading', { name: 'Receivables' }).closest('[data-slot="card"]')
    expect(within(receivables).getByText('500.00')).toBeInTheDocument()
    expect(within(receivables).getByRole('link', { name: 'INV-2026-00007' })).toHaveAttribute('href', '/accounting/documents/inv-1')
  })

  it('has no Sales tab without the CRM module', async () => {
    mockCrm(api, [['parties/pt-1', { data: PARTY }], [/^history\/party\//, { data: [], meta: { current_page: 1, last_page: 1 } }], ['master-data/settings', { data: [] }]], { permissions: PERMISSIONS, modules: ['core'] })
    renderApp('/contacts/customers/pt-1?tab=sales')
    expect(await screen.findByRole('tab', { name: 'Details' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Sales' })).not.toBeInTheDocument()
  })
})
