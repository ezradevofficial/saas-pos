import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { moduleLink, sourceLink } from './accountingData'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const ACCOUNTS = [
  { id: 'a-1110', code: '1110', name: 'Cash on hand', postable: true, archived_at: null, is_control: false },
  { id: 'a-4100', code: '4100', name: 'Sales', postable: true, archived_at: null, is_control: false },
]
const RULES = {
  data: [{ id: 'r-1', event_type: 'pos.sale', lines: [
    { field: 'tender.*', side: 'debit', by: 'payment_method', accounts: { 'type:cash': 'a-1110', 'type:card': 'a-1110' } },
    { field: 'revenue.*', side: 'credit', account_id: 'a-4100' },
  ] }],
  meta: { event_types: ['pos.sale', 'pos.refund', 'loyalty.gift_card_sold'], fallbacks: { 'pos.refund': { event_type: 'pos.sale', reversed: true } } },
}
const EXCEPTIONS = { data: [{
  id: 'e-1', source_type: 'loyalty.gift_card_sold', source_id: 'g-1', error_code: 'posting_rule_missing', error_message: 'There is no posting rule for loyalty.gift_card_sold.',
  attempts: 1, status: 'open', journal_id: null, date: '2026-10-14T08:00:00+00:00', reference: 'MPESA-1', link: { kind: 'loyalty.gift_card', id: 'card-1' },
}] }

function setup(permissions = ['core.company.view', 'accounting.posting_rule.view', 'accounting.posting_rule.manage', 'accounting.account.view']) {
  mockRoutes(api, [
    [/^companies\/c-1\/accounting\/accounts\?/, { data: ACCOUNTS }],
    ['companies/c-1/accounting/posting-rules', RULES],
    [/^companies\/c-1\/accounting\/posting-exceptions/, EXCEPTIONS],
    [/^config\/list_view/, { data: [] }],
  ], { permissions: tenantWide(permissions), modules: ['core', 'accounting', 'loyalty'], companies: [COMPANY] })
}

describe('Posting rules and exceptions (ACC-24)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists every source with its own rule, its fallback or none, and retries what waits with a drill-down to the record', async () => {
    setup()
    api.post.mockResolvedValue({ data: { status: 'posted' } })
    renderApp('/accounting/chart?tab=rules')

    const rules = await screen.findByRole('table', { name: 'Posting rules' })
    await within(rules).findByText('Own rule')
    const row = (name) => within(rules).getByText(name).closest('tr')
    expect(within(row('POS sale')).getByText('Own rule')).toBeInTheDocument()
    expect(within(row('POS sale')).getByText('tender.* → Debit (2 mapped)')).toBeInTheDocument()
    expect(within(row('POS sale')).getByText('revenue.* → Credit · 4100 Sales')).toBeInTheDocument()
    expect(within(row('POS refund')).getByText('Uses the POS sale rule, reversed')).toBeInTheDocument()
    expect(within(rules).getAllByText('No rule')).toHaveLength(1)

    const waiting = await screen.findByRole('table', { name: 'Waiting to post' })
    await within(waiting).findByText('MPESA-1')
    expect(within(waiting).getByRole('link', { name: 'Gift card sold' })).toHaveAttribute('href', '/loyalty/gift-cards/card-1')
    expect(within(waiting).getByText('MPESA-1')).toBeInTheDocument()
    fireEvent.click(within(waiting).getByRole('button', { name: 'Retry' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('accounting/posting-exceptions/e-1/retry'))
    expect(await screen.findByText('Posted.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Add default rules' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('companies/c-1/accounting/posting-rules/defaults'))
  })

  it('hides changes from readers', async () => {
    setup(['core.company.view', 'accounting.posting_rule.view', 'accounting.account.view'])
    renderApp('/accounting/chart?tab=rules')
    await screen.findByRole('table', { name: 'Waiting to post' })
    expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add default rules' })).not.toBeInTheDocument()
  })

  it('opens each source in its own module (ACC-17)', () => {
    expect(moduleLink({ kind: 'pos.sale', id: 's-1' })).toBe('/pos/sales/s-1')
    expect(moduleLink({ kind: 'pos.sales', id: 'l-1', date: '2026-10-13' })).toBe('/pos/sales?location=l-1&from=2026-10-13&to=2026-10-13')
    expect(moduleLink({ kind: 'inventory.movements', id: 'm-1', source_type: 'pos.sale' })).toBe('/inventory/movements?source=m-1')
    expect(moduleLink({ kind: 'loyalty.credit_account', id: 'a-1' })).toBe('/loyalty/credit/a-1')
    expect(moduleLink({ kind: 'loyalty.customer', id: 'p-1' })).toBe('/contacts/customers/p-1?tab=loyalty')
    expect(sourceLink({ type: 'pos.refund', id: 'r-1', link: { kind: 'pos.sale', id: 's-1' } })).toBe('/pos/sales/s-1')
    expect(sourceLink({ type: 'accounting.document', id: 'd-1', link: null })).toBe('/accounting/documents/d-1')
  })
})
