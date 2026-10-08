import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, optionTexts, waitForOption } from '@/test/combobox'
import { apiError, mockApi, OWNER, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const tenant = (names) => names.map((name) => ({ name, scopes: [{ type: 'tenant', id: 't-1' }] }))
const ADMIN = tenant([
  'core.company.view',
  'core.branch.view',
  'core.location.view',
  'core.user.view',
  'core.user.invite',
  'core.user.edit',
  'core.user.deactivate',
  'core.role.view',
  'core.role.assign',
  'core.access_review.export',
])

const ROLES = [
  { id: 'r-owner', name: 'Owner', is_system: true, is_owner: true, permissions: [] },
  { id: 'r-cashier', name: 'Cashier', is_system: true, is_owner: false, permissions: [] },
]
const COMPANIES = [{ id: 'c-1', name: 'Amani Retail' }]
const BRANCHES = [{ id: 'b-1', company_id: 'c-1', company: { id: 'c-1', name: 'Amani Retail' }, name: 'Westlands', code: 'WSTL' }]
const LOCATIONS = [{ id: 'l-1', branch_id: 'b-1', branch: { id: 'b-1', name: 'Westlands' }, name: 'Front till', type: 'outlet' }]

const assignment = (id, role, scope) => ({ id, role: { id: `r-${role.toLowerCase()}`, name: role, is_owner: role === 'Owner' }, scope, granted_by: null, granted_at: '2026-10-01T08:00:00Z' })
const AMINA = { ...OWNER, created_at: '2026-10-01T08:00:00Z', roles: [assignment('a-1', 'Owner', { type: 'tenant', id: 't-1', name: 'Amani Retail Group' })] }
const JOSEPH = {
  id: 'u-2',
  name: 'Joseph Mwangi',
  email: null,
  phone: '+254712345678',
  locale: 'en',
  status: 'active',
  roles: [assignment('a-2', 'Cashier', { type: 'location', id: 'l-1', name: 'Front till' })],
}
const INVITATIONS = [
  {
    id: 'i-1',
    name: 'Grace Wanjiru',
    email: 'grace@example.com',
    phone: null,
    status: 'pending',
    assignments: [{ role_id: 'r-cashier', scope_type: 'location', scope_id: 'l-1' }],
    expires_at: '2026-10-15T08:00:00Z',
  },
  { id: 'i-2', name: 'Old invite', email: 'old@example.com', phone: null, status: 'accepted', assignments: [], expires_at: '2026-10-02T08:00:00Z' },
]

function users({ permissions = ADMIN, invitations = INVITATIONS, extra = {} } = {}) {
  mockApi(api, {
    permissions,
    companies: COMPANIES,
    extra: {
      'users?status=active&per_page=25&page=1': { data: [AMINA, JOSEPH], meta: { last_page: 1, total: 2, from: 1, to: 2 } },
      'users?status=deactivated&per_page=25&page=1': { data: [], meta: { last_page: 1, total: 0 } },
      'invitations?per_page=25&page=1': () => {
        const data = typeof invitations === 'function' ? invitations() : invitations
        return { data, meta: { last_page: 1, total: data.length, from: data.length ? 1 : null, to: data.length } }
      },
      'roles?per_page=200': { data: ROLES },
      'companies?per_page=200': { data: COMPANIES },
      'branches?per_page=200': { data: BRANCHES },
      'locations?per_page=200': { data: LOCATIONS },
      // The signed-in owner's own record says they hold the Owner role.
      'users/u-1': { data: AMINA },
      ...extra,
    },
  })
}

describe('Users', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists users with contact, roles with their scope names and status', async () => {
    users()
    renderApp('/settings/users')

    const row = (await screen.findByText('Joseph Mwangi')).closest('tr')
    expect(within(row).getByText('+254712345678')).toBeInTheDocument()
    expect(within(row).getByText('Cashier at Front till')).toBeInTheDocument()
    expect(within(row).getByText('Active')).toBeInTheDocument()
    expect(screen.getByText('Owner at Amani Retail Group')).toBeInTheDocument()
    expect(screen.getByText('Showing 1–2 of 2')).toBeInTheDocument()
  })

  it('searches, sorts by a header, changes rows per page and exports the visible columns (EXP-01)', async () => {
    users()
    // Every users page answers with the same two people.
    const fallback = api.get.getMockImplementation()
    api.get.mockImplementation(async (path) => (path.startsWith('users?') ? { data: [AMINA, JOSEPH], meta: { last_page: 1, total: 2, from: 1, to: 2 } } : fallback(path)))
    api.download.mockResolvedValue({ blob: new Blob(['x']), filename: 'users-2026-10-08.csv' })
    URL.createObjectURL = vi.fn(() => 'blob:users')
    URL.revokeObjectURL = vi.fn()
    const usersCalls = () => api.get.mock.calls.map(([path]) => path).filter((path) => path.startsWith('users?'))
    renderApp('/settings/users')
    const table = await screen.findByRole('table', { name: 'Active' })
    await within(table).findByText('Joseph Mwangi')

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'joseph' } })
    await waitFor(() => expect(usersCalls().at(-1)).toBe('users?status=active&search=joseph&per_page=25&page=1'))

    fireEvent.click(within(table).getByRole('button', { name: 'Email' }))
    await waitFor(() => expect(usersCalls().at(-1)).toBe('users?status=active&search=joseph&sort=email&per_page=25&page=1'))
    expect(within(table).getByRole('columnheader', { name: /Email/ })).toHaveAttribute('aria-sort', 'ascending')

    chooseOption('Rows per page', '50')
    await waitFor(() => expect(usersCalls().at(-1)).toBe('users?status=active&search=joseph&sort=email&per_page=50&page=1'))

    fireEvent.pointerDown(screen.getByRole('button', { name: 'Export' }), { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'CSV' }))
    await waitFor(() => expect(api.download).toHaveBeenCalled())
    const [path] = api.download.mock.calls[0]
    const params = new URLSearchParams(path.split('?')[1])
    expect(path.startsWith('users?')).toBe(true)
    expect(params.get('status')).toBe('active')
    expect(params.get('format')).toBe('csv')
    expect(params.get('sort')).toBe('email')
    expect(params.get('page')).toBeNull()
    expect(params.getAll('columns[]')).toEqual(['name', 'email', 'phone', 'roles', 'status'])
  })

  it('switches to deactivated users and shows the empty sentence', async () => {
    users()
    renderApp('/settings/users')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Deactivated' }))
    expect(await screen.findByText('No one has been deactivated.')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('users?status=deactivated&per_page=25&page=1')
  })

  it('opens a user from the list', async () => {
    users({ extra: { 'users/u-2': { data: JOSEPH } } })
    const { router } = renderApp('/settings/users')
    fireEvent.click(await screen.findByText('Joseph Mwangi'))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/users/u-2'))
  })

  it('shows invitations with role and place names and their status, and revokes a pending one after confirming', async () => {
    let invitations = INVITATIONS
    users({ invitations: () => invitations })
    api.post.mockImplementation(async () => {
      invitations = []
      return { data: {} }
    })
    renderApp('/settings/users?tab=invitations')

    const row = (await screen.findByText('Grace Wanjiru')).closest('tr')
    expect(await within(row).findByText('Cashier at Front till · Westlands')).toBeInTheDocument()
    expect(within(row).getByText('15 Oct 2026')).toBeInTheDocument()
    expect(within(row).getByText('Pending')).toBeInTheDocument()
    // Accepted and revoked invitations stay listed, without a Revoke button.
    const old = screen.getByText('Old invite').closest('tr')
    expect(within(old).getByText('Accepted')).toBeInTheDocument()
    expect(within(old).queryByRole('button', { name: /Revoke/ })).not.toBeInTheDocument()

    fireEvent.click(within(row).getByRole('button', { name: 'Revoke the invitation for Grace Wanjiru' }))
    const dialog = await screen.findByRole('dialog', { name: 'Revoke the invitation for Grace Wanjiru?' })
    expect(api.post).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke invitation' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('invitations/i-1/revoke'))
    expect(await screen.findByText('No invitations yet. Invite someone to give them access.')).toBeInTheDocument()
  })

  it('hides invite and export without the permissions', async () => {
    users({ permissions: tenant(['core.user.view']) })
    renderApp('/settings/users')
    await screen.findByText('Joseph Mwangi')
    expect(screen.queryByRole('button', { name: 'Invite user' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Export access review' })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Invitations' })).not.toBeInTheDocument()
  })

  it('downloads the access review CSV with the bearer token through the client', async () => {
    users()
    const blob = new Blob(['user_name\n'], { type: 'text/csv' })
    api.download.mockResolvedValue({ blob, filename: null })
    const createObjectURL = vi.fn(() => 'blob:review')
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }))
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
      expect(this.download).toMatch(/^access-review-\d{4}-\d{2}-\d{2}\.csv$/)
      expect(this.href).toBe('blob:review')
    })
    renderApp('/settings/users')

    fireEvent.click(await screen.findByRole('button', { name: 'Export access review' }))
    await waitFor(() => expect(click).toHaveBeenCalled())
    expect(api.download).toHaveBeenCalledWith('access-review?format=csv')
    expect(createObjectURL).toHaveBeenCalledWith(blob)
    await waitFor(() => expect(revokeObjectURL).toHaveBeenCalledWith('blob:review'))
    click.mockRestore()
    vi.unstubAllGlobals()
  })

  it('falls back to Active for a tab the user cannot see', async () => {
    users({ permissions: tenant(['core.user.view']) })
    renderApp('/settings/users?tab=invitations')
    expect(await screen.findByText('Joseph Mwangi')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Active' })).toHaveAttribute('aria-selected', 'true')
    expect(api.get.mock.calls.some(([path]) => path.startsWith('invitations'))).toBe(false)
  })

  it('offers an invitation from the empty Active list', async () => {
    users({ extra: { 'users?status=active&per_page=25&page=1': { data: [], meta: { last_page: 1, total: 0 } } } })
    const { router } = renderApp('/settings/users')
    const empty = (await screen.findByText('No active users in your part of the organisation yet.')).closest('td')
    fireEvent.click(within(empty).getByRole('button', { name: 'Invite user' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/users/invite'))
  })

  it('explains a refused export in a sentence', async () => {
    users()
    api.download.mockRejectedValue(apiError(403, 'forbidden', 'This action is unauthorized.'))
    renderApp('/settings/users')
    fireEvent.click(await screen.findByRole('button', { name: 'Export access review' }))
    expect(await screen.findByText('You don’t have permission to do this. Ask an administrator if you need it.')).toBeInTheDocument()
  })
})

describe('InviteUser', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('opens from Users and sends name, contact and role rows with scopes', async () => {
    users()
    api.post.mockResolvedValue({ data: { id: 'i-9' } })
    const { router } = renderApp('/settings/users')

    fireEvent.click(await screen.findByRole('button', { name: 'Invite user' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/users/invite'))
    await screen.findByRole('heading', { name: 'Invite a user' })

    fireEvent.change(screen.getByLabelText(/Full name/), { target: { value: 'Grace Wanjiru' } })
    fireEvent.change(screen.getByLabelText(/Email or phone number/), { target: { value: 'grace@example.com' } })

    const first = screen.getByRole('group', { name: 'Role 1' })
    await waitForOption(within(first).getByLabelText(/^Role/), 'Cashier')
    chooseOption(within(first).getByLabelText(/^Role/), 'Cashier')
    expect(within(first).getByLabelText(/Applies to/)).toHaveValue('location')
    await waitForOption(within(first).getByLabelText(/^Location/), 'Front till · Westlands')
    chooseOption(within(first).getByLabelText(/^Location/), 'Front till · Westlands')

    fireEvent.click(screen.getByRole('button', { name: 'Add another role' }))
    const second = screen.getByRole('group', { name: 'Role 2' })
    chooseOption(within(second).getByLabelText(/^Role/), 'Cashier')
    chooseOption(within(second).getByLabelText(/Applies to/), 'Branch')
    chooseOption(within(second).getByLabelText(/^Branch/), 'Westlands · Amani Retail')

    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('invitations', {
        name: 'Grace Wanjiru',
        email: 'grace@example.com',
        assignments: [
          { role_id: 'r-cashier', scope_type: 'location', scope_id: 'l-1' },
          { role_id: 'r-cashier', scope_type: 'branch', scope_id: 'b-1' },
        ],
      }),
    )
    await waitFor(() => expect(router.state.location.search).toBe('?tab=invitations'))
    expect(await screen.findByText('Invitation sent to Grace Wanjiru. It is valid for 7 days.')).toBeInTheDocument()
  })

  it('never widens a row to the whole organisation while the place lists are loading', async () => {
    let release
    const pending = new Promise((resolve) => (release = resolve))
    users({ extra: { 'locations?per_page=200': () => pending.then(() => ({ data: LOCATIONS })) } })
    renderApp('/settings/users/invite')

    const row = await screen.findByRole('group', { name: 'Role 1' })
    // While the place lists load, the row cannot be filled in at all.
    expect(within(row).getByLabelText(/^Role/)).toBeDisabled()
    expect(within(row).getByLabelText(/Applies to/)).toBeDisabled()
    release()
    await waitFor(() => expect(within(row).getByLabelText(/^Role/)).toBeEnabled())
    chooseOption(within(row).getByLabelText(/^Role/), 'Cashier')
    await waitForOption(within(row).getByLabelText(/^Location/), 'Front till · Westlands')
    expect(within(row).getByLabelText(/Applies to/)).toHaveValue('location')
  })

  it('offers the whole organisation only to a tenant-wide assigner, and sends a phone number', async () => {
    users()
    api.post.mockResolvedValue({ data: { id: 'i-9' } })
    renderApp('/settings/users/invite')

    const row = await screen.findByRole('group', { name: 'Role 1' })
    await waitForOption(within(row).getByLabelText(/^Role/), 'Owner')
    expect(optionTexts(within(row).getByLabelText(/Applies to/))).toContain('Whole organisation')
    fireEvent.change(screen.getByLabelText(/Full name/), { target: { value: 'Peter' } })
    fireEvent.change(screen.getByLabelText(/Email or phone number/), { target: { value: '+254 712 000 111' } })
    chooseOption(within(row).getByLabelText(/^Role/), 'Owner')
    chooseOption(within(row).getByLabelText(/Applies to/), 'Whole organisation')
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('invitations', {
        name: 'Peter',
        phone: '+254712000111',
        assignments: [{ role_id: 'r-owner', scope_type: 'tenant', scope_id: null }],
      }),
    )
  })

  it('offers a Branch Manager only roles they hold and places in their branch', async () => {
    const branch = (names) => names.map((name) => ({ name, scopes: [{ type: 'branch', id: 'b-1' }] }))
    users({
      permissions: branch(['core.company.view', 'core.branch.view', 'core.location.view', 'core.user.view', 'core.user.invite', 'core.role.view', 'core.role.assign']),
      extra: {
        'users/u-1': { data: { ...AMINA, roles: [assignment('a-9', 'Branch Manager', { type: 'branch', id: 'b-1', name: 'Westlands' })] } },
        'roles?per_page=200': {
          data: [
            { id: 'r-owner', name: 'Owner', is_system: true, is_owner: true, permissions: ['core.location.view'] },
            { id: 'r-admin', name: 'Admin', is_system: true, is_owner: false, permissions: ['core.location.view', 'core.company.create'] },
            { id: 'r-cashier', name: 'Cashier', is_system: true, is_owner: false, permissions: ['core.location.view'] },
          ],
        },
        'branches?per_page=200': {
          data: [...BRANCHES, { id: 'b-2', company_id: 'c-1', company: { id: 'c-1', name: 'Amani Retail' }, name: 'Gombe', code: 'GMB' }],
        },
        'locations?per_page=200': {
          data: [...LOCATIONS, { id: 'l-2', branch_id: 'b-2', branch: { id: 'b-2', name: 'Gombe' }, name: 'Gombe till', type: 'outlet' }],
        },
      },
    })
    renderApp('/settings/users/invite')

    const row = await screen.findByRole('group', { name: 'Role 1' })
    await waitForOption(within(row).getByLabelText(/^Location/), 'Front till · Westlands')
    expect(optionTexts(within(row).getByLabelText(/^Role/))).toEqual(['Cashier'])
    expect(optionTexts(within(row).getByLabelText(/Applies to/))).toEqual(['Branch', 'Location'])
    expect(optionTexts(within(row).getByLabelText(/^Location/))).not.toContain('Gombe till · Gombe')
    chooseOption(within(row).getByLabelText(/Applies to/), 'Branch')
    const branches = optionTexts(within(row).getByLabelText(/^Branch/))
    expect(branches).toContain('Westlands · Amani Retail')
    expect(branches).not.toContain('Gombe · Amani Retail')
  })

  it('shows field errors under the fields and cannot_grant as a sentence', async () => {
    const scoped = [
      { name: 'core.user.view', scopes: [{ type: 'branch', id: 'b-1' }] },
      { name: 'core.user.invite', scopes: [{ type: 'branch', id: 'b-1' }] },
      { name: 'core.role.assign', scopes: [{ type: 'branch', id: 'b-1' }] },
      { name: 'core.role.view', scopes: [{ type: 'branch', id: 'b-1' }] },
    ]
    users({ permissions: scoped })
    renderApp('/settings/users/invite')
    const row = await screen.findByRole('group', { name: 'Role 1' })
    await waitForOption(within(row).getByLabelText(/Applies to/), 'Location')
    expect(optionTexts(within(row).getByLabelText(/Applies to/))).not.toContain('Whole organisation')

    api.post.mockRejectedValueOnce(
      apiError(422, 'validation_failed', 'Some fields need attention.', {
        email: ['This email address is already registered.'],
        'assignments.0.role_id': ['The role field is required.'],
      }),
    )
    fireEvent.change(screen.getByLabelText(/Email or phone number/), { target: { value: 'taken@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }))
    expect(await screen.findByText('This email address is already registered.')).toBeInTheDocument()
    expect(within(row).getByText('The role field is required.')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    api.post.mockRejectedValueOnce(apiError(403, 'cannot_grant', 'You can only give roles...'))
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('You can only give roles whose permissions you hold, where you manage access.')
  })
})
