import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { catalogue, ITEM } from '@/test/catalogue'
import { chooseOption, waitForOption } from '@/test/combobox'
import { closeFilters, openFilters } from '@/test/filters'
import { renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const listCalls = () => api.get.mock.calls.map(([path]) => path).filter((path) => path.startsWith('items?'))

describe('Items', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists items with their category, unit and barcode, and opens one', async () => {
    catalogue(api)
    const { router } = renderApp('/catalogue/items')
    const table = await screen.findByRole('table', { name: 'Items' })
    const row = (await within(table).findByText('Soda 500 ml')).closest('tr')
    expect(within(row).getByText('SODA-500')).toBeInTheDocument()
    expect(await within(row).findByText('Sodas')).toBeInTheDocument()
    expect(within(row).getByText('EA')).toBeInTheDocument()
    expect(within(row).getByText('6001234567890')).toBeInTheDocument()
    expect(within(row).getByText('+1 more')).toBeInTheDocument()
    fireEvent.click(row)
    await waitFor(() => expect(router.state.location.pathname).toBe('/catalogue/items/i-1'))
  })

  it('searches after typing stops (a barcode works too), filters and pages on the server', async () => {
    catalogue(api)
    renderApp('/catalogue/items')
    await screen.findByText('Soda 500 ml')
    expect(listCalls()[0]).toBe('items?status=active&per_page=25&page=1')

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: '6001' } })
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: '6001234567890' } })
    await waitFor(() => expect(listCalls().at(-1)).toBe('items?status=active&search=6001234567890&per_page=25&page=1'))
    // Debounced: the half-typed value was never asked for.
    expect(listCalls().some((path) => path.includes('search=6001&'))).toBe(false)

    // Filters sit in the drawer; the chips under the toolbar name them.
    openFilters()
    await waitForOption('Category', 'Drinks')
    chooseOption('Category', 'Drinks')
    await waitFor(() => expect(listCalls().at(-1)).toContain('category=cat-1'))
    chooseOption('Type', 'Service')
    await waitFor(() => expect(listCalls().at(-1)).toContain('type=service'))
    await closeFilters()
    expect(screen.getByText('Category: Drinks')).toBeInTheDocument()
    expect(screen.getByText('Type: Service')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Next page' }))
    await waitFor(() => expect(listCalls().at(-1)).toContain('page=2'))
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Archived' }))
    // A new filter starts again on page 1; search and the other filters stay.
    await waitFor(() => expect(listCalls().at(-1)).toBe('items?status=archived&category=cat-1&type=service&search=6001234567890&per_page=25&page=1'))
  })

  it('keeps the list state in the URL, sorts by a header and exports the visible columns (EXP-01, LAY-04)', async () => {
    catalogue(api)
    api.download.mockResolvedValue({ blob: new Blob(['x']), filename: 'items-2026-10-08.xlsx' })
    URL.createObjectURL = vi.fn(() => 'blob:items')
    URL.revokeObjectURL = vi.fn()
    const { router } = renderApp('/catalogue/items?type=service&sort=-code&page=2')
    const table = await screen.findByRole('table', { name: 'Items' })
    await waitFor(() => expect(listCalls()[0]).toBe('items?status=active&type=service&sort=-code&per_page=25&page=2'))
    expect(within(table).getByRole('columnheader', { name: /Code/ })).toHaveAttribute('aria-sort', 'descending')

    fireEvent.click(within(table).getByRole('button', { name: 'Name' }))
    await waitFor(() => expect(router.state.location.search).toBe('?type=service&sort=name'))
    expect(listCalls().at(-1)).toBe('items?status=active&type=service&sort=name&per_page=25&page=1')

    fireEvent.pointerDown(screen.getByRole('button', { name: 'Export' }), { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Excel (.xlsx)' }))
    await waitFor(() => expect(api.download).toHaveBeenCalled())
    const [path] = api.download.mock.calls[0]
    const params = new URLSearchParams(path.split('?')[1])
    expect(path.startsWith('items?')).toBe(true)
    expect(params.get('format')).toBe('xlsx')
    expect(params.get('sort')).toBe('name')
    expect(params.get('page')).toBeNull()
    expect(params.getAll('columns[]')).toEqual(['code', 'name', 'category', 'type', 'base_unit', 'barcodes'])
  })

  it('creates an item with two units, barcodes per unit, and names possible duplicates without blocking', async () => {
    catalogue(api, { item: { ...ITEM, id: 'i-9' } })
    api.post.mockResolvedValue({
      data: { ...ITEM, id: 'i-9' },
      meta: { possible_duplicates: [{ id: 'i-1', code: 'SODA-500', name: 'Soda 500 ml', reason: 'name' }] },
    })
    const { router } = renderApp('/catalogue/items/new')

    fireEvent.change(await screen.findByLabelText(/^Code/), { target: { value: 'SODA-330' } })
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Soda 330 ml' } })
    // MD-02: one name field, whatever the languages the app speaks.
    expect(screen.getAllByRole('textbox', { name: /name/i })).toHaveLength(1)
    await waitForOption('Category', /Sodas$/)
    chooseOption('Category', /Sodas$/)
    const base = screen.getByLabelText(/^Base unit/)
    await waitFor(() => expect(base).toHaveValue('u-ea'))

    fireEvent.click(screen.getByRole('button', { name: 'Add unit' }))
    chooseOption(screen.getByLabelText(/^Unit/), 'BOX · Box')
    fireEvent.change(screen.getByLabelText(/^Contains \(EA\)/), { target: { value: '12' } })
    fireEvent.click(screen.getByLabelText('Default for sales'))

    fireEvent.click(screen.getByRole('button', { name: 'Add barcode' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add barcode' }))
    const barcodes = screen.getAllByLabelText('Barcode')
    fireEvent.change(barcodes[0], { target: { value: '6001234500001' } })
    fireEvent.change(barcodes[1], { target: { value: ' 6001234500002 ' } })
    const list = screen.getByRole('list', { name: 'Barcodes' })
    chooseOption(within(list).getAllByLabelText('Unit')[1], 'BOX')
    fireEvent.click(screen.getByRole('button', { name: 'Create item' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('items', {
        code: 'SODA-330',
        name: 'Soda 330 ml',
        type: 'stock',
        category_id: 'cat-2',
        base_uom_id: 'u-ea',
        uoms: [{ uom_id: 'u-box', factor: '12', is_sales_default: true, is_purchase_default: false }],
        barcodes: [
          { barcode: '6001234500001', uom_id: null },
          { barcode: '6001234500002', uom_id: 'u-box' },
        ],
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/catalogue/items/i-9'))
    expect(await screen.findByText('This item may already exist')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'SODA-500 · Soda 500 ml' })).toHaveAttribute('href', '/catalogue/items/i-1')
  })

  it('asks for the company when items are kept per company', async () => {
    catalogue(api, { mode: 'per_company' })
    api.post.mockResolvedValue({ data: { ...ITEM, id: 'i-9', company_id: 'c-1' }, meta: { possible_duplicates: [] } })
    renderApp('/catalogue/items/new')
    const company = await screen.findByLabelText(/^Company/)
    // The only company is chosen already.
    await waitFor(() => expect(company).toHaveValue('c-1'))
    fireEvent.change(screen.getByLabelText(/^Code/), { target: { value: 'RICE-5' } })
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Rice 5 kg' } })
    await waitFor(() => expect(screen.getByLabelText(/^Base unit/)).toHaveValue('u-ea'))
    fireEvent.click(screen.getByRole('button', { name: 'Create item' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('items', expect.objectContaining({ company_id: 'c-1', code: 'RICE-5' })))
  })

  it('shows a unit factor that is not a number when the form is submitted, without sending', async () => {
    catalogue(api)
    renderApp('/catalogue/items/new')
    fireEvent.change(await screen.findByLabelText(/^Code/), { target: { value: 'X1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add unit' }))
    chooseOption(screen.getByLabelText(/^Unit/), 'BOX · Box')
    fireEvent.change(screen.getByLabelText(/^Contains/), { target: { value: '0' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create item' }))
    expect(await screen.findByText('Enter a number above zero.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })
})
