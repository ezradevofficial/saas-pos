import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { apiError, mockApi, OWNER, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const tenant = (names) => names.map((name) => ({ name, scopes: [{ type: 'tenant', id: 't-1' }] }))
const ADMIN = tenant(['core.user.view', 'core.user.edit', 'core.user.deactivate', 'core.role.view', 'core.role.assign', 'core.location.view'])

const ROLES = [{ id: 'r-cashier', name: 'Cashier', is_system: true, permissions: [] }]
const LOCATIONS = [
  { id: 'l-1', branch_id: 'b-1', branch: { id: 'b-1', name: 'Westlands' }, name: 'Front till', type: 'outlet' },
  { id: 'l-2', branch_id: 'b-1', branch: { id: 'b-1', name: 'Westlands' }, name: 'Back store', type: 'store' },
]
const CASHIER_AT_FRONT = {
  id: 'a-2',
  role: { id: 'r-cashier', name: 'Cashier', is_owner: false },
  scope: { type: 'location', id: 'l-1', name: 'Front till' },
  granted_by: { id: 'u-1', name: 'Amina Otieno' },
  granted_at: '2026-10-07T09:00:00Z',
}
const JOSEPH = { id: 'u-2', name: 'Joseph Mwangi', email: 'joseph@example.com', phone: null, locale: 'en', status: 'active', roles: [CASHIER_AT_FRONT] }

function detail(user = JOSEPH, { permissions = ADMIN } = {}) {
  let current = user
  mockApi(api, {
    permissions,
    extra: {
      [`users/${user.id}`]: () => ({ data: current }),
      // RBAC-04: the roles list is its own server list.
      [`users/${user.id}/assignments?per_page=25&page=1`]: () => ({
        data: current.roles ?? [],
        meta: { last_page: 1, total: current.roles?.length ?? 0, from: 1, to: current.roles?.length ?? 0 },
      }),
      'roles?per_page=200': { data: ROLES },
      'companies?per_page=200': { data: [] },
      'branches?per_page=200': { data: [] },
      'locations?per_page=200': { data: LOCATIONS },
    },
  })
  return { update: (next) => (current = next) }
}

describe('UserDetail', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the profile, roles with where and who gave them, and saves name and language', async () => {
    detail()
    api.patch.mockResolvedValue({ data: { ...JOSEPH, name: 'Joseph K. Mwangi', locale: 'fr' } })
    renderApp('/settings/users/u-2')

    expect(await screen.findByRole('heading', { name: 'Joseph Mwangi' })).toBeInTheDocument()
    expect(screen.getByText('joseph@example.com')).toBeInTheDocument()
    const row = (await screen.findByText('Cashier')).closest('tr')
    expect(within(row).getByText('Front till')).toBeInTheDocument()
    expect(within(row).getByText('by Amina Otieno')).toBeInTheDocument()
    expect(within(row).getByText('7 Oct 2026')).toBeInTheDocument()
    expect(screen.getByText('Showing 1–1 of 1')).toBeInTheDocument()
    // Who gave the role can be its own column (export key granted_by); hidden by default.
    expect(screen.queryByRole('columnheader', { name: 'Given by' })).not.toBeInTheDocument()
    fireEvent.pointerDown(screen.getByRole('button', { name: 'Columns' }), { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('menuitemcheckbox', { name: 'Given by' }))
    expect(screen.getByRole('columnheader', { name: 'Given by', hidden: true })).toBeInTheDocument()
    fireEvent.keyDown(document.activeElement, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('menu')).not.toBeInTheDocument())

    fireEvent.change(screen.getByLabelText(/Full name/), { target: { value: 'Joseph K. Mwangi' } })
    chooseOption(screen.getByLabelText(/Language/), 'Français')
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('users/u-2', { name: 'Joseph K. Mwangi', locale: 'fr' }))
    expect(await screen.findByText('Profile saved.')).toBeInTheDocument()
  })

  it('asks before deactivating, with a danger button that names the person', async () => {
    const user = detail()
    api.post.mockImplementation(async () => {
      user.update({ ...JOSEPH, status: 'deactivated' })
      return { data: { ...JOSEPH, status: 'deactivated' } }
    })
    renderApp('/settings/users/u-2')

    fireEvent.click(await screen.findByRole('button', { name: 'Deactivate' }))
    const dialog = await screen.findByRole('dialog', { name: 'Deactivate Joseph Mwangi?' })
    expect(api.post).not.toHaveBeenCalled()
    expect(within(dialog).getByText(/signed out everywhere at once/)).toBeInTheDocument()
    const confirm = within(dialog).getByRole('button', { name: 'Deactivate Joseph' })
    expect(confirm).toHaveAttribute('data-ds-variant', 'danger')
    fireEvent.click(confirm)

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('users/u-2/deactivate'))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await screen.findByRole('button', { name: 'Reactivate' })).toBeInTheDocument()
    expect(screen.getAllByText('Deactivated').length).toBeGreaterThan(0)
  })

  it('keeps the dialog open and explains last_owner when the last Owner deactivates themself', async () => {
    const me = { ...OWNER, roles: [{ ...CASHIER_AT_FRONT, id: 'a-1', role: { id: 'r-owner', name: 'Owner', is_owner: true }, scope: { type: 'tenant', id: 't-1', name: 'Amani Retail Group' } }] }
    detail(me)
    api.post.mockRejectedValue(apiError(422, 'last_owner', 'Your organisation needs at least one active Owner.'))
    renderApp('/settings/users/u-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Deactivate' }))
    const dialog = await screen.findByRole('dialog', { name: 'Deactivate Amina Otieno?' })
    expect(within(dialog).getByText(/You will be signed out at once/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Deactivate Amina' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Your organisation needs at least one active Owner. Give the Owner role to someone else first.',
    )
    await waitFor(() => expect(document.activeElement).toBe(within(dialog).getByRole('alert').parentElement))
  })

  it('reactivates after confirming, and explains contact_unverified', async () => {
    detail({ ...JOSEPH, status: 'deactivated' })
    api.post.mockRejectedValue(apiError(422, 'contact_unverified', 'This user never verified…'))
    renderApp('/settings/users/u-2')

    fireEvent.click(await screen.findByRole('button', { name: 'Reactivate' }))
    const dialog = await screen.findByRole('dialog', { name: 'Reactivate Joseph Mwangi?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reactivate Joseph' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('users/u-2/reactivate'))
    expect(await within(dialog).findByText(/never verified an email or phone number/)).toBeInTheDocument()
  })

  it('signs the user out everywhere after confirming', async () => {
    detail()
    api.post.mockResolvedValue(null)
    renderApp('/settings/users/u-2')

    fireEvent.click(await screen.findByRole('button', { name: 'Sign out everywhere' }))
    const dialog = await screen.findByRole('dialog', { name: 'Sign Joseph Mwangi out everywhere?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Sign out everywhere' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('users/u-2/sign-out-everywhere'))
    expect(await screen.findByText('Joseph has been signed out everywhere.')).toBeInTheDocument()
  })

  it('gives a role at a place and removes one after confirming', async () => {
    const user = detail()
    api.post.mockImplementation(async () => {
      user.update({ ...JOSEPH, roles: [CASHIER_AT_FRONT, { ...CASHIER_AT_FRONT, id: 'a-3', scope: { type: 'location', id: 'l-2', name: 'Back store' } }] })
      return { data: {} }
    })
    api.delete.mockImplementation(async () => {
      user.update({ ...JOSEPH, roles: [] })
      return null
    })
    renderApp('/settings/users/u-2')

    fireEvent.click(await screen.findByRole('button', { name: 'Give a role' }))
    const row = await screen.findByRole('group', { name: 'Role 2' })
    await waitForOption(within(row).getByLabelText(/^Location/), 'Back store · Westlands')
    chooseOption(within(row).getByLabelText(/^Role/), 'Cashier')
    chooseOption(within(row).getByLabelText(/^Location/), 'Back store · Westlands')
    fireEvent.click(screen.getByRole('button', { name: 'Give role' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('users/u-2/assignments', { role_id: 'r-cashier', scope_type: 'location', scope_id: 'l-2' }))
    expect(await screen.findByText('Back store')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Remove Cashier at Front till' }))
    const dialog = await screen.findByRole('dialog', { name: 'Remove Cashier at Front till from Joseph Mwangi?' })
    expect(api.delete).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove role' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('assignments/a-2'))
    expect(await screen.findByText('No roles yet. Give one so this person can work.')).toBeInTheDocument()
  })

  it('shows a refused grant as a sentence', async () => {
    detail()
    api.post.mockRejectedValue(apiError(403, 'cannot_grant', 'You can only give roles…'))
    renderApp('/settings/users/u-2')
    fireEvent.click(await screen.findByRole('button', { name: 'Give a role' }))
    const row = await screen.findByRole('group', { name: 'Role 2' })
    await waitForOption(within(row).getByLabelText(/^Role/), 'Cashier')
    chooseOption(within(row).getByLabelText(/^Role/), 'Cashier')
    chooseOption(within(row).getByLabelText(/^Location/), 'Back store · Westlands')
    fireEvent.click(screen.getByRole('button', { name: 'Give role' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Only an Owner can give or remove the Owner role.')
  })

  it('shows a read-only profile without edit, deactivate or role permissions', async () => {
    detail(JOSEPH, { permissions: tenant(['core.user.view']) })
    renderApp('/settings/users/u-2')
    await screen.findByRole('heading', { name: 'Joseph Mwangi' })
    expect(screen.getByLabelText(/Full name/)).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Deactivate' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Give a role' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Remove/ })).not.toBeInTheDocument()
  })

  it('shows the user’s history in its own tab (MD-07)', async () => {
    detail()
    const get = api.get.getMockImplementation()
    api.get.mockImplementation(async (path) =>
      path === 'history/user/u-2?per_page=20&page=1' ? { data: [{ id: 'h-1', action: 'core.user.deactivate', actor: { id: 'u-1', name: 'Amina Otieno' }, before: { status: 'active' }, after: { status: 'deactivated' }, occurred_at: '2026-10-08T08:00:00Z' }], meta: { current_page: 1, last_page: 1 } } : get(path),
    )
    renderApp('/settings/users/u-2')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'History' }))
    const list = await screen.findByRole('list', { name: 'History' })
    expect(within(list).getByText('Deactivated')).toBeInTheDocument()
    expect(within(list).getByText('Amina Otieno')).toBeInTheDocument()
  })
})
