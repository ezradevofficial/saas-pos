import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const money = (amount_minor, currency = 'KES') => ({ amount_minor, currency })
const ALL = ['core.company.view', 'accounting.account.view', 'accounting.account.manage', 'accounting.bank.view', 'accounting.bank.reconcile', 'accounting.report.view',
  'accounting.period.view', 'accounting.period.close', 'accounting.period.reopen', 'accounting.invoice.view', 'accounting.invoice.post', 'accounting.invoice.create']

function setup(routes, permissions = ALL, modules = ['core', 'accounting', 'accounting_pro']) {
  mockRoutes(api, [...routes, [/^config\/list_view/, { data: [] }]], { permissions: tenantWide(permissions), modules, companies: [COMPANY] })
}

const RECONCILIATION = {
  bank_account: { id: 'b-1', name: 'M-Pesa till', currency: 'KES' },
  summary: { statement_balance: money('111000'), ledger_balance: money('112500'), unmatched_statement: money('111000'), unmatched_ledger: money('112500') },
  statement_lines: [
    { id: 's-1', date: '2026-10-12', description: 'Customer Payment', reference: 'QJK1AB2CD3', amount: money('112500'), status: 'unmatched' },
    { id: 's-2', date: '2026-10-12', description: 'Pay Bill Charge', reference: 'QJK1AB2CD4', amount: money('-1500'), status: 'unmatched' },
  ],
  ledger_lines: [{ id: 'g-1', date: '2026-10-12', journal: { id: 'j-1', number: 'JV-2026-00007' }, reference: 'QJK1AB2CD3', description: 'Receipt Baraka', amount: money('112500') }],
  suggestions: { 's-1': ['g-1'] },
}

describe('Accounting screens', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('reconciles in two panes: picking a statement line selects its suggestion and matches it (ACC-14)', async () => {
    setup([['accounting/bank-accounts/b-1/reconciliation', { data: RECONCILIATION }], [/^companies\/c-1\/accounting\/accounts\?/, { data: [] }]])
    api.post.mockResolvedValue({ data: { id: 's-1', status: 'matched' } })
    renderApp('/accounting/bank/b-1')

    const statement = await screen.findByRole('list', { name: 'Statement lines' })
    const ledger = screen.getByRole('list', { name: 'Ledger lines' })
    expect(within(statement).getAllByRole('button')).toHaveLength(2)
    fireEvent.click(within(statement).getByRole('button', { name: /Customer Payment/ }))
    expect(within(ledger).getByRole('button', { name: /Receipt Baraka/ })).toHaveAttribute('aria-pressed', 'true')
    expect(within(ledger).getByText('Suggested')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Match' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('accounting/bank-statement-lines/s-1/match', { journal_line_ids: ['g-1'] }))
  })

  it('does not match ledger lines that do not add up to the statement line', async () => {
    setup([['accounting/bank-accounts/b-1/reconciliation', { data: RECONCILIATION }], [/^companies\/c-1\/accounting\/accounts\?/, { data: [] }]])
    renderApp('/accounting/bank/b-1')
    const statement = await screen.findByRole('list', { name: 'Statement lines' })
    fireEvent.click(within(statement).getByRole('button', { name: /Pay Bill Charge/ }))
    fireEvent.click(within(screen.getByRole('list', { name: 'Ledger lines' })).getByRole('button', { name: /Receipt Baraka/ }))
    expect(screen.getByRole('button', { name: 'Match' })).toBeDisabled()
  })

  it('shows the trial balance and drills down from an account to its ledger and the journal (ACC-17)', async () => {
    const tb = {
      currency: 'KES', from: '2026-01-01', to: '2026-10-31', balanced: true, totals: { opening: 0, debit: 112500, credit: 112500, closing: 0 },
      rows: [
        { account: { id: 'a-1200', code: '1200', name: 'Trade receivables', type: 'asset' }, opening: 0, debit: 112500, credit: 0, closing: 112500 },
        { account: { id: 'a-4100', code: '4100', name: 'Sales', type: 'income' }, opening: 0, debit: 0, credit: 112500, closing: -112500 },
      ],
    }
    const gl = {
      currency: 'KES', from: '2026-01-01', to: '2026-10-31', opening: 0, closing: -112500, truncated: false,
      account: { id: 'a-4100', code: '4100', name: 'Sales', type: 'income' },
      lines: [{ id: 'l-1', journal: { id: 'j-9', number: 'JV-2026-00009', kind: 'system', source_type: 'accounting.document', source_id: 'd-1', source_number: 'INV-2026-00001' }, date: '2026-10-10', reference: 'INV-2026-00001',
        description: 'Goods', party: { id: 'p-1', name: 'Baraka Traders' }, currency: 'KES', debit: 0, credit: 112500, base_debit: 0, base_credit: 112500, balance: -112500 }],
    }
    setup([
      [/^companies\/c-1\/accounting\/reports\/trial-balance/, { data: tb }],
      [/^companies\/c-1\/accounting\/reports\/general-ledger\?account=a-4100/, { data: gl }],
    ])
    const { router } = renderApp('/accounting/reports')

    expect(await screen.findByText('Balanced')).toBeInTheDocument()
    fireEvent.click(screen.getByText('Sales').closest('tr'))
    await waitFor(() => expect(router.state.location.pathname).toBe('/accounting/reports/ledger'))
    expect(await screen.findByRole('link', { name: 'JV-2026-00009' })).toHaveAttribute('href', '/accounting/journals/j-9')
    // The source is named by its document number.
    expect(screen.getByRole('link', { name: 'INV-2026-00001' })).toHaveAttribute('href', '/accounting/documents/d-1')
    expect(screen.getByText('Goods · Baraka Traders')).toBeInTheDocument()
  })

  it('closes a period with revaluation, and only the latest closed period offers reopening (ACC-08)', async () => {
    const period = (number, status) => ({ id: `p-${number}`, company_id: 'c-1', fiscal_year: 2026, number, starts_on: `2026-${String(number).padStart(2, '0')}-01`, ends_on: `2026-${String(number).padStart(2, '0')}-28`, status, closed_by: status === 'closed' ? { id: 'u-1', name: 'Amina Otieno' } : null, reopened_by: null, revalued: false })
    setup([[/^companies\/c-1\/accounting\/periods/, { data: [period(1, 'closed'), period(2, 'closed'), period(3, 'open')], meta: { fiscal_year: 2026 } }]])
    api.post.mockResolvedValue({ data: period(3, 'closed') })
    renderApp('/accounting/periods')

    const table = await screen.findByRole('table', { name: 'Periods' })
    expect(await within(table).findAllByRole('button', { name: 'Reopen' })).toHaveLength(1)
    fireEvent.click(within(table).getByRole('button', { name: 'Close period' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('checkbox', { name: /Revalue foreign currency items first/ }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Close period' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('accounting/periods/p-3/close', { revalue: true }))
  })

  it('shows consolidation and revalue-first only with Accounting Pro (ACC-15, ACC-18)', async () => {
    const period = { id: 'p-3', company_id: 'c-1', fiscal_year: 2026, number: 3, starts_on: '2026-03-01', ends_on: '2026-03-31', status: 'open', closed_by: null, reopened_by: null, revalued: false }
    const tb = { currency: 'KES', from: '2026-01-01', to: '2026-10-31', balanced: true, totals: { opening: 0, debit: 0, credit: 0, closing: 0 }, rows: [] }
    const routes = [
      [/^companies\/c-1\/accounting\/periods/, { data: [period], meta: { fiscal_year: 2026 } }],
      [/^companies\/c-1\/accounting\/reports\/trial-balance/, { data: tb }],
    ]
    setup(routes, [...ALL, 'accounting.report.consolidate'], ['core', 'accounting'])
    api.post.mockResolvedValue({ data: { ...period, status: 'closed' } })

    const reports = renderApp('/accounting/reports?report=consolidation')
    expect(await screen.findByRole('tab', { name: 'Trial balance' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Group' })).not.toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith(expect.stringMatching(/^accounting\/consolidation/))
    reports.unmount()

    renderApp('/accounting/periods')
    fireEvent.click(await within(await screen.findByRole('table', { name: 'Periods' })).findByRole('button', { name: 'Close period' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).queryByRole('checkbox', { name: /Revalue foreign currency items first/ })).not.toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Close period' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('accounting/periods/p-3/close', { revalue: false }))
  })

  it('offers the consolidation tab with Accounting Pro and the permission (ACC-18)', async () => {
    setup([[/^companies\/c-1\/accounting\/reports\/trial-balance/, { data: { currency: 'KES', from: '2026-01-01', to: '2026-10-31', balanced: true, totals: { opening: 0, debit: 0, credit: 0, closing: 0 }, rows: [] } }]], [...ALL, 'accounting.report.consolidate'])
    renderApp('/accounting/reports')
    expect(await screen.findByRole('tab', { name: 'Group' })).toBeInTheDocument()
  })

  it('offers the country template when the company has no chart yet (ACC-01)', async () => {
    setup([
      [/^companies\/c-1\/accounting\/accounts\?/, { data: [], meta: { total: 0 } }],
      ['accounting/chart-templates', { data: [{ code: 'KE', name: 'Kenya (IFRS for SMEs)', accounts: 56 }, { code: 'CD', name: 'DR Congo (OHADA SYSCOHADA)', accounts: 74 }] }],
    ])
    api.post.mockResolvedValue({ data: { has_chart: true } })
    renderApp('/accounting/chart')
    fireEvent.click(await screen.findByRole('button', { name: 'Create the chart' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('companies/c-1/accounting/chart', {}))
  })

  it('keeps Post off while a tax rate is needed on a draft invoice (ACC-09)', async () => {
    const draft = {
      id: 'd-1', company_id: 'c-1', kind: 'customer_invoice', number: null, status: 'draft', party: { id: 'p-1', name: 'Baraka Traders' }, issue_date: '2026-10-10', due_date: '2026-11-09',
      currency: 'KES', subtotal: money('10000'), tax: money('0'), total: money('10000'), open: money('0'), base_total: null, journal_id: null, rate_needed: true, allocations: [], fiscal: null,
      lines: [{ id: 'dl-1', description: 'Goods', account: { id: 'a', code: '4100', name: 'Sales' }, qty: '1', unit_price: money('10000'), tax_code: { id: 't', code: 'VAT_STD', name: 'VAT' }, tax_rate: null, rate_needed: true, net: money('10000'), tax: money('0'), total: money('10000') }],
    }
    setup([['accounting/documents/d-1', { data: draft }]])
    renderApp('/accounting/documents/d-1')
    expect(await screen.findByText('A tax rate is needed')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Post' })).toBeDisabled()
    // L10N-03: calendar dates show in the UI language, not as ISO strings.
    expect(screen.getByText('10 Oct 2026')).toBeInTheDocument()
    expect(screen.getByText('9 Nov 2026')).toBeInTheDocument()
    expect(screen.queryByText('2026-10-10')).not.toBeInTheDocument()
    expect(within(screen.getByRole('table', { name: 'Lines' })).getByText('Rate needed').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
  })

  it('names a new document by the chosen kind (ACC-09)', async () => {
    setup([
      [/^parties\?/, { data: [] }],
      [/^companies\/c-1\/tax-codes/, { data: [] }],
      [/^companies\/c-1\/accounting\/documents\?/, { data: [] }],
      [/^companies\/c-1\/accounting\/accounts/, { data: [], meta: { total: 0 } }],
    ])
    renderApp('/accounting/documents/new?side=customer')
    expect(await screen.findByRole('heading', { name: 'New invoice' })).toBeInTheDocument()
    chooseOption(screen.getByLabelText('Kind'), 'Credit note')
    expect(await screen.findByRole('heading', { name: 'New credit note' })).toBeInTheDocument()
  })

  it('shows no empty untitled table in the cash flow (ACC-17)', async () => {
    const row = { account: { id: 'a-1200', code: '1200', name: 'Trade receivables', type: 'asset' }, amount: -112500 }
    const cashFlow = {
      currency: 'KES', from: '2026-01-01', to: '2026-10-31', net_profit: 112500, operating: 0, net_change: 0, opening_cash: 0, closing_cash: 0, reconciles: true,
      non_cash: { rows: [] }, working_capital: { rows: [row] }, investing: { rows: [], total: 0 }, financing: { rows: [], total: 0 },
    }
    setup([[/^companies\/c-1\/accounting\/reports\/cash-flow/, { data: cashFlow }]])
    renderApp('/accounting/reports?report=cash-flow')
    expect(await screen.findByText('Trade receivables')).toBeInTheDocument()
    // Only the working capital part has lines: one table, under its heading.
    expect(screen.getAllByRole('table')).toHaveLength(1)
    expect(screen.getByRole('heading', { name: 'Working capital' })).toBeInTheDocument()
  })
})
