// PRC-17, PRC-18, PRC-01: landed costs (form, allocation table), supplier performance and the company tolerances.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, closeCombobox, openCombobox, waitForOption } from '@/test/combobox'
import { BRANCH, mockProcurement, money, named, page } from '@/test/procurement'
import { renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const RECEIPT = { id: 'grn-1', number: 'GRN-WL-2026-00001', status: 'posted', party: named('p-1', 'Mombasa Millers'), order: { id: 'po-1', number: 'PO-WL-2026-00001' }, company: named('c-1', 'Amani Retail') }

const LANDED = {
  id: 'lc-1',
  number: null,
  status: 'draft',
  method: 'value',
  description: 'Container MSKU-1',
  cost_date: '2026-10-12',
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  base_total: null,
  created_at: '2026-10-12T06:00:00Z',
  charges: [{ id: 'ch-1', description: 'Freight', party: null, amount: money('1000', 'USD'), fx: null, base_amount: null }],
  receipts: [{ id: 'grn-1', number: 'GRN-WL-2026-00001', status: 'posted' }],
  allocation: {
    base_currency: 'KES',
    total: money('13000000'),
    error: null,
    lines: [
      { receipt_line_id: 'rl-1', receipt: { id: 'grn-1', number: 'GRN-WL-2026-00001' }, item: { id: 'i-1', code: 'FLOUR', name: 'Maize flour 2 kg', type: 'stock' }, accepted_qty: '29', basis: '696000', amount: money('8666667') },
      { receipt_line_id: 'rl-2', receipt: { id: 'grn-1', number: 'GRN-WL-2026-00001' }, item: { id: 'i-2', code: 'SUGAR', name: 'Sugar 1 kg', type: 'stock' }, accepted_qty: '20', basis: '348000', amount: money('4333333') },
    ],
  },
  can: { edit: true, post: true, cancel: true },
}

describe('Landed costs (PRC-17)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('saves charges in their currency (minor units) and the goods received they apply to', async () => {
    mockProcurement(api, [[/^procurement\/receipts\?company=c-1/, page([RECEIPT, { ...RECEIPT, id: 'grn-9', number: null, status: 'cancelled' }])]])
    api.post.mockResolvedValue({ data: LANDED })
    const { router } = renderApp('/procurement/landed-costs/new')
    await waitForOption(await screen.findByLabelText(/^Branch/), BRANCH.name)
    chooseOption(screen.getByLabelText(/^Branch/), BRANCH.name)
    fireEvent.change(screen.getByLabelText(/^Date/), { target: { value: '2026-10-12' } })
    chooseOption(screen.getByLabelText('Allocation method'), 'By value')
    fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Container MSKU-1' } })
    const receipts = openCombobox(screen.getByLabelText(/^Goods received it applies to/))
    const options = await within(receipts).findAllByRole('option')
    // Cancelled GRNs are not offered.
    expect(options).toHaveLength(1)
    fireEvent.click(within(receipts).getByRole('option', { name: /GRN-WL-2026-00001/ }))
    closeCombobox()
    fireEvent.change(screen.getByLabelText(/^Charge$|^Charge \*/), { target: { value: 'Freight' } })
    chooseOption(screen.getByLabelText('Currency'), 'USD')
    fireEvent.change(screen.getByLabelText(/^Amount/), { target: { value: '1000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save landed cost' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('procurement/landed-costs', {
        branch_id: 'br-1',
        cost_date: '2026-10-12',
        method: 'value',
        description: 'Container MSKU-1',
        charges: [{ description: 'Freight', currency: 'USD', amount_minor: '100000' }],
        receipt_ids: ['grn-1'],
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/procurement/landed-costs/lc-1'))
  })

  it('shows the allocation per received line and posts the draft', async () => {
    mockProcurement(api, [['procurement/landed-costs/lc-1', { data: LANDED }]])
    api.post.mockResolvedValue({ data: { ...LANDED, number: 'LC-WL-2026-00001', status: 'posted', can: {} } })
    renderApp('/procurement/landed-costs/lc-1')
    const table = await screen.findByRole('table', { name: 'Allocation' })
    const flour = within(table).getByText('Maize flour 2 kg').closest('tr')
    expect(within(flour).getByText('86,666.67')).toBeInTheDocument()
    // By value, the basis is the goods' base value as money, not a count of minor units.
    expect(within(flour).getByText('6,960.00')).toBeInTheDocument()
    expect(within(flour).queryByText('696,000')).not.toBeInTheDocument()
    expect(within(within(table).getByText('Sugar 1 kg').closest('tr')).getByText('43,333.33')).toBeInTheDocument()
    expect(screen.getByText('What posting will allocate to each received line, in base currency.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Post landed cost' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('procurement/landed-costs/lc-1/post', {}))
  })

  it('explains why a landed cost cannot be split and holds the posting', async () => {
    const blocked = { ...LANDED, method: 'weight', allocation: { base_currency: 'KES', total: null, error: { code: 'weight_missing', message: 'A received line has no weight.' }, lines: [] } }
    mockProcurement(api, [['procurement/landed-costs/lc-1', { data: blocked }]])
    renderApp('/procurement/landed-costs/lc-1')
    expect(await screen.findByText('This landed cost cannot be split yet')).toBeInTheDocument()
    expect(screen.getByText('A received line has no weight.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Post landed cost' })).toBeDisabled()
  })
})

describe('Supplier performance (PRC-18) and settings (PRC-01)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows each supplier’s on-time share, quality, lead time and price variance, and exports CSV', async () => {
    const row = {
      company: named('c-1', 'Amani Retail'),
      party: named('p-1', 'Mombasa Millers'),
      orders: 4,
      receipts: 5,
      lines: 6,
      on_time_bp: 8333,
      quality_bp: 9600,
      lead_time_days: '3.5',
      price_variance: money('18000'),
      price_variance_bp: 125,
      ordered_value: money('1440000'),
    }
    mockProcurement(api, [['procurement/supplier-performance', { data: [row], meta: { from: '2026-07-17', to: '2026-10-14' } }]])
    api.download.mockResolvedValue({ blob: new Blob(['a,b']), filename: 'supplier-performance.csv' })
    window.URL.createObjectURL = vi.fn(() => 'blob:x')
    window.URL.revokeObjectURL = vi.fn()
    renderApp('/procurement/supplier-performance')
    const table = await screen.findByRole('table', { name: 'Supplier performance' })
    const line = (await within(table).findByText('Mombasa Millers')).closest('tr')
    expect(within(line).getByText('83.33 %')).toBeInTheDocument()
    expect(within(line).getByText('96.00 %')).toBeInTheDocument()
    expect(within(line).getByText('3.5 days')).toBeInTheDocument()
    expect(within(line).getByText('1.25 %')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }))
    await waitFor(() => expect(api.download).toHaveBeenCalledWith('procurement/supplier-performance?format=csv'))
  })

  it('saves a company’s tolerances', async () => {
    const settings = { company: named('c-1', 'Amani Retail'), price_tolerance_percent: '0', qty_tolerance_percent: '0', over_receipt_percent: '0' }
    mockProcurement(api, [['procurement/companies/c-1/settings', { data: settings }]])
    api.put.mockResolvedValue({ data: { ...settings, price_tolerance_percent: '2.5' } })
    renderApp('/procurement/settings')
    fireEvent.change(await screen.findByLabelText('Price tolerance'), { target: { value: '2.5' } })
    fireEvent.change(screen.getByLabelText('Over-receipt allowance'), { target: { value: '5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('procurement/companies/c-1/settings', { price_tolerance_percent: '2.5', qty_tolerance_percent: '0', over_receipt_percent: '5' }))
    expect(await screen.findByText('Procurement settings saved.')).toBeInTheDocument()
  })
})
