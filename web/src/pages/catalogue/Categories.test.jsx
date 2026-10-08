import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { catalogue } from '@/test/catalogue'
import { apiError, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

describe('Categories', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the tree and adds a subcategory under a parent', async () => {
    catalogue(api)
    api.post.mockResolvedValue({ data: {} })
    renderApp('/catalogue/categories')
    const list = await screen.findByRole('list', { name: 'Categories' })
    const rows = within(list).getAllByRole('listitem')
    expect(rows.map((row) => row.querySelector('.font-medium').textContent)).toEqual(['Drinks', 'Sodas'])
    expect(within(rows[1]).getByText('Sodas').closest('div.flex-col')).toHaveClass('border-l')

    fireEvent.click(within(rows[0]).getByRole('button', { name: 'Add a subcategory under Drinks' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a category' })
    expect(within(dialog).getByLabelText('Parent')).toHaveValue('cat-1')
    fireEvent.change(within(dialog).getByLabelText('Name in English'), { target: { value: 'Juices' } })
    fireEvent.change(within(dialog).getByLabelText('Name in French'), { target: { value: 'Jus' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add category' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('item-categories', { name_en: 'Juices', name_fr: 'Jus', parent_id: 'cat-1' }))
  })

  it('explains why a category with active subcategories cannot be archived', async () => {
    catalogue(api)
    api.post.mockRejectedValue(apiError(422, 'category_in_use', 'In use.'))
    renderApp('/catalogue/categories')
    fireEvent.click(await screen.findByRole('button', { name: 'Archive Drinks' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Archive category' }))
    expect(await within(dialog).findByText('This category has active subcategories. Move or archive them first.')).toBeInTheDocument()
  })

  it('opens a category’s history in a dialog', async () => {
    catalogue(api, {
      extra: [
        [
          'history/item_category/cat-1?per_page=20&page=1',
          { data: [{ id: 'a-1', action: 'core.item_category.create', actor: { id: 'u-1', name: 'Amina Otieno' }, before: null, after: { name_en: 'Drinks' }, occurred_at: '2026-10-08T08:00:00Z' }], meta: { current_page: 1, last_page: 1 } },
        ],
      ],
    })
    renderApp('/catalogue/categories')
    fireEvent.click(await screen.findByRole('button', { name: 'History of Drinks' }))
    const dialog = await screen.findByRole('dialog', { name: 'History of Drinks' })
    expect(await within(dialog).findByText('Created')).toBeInTheDocument()
    expect(within(dialog).getByText('Amina Otieno')).toBeInTheDocument()
  })

  it('offers no changes to a user who may only view categories', async () => {
    catalogue(api, { permissions: tenantWide(['core.item_category.view']) })
    renderApp('/catalogue/categories')
    await screen.findByRole('list', { name: 'Categories' })
    expect(screen.queryByRole('button', { name: 'Add category' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit Drinks' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Archive Drinks' })).not.toBeInTheDocument()
  })
})
