import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { closeFilters, openFilters } from '@/test/filters'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const money = (amount_minor, currency = 'KES') => ({ amount_minor, currency })
const named = (id, name) => ({ id, name })

const SALE = {
  id: 's-1',
  receipt_number: 'R-L01-000001',
  status: 'completed',
  sold_at: '2026-10-08T09:00:00Z',
  received_at: '2026-10-08T09:00:02Z',
  offline: false,
  company: named('c-1', 'Amani Retail'),
  branch: named('b-1', 'Westlands'),
  location: named('l-1', 'Front till'),
  device: named('d-1', 'Till 1'),
  shift_id: 'sh-1',
  cashier: named('u-2', 'Joseph Mwangi'),
  customer: null,
  currency: 'KES',
  subtotal: money('112500'),
  discount: money('0'),
  tax: money('12500'),
  total: money('112500'),
  paid: money('120000'),
  change: money('7500'),
  rounding: money('0'),
  base_total: money('112500'),
  base_tax: money('12500'),
  fx: { rate: '1.00000000', base: 'KES', quote: 'KES', kind: null, effective_at: null },
  flags: [{ code: 'price_differs', line: 1, detail: { expected_unit_price_minor: '60000' } }],
  reviewed_at: null,
  voided_at: null,
  tenders: [
    { method_type: 'cash', amount: money('100000') },
    { method_type: 'cash', amount: money('500', 'USD') },
  ],
}

const DETAIL = {
  ...SALE,
  lines: [
    {
      id: 'ln-1',
      line_no: 1,
      item: named('i-1', 'Soap'),
      qty: '2.000000',
      unit_price: money('56250'),
      list_price: money('60000'),
      tax_inclusive: true,
      discount: money('0'),
      tax_rate: '12.5000',
      tax: money('12500'),
      total: money('112500'),
      refunded_qty: '0.000000',
    },
  ],
  payments: [
    { id: 'p-1', method_type: 'cash', method_name: 'Cash KES', amount: money('100000'), amount_in_sale: money('100000'), fx: { rate: '1.00000000', base: 'KES', quote: 'KES' }, status: 'confirmed', provider_reference: null },
    { id: 'p-2', method_type: 'cash', method_name: 'Cash USD', amount: money('500', 'USD'), amount_in_sale: money('65000'), fx: { rate: '130.00000000', base: 'USD', quote: 'KES' }, status: 'confirmed', provider_reference: null },
  ],
  void: null,
  refunds: [{ id: 'r-1', receipt_number: 'RF-L01-000001', status: 'held', total: money('56250'), reason: 'Damaged', refunded_at: '2026-10-08T10:00:00Z' }],
}

const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })
const VIEWER = tenantWide(['core.company.view', 'pos.sale.view', 'pos.shift.view'])

function setup({ permissions = VIEWER, modules = ['core', 'pos'], detail = DETAIL } = {}) {
  mockRoutes(
    api,
    [
      [/^pos\/sales\?/, (path) => page(path.includes('flag=tax_differs') ? [] : [SALE])],
      ['pos/sales/s-1', () => ({ data: detail })],
      [
        'pos/sales/s-1/fiscal-status',
        {
          data: {
            sale_id: 's-1',
            transmits: true,
            sale: { status: 'accepted', invoice_number: 41, accepted_at: '2026-10-08T09:01:00Z', authority: { receipt_signature: 'SIG-ABC123', internal_data: 'INTDATA42', qr: 'https://qr.invalid/SIG-ABC123' } },
            refunds: [],
            void: null,
          },
        },
      ],
      [/^branches\?/, { data: [{ id: 'b-1', company_id: 'c-1', name: 'Westlands' }] }],
      [/^locations\?/, { data: [{ id: 'l-1', branch_id: 'b-1', name: 'Front till' }] }],
    ],
    { permissions, modules, companies: [COMPANY] },
  )
}

describe('POS sales (POS-12)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists sales with receipt, time, place, cashier, total, payments, status and flags as quiet chips', async () => {
    setup()
    renderApp('/pos/sales')
    const table = await screen.findByRole('table')
    const row = (await within(table).findByText('R-L01-000001')).closest('tr')
    expect(within(row).getByText('Front till')).toBeInTheDocument()
    expect(within(row).getByText('Till 1')).toBeInTheDocument()
    expect(within(row).getByText('Joseph Mwangi')).toBeInTheDocument()
    expect(within(row).getByText('1,125.00')).toBeInTheDocument()
    expect(within(row).getByText('Cash KES 1,000.00 · Cash USD 5.00')).toBeInTheDocument()
    expect(within(row).getByText('Completed').closest('[data-tone]')).toHaveAttribute('data-tone', 'success')
    expect(within(within(row).getByRole('list', { name: 'Flags' })).getByText('Price differs')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('pos/sales?sort=-sold_at&per_page=25&page=1')
  })

  it('filters by flag and review state through the API', async () => {
    setup()
    const { router } = renderApp('/pos/sales')
    await screen.findByText('R-L01-000001')

    openFilters()
    chooseOption('Flag', 'Tax differs')
    await waitFor(() => expect(router.state.location.search).toBe('?flag=tax_differs'))
    chooseOption('Review', 'Not reviewed')
    await waitFor(() => expect(router.state.location.search).toBe('?flag=tax_differs&reviewed=0'))
    await closeFilters()
    expect(api.get).toHaveBeenCalledWith('pos/sales?flag=tax_differs&reviewed=0&sort=-sold_at&per_page=25&page=1')
    expect(await screen.findByText('No sales match. Try other filters or another receipt number.')).toBeInTheDocument()
    expect(screen.getByRole('list', { name: 'Active filters' })).toHaveTextContent('Flag: Tax differs')
  })

  it('filters by company-local days sent as calendar dates', async () => {
    setup()
    const { router } = renderApp('/pos/sales')
    await screen.findByText('R-L01-000001')
    const drawer = openFilters()
    fireEvent.change(within(drawer).getByLabelText('Sold from'), { target: { value: '2026-10-08' } })
    fireEvent.change(within(drawer).getByLabelText('Sold to'), { target: { value: '2026-10-09' } })
    await waitFor(() => expect(router.state.location.search).toBe('?from=2026-10-08&to=2026-10-09'))
    expect(api.get).toHaveBeenCalledWith('pos/sales?from=2026-10-08&to=2026-10-09&sort=-sold_at&per_page=25&page=1')
  })

  it('opens a sale with lines, tax, payments with the rate used, change, fiscal state and refunds', async () => {
    setup()
    renderApp('/pos/sales/s-1')
    expect(await screen.findByRole('heading', { name: 'R-L01-000001' })).toBeInTheDocument()

    const lines = screen.getByRole('table', { name: 'Lines' })
    expect(within(lines).getByText('Soap')).toBeInTheDocument()
    expect(within(lines).getByText('12.5 % included')).toBeInTheDocument()
    expect(within(lines).getByText('Differs from the list price')).toBeInTheDocument()

    const payments = screen.getByRole('table', { name: 'Payments' })
    expect(within(payments).getByText('Cash USD')).toBeInTheDocument()
    expect(within(payments).getByText('1 USD = 130 KES')).toBeInTheDocument()
    expect(within(payments).getByText('650.00')).toBeInTheDocument()

    expect(screen.getByText('Change')).toBeInTheDocument()
    // POS-10: what the tax authority answered, with its references.
    expect(await screen.findByText('Invoice 41')).toBeInTheDocument()
    expect(screen.getAllByText('Accepted').length).toBeGreaterThan(0)
    expect(screen.getByText('SIG-ABC123')).toBeInTheDocument()
    expect(screen.getByText('https://qr.invalid/SIG-ABC123')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('pos/sales/s-1/fiscal-status')
    expect(screen.getByText('Refund RF-L01-000001')).toBeInTheDocument()
    expect(screen.getByText('Price differs')).toBeInTheDocument()
    expect(screen.getByText('The server expected KES 600.00.')).toBeInTheDocument()
    // Without pos.sale.review there is nothing to acknowledge.
    expect(screen.queryByRole('button', { name: 'Mark reviewed' })).not.toBeInTheDocument()
  })

  it('marks a flagged sale reviewed with pos.sale.review', async () => {
    setup({ permissions: [...VIEWER, ...tenantWide(['pos.sale.review'])] })
    api.post.mockResolvedValue({ data: { ...DETAIL, reviewed_at: '2026-10-08T12:00:00Z' } })
    renderApp('/pos/sales/s-1')

    const button = await screen.findByRole('button', { name: 'Mark reviewed' })
    expect(button).toHaveAttribute('data-ds-variant', 'pay')
    fireEvent.click(button)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('pos/sales/s-1/review', {}))
    expect(await screen.findByText('The sale is marked reviewed.')).toBeInTheDocument()
  })

  it('shows the Point of sale group only with the POS module and a POS view permission (RBAC-08, RBAC-09)', async () => {
    setup()
    const { unmount } = renderApp('/')
    const nav = (await screen.findAllByRole('navigation', { name: 'Main' }))[0]
    expect(await within(nav).findByRole('link', { name: 'Sales' })).toHaveAttribute('href', '/pos/sales')
    expect(within(nav).getByRole('link', { name: 'Shifts' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Sales overview' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Held for review' })).not.toBeInTheDocument()
    unmount()

    resetSession()
    signedIn()
    setup({ modules: ['core'] })
    renderApp('/')
    const without = (await screen.findAllByRole('navigation', { name: 'Main' }))[0]
    await within(without).findByRole('link', { name: 'Organisation' })
    expect(within(without).queryByRole('link', { name: 'Sales' })).not.toBeInTheDocument()
    expect(within(without).queryByRole('link', { name: 'My POS PIN' })).not.toBeInTheDocument()
  })
})
