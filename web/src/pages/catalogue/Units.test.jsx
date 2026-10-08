import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { catalogue } from '@/test/catalogue'
import { renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

describe('Units', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists active and archived units and adds one at tenant scope', async () => {
    catalogue(api, { permissions: tenantWide(['core.uom.view', 'core.uom.edit']) })
    api.post.mockResolvedValue({ data: {} })
    renderApp('/catalogue/units')
    const table = await screen.findByRole('table', { name: 'Units' })
    expect(await within(table).findByText('BOX')).toBeInTheDocument()
    expect(within(table).queryByText('OLD')).not.toBeInTheDocument()
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Archived' }))
    expect(await within(table).findByText('OLD')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Add unit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a unit' })
    fireEvent.change(within(dialog).getByLabelText(/^Code/), { target: { value: 'crate24' } })
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Crate of 24' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add unit' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('uoms', { code: 'CRATE24', name: 'Crate of 24', kind: 'count' }))
  })

  it('lets a user who holds core.uom.edit only at a company read units but not change them', async () => {
    catalogue(api, { permissions: [{ name: 'core.uom.edit', scopes: [{ type: 'company', id: 'c-1' }] }] })
    renderApp('/catalogue/units')
    expect(await screen.findByText('BOX')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add unit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit BOX' })).not.toBeInTheDocument()
  })
})
