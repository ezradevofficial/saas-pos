// PRC-13, PRC-14: purchase orders (form, detail, send, PDF) and goods received from a PO.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { BRANCH, mockProcurement, money, named, page } from '@/test/procurement'
import { renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const PCS = { id: 'u-pcs', code: 'pcs', name: 'Pieces' }
const FLOUR = { id: 'i-1', code: 'FLOUR', name: 'Maize flour 2 kg', type: 'stock' }

const line = (extra = {}) => ({
  id: 'ol-1',
  line_no: 1,
  item: FLOUR,
  description: null,
  uom: PCS,
  qty: '50',
  unit_price: money('24000'),
  tax_code: { id: 'tx-1', code: 'VAT16', name: 'VAT 16 %' },
  tax_rate: '16',
  rate_needed: false,
  net: money('1200000'),
  tax: money('192000'),
  total: money('1392000'),
  received_qty: '20',
  rejected_qty: '2',
  invoiced_qty: '0',
  cost_centre: null,
  project: null,
  requisition_line_id: null,
  ...extra,
})

const ORDER = {
  id: 'po-1',
  number: 'PO-WL-2026-00001',
  status: 'partially_received',
  party: named('p-1', 'Mombasa Millers'),
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  location: named('l-1', 'Main store'),
  currency: 'KES',
  fx: null,
  tax_inclusive: false,
  order_date: '2026-10-10',
  expected_on: '2026-10-15',
  terms: null,
  subtotal: money('1200000'),
  tax: money('192000'),
  total: money('1392000'),
  base_total: money('1392000'),
  requested_by: named('u-2', 'Joseph Mwangi'),
  requisition_id: null,
  created_at: '2026-10-10T06:00:00Z',
  lines: [line()],
  can: { edit: false, submit: false, cancel: true, send: true, print: true, receive: true, invoice: true },
}

describe('Purchase orders (PRC-13)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists purchase orders with supplier and total', async () => {
    mockProcurement(api, [[/^procurement\/orders\?/, page([ORDER])]])
    renderApp('/procurement/orders')
    const row = (await screen.findByText('PO-WL-2026-00001')).closest('tr')
    expect(within(row).getByText('Mombasa Millers')).toBeInTheDocument()
    expect(within(row).getByText('Partly received').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(row).getByText('13,920.00')).toBeInTheDocument()
  })

  it('saves a PO with supplier, tax code and the unit price in minor units', async () => {
    mockProcurement(api)
    api.post.mockResolvedValue({ data: { ...ORDER, status: 'draft' } })
    renderApp('/procurement/orders/new')
    await waitForOption(await screen.findByLabelText(/^Branch/), BRANCH.name)
    chooseOption(screen.getByLabelText(/^Branch/), BRANCH.name)
    await waitForOption(screen.getByLabelText(/^Supplier/), 'Mombasa Millers')
    chooseOption(screen.getByLabelText(/^Supplier/), 'Mombasa Millers')
    chooseOption(screen.getByLabelText('Delivery location'), 'Main store')
    fireEvent.change(screen.getByLabelText('Order date'), { target: { value: '2026-10-10' } })
    fireEvent.change(screen.getByLabelText('Expected date'), { target: { value: '2026-10-15' } })
    await waitForOption(screen.getByLabelText('Item'), 'Maize flour 2 kg · FLOUR')
    chooseOption(screen.getByLabelText('Item'), 'Maize flour 2 kg · FLOUR')
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '50' } })
    fireEvent.change(screen.getByLabelText(/^Unit price/), { target: { value: '240' } })
    await waitForOption(screen.getByLabelText('Tax code'), 'VAT 16 %')
    chooseOption(screen.getByLabelText('Tax code'), 'VAT 16 %')
    fireEvent.click(screen.getByRole('button', { name: 'Save purchase order' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('procurement/orders', {
        branch_id: 'br-1',
        party_id: 'p-1',
        location_id: 'l-1',
        currency: 'KES',
        tax_inclusive: false,
        order_date: '2026-10-10',
        expected_on: '2026-10-15',
        terms: null,
        lines: [{ item_id: 'i-1', uom_id: 'u-pcs', qty: '50', unit_price_minor: '24000', tax_code_id: 'tx-1' }],
      }),
    )
  })

  it('shows "Rate needed" on a line whose tax code has no rate and holds the submission', async () => {
    const draft = { ...ORDER, number: null, status: 'draft', lines: [line({ tax_rate: null, rate_needed: true, received_qty: '0', rejected_qty: '0' })], can: { edit: true, submit: true, cancel: true, print: true } }
    mockProcurement(api, [['procurement/orders/po-1', { data: draft }]])
    renderApp('/procurement/orders/po-1')
    const lines = await screen.findByRole('table', { name: 'Lines' })
    expect(within(lines).getByText('Rate needed')).toBeInTheDocument()
    expect(screen.getByText('A tax rate is missing')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Submit for approval' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Receive goods' })).not.toBeInTheDocument()
  })

  it('shows received, rejected and invoiced quantities, sends the PO and opens the goods received form', async () => {
    mockProcurement(api, [['procurement/orders/po-1', { data: ORDER }]])
    api.post.mockResolvedValue({ data: { ...ORDER, sent_at: '2026-10-11T08:00:00Z' } })
    const { router } = renderApp('/procurement/orders/po-1')
    const lines = await screen.findByRole('table', { name: 'Lines' })
    const row = within(lines).getByText('Maize flour 2 kg').closest('tr')
    expect(within(row).getByText('20')).toBeInTheDocument()
    expect(within(row).getByText('2')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('document-workflows/procurement.po/po-1')

    fireEvent.click(screen.getByRole('button', { name: 'Send to supplier' }))
    const dialog = await screen.findByRole('dialog', { name: 'Send PO-WL-2026-00001' })
    fireEvent.change(within(dialog).getByLabelText(/^Email/), { target: { value: 'orders@millers.example' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('procurement/orders/po-1/send', { email: 'orders@millers.example' }))

    fireEvent.click(screen.getByRole('button', { name: 'Receive goods' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/procurement/receipts/new'))
    expect(router.state.location.search).toBe('?order=po-1')
  })

  it('downloads the PO as a PDF', async () => {
    mockProcurement(api, [['procurement/orders/po-1', { data: ORDER }]])
    api.download.mockResolvedValue({ blob: new Blob(['%PDF']), filename: 'PO-WL-2026-00001.pdf' })
    window.URL.createObjectURL = vi.fn(() => 'blob:x')
    window.URL.revokeObjectURL = vi.fn()
    renderApp('/procurement/orders/po-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Download PDF' }))
    await waitFor(() => expect(api.download).toHaveBeenCalledWith('procurement/orders/po-1/pdf'))
  })
})

describe('Goods received (PRC-14)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('receives what is left on the PO with a rejection, batch, expiry and weight', async () => {
    mockProcurement(api, [['procurement/orders/po-1', { data: ORDER }]])
    api.post.mockResolvedValue({ data: { id: 'grn-1', status: 'draft' } })
    const { router } = renderApp('/procurement/receipts/new?order=po-1')
    const received = await screen.findByLabelText('Received, Maize flour 2 kg')
    // 50 ordered, 20 received so far: 30 left.
    expect(received).toHaveValue('30')
    fireEvent.change(screen.getByLabelText('Rejected, Maize flour 2 kg'), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText('Reason for rejecting'), { target: { value: 'Torn bag' } })
    fireEvent.change(screen.getByLabelText('Batch number'), { target: { value: 'MM-2210' } })
    fireEvent.change(screen.getByLabelText('Expiry date'), { target: { value: '2027-04-30' } })
    fireEvent.change(screen.getByLabelText(/^Weight/), { target: { value: '60' } })
    fireEvent.change(screen.getByLabelText('Delivery note'), { target: { value: 'DN-5521' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save goods received' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('procurement/receipts', {
        order_id: 'po-1',
        location_id: 'l-1',
        delivery_note: 'DN-5521',
        lines: [{ order_line_id: 'ol-1', received_qty: '30', rejected_qty: '1', reject_reason: 'Torn bag', batch_number: 'MM-2210', expires_on: '2027-04-30', weight: '60' }],
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/procurement/receipts/grn-1'))
  })

  it('posts a draft goods received note', async () => {
    const receipt = {
      id: 'grn-1',
      number: null,
      status: 'draft',
      order: { id: 'po-1', number: 'PO-WL-2026-00001' },
      party: named('p-1', 'Mombasa Millers'),
      company: named('c-1', 'Amani Retail'),
      branch: named('br-1', 'Westlands'),
      location: named('l-1', 'Main store'),
      received_at: '2026-10-12T08:00:00Z',
      currency: 'KES',
      value: money('696000'),
      base_value: money('696000'),
      landed: money('0'),
      lines: [{ id: 'rl-1', line_no: 1, order_line_id: 'ol-1', item: FLOUR, description: null, uom: PCS, ordered_qty: '50', received_qty: '30', rejected_qty: '1', accepted_qty: '29', reject_reason: 'Torn bag', batch_number: 'MM-2210', expires_on: '2027-04-30', serials: [], weight: '60', value: money('696000'), base_value: money('696000'), landed: money('0'), on_time: null }],
      can: { edit: true, post: true, cancel: true },
    }
    mockProcurement(api, [['procurement/receipts/grn-1', { data: receipt }]])
    api.post.mockResolvedValue({ data: { ...receipt, number: 'GRN-WL-2026-00001', status: 'posted', can: {} } })
    renderApp('/procurement/receipts/grn-1')
    const lines = await screen.findByRole('table', { name: 'Lines' })
    expect(within(lines).getByText('29')).toBeInTheDocument()
    expect(within(lines).getByText('Torn bag')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Post goods received' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('procurement/receipts/grn-1/post', {}))
    expect(await screen.findByText('Goods received posted.')).toBeInTheDocument()
  })
})
