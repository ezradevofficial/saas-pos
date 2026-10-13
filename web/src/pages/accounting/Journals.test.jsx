import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { totalsOf } from './accountingData'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const money = (amount_minor, currency = 'KES') => ({ amount_minor, currency })
const ACCOUNTS = [
  { id: 'a-6200', code: '6200', name: 'Rent', type: 'expense', subtype: 'expense', postable: true, is_control: false, archived_at: null },
  { id: 'a-1120', code: '1120', name: 'Bank accounts', type: 'asset', subtype: 'bank', postable: true, is_control: false, archived_at: null },
  { id: 'a-1200', code: '1200', name: 'Trade receivables', type: 'asset', subtype: 'receivable', postable: true, is_control: true, archived_at: null },
]
const JOURNAL = {
  id: 'j-1', company_id: 'c-1', number: 'JV-2026-00001', date: '2026-10-12', kind: 'manual', status: 'posted', reference: null, memo: 'October rent',
  total: money('1500000'), source: null, reverses_journal_id: null, reversed_by_journal_id: null, post_error: null, created_by: { id: 'u-1', name: 'Amina Otieno' },
  posted_at: '2026-10-12T09:00:00Z', editable: false, reversible: true,
  lines: [
    { id: 'l-1', line_no: 1, account: { id: 'a-6200', code: '6200', name: 'Rent' }, description: null, debit: money('1500000'), credit: money('0'), base_debit: money('1500000'), base_credit: money('0'), fx: null, party: null, cost_centre: { id: 'cc', name: 'Operations' }, department: null, project: null },
    { id: 'l-2', line_no: 2, account: { id: 'a-1120', code: '1120', name: 'Bank accounts' }, description: null, debit: money('0'), credit: money('1500000'), base_debit: money('0'), base_credit: money('1500000'), fx: null, party: null, cost_centre: null, department: null, project: null },
  ],
}
const PERMISSIONS = ['core.company.view', 'accounting.journal.view', 'accounting.journal.create', 'accounting.journal.reverse', 'accounting.journal.approve']

function setup() {
  mockRoutes(
    api,
    [
      [/^companies\/c-1\/accounting\/journals\?/, { data: [JOURNAL], meta: { total: 1, last_page: 1, from: 1, to: 1 } }],
      [/^companies\/c-1\/accounting\/accounts\?/, { data: ACCOUNTS, meta: { total: 3 } }],
      ['accounting/journals/j-1', { data: JOURNAL }],
      ['tenant/currencies', { data: [{ code: 'KES', active: true }, { code: 'USD', active: true }] }],
      [/^config\/list_view/, { data: [] }],
    ],
    { permissions: tenantWide(PERMISSIONS), modules: ['core', 'accounting'], companies: [COMPANY] },
  )
}

describe('Accounting journals (ACC-03 to ACC-05)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists the company’s journals with the status as a dot and a word and the total with its currency first', async () => {
    setup()
    renderApp('/accounting/journals')
    const row = (await screen.findByText('JV-2026-00001')).closest('tr')
    expect(within(row).getByText('Posted').closest('[data-tone]')).toHaveAttribute('data-tone', 'success')
    expect(within(row).getByText('October rent')).toBeInTheDocument()
    expect(row.textContent).toContain('KES')
    expect(row.textContent).toContain('15,000.00')
    expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^companies\/c-1\/accounting\/journals\?sort=-date/))
  })

  it('opens a journal with its lines in base currency and offers the reversal', async () => {
    setup()
    renderApp('/accounting/journals/j-1')
    const lines = await screen.findByRole('table', { name: 'Lines' })
    expect(within(lines).getByText('6200 · Rent')).toBeInTheDocument()
    expect(within(lines).getByText('Operations')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reverse' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Submit for approval' })).not.toBeInTheDocument()
  })

  it('does not offer the reversal of a journal that comes from a document (ACC-05)', async () => {
    setup()
    api.get.mockImplementation(((fallback) => async (path) => (path === 'accounting/journals/j-1'
      ? { data: { ...JOURNAL, kind: 'system', source: { type: 'accounting.document', id: 'd-1' }, reversible: false } }
      : fallback(path)))(api.get.getMockImplementation()))
    renderApp('/accounting/journals/j-1')
    await screen.findByRole('table', { name: 'Lines' })
    expect(screen.queryByRole('button', { name: 'Reverse' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Invoice or credit note' })).toHaveAttribute('href', '/accounting/documents/d-1')
  })

  it('totals the base-currency lines so the form shows whether the journal balances', () => {
    const totals = totalsOf([
      { currency: 'KES', debit: '1500000', credit: '' },
      { currency: 'KES', debit: '', credit: '1499999' },
      { currency: 'USD', debit: '100', credit: '' },
    ], 'KES')
    expect([totals.debit, totals.credit, totals.difference]).toEqual([1500000n, 1499999n, 1n])
  })

  it('drafts a manual journal without offering control accounts', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...JOURNAL, id: 'j-2', status: 'draft', number: null } })
    renderApp('/accounting/journals/new')
    expect(await screen.findByRole('heading', { name: 'New journal' })).toBeInTheDocument()
    expect(screen.getByText('Balanced')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled()
    fireEvent.click(screen.getAllByRole('combobox', { name: 'Account' })[0])
    expect(await screen.findByRole('option', { name: '6200 · Rent' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: '1200 · Trade receivables' })).not.toBeInTheDocument()
    await waitFor(() => expect(api.post).not.toHaveBeenCalled())
  })
})
