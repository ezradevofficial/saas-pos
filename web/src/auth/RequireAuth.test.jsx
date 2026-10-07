import { screen, waitFor } from '@testing-library/react'
import { api, getToken } from '@/api/client'
import i18n from '@/i18n'
import { mockApi, OWNER, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { safeNext } from './paths'
import { allows } from './usePermissions'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

describe('route guards', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    mockApi(api)
  })
  afterEach(() => i18n.changeLanguage('en'))

  it('sends a signed-out visitor to sign in, keeping where they were going', async () => {
    const { router } = renderApp('/settings/sessions')
    await waitFor(() => expect(router.state.location.pathname).toBe('/sign-in'))
    expect(router.state.location.search).toBe('?next=%2Fsettings%2Fsessions')
  })

  it('shows the app to a signed-in user', async () => {
    signedIn()
    renderApp('/')
    expect(await screen.findByRole('heading', { level: 1, name: /Amina/ })).toBeInTheDocument()
  })

  it('sends a signed-in user away from the sign-in page', async () => {
    signedIn()
    const { router } = renderApp('/sign-in?next=%2Fsettings%2Fappearance')
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/appearance'))
  })

  // These two go through the real client (with fetch stubbed) so its auth events fire.
  async function useRealClient(answer) {
    const actual = await vi.importActual('@/api/client')
    api.get.mockImplementation(actual.api.get)
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url) => {
        const [status, body] = answer(String(url))
        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
      }),
    )
  }
  afterEach(() => vi.unstubAllGlobals())

  it('signs out and returns to sign in when the token is rejected', async () => {
    signedIn('expired')
    await useRealClient(() => [401, { message: 'Sign in again.', code: 'unauthenticated' }])
    const { router } = renderApp('/settings/sessions')
    await waitFor(() => expect(router.state.location.pathname).toBe('/sign-in'))
    expect(router.state.location.search).toBe('?next=%2Fsettings%2Fsessions')
    expect(getToken()).toBeNull()
  })

  it('sends a token that may only enrol to the two-factor setup', async () => {
    signedIn('enrol-only')
    await useRealClient((url) =>
      url.endsWith('/me')
        ? [200, { data: OWNER }]
        : [403, { message: 'Set up two-step sign-in.', code: 'two_factor_enrollment_required' }],
    )
    const { router } = renderApp('/')
    await waitFor(() => expect(router.state.location.pathname).toBe('/two-factor/enrol'))
    expect(await screen.findByRole('heading', { level: 1, name: 'Set up two-step sign-in' })).toBeInTheDocument()
  })

  it('uses the language of the user profile', async () => {
    signedIn()
    mockApi(api, { user: { ...OWNER, locale: 'fr' } })
    renderApp('/settings/sessions')
    expect(await screen.findByRole('heading', { level: 1, name: 'Sessions' })).toBeInTheDocument()
    await waitFor(() => expect(i18n.language).toBe('fr'))
    expect(screen.getByText('Paramètres')).toBeInTheDocument()
  })

  it('shows a no-access page for a settings page the user cannot see', async () => {
    signedIn()
    mockApi(api, { permissions: [] })
    renderApp('/settings/users')
    expect(await screen.findByRole('heading', { name: 'You do not have access to this page' })).toBeInTheDocument()
  })
})

describe('safeNext', () => {
  it('follows only same-site paths', () => {
    expect(safeNext('/settings/users')).toBe('/settings/users')
    expect(safeNext('//evil.example')).toBe('/')
    expect(safeNext('https://evil.example')).toBe('/')
    expect(safeNext(null)).toBe('/')
  })
})

describe('allows', () => {
  const permissions = [
    { name: 'core.user.view', scopes: [{ type: 'branch', id: 'b-1' }] },
    { name: 'core.company.view', scopes: [{ type: 'tenant', id: 't-1' }] },
  ]

  it('checks the permission anywhere, or at one scope', () => {
    expect(allows(permissions, 'core.user.view')).toBe(true)
    expect(allows(permissions, 'core.user.view', { type: 'branch', id: 'b-1' })).toBe(true)
    expect(allows(permissions, 'core.user.view', { type: 'branch', id: 'b-2' })).toBe(false)
    expect(allows(permissions, 'core.role.view')).toBe(false)
  })

  it('treats a tenant-wide grant as covering every scope', () => {
    expect(allows(permissions, 'core.company.view', { type: 'location', id: 'l-9' })).toBe(true)
  })
})
