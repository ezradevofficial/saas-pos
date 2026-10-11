import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ApiError, api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const PERMISSIONS = tenantWide(['core.layout.view', 'core.layout.edit', 'core.layout.publish', 'core.user.view', 'core.role.view'])
const TENANT = { type: 'tenant', id: null }
const SOURCES = [{ key: 'approvals.waiting', label: 'Approvals waiting for me', widgets: ['kpi', 'approval_count'], params: [] }]

const documentWith = (payload, { revision = 1 } = {}) => ({
  data: { id: 'd-dash', kind: 'dashboard', key: 'default', scope: TENANT, published: null, draft: { version: 1, revision, status: 'draft', payload }, history: [{ version: 1, status: 'draft' }] },
  meta: { problems: [] },
})

function setUp({ stored = documentWith({ title: 'Ours', widgets: [] }) } = {}) {
  let current = stored
  mockRoutes(
    api,
    [
      [/^config\/dashboard\?.*scope_type=tenant/, () => ({ data: current ? [current.data] : [], meta: { total: current ? 1 : 0 } })],
      [/^config\/dashboard\?/, { data: [], meta: { total: 0 } }],
      [/^config\/dashboard\/d-dash$/, () => current],
      ['config/dashboard/resolved', { data: { payload: null, source: null } }],
      ['dashboard/sources', { data: SOURCES }],
      [/^dashboard\/sources\//, { data: { value: 3 } }],
      [/^roles\?/, { data: [] }],
    ],
    { permissions: PERMISSIONS },
  )
  return { set: (next) => (current = next) }
}

const titleField = () => screen.getByRole('textbox', { name: 'Dashboard title' })

describe('Dashboard designer (LAY-01, LAY-06)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('adds a widget, saves the draft and publishes it', async () => {
    setUp()
    api.post.mockImplementation(async (path, body) => (path === 'config/dashboard' ? documentWith(body.payload, { revision: 2 }) : documentWith({ title: 'Ours', widgets: [] }, { revision: 2 })))
    renderApp('/settings/layouts/dashboards')

    await waitFor(() => expect(titleField()).toHaveValue('Ours'))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Add widget' })).toBeEnabled())
    fireEvent.click(screen.getByRole('button', { name: 'Add widget' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('config/dashboard', expect.objectContaining({ revision: 1 })))
    const body = api.post.mock.calls[0][1]
    expect(body.payload.widgets).toHaveLength(1)
    expect(body.payload.widgets[0]).toMatchObject({ type: 'kpi', source: 'approvals.waiting', x: 0, w: 4, h: 2 })

    await waitFor(() => expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled())
    fireEvent.click(screen.getByRole('button', { name: 'Publish v1' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('config/dashboard/d-dash/publish', { revision: 2 }))
  })

  it('does not publish when saving first fails', async () => {
    setUp()
    api.post.mockImplementation(async (path) => {
      if (path === 'config/dashboard') throw new ApiError({ status: 422, code: 'validation_failed', message: 'The dashboard could not be saved.' })
      return documentWith({ widgets: [] })
    })
    renderApp('/settings/layouts/dashboards')

    await waitFor(() => expect(titleField()).toBeEnabled())
    fireEvent.change(titleField(), { target: { value: 'Shop floor' } })
    fireEvent.click(screen.getByRole('button', { name: 'Publish v1' }))

    expect(await screen.findAllByText('The dashboard could not be saved.')).not.toHaveLength(0)
    expect(api.post).not.toHaveBeenCalledWith('config/dashboard/d-dash/publish', expect.anything())
  })

  it('shows the draft as it is now after Reload on a conflict', async () => {
    const routes = setUp()
    const theirs = documentWith({ title: 'Theirs', widgets: [] }, { revision: 4 })
    api.post.mockImplementation(async () => {
      routes.set(theirs)
      throw new ApiError({ status: 409, code: 'config_changed', message: 'Someone changed this draft.', data: theirs })
    })
    renderApp('/settings/layouts/dashboards')

    await waitFor(() => expect(titleField()).toBeEnabled())
    fireEvent.change(titleField(), { target: { value: 'Mine' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))

    fireEvent.click(await screen.findByRole('button', { name: 'Reload' }))
    await waitFor(() => expect(titleField()).toHaveValue('Theirs'))
    expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled()
  })
})
