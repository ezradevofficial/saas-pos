import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { UOMS } from '@/test/catalogue'
import { chooseOption, optionTexts, waitForOption } from '@/test/combobox'
import { apiError, CD_COMPANY, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const VIEW = ['core.company.view', 'core.currency.view', 'core.price_list.view', 'core.price.view', 'core.item.view', 'core.uom.view']
const EDITOR = tenantWide([...VIEW, 'core.price.edit'])
const LIST = { id: 'pl-1', company_id: 'c-1', name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true, archived_at: null }
const CDF_LIST = { ...LIST, id: 'pl-2', name: 'Francs', currency: 'CDF' }
const price = (overrides) => ({
  id: 'p-1',
  price_list_id: 'pl-1',
  item_id: 'i-1',
  item_code: 'SODA-500',
  item_name: 'Soda 500 ml',
  uom_id: 'u-ea',
  uom_code: 'EA',
  amount_minor: '12450',
  currency: 'KES',
  effective_from: '2026-10-08',
  min_quantity: '1',
  state: 'current',
  archived_at: null,
  ...overrides,
})
const ITEM = { id: 'i-1', company_id: null, code: 'SODA-500', name: 'Soda 500 ml', base_uom_id: 'u-ea', uoms: [{ uom_id: 'u-box', code: 'BOX', factor: '24' }] }

function setUp({ list = LIST, rows = [price()], permissions = EDITOR } = {}) {
  mockRoutes(
    api,
    [
      [`price-lists/${list.id}`, { data: list }],
      [new RegExp(`^price-lists/${list.id}/prices\\?`), { data: rows, meta: { last_page: 1, total: rows.length, from: 1, to: rows.length, today: '2026-10-08', currency: list.currency } }],
      // The picker asks for the list company's items (shared or its own).
      [/^items\?.*company=c-1/, { data: [ITEM] }],
      ['items/i-1', { data: { ...ITEM, prices: [{ price_list_id: list.id, prices: [{ id: 'p-1', uom_id: 'u-ea', min_quantity: '1', amount_minor: '12450', currency: list.currency }], scheduled: [] }] } }],
      ['uoms?status=all&per_page=200', { data: UOMS }],
      ['tenant/currencies', { data: [{ id: 'tc-1', code: 'KES', active: true, decimals: 2 }, { id: 'tc-2', code: 'CDF', active: true, decimals: 0 }] }],
      [/^history\/price_list\//, { data: [], meta: { current_page: 1, last_page: 1 } }],
    ],
    { permissions, companies: [CD_COMPANY] },
  )
}

describe('Price list prices', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists prices with the item, unit, price in its currency and whether it is in force', async () => {
    setUp({ rows: [price(), price({ id: 'p-2', amount_minor: '13000', effective_from: '2026-11-01', state: 'scheduled' })] })
    renderApp('/settings/taxes/price-lists/pl-1')

    expect(await screen.findByRole('heading', { name: 'Retail' })).toBeInTheDocument()
    const rows = screen.getAllByRole('row').slice(1)
    expect(rows).toHaveLength(2)
    expect(within(rows[0]).getByText('SODA-500')).toBeInTheDocument()
    expect(within(rows[0]).getByText('124.50')).toBeInTheDocument()
    expect(within(rows[0]).getByText('Current')).toBeInTheDocument()
    expect(within(rows[1]).getByText('130.00')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Scheduled')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^price-lists\/pl-1\/prices\?/))
  })

  it('adds a price as a string of minor units in the list currency, from today', async () => {
    setUp({ rows: [] })
    api.post.mockResolvedValue({ data: price() })
    renderApp('/settings/taxes/price-lists/pl-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Add price' }))
    const dialog = await screen.findByRole('dialog')
    await waitForOption(within(dialog).getByLabelText(/^Item/), 'SODA-500 · Soda 500 ml')
    expect(optionTexts(within(dialog).getByLabelText(/^Item/))).toEqual(['SODA-500 · Soda 500 ml'])
    expect(api.get).toHaveBeenCalledWith('items?per_page=20&company=c-1')
    chooseOption(within(dialog).getByLabelText(/^Item/), 'SODA-500 · Soda 500 ml')
    expect(within(dialog).getByLabelText(/^Unit/)).toBeEnabled()
    chooseOption(within(dialog).getByLabelText(/^Unit/), 'BOX')
    fireEvent.change(within(dialog).getByRole('textbox', { name: /^Price/ }), { target: { value: '2,988.00' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save price' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('price-lists/pl-1/prices', {
        item_id: 'i-1',
        uom_id: 'u-box',
        amount_minor: '298800',
        currency: 'KES',
        effective_from: '2026-10-08',
        min_quantity: '1',
      }),
    )
    const [, body] = api.post.mock.calls[0]
    expect(typeof body.amount_minor).toBe('string')
  })

  it('changes the price in force from today, in whole francs, and shows the API refusal under the field', async () => {
    setUp({ list: CDF_LIST, rows: [price({ price_list_id: 'pl-2', currency: 'CDF', amount_minor: '1500', effective_from: '2026-10-01' })] })
    api.post.mockRejectedValue(apiError(422, 'validation_failed', 'Some fields need attention.', { uom_id: ['This unit isn’t one of the item’s units. Choose its base unit or one of its other units.'] }))
    renderApp('/settings/taxes/price-lists/pl-2')

    const row = (await screen.findByText('SODA-500')).closest('tr')
    expect(within(row).getByText('1,500')).toBeInTheDocument()
    fireEvent.click(within(row).getByRole('button', { name: 'Edit the price of SODA-500 per EA' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('The new price applies from today. The current price stays in the history.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText(/^From$/)).toHaveValue('2026-10-08')
    const amount = within(dialog).getByRole('textbox', { name: /^Price/ })
    expect(amount).toHaveValue('1,500')
    fireEvent.change(amount, { target: { value: '1750' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save price' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('price-lists/pl-2/prices', {
        item_id: 'i-1',
        uom_id: 'u-ea',
        amount_minor: '1750',
        currency: 'CDF',
        effective_from: '2026-10-08',
        min_quantity: '1',
      }),
    )
    expect(await within(dialog).findByText(/This unit isn’t one of the item’s units/)).toBeInTheDocument()
  })

  it('shows prices read-only without core.price.edit', async () => {
    setUp({ permissions: tenantWide(VIEW) })
    renderApp('/settings/taxes/price-lists/pl-1')

    expect(await screen.findByText('SODA-500')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add price' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Edit the price/ })).not.toBeInTheDocument()
  })
  it('changes a scheduled price in place and keeps replaced prices read-only', async () => {
    setUp({
      rows: [
        price({ id: 'p-0', effective_from: '2026-01-01', amount_minor: '9000', state: 'replaced' }),
        price({ id: 'p-2', amount_minor: '13000', effective_from: '2026-11-01', state: 'scheduled' }),
      ],
    })
    api.post.mockResolvedValue({ data: price() })
    renderApp('/settings/taxes/price-lists/pl-1')

    const rows = (await screen.findAllByText('SODA-500')).map((cell) => cell.closest('tr'))
    expect(within(rows[0]).queryByRole('button', { name: /Edit the price/ })).not.toBeInTheDocument()
    fireEvent.click(within(rows[1]).getByRole('button', { name: 'Edit the price of SODA-500 per EA' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).queryByText(/applies from today/)).not.toBeInTheDocument()
    fireEvent.change(within(dialog).getByRole('textbox', { name: /^Price/ }), { target: { value: '135.00' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save price' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('price-lists/pl-1/prices', expect.objectContaining({ amount_minor: '13500', effective_from: '2026-11-01' })))
  })

  it('warns, without refusing, when a quantity break costs more than one unit', async () => {
    setUp({ rows: [] })
    api.post.mockResolvedValue({ data: price() })
    renderApp('/settings/taxes/price-lists/pl-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Add price' }))
    const dialog = await screen.findByRole('dialog')
    await waitForOption(within(dialog).getByLabelText(/^Item/), 'SODA-500 · Soda 500 ml')
    chooseOption(within(dialog).getByLabelText(/^Item/), 'SODA-500 · Soda 500 ml')
    fireEvent.change(within(dialog).getByLabelText(/^From quantity/), { target: { value: '10' } })
    fireEvent.change(within(dialog).getByRole('textbox', { name: /^Price/ }), { target: { value: '130.00' } })
    expect(await within(dialog).findByText(/more than the single-unit price of KES 124.50/)).toBeInTheDocument()
    fireEvent.change(within(dialog).getByRole('textbox', { name: /^Price/ }), { target: { value: '120.00' } })
    await waitFor(() => expect(within(dialog).queryByText(/more than the single-unit price/)).not.toBeInTheDocument())
    fireEvent.change(within(dialog).getByRole('textbox', { name: /^Price/ }), { target: { value: '130.00' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save price' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('price-lists/pl-1/prices', expect.objectContaining({ amount_minor: '13000', min_quantity: '10' })))
  })

  it('shows "Item" when the user may not view the item', async () => {
    const { item_code: _code, item_name: _name, ...row } = price()
    setUp({ rows: [row], permissions: tenantWide(['core.company.view', 'core.price_list.view', 'core.price.view']) })
    renderApp('/settings/taxes/price-lists/pl-1')

    const cell = await screen.findByText('124.50')
    expect(within(cell.closest('tr')).getByText('Item')).toBeInTheDocument()
    expect(screen.queryByText('SODA-500')).not.toBeInTheDocument()
  })
})
