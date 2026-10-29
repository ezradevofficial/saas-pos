import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const row = (over) => ({ direction: 'output', tax_code_id: 'tc-1', code: 'VAT_STD', name: 'VAT standard', kind: 'vat', rate: '16', rate_needed: false, taxable_minor: 100000, taxable_complete: true, tax_minor: 16000, entries: 2, ...over })
const RETURN = {
  company: { id: 'c-1', name: 'Amani Retail', country: 'KE' },
  form: 'vat_return',
  from: '2026-10-01',
  to: '2026-10-31',
  currency: 'KES',
  rows: [row(), row({ tax_code_id: null, code: null, name: null, kind: null, rate: null, taxable_minor: null, taxable_complete: false, tax_minor: 1000, entries: 1 }), row({ direction: 'input', tax_minor: 4000, taxable_minor: 25000 })],
  totals: { output_minor: 17000, input_minor: 4000, net_payable_minor: 13000 },
  ledger: { output_minor: 17000, input_minor: 4000 },
  reconciled: true,
  fiscal: { documents: 2, accepted: 1, pending: 1 },
  notes: ['statutory_boxes_unmapped', 'no_tax_code'],
}
const ENTRIES = {
  data: [{ journal_id: 'j-1', journal_number: 'JV-1', date: '2026-10-10', document_id: 'd-1', document_kind: 'customer_invoice', document_number: 'INV-2026-0001', direction: 'output', taxable_minor: 100000, tax_minor: 16000, fiscal: { status: 'accepted' } }],
  meta: { total: 1, page: 1, per_page: 50, currency: 'KES' },
}

function setup(permissions = ['core.company.view', 'accounting.report.view'], report = RETURN) {
  mockRoutes(
    api,
    [
      [/^companies\/c-1\/accounting\/reports\/tax-return\/entries/, ENTRIES],
      [/^companies\/c-1\/accounting\/reports\/tax-return\?/, { data: report }],
    ],
    { permissions: tenantWide(permissions), modules: ['core', 'accounting'], companies: [COMPANY] },
  )
}

describe('Tax returns', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the VAT return summary with totals per code and rate and the missing-data notes (ACC-36)', async () => {
    setup()
    renderApp('/accounting/tax-returns')

    expect(await screen.findByRole('heading', { name: 'VAT return summary' })).toBeInTheDocument()
    expect(await screen.findByText('Matches the ledger')).toBeInTheDocument()
    expect(screen.getByText(/statutory form boxes are not mapped/i)).toBeInTheDocument()
    const output = screen.getByRole('table', { name: 'Output tax' })
    expect(within(output).getByText(/VAT_STD/)).toBeInTheDocument()
    expect(within(output).getByText('16 %')).toBeInTheDocument()
    expect(within(output).getByText('No tax code')).toBeInTheDocument()
    expect(screen.getByText('Net payable')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^companies\/c-1\/accounting\/reports\/tax-return\?from=\d{4}-\d{2}-\d{2}&to=\d{4}-\d{2}-\d{2}$/))
  })

  it('opens the documents behind a row (ACC-36)', async () => {
    setup()
    renderApp('/accounting/tax-returns')

    const output = await screen.findByRole('table', { name: 'Output tax' })
    fireEvent.click(within(output).getByText(/VAT_STD/))
    const link = await screen.findByRole('link', { name: 'Invoice INV-2026-0001' })
    expect(link).toHaveAttribute('href', '/accounting/documents/d-1')
    expect(screen.getByText('Accepted')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/tax-return\/entries\?.*direction=output.*tax_code=tc-1.*rate=16/))
  })

  it('exports the period as CSV through the API (ACC-36)', async () => {
    setup()
    api.download.mockResolvedValue({ blob: new Blob(['x']), filename: 'tax-return.csv' })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    renderApp('/accounting/tax-returns')

    await screen.findByText('Matches the ledger')
    fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }))
    await waitFor(() => expect(api.download).toHaveBeenCalledWith(expect.stringMatching(/^companies\/c-1\/accounting\/reports\/tax-return\/export\?from=/)))
  })

  it('says so when the period has no tax', async () => {
    setup(undefined, { ...RETURN, rows: [], totals: { output_minor: 0, input_minor: 0, net_payable_minor: 0 }, ledger: { output_minor: 0, input_minor: 0 }, fiscal: { documents: 0, accepted: 0, pending: 0 }, notes: ['statutory_boxes_unmapped'] })
    renderApp('/accounting/tax-returns')

    expect(await screen.findAllByText('No tax in this period.')).toHaveLength(2)
  })

  it('is closed without the report permission', async () => {
    setup(['core.company.view', 'accounting.journal.view'])
    renderApp('/accounting/tax-returns')

    await waitFor(() => expect(screen.queryByRole('heading', { name: 'VAT return summary' })).not.toBeInTheDocument())
    expect(api.get).not.toHaveBeenCalledWith(expect.stringMatching(/tax-return\?/))
  })

  it('shows the TVA declaration for the DR Congo', async () => {
    mockRoutes(api, [[/^companies\/c-1\/accounting\/reports\/tax-return\?/, { data: { ...RETURN, form: 'tva_declaration', company: { ...RETURN.company, country: 'CD' } } }]], {
      permissions: tenantWide(['core.company.view', 'accounting.report.view']),
      modules: ['core', 'accounting'],
      companies: [{ ...COMPANY, country: 'CD', base_currency: 'CDF' }],
    })
    renderApp('/accounting/tax-returns')

    expect(await screen.findByRole('heading', { name: 'TVA declaration summary' })).toBeInTheDocument()
  })
})
