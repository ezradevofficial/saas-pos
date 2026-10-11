import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ApiError, api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const PERMISSIONS = tenantWide(['core.layout.view', 'core.layout.edit', 'core.layout.publish', 'core.user.view', 'core.role.view', 'core.company.view'])
const TENANT = { type: 'tenant', id: null }

const documentWith = (payload, { revision = 1, id = 'd-nav', scope = TENANT } = {}) => ({
  data: { id, kind: 'navigation', key: 'default', scope, published: null, draft: { version: 1, revision, status: 'draft', payload }, history: [{ version: 1, status: 'draft' }] },
  meta: { problems: [] },
})

const USERS_RENAMED = (label) => ({ groups: [{ id: 'settings', label: null, items: [{ id: '/settings/users', label, hidden: false }] }], home: null })

function setUp({ stored = documentWith(USERS_RENAMED(null)) } = {}) {
  let current = stored
  mockRoutes(
    api,
    [
      [/^config\/navigation\?.*scope_type=tenant/, () => ({ data: current ? [current.data] : [], meta: { total: current ? 1 : 0 } })],
      [/^config\/navigation\?/, { data: [], meta: { total: 0 } }],
      [/^config\/navigation\/d-nav$/, () => current],
      [/^roles\?/, { data: [{ id: 'r-1', name: 'Cashier' }] }],
      [/^roles\/r-1$/, { data: { id: 'r-1', name: 'Cashier', permissions: [] } }],
    ],
    { permissions: PERMISSIONS },
  )
  return { set: (next) => (current = next) }
}

const usersName = () => screen.getByRole('textbox', { name: 'Name of Users' })

describe('Navigation editor (LAY-02, LAY-06)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('renames and hides an item and saves the draft', async () => {
    setUp()
    api.post.mockImplementation(async (path, body) => documentWith(body.payload, { revision: 2 }))
    renderApp('/settings/layouts/navigation')

    await waitFor(() => expect(usersName()).toBeEnabled(), { timeout: 5000 })
    fireEvent.change(usersName(), { target: { value: 'Staff' } })
    fireEvent.click(screen.getByRole('button', { name: 'Hide Staff' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('config/navigation', expect.objectContaining({ revision: 1 })))
    const body = api.post.mock.calls[0][1]
    const users = body.payload.groups.flatMap((group) => group.items).find((item) => item.id === '/settings/users')
    expect(users).toEqual({ id: '/settings/users', label: 'Staff', hidden: true })
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled())
  })

  it('does not publish when saving the edits first fails, and shows why', async () => {
    setUp()
    api.post.mockImplementation(async (path) => {
      if (path === 'config/navigation') throw apiError(422, 'validation_failed', 'The menu could not be saved.')
      return documentWith(USERS_RENAMED(null))
    })
    renderApp('/settings/layouts/navigation')

    await waitFor(() => expect(usersName()).toBeEnabled(), { timeout: 5000 })
    fireEvent.change(usersName(), { target: { value: 'Staff' } })
    fireEvent.click(screen.getByRole('button', { name: 'Publish v1' }))

    expect(await screen.findAllByText('The menu could not be saved.')).not.toHaveLength(0)
    expect(api.post).toHaveBeenCalledWith('config/navigation', expect.anything())
    expect(api.post).not.toHaveBeenCalledWith('config/navigation/d-nav/publish', expect.anything())
    // The edit is still there to save again.
    expect(usersName()).toHaveValue('Staff')
  })

  it('shows the draft as it is now after Reload on a conflict', async () => {
    const routes = setUp()
    const theirs = documentWith(USERS_RENAMED('Team'), { revision: 4 })
    // The 409 carries the document as it is now.
    api.post.mockImplementation(async () => {
      routes.set(theirs)
      throw new ApiError({ status: 409, code: 'config_changed', message: 'Someone changed this draft.', data: theirs })
    })
    renderApp('/settings/layouts/navigation')

    await waitFor(() => expect(usersName()).toBeEnabled(), { timeout: 5000 })
    fireEvent.change(usersName(), { target: { value: 'Staff' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))

    const reload = await screen.findByRole('button', { name: 'Reload' })
    fireEvent.click(reload)
    await waitFor(() => expect(usersName()).toHaveValue('Team'))
    expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled()
    expect(screen.queryByText('Someone changed this draft')).not.toBeInTheDocument()
  })

  it('keeps edits made while a save was running unsaved', async () => {
    setUp()
    let finish
    api.post.mockImplementation((path, body) => new Promise((resolve) => (finish = () => resolve(documentWith(body.payload, { revision: 2 })))))
    renderApp('/settings/layouts/navigation')

    await waitFor(() => expect(usersName()).toBeEnabled(), { timeout: 5000 })
    fireEvent.change(usersName(), { target: { value: 'Staff' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1))
    fireEvent.change(usersName(), { target: { value: 'Staff members' } })
    finish()

    await screen.findByText('Draft saved')
    expect(usersName()).toHaveValue('Staff members')
    expect(screen.getByRole('button', { name: 'Save draft' })).toBeEnabled()
  })

  it('asks before switching the scope with unsaved edits', async () => {
    setUp()
    renderApp('/settings/layouts/navigation')

    await waitFor(() => expect(usersName()).toBeEnabled(), { timeout: 5000 })
    await waitForOption('Applies to', 'Role: Cashier')
    fireEvent.change(usersName(), { target: { value: 'Staff' } })
    chooseOption('Applies to', 'Role: Cashier')

    const dialog = await screen.findByRole('dialog', { name: 'Discard unsaved changes' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Keep editing' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByLabelText('Applies to')).toHaveValue('tenant:')
    expect(usersName()).toHaveValue('Staff')

    chooseOption('Applies to', 'Role: Cashier')
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Discard changes' }))
    await waitFor(() => expect(screen.getByLabelText('Applies to')).toHaveValue('role:r-1'))
    await waitFor(() => expect(usersName()).toHaveValue(''))
  })
})
