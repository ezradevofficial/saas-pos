import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import i18n from '@/i18n'
import { mockApi, OWNER, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const PERMISSIONS = { permissions: [{ name: 'core.company.view', scopes: [{ type: 'tenant', id: 't-1' }] }], modules: ['core'], owner: true }

const PARTS = {
  permissions: PERMISSIONS,
  navigation: { data: { payload: null, source: null } },
  theme: { data: { kind: 'theme', payload: {}, source: null } },
  usage: { data: { units: [] } },
  subscription: { data: { status: 'active' } },
  trial: { data: { state: 'none' } },
  unread: { data: { unread: 3 } },
  support_active: { data: [] },
  approvals_waiting: { data: [], meta: { total: 2 } },
  companies: { data: [{ id: 'c-1', name: 'Amani Retail' }], meta: { total: 1 } },
  my_companies: { data: [{ id: 'c-1', name: 'Amani Retail' }] },
  custom_form_types: { data: [] },
  hr_me: null,
  theme_place: null,
}

// NFR-02: the shell makes one bootstrap call, not one call per payload.
describe('shell bootstrap', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })
  afterEach(() => i18n.changeLanguage('en'))

  it('asks for the shell payloads once, in a single bootstrap call', async () => {
    mockApi(api, { user: OWNER })
    const base = api.get.getMockImplementation()
    api.get.mockImplementation(async (path) => (path === 'bootstrap' ? { data: PARTS } : base(path)))
    const { queryClient } = renderApp('/')

    await waitFor(() => expect(queryClient.getQueryData(['me', 'permissions'])).toEqual(PERMISSIONS))
    await waitFor(() => expect(queryClient.getQueryData(['notifications', 'unread-count'])).toEqual(PARTS.unread))

    const paths = api.get.mock.calls.map(([path]) => path)
    expect(paths.filter((path) => path === 'bootstrap')).toHaveLength(1)
    for (const own of ['me/permissions', 'config/navigation/resolved', 'subscription/status', 'onboarding/trial', 'notifications/unread-count', 'approvals?status=waiting&per_page=1&page=1', 'custom-form-types']) {
      expect(paths, `${own} was fetched on its own`).not.toContain(own)
    }
  })

  it('falls back to each payload own endpoint when the bootstrap fails', async () => {
    mockApi(api, { user: OWNER })
    const { queryClient } = renderApp('/')

    await waitFor(() => expect(queryClient.getQueryData(['me', 'permissions'])).toBeTruthy())
    const paths = api.get.mock.calls.map(([path]) => path)
    expect(paths).toContain('bootstrap')
    expect(paths).toContain('me/permissions')
  })

  it('refetches permissions from their own endpoint after an invalidation', async () => {
    mockApi(api, { user: OWNER })
    const base = api.get.getMockImplementation()
    api.get.mockImplementation(async (path) => (path === 'bootstrap' ? { data: PARTS } : base(path)))
    const { queryClient } = renderApp('/')
    await waitFor(() => expect(queryClient.getQueryData(['me', 'permissions'])).toEqual(PERMISSIONS))

    await queryClient.invalidateQueries({ queryKey: ['me', 'permissions'] })

    expect(api.get.mock.calls.map(([path]) => path)).toContain('me/permissions')
  })

  // NFR-02: sign in, land in the shell: one bootstrap call, and permissions, hr/me and the themed place come from it.
  it('after sign-in makes one bootstrap call and none of me/permissions, hr/me or the company theme', async () => {
    resetSession()
    const parts = {
      ...PARTS,
      permissions: { ...PERMISSIONS, modules: ['core', 'hr'] },
      hr_me: { data: null },
      theme_place: { data: { kind: 'theme', payload: {}, source: null } },
    }
    mockApi(api, { user: OWNER })
    const base = api.get.getMockImplementation()
    api.get.mockImplementation(async (path) => (path.startsWith('bootstrap') ? { data: parts } : base(path)))
    api.post.mockResolvedValue({ token: 'new-token', user: OWNER, two_factor_enrollment_required: false })
    const { router, queryClient } = renderApp('/sign-in')

    fireEvent.change(screen.getByLabelText('Email or phone number', { exact: false }), { target: { value: 'amina@example.com' } })
    fireEvent.change(screen.getByLabelText('Password', { exact: false }), { target: { value: 'correct horse' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(queryClient.getQueryData(['hr', 'me'])).toEqual({ data: null }))
    await waitFor(() => expect(queryClient.getQueryData(['config', 'theme', 'resolved', OWNER.id, { company: 'c-1' }])).toBeTruthy())
    expect(router.state.location.pathname).not.toBe('/sign-in')

    const paths = api.get.mock.calls.map(([path]) => path)
    expect(paths.filter((path) => path.startsWith('bootstrap'))).toHaveLength(1)
    expect(paths).not.toContain('me/permissions')
    expect(paths).not.toContain('hr/me')
    expect(paths.filter((path) => path.startsWith('config/theme/resolved'))).toEqual([])
  })
})
