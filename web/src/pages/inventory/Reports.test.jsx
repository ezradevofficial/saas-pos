// INV-04, INV-11, INV-12, INV-13, INV-20, INV-21: movements, reorder levels, valuation totals,
// labels and the company settings.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const named = (id, name) => ({ id, name })
const page = (data, meta = {}) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length, ...meta } })
const PCS = { id: 'u-pcs', code: 'PCS', name: 'Pieces' }
const ITEM = { id: 'i-1', code: 'SOAP-1', name: 'Soap 1 kg', type: 'stock', base_uom_id: 'u-pcs', uoms: [] }
const LOCATIONS = [{ id: 'l-1', name: 'Front store', branch: named('br-1', 'Westlands'), archived_at: null }]
const RULE = {
  id: 'r-1',
  item: { id: 'i-1', code: 'SOAP-1', name: 'Soap 1 kg' },
  base_uom: PCS,
  company_id: 'c-1',
  location: named('l-1', 'Front store'),
  reorder_level: '10.000000',
  reorder_qty: '24.000000',
  on_hand: '4.000000',
  low: true,
  low_since: '2026-10-09T08:00:00Z',
  archived_at: null,
}
const SETTINGS = { company: { id: 'c-1', name: 'Amani Retail', base_currency: 'KES' }, costing_method: 'weighted_average', allow_negative: true, costing_locked: true }

function setup(permissions, extra = []) {
  mockRoutes(
    api,
    [
      ...extra,
      [/^inventory\/movements\?/, page([
        {
          id: 'm-1',
          kind: 'transfer_out',
          item: { id: 'i-1', code: 'SOAP-1', name: 'Soap 1 kg' },
          company_id: 'c-1',
          location: named('l-1', 'Front store'),
          batch: null,
          qty: '-2.000000',
          balance_after: '4.000000',
          value: null,
          source_type: 'inventory.transfer',
          source_id: 't-1',
          flags: [{ code: 'negative_stock' }],
          occurred_at: '2026-10-09T08:00:00Z',
        },
      ])],
      [/^inventory\/reorder-rules\?/, page([RULE])],
      [/^inventory\/valuation\?/, page([{ id: 'v-1', company: named('c-1', 'Amani Retail'), item: { id: 'i-1', code: 'SOAP-1', name: 'Soap 1 kg' }, base_uom: PCS, qty: '34.000000', value: { amount_minor: '408000', currency: 'KES' }, unit_cost_minor: '12000', currency: 'KES' }], { totals: [{ amount_minor: '408000', currency: 'KES' }, { amount_minor: '135000', currency: 'CDF' }] })],
      ['inventory/companies/c-1/settings', { data: SETTINGS }],
      [/^companies\/c-1\/price-lists\?/, { data: [{ id: 'pl-1', name: 'Retail prices', archived_at: null }] }],
      ['inventory/items/i-1', { data: { item: { ...ITEM, base_uom: PCS }, settings: { tracking: 'none', track_expiry: false }, variant: null, variants: [], balances: [], on_hand: '0', batches: [], serials: [], reorder_rules: [], costs: [], has_movements: true } }],
      [/^items\?/, page([ITEM])],
      [/^branches\?/, { data: [] }],
      [/^locations\?/, { data: LOCATIONS }],
    ],
    { permissions: tenantWide(['core.company.view', ...permissions]), modules: ['core', 'inventory'], companies: [COMPANY] },
  )
}

describe('Inventory reports and settings', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists movements with the kind linked to its document, flags as chips and no value when hidden (INV-04)', async () => {
    setup(['inventory.stock.view'])
    renderApp('/inventory/movements')
    const row = (await screen.findByText('Soap 1 kg')).closest('tr')
    expect(within(row).getByRole('link', { name: 'Transfer out' })).toHaveAttribute('href', '/inventory/transfers/t-1')
    expect(within(row).getByText('-2')).toBeInTheDocument()
    expect(within(row).getByText('Stock went below zero')).toBeInTheDocument()
    expect(within(row).getByText('—')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('inventory/movements?sort=-occurred_at&per_page=25&page=1')
  })

  it('sets a new reorder level and removes one by leaving the level empty (INV-11)', async () => {
    setup(['inventory.reorder.view', 'inventory.reorder.edit'])
    api.put.mockResolvedValue({ data: RULE })
    renderApp('/inventory/reorder')
    const row = (await screen.findByText('Front store')).closest('tr')
    expect(within(row).getByText('Low')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Set reorder level' }))
    let dialog = await screen.findByRole('dialog', { name: 'Set reorder level' })
    await waitForOption(within(dialog).getByLabelText(/^Item/), 'Soap 1 kg · SOAP-1')
    chooseOption(within(dialog).getByLabelText(/^Item/), 'Soap 1 kg · SOAP-1')
    chooseOption(within(dialog).getByLabelText(/^Location/), 'Front store · Westlands')
    fireEvent.change(within(dialog).getByLabelText('Reorder level'), { target: { value: '12' } })
    fireEvent.change(within(dialog).getByLabelText('Reorder quantity'), { target: { value: '36' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save reorder level' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('inventory/reorder-rules', { item_id: 'i-1', location_id: 'l-1', reorder_level: '12', reorder_qty: '36' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())

    fireEvent.click(within(row).getByText('Soap 1 kg'))
    dialog = await screen.findByRole('dialog', { name: 'Change reorder level' })
    fireEvent.change(within(dialog).getByLabelText('Reorder level'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove reorder level' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('inventory/reorder-rules', { item_id: 'i-1', location_id: 'l-1', reorder_level: null, reorder_qty: '24.000000' }))
  })

  it('shows reorder levels read-only without inventory.reorder.edit', async () => {
    setup(['inventory.reorder.view'])
    renderApp('/inventory/reorder')
    await screen.findByText('Front store')
    expect(screen.queryByRole('button', { name: 'Set reorder level' })).not.toBeInTheDocument()
  })

  it('shows the valuation with totals per currency, currency code first (INV-20)', async () => {
    setup(['inventory.valuation.view'])
    renderApp('/inventory/valuation')
    const totals = await screen.findByRole('region', { name: 'Totals' })
    expect(within(totals).getByText('Total value in KES')).toBeInTheDocument()
    expect(within(totals).getByText('KES 4,080.00')).toBeInTheDocument()
    expect(within(totals).getByText('CDF 135,000')).toBeInTheDocument()
    const row = (await screen.findByText('Soap 1 kg')).closest('tr')
    expect(within(row).getByText('120')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('inventory/valuation?sort=-value&per_page=25&page=1')
  })

  it('prints labels in a sandboxed frame and downloads them as a PDF (INV-21)', async () => {
    setup(['inventory.label.print'])
    api.download.mockResolvedValue({ blob: new Blob(['<html><body><p>Label</p></body></html>'], { type: 'text/html' }), filename: null })
    renderApp('/inventory/labels')
    await waitForOption(await screen.findByLabelText(/^Item/), 'Soap 1 kg · SOAP-1')
    chooseOption(screen.getByLabelText(/^Item/), 'Soap 1 kg · SOAP-1')
    fireEvent.change(screen.getByLabelText(/^Copies/), { target: { value: '3' } })
    await waitForOption(screen.getByLabelText('Price list'), 'Retail prices')
    chooseOption(screen.getByLabelText('Price list'), 'Retail prices')
    fireEvent.click(screen.getByRole('button', { name: 'Print labels' }))
    await waitFor(() =>
      expect(api.download).toHaveBeenCalledWith('inventory/labels', { method: 'POST', body: { labels: [{ item_id: 'i-1', copies: 3 }], price_list_id: 'pl-1', format: 'html' } }),
    )
    await waitFor(() => expect(document.querySelector('iframe')).not.toBeNull())
    expect(document.querySelector('iframe').getAttribute('sandbox')).toBe('allow-scripts allow-modals')
  })

  it('locks the costing method once the company has movements but still saves negative stock (INV-12, INV-13)', async () => {
    setup(['inventory.stock.view', 'inventory.settings.edit'])
    api.put.mockResolvedValue({ data: { ...SETTINGS, allow_negative: false } })
    renderApp('/inventory/settings')
    expect(await screen.findByText('The costing method can no longer change')).toBeInTheDocument()
    expect(screen.getByLabelText('Costing method')).toBeDisabled()
    fireEvent.click(screen.getByRole('switch', { name: 'Allow negative stock' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('inventory/companies/c-1/settings', { costing_method: 'weighted_average', allow_negative: false }))
  })

  it('shows the settings read-only without inventory.settings.edit', async () => {
    setup(['inventory.stock.view'])
    renderApp('/inventory/settings')
    expect(await screen.findByText('You can see these settings but not change them.')).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Allow negative stock' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Save settings' })).not.toBeInTheDocument()
  })
})
