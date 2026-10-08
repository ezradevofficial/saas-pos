import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { catalogue, UOMS } from '@/test/catalogue'
import { chooseOption } from '@/test/combobox'
import { renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

// The list asks the server for one status at a time.
const UNIT_PAGES = [
  /^uoms\?status=(active|archived)/,
  (path) => {
    const archived = path.includes('status=archived')
    const rows = UOMS.filter((uom) => Boolean(uom.archived_at) === archived)
    return { data: rows, meta: { last_page: 1, total: rows.length, from: 1, to: rows.length } }
  },
]

describe('Units', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists active and archived units and adds one at tenant scope', async () => {
    catalogue(api, { permissions: tenantWide(['core.uom.view', 'core.uom.edit']), extra: [UNIT_PAGES] })
    api.post.mockResolvedValue({ data: {} })
    renderApp('/catalogue/units')
    const table = await screen.findByRole('table', { name: 'Units' })
    expect(await within(table).findByText('BOX')).toBeInTheDocument()
    expect(within(table).queryByText('OLD')).not.toBeInTheDocument()
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Archived' }))
    expect(await within(table).findByText('OLD')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('uoms?status=archived&per_page=25&page=1')

    fireEvent.click(screen.getByRole('button', { name: 'Add unit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a unit' })
    fireEvent.change(within(dialog).getByLabelText(/^Code/), { target: { value: 'crate24' } })
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Crate of 24' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add unit' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('uoms', { code: 'CRATE24', name: 'Crate of 24', kind: 'count' }))
  })

  it('sorts by a header, pages 50 at a time and exports the visible columns (EXP-01)', async () => {
    catalogue(api, { permissions: tenantWide(['core.uom.view']), extra: [UNIT_PAGES] })
    api.download.mockResolvedValue({ blob: new Blob(['x']), filename: null })
    URL.createObjectURL = vi.fn(() => 'blob:list')
    URL.revokeObjectURL = vi.fn()
    const calls = () => api.get.mock.calls.map(([path]) => path).filter((path) => /^uoms\?status=(active|archived)/.test(path))
    renderApp('/catalogue/units')
    const table = await screen.findByRole('table', { name: 'Units' })
    await within(table).findByText('BOX')
    fireEvent.click(within(table).getByRole('button', { name: 'Kind' }))
    await waitFor(() => expect(calls().at(-1)).toBe('uoms?status=active&sort=kind&per_page=25&page=1'))
    chooseOption('Rows per page', '50')
    await waitFor(() => expect(calls().at(-1)).toBe('uoms?status=active&sort=kind&per_page=50&page=1'))
    fireEvent.pointerDown(screen.getByRole('button', { name: 'Export' }), { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'CSV' }))
    await waitFor(() => expect(api.download).toHaveBeenCalled())
    const params = new URLSearchParams(api.download.mock.calls[0][0].split('?')[1])
    expect(api.download.mock.calls[0][0].startsWith('uoms?')).toBe(true)
    expect(params.get('status')).toBe('active')
    expect(params.get('format')).toBe('csv')
    expect(params.getAll('columns[]')).toEqual(['code', 'name', 'kind', 'status'])
  })

  it('lets a user who holds core.uom.edit only at a company read units but not change them', async () => {
    catalogue(api, { permissions: [{ name: 'core.uom.edit', scopes: [{ type: 'company', id: 'c-1' }] }], extra: [UNIT_PAGES] })
    renderApp('/catalogue/units')
    expect(await screen.findByText('BOX')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add unit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit BOX' })).not.toBeInTheDocument()
  })
})
