// PRC-03 to PRC-05: RFQs (list, quote entry, the comparison grid and the award).
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { mockProcurement, money, named, page } from '@/test/procurement'
import { renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const FLOUR = { id: 'i-1', code: 'FLOUR', name: 'Maize flour 2 kg', type: 'stock' }
const SUGAR = { id: 'i-2', code: 'SUGAR', name: 'Sugar 1 kg', type: 'stock' }
const PCS = { id: 'u-pcs', code: 'pcs', name: 'Pieces' }
const quote = (id, party, lines) => ({ id, party, reference: null, quote_date: '2026-10-11', valid_until: null, status: 'received', currency: 'KES', total: money('100000'), base_total: money('100000'), lines })

const RFQ = {
  id: 'rfq-1',
  number: 'RFQ-WL-2026-00001',
  status: 'issued',
  title: 'Flour and sugar',
  note: null,
  deadline_at: '2026-10-20T12:00:00Z',
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  requisition: null,
  suppliers_count: 2,
  quotes_count: 2,
  created_at: '2026-10-10T06:00:00Z',
  lines: [
    { id: 'l-1', line_no: 1, item: FLOUR, description: null, uom: PCS, qty: '50' },
    { id: 'l-2', line_no: 2, item: SUGAR, description: null, uom: PCS, qty: '20' },
  ],
  suppliers: [named('p-1', 'Mombasa Millers'), named('p-2', 'Kisumu Traders')],
  quotes: [quote('q-1', named('p-1', 'Mombasa Millers'), []), quote('q-2', named('p-2', 'Kisumu Traders'), [])],
  can: { edit: false, issue: false, quote: true, award: true, cancel: true },
}

const offer = (quoteId, quoteLineId, party, base, lowest) => ({ quote_id: quoteId, quote_line_id: quoteLineId, party_id: party, unit_price: money(base), base_unit_price: money(base), lead_time_days: 3, lowest, selected: false })

const COMPARISON = {
  base_currency: 'KES',
  lines: [
    { rfq_line_id: 'l-1', description: null, item: FLOUR, uom: PCS, qty: '50', offers: [offer('q-1', 'ql-1', 'p-1', '24000', true), offer('q-2', 'ql-3', 'p-2', '26000', false)] },
    { rfq_line_id: 'l-2', description: null, item: SUGAR, uom: PCS, qty: '20', offers: [offer('q-1', 'ql-2', 'p-1', '16000', false), offer('q-2', 'ql-4', 'p-2', '15000', true)] },
  ],
  suppliers: [
    { quote_id: 'q-1', party: named('p-1', 'Mombasa Millers'), currency: 'KES', total: money('1520000'), base_total: money('1520000'), lines_quoted: 2, status: 'received', valid_until: null },
    { quote_id: 'q-2', party: named('p-2', 'Kisumu Traders'), currency: 'KES', total: money('1600000'), base_total: money('1600000'), lines_quoted: 2, status: 'received', valid_until: null },
  ],
}

function setup() {
  mockProcurement(api, [
    ['procurement/rfqs/rfq-1', { data: RFQ }],
    ['procurement/rfqs/rfq-1/comparison', { data: COMPARISON }],
    [/^procurement\/rfqs\?/, page([RFQ])],
  ])
}

describe('RFQs (PRC-03 to PRC-05)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists RFQs with title, branch and the quotes typed in', async () => {
    setup()
    renderApp('/procurement/rfqs')
    const row = (await screen.findByText('RFQ-WL-2026-00001')).closest('tr')
    expect(within(row).getByText('Flour and sugar')).toBeInTheDocument()
    expect(within(row).getByText('Issued')).toBeInTheDocument()
    expect(within(row).getByText('2')).toBeInTheDocument()
  })

  it('enters a supplier quote with a price per line in minor units; a line left empty is no offer', async () => {
    setup()
    api.post.mockResolvedValue({ data: quote('q-3', named('p-1', 'Mombasa Millers'), []) })
    renderApp('/procurement/rfqs/rfq-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Enter quote' }))
    const dialog = await screen.findByRole('dialog', { name: 'Enter quote' })
    chooseOption(within(dialog).getByLabelText(/^Supplier/), 'Mombasa Millers')
    fireEvent.change(within(dialog).getByLabelText('Reference'), { target: { value: 'MM-778' } })
    fireEvent.change(within(dialog).getByLabelText(/^Quote date/), { target: { value: '2026-10-11' } })
    fireEvent.change(within(dialog).getByLabelText('Unit price, line 1'), { target: { value: '240.50' } })
    fireEvent.change(within(dialog).getByLabelText('Lead time, line 1'), { target: { value: '3' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save quote' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('procurement/quotes', {
        rfq_id: 'rfq-1',
        party_id: 'p-1',
        reference: 'MM-778',
        quote_date: '2026-10-11',
        valid_until: null,
        currency: 'KES',
        lines: [{ rfq_line_id: 'l-1', unit_price_minor: '24050', lead_time_days: 3 }],
      }),
    )
    expect(await screen.findByText('Quote saved.')).toBeInTheDocument()
  })

  it('compares quotes in base currency, marks the lowest, and awards the lowest per line by default', async () => {
    setup()
    api.post.mockResolvedValue({ data: [{ id: 'po-1', number: null, party: named('p-1', 'Mombasa Millers') }, { id: 'po-2', number: null, party: named('p-2', 'Kisumu Traders') }] })
    renderApp('/procurement/rfqs/rfq-1')
    const grid = await screen.findByRole('table', { name: 'Quote comparison' })
    const flour = within(grid).getByText('Maize flour 2 kg').closest('tr')
    expect(within(flour).getAllByText('Lowest')).toHaveLength(1)
    expect(within(flour).getByLabelText('Choose Mombasa Millers for Maize flour 2 kg')).toBeChecked()
    // The buyer prefers Mombasa Millers for sugar too.
    fireEvent.click(within(grid).getByLabelText('Choose Mombasa Millers for Sugar 1 kg'))
    fireEvent.click(screen.getByRole('button', { name: 'Award and create purchase orders' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('procurement/rfqs/rfq-1/award', { selections: { 'l-1': 'ql-1', 'l-2': 'ql-2' } }))
    expect(await screen.findByText('2 draft purchase orders were made.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Mombasa Millers: Draft' })).toHaveAttribute('href', '/procurement/orders/po-1')
  })
})
