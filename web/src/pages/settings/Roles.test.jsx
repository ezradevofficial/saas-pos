import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockApi, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const tenant = (names) => names.map((name) => ({ name, scopes: [{ type: 'tenant', id: 't-1' }] }))
const MANAGER = tenant(['core.role.view', 'core.role.create', 'core.role.edit', 'core.role.archive', 'core.user.view'])

const CASHIER = {
  id: 'r-cashier',
  name: 'Cashier',
  description: 'Sells at the till',
  is_system: true,
  is_owner: false,
  requires_two_factor: false,
  archived_at: null,
  permissions: ['core.device.view', 'core.location.view'],
}
const SUPERVISOR = {
  id: 'r-sup',
  name: 'Supervisor',
  description: null,
  is_system: false,
  is_owner: false,
  requires_two_factor: true,
  archived_at: null,
  permissions: ['core.location.view'],
}
const action = (resource, name, label) => ({ action: name, name: `core.${resource}.${name}`, label })
const CATALOGUE = {
  core: {
    label: 'Core',
    resources: {
      access_review: { label: 'Access review', actions: [action('access_review', 'export', 'Export'), action('access_review', 'view', 'View')] },
      location: { label: 'Locations', actions: [action('location', 'view', 'View'), action('location', 'create', 'Create'), action('location', 'edit', 'Edit')] },
      device: { label: 'POS devices', actions: [action('device', 'view', 'View'), action('device', 'pair', 'Pair')] },
    },
  },
}

function roles({ permissions = MANAGER, extra = {} } = {}) {
  mockApi(api, {
    permissions,
    extra: {
      'roles?per_page=200': { data: [CASHIER, SUPERVISOR] },
      'roles/r-cashier': { data: CASHIER },
      'roles/r-sup': { data: SUPERVISOR },
      permissions: { data: CATALOGUE },
      ...extra,
    },
  })
}

describe('Roles', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists roles, marks system roles and offers Copy only on them', async () => {
    roles()
    renderApp('/settings/roles')

    const cashier = (await screen.findByText('Cashier')).closest('tr')
    expect(within(cashier).getByText('System')).toBeInTheDocument()
    expect(within(cashier).getByText('2 permissions')).toBeInTheDocument()
    expect(within(cashier).getByRole('button', { name: 'Copy Cashier' })).toBeInTheDocument()
    const supervisor = screen.getByText('Supervisor').closest('tr')
    expect(within(supervisor).queryByText('System')).not.toBeInTheDocument()
    expect(within(supervisor).getByText('1 permission')).toBeInTheDocument()
    expect(within(supervisor).getByText('Required')).toBeInTheDocument()
    expect(within(supervisor).queryByRole('button', { name: /Copy/ })).not.toBeInTheDocument()
  })

  it('copies a system role and opens the copy', async () => {
    roles({ extra: { 'roles/r-copy': { data: { ...CASHIER, id: 'r-copy', name: 'Cashier (copy)', is_system: false } } } })
    api.post.mockResolvedValue({ data: { id: 'r-copy' } })
    const { router } = renderApp('/settings/roles')

    fireEvent.click(await screen.findByRole('button', { name: 'Copy Cashier' }))
    const dialog = await screen.findByRole('dialog', { name: 'Copy Cashier' })
    expect(within(dialog).getByLabelText(/Role name/)).toHaveValue('Cashier (copy)')
    fireEvent.change(within(dialog).getByLabelText(/Role name/), { target: { value: 'Senior cashier' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Copy role' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('roles/r-cashier/copy', { name: 'Senior cashier' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/roles/r-copy'))
  })

  it('shows a system role read-only with a Copy button', async () => {
    roles()
    renderApp('/settings/roles/r-cashier')

    expect(await screen.findByRole('heading', { name: 'Cashier' })).toBeInTheDocument()
    expect(screen.getByText('System roles can’t be changed')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Copy' })).toBeInTheDocument()
    expect(screen.getByLabelText(/Role name/)).toBeDisabled()
    expect(screen.getByRole('checkbox', { name: 'Locations: View' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Locations: View' })).toBeDisabled()
    expect(screen.getByRole('switch', { name: 'Require two-factor sign-in' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Archive' })).not.toBeInTheDocument()
  })

  it('edits a custom role’s permission matrix grouped by module and saves', async () => {
    roles()
    api.patch.mockResolvedValue({ data: SUPERVISOR })
    renderApp('/settings/roles/r-sup')

    const module = await screen.findByRole('region', { name: 'Core' })
    const devices = within(module).getByRole('row', { name: /POS devices/ })
    expect(within(devices).getAllByRole('checkbox')).toHaveLength(2)
    // No checkbox where the resource lacks the action (devices have no Create or Edit).
    expect(within(devices).getAllByRole('cell')).toHaveLength(5)
    // The lifecycle actions lead, whatever order the catalogue lists them in.
    expect(within(module).getAllByRole('columnheader').map((cell) => cell.textContent)).toEqual(['Resource', 'View', 'Create', 'Edit', 'Export', 'Pair'])

    fireEvent.click(within(module).getByRole('checkbox', { name: 'POS devices: Pair' }))
    fireEvent.click(within(module).getByRole('checkbox', { name: 'Locations: View' }))
    fireEvent.change(screen.getByLabelText(/^Description/), { target: { value: 'Runs the floor' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() =>
      expect(api.patch).toHaveBeenCalledWith('roles/r-sup', { name: 'Supervisor', description: 'Runs the floor', permissions: ['core.device.pair'] }),
    )
    expect(await screen.findByText('Role saved.')).toBeInTheDocument()
  })

  it('applies the two-factor switch at once', async () => {
    roles()
    api.patch.mockResolvedValue({ data: { ...SUPERVISOR, requires_two_factor: false } })
    renderApp('/settings/roles/r-sup')

    const toggle = await screen.findByRole('switch', { name: 'Require two-factor sign-in' })
    expect(toggle).toBeChecked()
    fireEvent.click(toggle)
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('roles/r-sup', { requires_two_factor: false }))
    expect(await screen.findByText('Two-factor sign-in is no longer required for this role.')).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Require two-factor sign-in' })).not.toBeChecked()
  })

  it('shows cannot_grant as a sentence when saving permissions the user does not hold', async () => {
    roles()
    api.patch.mockRejectedValue(apiError(403, 'cannot_grant', 'You can only give roles…'))
    renderApp('/settings/roles/r-sup')
    fireEvent.click(await screen.findByRole('checkbox', { name: 'POS devices: Pair' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('You can only give roles whose permissions you hold')
  })

  it('archives a custom role after confirming', async () => {
    roles()
    api.post.mockResolvedValue({ data: { ...SUPERVISOR, archived_at: '2026-10-08T08:00:00Z' } })
    const { router } = renderApp('/settings/roles/r-sup')

    fireEvent.click(await screen.findByRole('button', { name: 'Archive' }))
    const dialog = await screen.findByRole('dialog', { name: 'Archive Supervisor?' })
    expect(api.post).not.toHaveBeenCalled()
    const confirm = within(dialog).getByRole('button', { name: 'Archive role' })
    expect(confirm).toHaveAttribute('data-ds-variant', 'danger')
    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('roles/r-sup/archive'))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/roles'))
  })

  it('creates a role with two-factor as a checkbox saved with the form', async () => {
    roles({ extra: { 'roles/r-new': { data: { ...SUPERVISOR, id: 'r-new', name: 'Auditor' } } } })
    api.post.mockResolvedValue({ data: { id: 'r-new' } })
    const { router } = renderApp('/settings/roles')

    fireEvent.click(await screen.findByRole('button', { name: 'Create role' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/roles/new'))
    fireEvent.change(await screen.findByLabelText(/Role name/), { target: { value: 'Auditor' } })
    fireEvent.click(screen.getByRole('checkbox', { name: /Require two-factor sign-in/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Locations: View' }))
    fireEvent.click(screen.getByRole('button', { name: 'Create role' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('roles', { name: 'Auditor', description: null, permissions: ['core.location.view'], requires_two_factor: true }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/roles/r-new'))
  })

  it('offers Create role from the empty list', async () => {
    roles({ extra: { 'roles?per_page=200': { data: [] } } })
    const { router } = renderApp('/settings/roles')
    const empty = (await screen.findByText('No roles yet. Create one to group permissions.')).closest('td')
    fireEvent.click(within(empty).getByRole('button', { name: 'Create role' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/roles/new'))
  })

  it('hides create, copy and editing from a view-only user', async () => {
    roles({ permissions: tenant(['core.role.view']) })
    renderApp('/settings/roles/r-sup')
    expect(await screen.findByLabelText(/Role name/)).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Copy' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Archive' })).not.toBeInTheDocument()
  })

  it('refuses the new-role page to someone who may create roles only below tenant scope', async () => {
    roles({
      permissions: [
        ...tenant(['core.role.view']),
        { name: 'core.role.create', scopes: [{ type: 'branch', id: 'b-1' }] },
      ],
    })
    renderApp('/settings/roles/new')
    expect(await screen.findByRole('heading', { level: 1, name: 'You do not have access to this page' })).toBeInTheDocument()
    expect(screen.queryByLabelText(/Role name/)).not.toBeInTheDocument()
  })

  it('shows a role’s history in its own tab, permissions by name (MD-07)', async () => {
    roles({ extra: { 'history/role/r-sup?per_page=20&page=1': { data: [{ id: 'h-1', action: 'rbac.role.permissions_update', actor: { id: 'u-1', name: 'Amina Otieno' }, before: { permissions: ['core.user.view'] }, after: { permissions: ['core.user.view', 'core.user.edit'] }, occurred_at: '2026-10-08T08:00:00Z' }], meta: { current_page: 1, last_page: 1 } } } })
    renderApp('/settings/roles/r-sup')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'History' }))
    const list = await screen.findByRole('list', { name: 'History' })
    expect(within(list).getByText('Permissions changed')).toBeInTheDocument()
    expect(within(list).getByText('core.user.view, core.user.edit')).toBeInTheDocument()
  })
})
