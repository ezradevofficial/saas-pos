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
const OTHER_COMPANY_ITEM = { id: 'i-9', company_id: 'c-9', code: 'ELSE-1', name: 'Elsewhere', base_uom_id: 'u-ea', uoms: [] }

function setUp({ list = LIST, rows = [price()], permissions = EDITOR } = {}) {
  mockRoutes(
    api,
    [
      [`price-lists/${list.id}`, { data: list }],
      [new RegExp(`^price-lists/${list.id}/prices\\?`), { data: rows, meta: { last_page: 1, total: rows.length, from: 1, to: rows.length, today: '2026-10-08', currency: list.currency } }],
      [/^items\?/, { data: [ITEM, OTHER_COMPANY_ITEM] }],
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
    // Items of another company can't be priced in this list.
    await waitForOption(within(dialog).getByLabelText(/^Item/), 'SODA-500 · Soda 500 ml')
    expect(optionTexts(within(dialog).getByLabelText(/^Item/))).toEqual(['SODA-500 · Soda 500 ml'])
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

  it('keeps CDF prices in whole francs and shows the API refusal under the field', async () => {
    setUp({ list: CDF_LIST, rows: [price({ price_list_id: 'pl-2', currency: 'CDF', amount_minor: '1500' })] })
    api.post.mockRejectedValue(apiError(422, 'validation_failed', 'Some fields need attention.', { uom_id: ['This unit isn’t one of the item’s units. Choose its base unit or one of its other units.'] }))
    renderApp('/settings/taxes/price-lists/pl-2')

    const row = (await screen.findByText('SODA-500')).closest('tr')
    expect(within(row).getByText('1,500')).toBeInTheDocument()
    fireEvent.click(within(row).getByRole('button', { name: 'Edit the price of SODA-500 per EA' }))
    const dialog = await screen.findByRole('dialog')
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
})
