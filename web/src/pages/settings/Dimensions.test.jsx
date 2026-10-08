import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, CD_COMPANY, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const EDITOR = tenantWide(['core.company.view', 'core.user.view', 'core.dimension.view', 'core.dimension.create', 'core.dimension.edit', 'core.dimension.archive'])
const row = (overrides) => ({ company_id: 'c-1', parent_id: null, owner_user_id: null, archived_at: null, ...overrides })
const COST_CENTRES = [row({ id: 'cc-1', code: 'OPS', name: 'Operations', owner_user_id: 'u-2' }), row({ id: 'cc-2', code: 'OPS-KIN', name: 'Kinshasa operations', parent_id: 'cc-1' })]
const USERS = [
  { id: 'u-2', name: 'Grace Mbuyi', roles: [{ scope: { type: 'company', id: 'c-1' } }] },
  { id: 'u-3', name: 'Joseph Kabila', roles: [{ scope: { type: 'branch', id: 'b-9' } }] },
  { id: 'u-4', name: 'Esther Ilunga', roles: [{ scope: { type: 'branch', id: 'b-1' } }] },
]

function dimensions() {
  mockRoutes(
    api,
    [
      ['companies/c-1/departments?status=all&per_page=200', { data: [] }],
      ['companies/c-1/cost-centres?status=all&per_page=200', { data: COST_CENTRES }],
      ['users?status=active&per_page=200', { data: USERS }],
      ['branches?per_page=200', { data: [{ id: 'b-1', company_id: 'c-1', name: 'Gombe' }, { id: 'b-9', company_id: 'c-9', name: 'Elsewhere' }] }],
      ['locations?per_page=200', { data: [] }],
    ],
    { permissions: EDITOR, companies: [CD_COMPANY] },
  )
}

describe('Dimensions', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows cost centres as a tree with their owners', async () => {
    dimensions()
    renderApp('/settings/dimensions')
    expect(await screen.findByText('No departments yet.')).toBeInTheDocument()
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Cost centres' }))

    const list = await screen.findByRole('list', { name: 'Cost centres' })
    const items = within(list).getAllByRole('listitem')
    expect(items.map((item) => within(item).getByText(/^OPS/).textContent)).toEqual(['OPS', 'OPS-KIN'])
    expect(await within(items[0]).findByText('Owner: Grace Mbuyi')).toBeInTheDocument()
    expect(within(items[1]).getByText('Kinshasa operations').closest('div.flex-col')).toHaveClass('border-l')
  })

  it('adds a cost centre with a parent and an owner who can view the company', async () => {
    dimensions()
    api.post.mockResolvedValue({ data: row({ id: 'cc-3' }) })
    renderApp('/settings/dimensions')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Cost centres' }))

    fireEvent.click(await screen.findByRole('button', { name: 'Add cost centre' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a cost centre' })
    fireEvent.change(within(dialog).getByLabelText(/^Code/), { target: { value: 'MKT' } })
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Marketing' } })
    fireEvent.change(within(dialog).getByLabelText('Parent'), { target: { value: 'cc-1' } })
    const owner = within(dialog).getByLabelText('Owner')
    await waitFor(() => expect(within(owner).getByRole('option', { name: 'Esther Ilunga' })).toBeInTheDocument())
    // Someone with a role only at another company's branch is not offered.
    expect(within(owner).queryByRole('option', { name: 'Joseph Kabila' })).not.toBeInTheDocument()
    fireEvent.change(owner, { target: { value: 'u-4' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add cost centre' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('companies/c-1/cost-centres', { code: 'MKT', name: 'Marketing', parent_id: 'cc-1', owner_user_id: 'u-4' }),
    )
  })

  it('explains why a parent with active children cannot be archived', async () => {
    dimensions()
    api.post.mockRejectedValue(apiError(422, 'dimension_in_use', 'This record has active children.'))
    renderApp('/settings/dimensions')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Cost centres' }))

    fireEvent.click(await screen.findByRole('button', { name: 'Archive Operations' }))
    const dialog = await screen.findByRole('dialog', { name: 'Archive Operations?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Archive cost centre' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('cost-centres/cc-1/archive'))
    expect(await within(dialog).findByText('This record has active records under it. Move or archive them first.')).toBeInTheDocument()
  })

  it('shows an archived current parent as selected but not choosable, and does not resend it', async () => {
    mockRoutes(
      api,
      [
        ['companies/c-1/departments?status=all&per_page=200', { data: [] }],
        [
          'companies/c-1/cost-centres?status=all&per_page=200',
          { data: [row({ id: 'cc-1', code: 'OPS', name: 'Operations', archived_at: '2026-10-01T00:00:00Z' }), row({ id: 'cc-2', code: 'OPS-KIN', name: 'Kinshasa operations', parent_id: 'cc-1' })] },
        ],
        ['users?status=active&per_page=200', { data: USERS }],
        ['branches?per_page=200', { data: [] }],
        ['locations?per_page=200', { data: [] }],
      ],
      { permissions: EDITOR, companies: [CD_COMPANY] },
    )
    api.patch.mockResolvedValue({ data: row({ id: 'cc-2' }) })
    renderApp('/settings/dimensions')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Cost centres' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Kinshasa operations' }))
    const dialog = await screen.findByRole('dialog')
    const parent = within(dialog).getByLabelText('Parent')
    expect(parent).toHaveValue('cc-1')
    expect(within(parent).getByRole('option', { name: 'OPS · Operations (archived)' })).toBeDisabled()
    expect(within(dialog).getByText('The current parent is archived. Keep it, or choose an active one.')).toBeInTheDocument()
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Kinshasa ops' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('cost-centres/cc-2', { code: 'OPS-KIN', name: 'Kinshasa ops', owner_user_id: null }))
  })
})
