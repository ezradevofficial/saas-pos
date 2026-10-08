import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { catalogue, ITEM, ITEM_EDITOR } from '@/test/catalogue'
import { chooseOption } from '@/test/combobox'
import { apiError, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

describe('Item detail', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('sends the full units and barcodes when the base unit changes (base_change_needs_units)', async () => {
    catalogue(api)
    api.patch.mockResolvedValue({ data: { ...ITEM, base_uom_id: 'u-kg' }, meta: { possible_duplicates: [] } })
    renderApp('/catalogue/items/i-1')

    const base = await screen.findByLabelText(/^Base unit/)
    await waitFor(() => expect(base).toHaveValue('u-ea'))
    chooseOption(base, 'KG · Kilogram')
    expect(screen.getByText(/The base unit changed/)).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Soda 500 ml can' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() =>
      expect(api.patch).toHaveBeenCalledWith('items/i-1', {
        code: 'SODA-500',
        name: 'Soda 500 ml can',
        type: 'stock',
        category_id: 'cat-2',
        base_uom_id: 'u-kg',
        uoms: [{ uom_id: 'u-box', factor: '24', is_sales_default: false, is_purchase_default: true }],
        barcodes: [
          { barcode: '6001234567890', uom_id: null },
          { barcode: '6001234567891', uom_id: 'u-box' },
        ],
      }),
    )
    expect(await screen.findByText('Soda 500 ml saved.')).toBeInTheDocument()
  })

  it('shows a refused barcode under its own field', async () => {
    catalogue(api)
    api.patch.mockRejectedValue(apiError(422, 'validation_failed', 'Some fields need attention.', { 'barcodes.1.barcode': ['This barcode is used by another item.'] }))
    renderApp('/catalogue/items/i-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Save changes' }))
    const field = await screen.findByText('This barcode is used by another item.')
    expect(field.closest('li')).toContainElement(screen.getByDisplayValue('6001234567891'))
  })

  it('uploads an image with progress, reorders with buttons (focus kept) and removes after confirming', async () => {
    catalogue(api)
    let report
    api.upload.mockImplementation((_path, _form, { onProgress }) => {
      report = onProgress
      return new Promise(() => {})
    })
    api.put.mockResolvedValue({ data: { ...ITEM, images: [{ ...ITEM.images[1], position: 1 }, { ...ITEM.images[0], position: 2 }] } })
    api.delete.mockResolvedValue(null)
    renderApp('/catalogue/items/i-1')

    const images = await screen.findByRole('list', { name: 'Images' })
    expect(within(images).getAllByRole('img').map((img) => img.getAttribute('src'))).toEqual(['https://media.test/1.png', 'https://media.test/2.png'])

    const file = new File(['png'], 'soda.png', { type: 'image/png' })
    fireEvent.change(screen.getByLabelText('Choose an image file'), { target: { files: [file] } })
    await waitFor(() => expect(api.upload).toHaveBeenCalledWith('items/i-1/images', expect.any(FormData), expect.any(Object)))
    expect(api.upload.mock.calls[0][1].get('image')).toBe(file)
    report(0.5)
    expect(await screen.findByText('Uploading soda.png: 50%')).toBeInTheDocument()

    const later = screen.getByRole('button', { name: 'Move image 1 later' })
    later.focus()
    fireEvent.click(later)
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('items/i-1/images/order', { image_ids: ['img-2', 'img-1'] }))
    // The moved image is now last: its "later" is disabled, so focus is on its "earlier".
    expect(screen.getByRole('button', { name: 'Move image 2 earlier' })).toHaveFocus()
    expect(screen.getByText('Image moved to position 2 of 2.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Remove image 1' }))
    const dialog = await screen.findByRole('dialog', { name: 'Remove image 1?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove image' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('item-images/img-2'))
  })

  it('refuses a file that is not an image or is too large before sending it', async () => {
    catalogue(api)
    renderApp('/catalogue/items/i-1')
    const input = await screen.findByLabelText('Choose an image file')
    fireEvent.change(input, { target: { files: [new File(['%PDF'], 'menu.pdf', { type: 'application/pdf' })] } })
    expect(await screen.findByText('This file is not a JPEG, PNG or WebP image. Choose another file.')).toBeInTheDocument()
    const big = new File([new Uint8Array(3 * 1024 * 1024)], 'big.png', { type: 'image/png' })
    fireEvent.change(input, { target: { files: [big] } })
    expect(await screen.findByText('This image is 3 MB, more than 2 MB. Choose a smaller file.')).toBeInTheDocument()
    expect(api.upload).not.toHaveBeenCalled()
  })

  it('shows the history with units and barcodes by code (MD-07)', async () => {
    catalogue(api, {
      extra: [
        [
          'history/item/i-1?per_page=20&page=1',
          {
            data: [
              {
                id: 'a-2',
                action: 'core.item.units_update',
                actor: { id: 'u-1', name: 'Amina Otieno' },
                before: { uoms: [] },
                after: { uoms: [{ uom_id: 'u-box', factor: '24', is_sales_default: false, is_purchase_default: true }] },
                occurred_at: '2026-10-08T09:00:00Z',
              },
              { id: 'a-1', action: 'core.item.update', actor: null, before: { name: 'Soda' }, after: { name: 'Soda 500 ml' }, occurred_at: '2026-10-08T08:00:00Z' },
            ],
            meta: { current_page: 1, last_page: 1 },
          },
        ],
      ],
    })
    renderApp('/catalogue/items/i-1?tab=history')
    const list = await screen.findByRole('list', { name: 'History' })
    expect(await within(list).findByText('BOX × 24')).toBeInTheDocument()
    expect(within(list).getByText('Units changed')).toBeInTheDocument()
    expect(within(list).getByText('Name')).toBeInTheDocument()
    expect(within(list).getByText('Soda 500 ml')).toBeInTheDocument()
    expect(within(list).getByText('System')).toBeInTheDocument()
  })

  it('is read-only without edit permission and leaves out fields hidden by field rules (RBAC-05)', async () => {
    const hidden = { ...ITEM }
    delete hidden.barcodes
    delete hidden.images
    catalogue(api, { item: hidden, permissions: tenantWide(['core.item.view', 'core.uom.view', 'core.item_category.view']) })
    renderApp('/catalogue/items/i-1')
    expect(await screen.findByLabelText(/^Code/)).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Archive item' })).not.toBeInTheDocument()
    expect(screen.queryByText('Barcodes')).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Images' })).not.toBeInTheDocument()
  })

  it('archives after a confirmation', async () => {
    catalogue(api, { permissions: ITEM_EDITOR })
    api.post.mockResolvedValue({ data: { ...ITEM, archived_at: '2026-10-08T10:00:00Z' } })
    renderApp('/catalogue/items/i-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Archive item' }))
    const dialog = await screen.findByRole('dialog', { name: 'Archive Soda 500 ml?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Archive item' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('items/i-1/archive'))
    expect(await screen.findByRole('button', { name: 'Restore item' })).toBeInTheDocument()
  })
  describe('prices', () => {
    const RETAIL = {
      price_list_id: 'pl-1',
      name: 'Retail',
      company_id: 'c-1',
      currency: 'CDF',
      tax_inclusive: true,
      is_default: true,
      can_edit: true,
      today: '2026-10-08',
      prices: [{ id: 'p-1', uom_id: 'u-ea', uom_code: 'EA', amount_minor: '1500', currency: 'CDF', effective_from: '2026-10-01', min_quantity: '1' }],
      scheduled: [{ id: 'p-2', uom_id: 'u-ea', uom_code: 'EA', amount_minor: '1750', currency: 'CDF', effective_from: '2026-11-01', min_quantity: '1' }],
    }
    const withPrices = (lists) => ({ ...ITEM, prices: lists })
    const currencies = ['tenant/currencies', { data: [{ id: 'tc-1', code: 'CDF', active: true, decimals: 0 }] }]

    it('shows each price list with today’s and scheduled prices, and sets a price in whole francs', async () => {
      catalogue(api, { item: withPrices([RETAIL]), extra: [currencies] })
      api.post.mockResolvedValue({ data: {} })
      renderApp('/catalogue/items/i-1')

      const section = await screen.findByRole('region', { name: 'Retail' })
      expect(within(within(section).getByRole('list', { name: 'Current prices in Retail' })).getByText('1,500')).toBeInTheDocument()
      expect(within(within(section).getByRole('list', { name: 'Scheduled prices in Retail' })).getByText('1,750')).toBeInTheDocument()

      fireEvent.click(within(section).getByRole('button', { name: 'Set a price in Retail' }))
      const dialog = await screen.findByRole('dialog')
      chooseOption(within(dialog).getByLabelText(/^Unit/), 'BOX')
      fireEvent.change(within(dialog).getByRole('textbox', { name: /^Price/ }), { target: { value: '33000' } })
      fireEvent.change(within(dialog).getByLabelText(/^From$/), { target: { value: '2026-12-01' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Save price' }))

      await waitFor(() =>
        expect(api.post).toHaveBeenCalledWith('price-lists/pl-1/prices', {
          item_id: 'i-1',
          uom_id: 'u-box',
          amount_minor: '33000',
          currency: 'CDF',
          effective_from: '2026-12-01',
          min_quantity: '1',
        }),
      )
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    })

    it('shows prices without edit when the user may not change them', async () => {
      catalogue(api, { item: withPrices([{ ...RETAIL, can_edit: false }]), extra: [currencies] })
      renderApp('/catalogue/items/i-1')
      const section = await screen.findByRole('region', { name: 'Retail' })
      expect(within(section).queryByRole('button', { name: 'Set a price in Retail' })).not.toBeInTheDocument()
      expect(within(section).queryByRole('button', { name: /Edit the price/ })).not.toBeInTheDocument()
    })

    it('has no prices card when the API leaves prices out', async () => {
      catalogue(api)
      renderApp('/catalogue/items/i-1')
      expect(await screen.findByRole('button', { name: 'Save changes' })).toBeInTheDocument()
      expect(screen.queryByRole('heading', { name: 'Prices' })).not.toBeInTheDocument()
    })
  })
})
