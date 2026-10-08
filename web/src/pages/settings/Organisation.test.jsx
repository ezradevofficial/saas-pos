import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockApi, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { chooseOption } from '@/test/combobox'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const tenant = (names) => names.map((name) => ({ name, scopes: [{ type: 'tenant', id: 't-1' }] }))
const OWNER_PERMISSIONS = tenant(
  ['company', 'branch', 'location', 'device'].flatMap((resource) => ['view', 'create', 'edit', 'archive'].map((action) => `core.${resource}.${action}`)).concat(['core.device.pair', 'core.user.view', 'core.role.view']),
)

const COMPANIES = [{ id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', archived_at: null }]
const BRANCHES = [
  { id: 'b-1', company_id: 'c-1', company: { id: 'c-1', name: 'Amani Retail' }, name: 'Westlands', code: 'WSTL', archived_at: null },
  { id: 'b-2', company_id: 'c-1', company: { id: 'c-1', name: 'Amani Retail' }, name: 'Old Town', code: 'OLD', archived_at: '2026-10-01T08:00:00Z' },
]
const LOCATIONS = [{ id: 'l-1', branch_id: 'b-1', branch: { id: 'b-1', name: 'Westlands' }, name: 'Front till', type: 'outlet', archived_at: null }]
const DEVICES = [
  { id: 'd-1', location_id: 'l-1', name: 'Till 1', status: 'active', paired_at: '2026-10-05T08:00:00Z', last_seen_at: '2026-10-07T14:05:00Z' },
  { id: 'd-2', location_id: 'l-1', name: 'Till 2', status: 'pending', paired_at: null, last_seen_at: null },
]

function organisation({ permissions = OWNER_PERMISSIONS, companies = COMPANIES, branches = BRANCHES, locations = LOCATIONS, devices = DEVICES } = {}) {
  mockApi(api, {
    permissions,
    companies,
    extra: {
      'companies?status=all&per_page=200': () => ({ data: companies }),
      'branches?status=all&per_page=200': () => ({ data: branches }),
      'locations?status=all&per_page=200': () => ({ data: typeof locations === 'function' ? locations() : locations }),
      'locations/l-1/devices?per_page=200': () => ({ data: devices }),
    },
  })
}

describe('Organisation', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows companies, branches and locations as a tree with codes, types and status', async () => {
    organisation()
    renderApp('/settings/organisation')

    const branches = await screen.findByRole('list', { name: 'Branches of Amani Retail' })
    const westlands = within(branches).getByText('Westlands').closest('li')
    expect(within(westlands).getByText('Code WSTL')).toBeInTheDocument()
    expect(within(westlands).getAllByText('Active').length).toBeGreaterThan(0)
    const locations = within(westlands).getByRole('list', { name: 'Locations of Westlands' })
    expect(within(locations).getByText('Front till')).toBeInTheDocument()
    expect(within(locations).getByText('Outlet')).toBeInTheDocument()
    expect(screen.getByText('Kenya · KES')).toBeInTheDocument()

    // Archived records are hidden until asked for, then offer Restore.
    expect(screen.queryByText('Old Town')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('switch', { name: 'Show archived' }))
    const oldTown = screen.getByText('Old Town').closest('li')
    expect(within(oldTown).getByText('Archived')).toBeInTheDocument()
    expect(within(oldTown).getByRole('button', { name: 'Restore Old Town' })).toBeInTheDocument()
  })

  it('creates a location under a branch and refreshes the tree', async () => {
    let locations = []
    organisation({ locations: () => locations })
    api.post.mockImplementation(async (path, body) => {
      locations = [{ ...LOCATIONS[0], id: 'l-2', name: body.name, type: body.type }]
      return { data: locations[0] }
    })
    renderApp('/settings/organisation')

    fireEvent.click(await screen.findByRole('button', { name: 'Add a location to Westlands' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a location to Westlands' })
    fireEvent.change(within(dialog).getByLabelText(/Name/), { target: { value: 'Back store' } })
    chooseOption(within(dialog).getByLabelText(/Type/), 'Store')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add location' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('branches/b-1/locations', { name: 'Back store', type: 'store' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await screen.findByText('Back store')).toBeInTheDocument()
  })

  it('shows a 422 field error under the field', async () => {
    organisation()
    api.post.mockRejectedValue(apiError(422, 'validation_failed', 'Some fields need attention.', { code: ['An active branch of this company already uses this code.'] }))
    renderApp('/settings/organisation')

    fireEvent.click(await screen.findByRole('button', { name: 'Add a branch to Amani Retail' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Kilimani' } })
    fireEvent.change(within(dialog).getByLabelText(/Branch code/), { target: { value: 'wstl' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add branch' }))

    expect(await within(dialog).findByText('An active branch of this company already uses this code.')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('companies/c-1/branches', { name: 'Kilimani', code: 'wstl' })
    expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument()
    await waitFor(() => expect(document.activeElement).toBe(within(dialog).getByLabelText(/Branch code/)))
  })

  it('asks before archiving and explains why the API refused', async () => {
    organisation()
    api.post.mockRejectedValue(apiError(422, 'has_active_children', 'This record still has active records under it.'))
    renderApp('/settings/organisation')

    fireEvent.click(await screen.findByRole('button', { name: 'Archive Westlands' }))
    const dialog = await screen.findByRole('dialog', { name: 'Archive Westlands?' })
    expect(api.post).not.toHaveBeenCalled()
    const confirm = within(dialog).getByRole('button', { name: 'Archive branch' })
    expect(confirm).toHaveAttribute('data-ds-variant', 'danger')
    fireEvent.click(confirm)

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('branches/b-1/archive'))
    expect(await within(dialog).findByText('This branch still has active locations. Archive them first.')).toBeInTheDocument()
  })

  it('explains a refused restore under an archived parent', async () => {
    organisation({ locations: [{ ...LOCATIONS[0], archived_at: '2026-10-02T08:00:00Z', branch_id: 'b-2', branch: { id: 'b-2', name: 'Old Town' } }] })
    api.post.mockRejectedValue(apiError(422, 'parent_archived', 'This record is archived.'))
    renderApp('/settings/organisation')

    fireEvent.click(await screen.findByRole('switch', { name: 'Show archived' }))
    fireEvent.click(screen.getByRole('button', { name: 'Restore Front till' }))
    expect(await screen.findByText('The branch of this location is archived. Restore the branch first.')).toBeInTheDocument()
  })

  it('shows a location-scoped user only their branch and location, without actions', async () => {
    organisation({
      permissions: [
        { name: 'core.location.view', scopes: [{ type: 'location', id: 'l-1' }] },
        { name: 'core.device.view', scopes: [{ type: 'location', id: 'l-1' }] },
      ],
      companies: [],
      branches: [],
    })
    renderApp('/settings/organisation')

    expect(await screen.findByText('Front till')).toBeInTheDocument()
    expect(screen.getByText('Westlands')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add company' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Edit/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Archive/ })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Devices of Front till' }))
    expect(await screen.findByText('Till 1')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add device' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Unpair/ })).not.toBeInTheDocument()
  })

  it('offers Organisation, not Users or Roles, to a cashier who sees one location', async () => {
    organisation({ permissions: [{ name: 'core.location.view', scopes: [{ type: 'location', id: 'l-1' }] }], companies: [], branches: [] })
    renderApp('/')
    const nav = (await screen.findAllByRole('navigation', { name: 'Main' }))[0]
    expect(await within(nav).findByRole('link', { name: 'Organisation' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Users' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Roles' })).not.toBeInTheDocument()
  })

  it('adds a device and shows its one-time pairing code with the expiry', async () => {
    organisation()
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    api.post.mockImplementation(async (path) => {
      if (path === 'locations/l-1/devices') return { data: { id: 'd-3', location_id: 'l-1', name: 'Till 3', status: 'pending' } }
      if (path === 'devices/d-3/pairing-code') return { code: 'K7MX4PQR', expires_at: '2026-10-07T14:20:00Z', device: {} }
      throw new Error(path)
    })
    renderApp('/settings/organisation')

    fireEvent.click(await screen.findByRole('button', { name: 'Devices of Front till' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Add device' }))
    const form = await screen.findByRole('dialog', { name: 'Add a device to Front till' })
    fireEvent.change(within(form).getByLabelText(/Device name/), { target: { value: 'Till 3' } })
    fireEvent.click(within(form).getByRole('button', { name: 'Add and get pairing code' }))

    const dialog = await screen.findByRole('dialog', { name: 'Pairing code for Till 3' })
    expect(api.post).toHaveBeenCalledWith('locations/l-1/devices', { name: 'Till 3' })
    expect(within(dialog).getByText('K7MX4PQR')).toBeInTheDocument()
    const time = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date('2026-10-07T14:20:00Z'))
    expect(within(dialog).getByText(`It works once and is valid until ${time}.`)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Copy code' }))
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('K7MX4PQR'))
    expect(await within(dialog).findByRole('button', { name: 'Copied' })).toBeInTheDocument()
  })

  it('explains suspension before suspending, and confirms unpairing as a danger action', async () => {
    organisation()
    api.post.mockResolvedValue({ data: {} })
    renderApp('/settings/organisation')

    fireEvent.click(await screen.findByRole('button', { name: 'Devices of Front till' }))
    const till = (await screen.findByText('Till 1')).closest('li')
    expect(within(till).getByText('Paired')).toBeInTheDocument()

    fireEvent.click(within(till).getByRole('button', { name: 'Suspend Till 1' }))
    let dialog = await screen.findByRole('dialog', { name: 'Suspend Till 1?' })
    expect(within(dialog).getByText(/Suspending is temporary/)).toBeInTheDocument()
    expect(within(dialog).getByText(/If the device is lost or stolen, unpair it instead/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend device' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('devices/d-1/suspend'))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())

    fireEvent.click(within(till).getByRole('button', { name: 'Unpair Till 1' }))
    dialog = await screen.findByRole('dialog', { name: 'Unpair Till 1?' })
    const confirm = within(dialog).getByRole('button', { name: 'Unpair device' })
    expect(confirm).toHaveAttribute('data-ds-variant', 'danger')
    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('devices/d-1/unpair'))
  })

  it('offers Resume and Unpair for a suspended device, without resuming first', async () => {
    organisation({ devices: [{ ...DEVICES[0], status: 'suspended' }] })
    api.post.mockResolvedValue({ data: {} })
    renderApp('/settings/organisation')

    fireEvent.click(await screen.findByRole('button', { name: 'Devices of Front till' }))
    const till = (await screen.findByText('Till 1')).closest('li')
    expect(within(till).getByRole('button', { name: 'Resume Till 1' })).toBeInTheDocument()
    expect(within(till).queryByRole('button', { name: 'Suspend Till 1' })).not.toBeInTheDocument()

    fireEvent.click(within(till).getByRole('button', { name: 'Unpair Till 1' }))
    const dialog = await screen.findByRole('dialog', { name: 'Unpair Till 1?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Unpair device' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('devices/d-1/unpair'))
    expect(api.post).not.toHaveBeenCalledWith('devices/d-1/resume')
  })

  it('opens the history of a company, branch or location in a dialog (MD-07)', async () => {
    organisation()
    const get = api.get.getMockImplementation()
    api.get.mockImplementation(async (path) => (path === 'history/branch/b-1?per_page=20&page=1' ? { data: [{ id: 'h-1', action: 'core.branch.update', actor: { id: 'u-1', name: 'Amina Otieno' }, before: { name: 'Westlands Mall' }, after: { name: 'Westlands' }, occurred_at: '2026-10-08T08:00:00Z' }], meta: { current_page: 1, last_page: 1 } } : get(path)))
    renderApp('/settings/organisation')
    fireEvent.click(await screen.findByRole('button', { name: 'History of Westlands' }))
    const dialog = await screen.findByRole('dialog', { name: 'History of Westlands' })
    expect(await within(dialog).findByText('Updated')).toBeInTheDocument()
    expect(within(dialog).getByText('Westlands Mall')).toBeInTheDocument()
  })
})
