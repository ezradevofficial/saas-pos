// PRC-15: supplier invoices (form from a PO, the three-way match view, approval with an override).
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { ALL, mockProcurement, money, named, page } from '@/test/procurement'
import { renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const PCS = { id: 'u-pcs', code: 'pcs', name: 'Pieces' }
const ORDER = {
  id: 'po-1',
  number: 'PO-WL-2026-00001',
  status: 'received',
  party: named('p-1', 'Mombasa Millers'),
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  location: named('l-1', 'Main store'),
  currency: 'KES',
  tax_inclusive: false,
  lines: [
    {
      id: 'ol-1',
      line_no: 1,
      item: { id: 'i-1', code: 'FLOUR', name: 'Maize flour 2 kg', type: 'stock' },
      description: null,
      uom: PCS,
      qty: '50',
      unit_price: money('24000'),
      tax_code: { id: 'tx-1', code: 'VAT16', name: 'VAT 16 %' },
      received_qty: '50',
      rejected_qty: '2',
      invoiced_qty: '10',
    },
  ],
  can: { invoice: true, receive: false },
}

const matchLine = (extra) => ({
  id: 'il-1',
  line_no: 1,
  order_line_id: 'ol-1',
  description: 'Maize flour 2 kg',
  ordered_qty: '50',
  ordered_unit_price: money('24000'),
  received_qty: '50',
  accepted_qty: '48',
  invoiced_before_qty: '10',
  qty: '38',
  unit_price: money('24000'),
  tax_code: null,
  tax_rate: '16',
  net: money('912000'),
  tax: money('145920'),
  total: money('1057920'),
  match_status: 'matched',
  price_variance: money('0'),
  qty_variance: '0',
  ...extra,
})

const INVOICE = {
  id: 'inv-1',
  number: 'PINV-WL-2026-00001',
  supplier_reference: 'MM-INV-889',
  status: 'exception',
  order: { id: 'po-1', number: 'PO-WL-2026-00001' },
  party: named('p-1', 'Mombasa Millers'),
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  invoice_date: '2026-10-13',
  due_date: '2026-11-12',
  currency: 'KES',
  subtotal: money('1912000'),
  tax: money('305920'),
  total: money('2217920'),
  created_at: '2026-10-13T06:00:00Z',
  lines: [
    matchLine({}),
    matchLine({ id: 'il-2', line_no: 2, description: 'Sugar 1 kg', qty: '12', received_qty: '8', accepted_qty: '8', invoiced_before_qty: '0', unit_price: money('16500'), ordered_unit_price: money('15000'), match_status: 'price_variance', price_variance: money('18000'), qty_variance: '4' }),
    matchLine({ id: 'il-3', line_no: 3, description: 'Salt 500 g', received_qty: '0', accepted_qty: '0', invoiced_before_qty: '0', qty: '5', match_status: 'not_received', qty_variance: '5' }),
  ],
  can: { edit: true, approve: true, override: true, cancel: true },
}

describe('Supplier invoices (PRC-15)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists invoices with the supplier’s reference and the match result', async () => {
    mockProcurement(api, [[/^procurement\/invoices\?/, page([INVOICE])]])
    renderApp('/procurement/invoices')
    const row = (await screen.findByText('PINV-WL-2026-00001')).closest('tr')
    expect(within(row).getByText('MM-INV-889')).toBeInTheDocument()
    expect(within(row).getByText('Exception').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')
  })

  it('enters an invoice from a PO: what was accepted and not yet invoiced, at the PO’s price', async () => {
    mockProcurement(api, [['procurement/orders/po-1', { data: ORDER }]])
    api.post.mockResolvedValue({ data: { ...INVOICE, status: 'matched' } })
    const { router } = renderApp('/procurement/invoices/new?order=po-1')
    // 50 received, 2 rejected, 10 invoiced before: 38 to invoice.
    expect(await screen.findByLabelText('Quantity invoiced, Maize flour 2 kg')).toHaveValue('38')
    expect(screen.getByLabelText('Unit price, Maize flour 2 kg')).toHaveValue('240.00')
    fireEvent.change(screen.getByLabelText(/^Supplier invoice number/), { target: { value: 'MM-INV-889' } })
    fireEvent.change(screen.getByLabelText(/^Invoice date/), { target: { value: '2026-10-13' } })
    fireEvent.change(screen.getByLabelText('Due date'), { target: { value: '2026-11-12' } })
    fireEvent.change(screen.getByLabelText('Unit price, Maize flour 2 kg'), { target: { value: '245' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save and match' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('procurement/invoices', {
        order_id: 'po-1',
        supplier_reference: 'MM-INV-889',
        invoice_date: '2026-10-13',
        due_date: '2026-11-12',
        tax_inclusive: false,
        lines: [{ order_line_id: 'ol-1', qty: '38', unit_price_minor: '24500', tax_code_id: 'tx-1' }],
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/procurement/invoices/inv-1'))
  })

  it('shows the three-way match per line with its result, and approves an exception only with a reason', async () => {
    mockProcurement(api, [['procurement/invoices/inv-1', { data: INVOICE }]])
    api.post.mockResolvedValue({ data: { ...INVOICE, status: 'approved', override_reason: 'Price rise agreed by phone', can: {} } })
    renderApp('/procurement/invoices/inv-1')
    const match = await screen.findByRole('table', { name: 'Three-way match' })
    const flour = within(match).getByText('Maize flour 2 kg').closest('tr')
    expect(within(flour).getByText('Matched').closest('[data-tone]')).toHaveAttribute('data-tone', 'success')
    const sugar = within(match).getByText('Sugar 1 kg').closest('tr')
    expect(within(sugar).getByText('Price variance').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(sugar).getByText('180.00')).toBeInTheDocument()
    expect(within(sugar).getByText('165.00')).toBeInTheDocument()
    const salt = within(match).getByText('Salt 500 g').closest('tr')
    expect(within(salt).getByText('Not received').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')

    const approve = screen.getByRole('button', { name: 'Approve with exception' })
    expect(approve).toBeDisabled()
    fireEvent.change(screen.getByLabelText(/^Reason for approving/), { target: { value: 'Price rise agreed by phone' } })
    fireEvent.click(approve)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('procurement/invoices/inv-1/approve', { override_reason: 'Price rise agreed by phone' }))
    expect(await screen.findByText('Invoice approved.')).toBeInTheDocument()
  })

  it('offers no approval of an exception to a reader without the override', async () => {
    const withoutOverride = ALL.filter((permission) => permission.name !== 'procurement.invoice.override')
    mockProcurement(api, [['procurement/invoices/inv-1', { data: { ...INVOICE, can: { edit: true, approve: false, override: false, cancel: true } } }]], { permissions: withoutOverride })
    renderApp('/procurement/invoices/inv-1')
    expect(await screen.findByRole('table', { name: 'Three-way match' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Approve/ })).not.toBeInTheDocument()
    expect(screen.getByText(/Someone allowed to approve exceptions must approve it/)).toBeInTheDocument()
  })
})
