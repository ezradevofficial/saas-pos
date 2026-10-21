import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, OWNER, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const SETTINGS = { data: { password_min_length: 8, session_timeout_minutes: 60, default_locale: 'en' } }
const LINK = { id: 'i-1', connection_id: 'c-1', connection_name: 'Microsoft', provider: 'entra', email: 'amina@acme.co.ke', last_sign_in_at: '2026-10-19T08:00:00Z' }

// AUTH-11, ADR 023: linking one's own account (Settings, Security) and a user's links for an SSO manager.
describe('Single sign-on links', () => {
  const assign = vi.fn()

  beforeEach(() => {
    resetSession()
    signedIn()
    vi.clearAllMocks()
  })

  afterEach(() => vi.unstubAllGlobals())

  it('lets a signed-in Owner link their own account and starts at the identity provider', async () => {
    vi.stubGlobal('location', { ...window.location, assign, origin: 'http://localhost:3000', hash: '', pathname: '/settings/security' })
    mockRoutes(api, [['tenant/settings', SETTINGS], ['me/sso', { data: { connections: [{ id: 'c-1', name: 'Microsoft', provider: 'entra' }], identities: [] } }]], {
      permissions: tenantWide(['core.settings.edit']),
    })
    api.post.mockResolvedValue({ data: { redirect_url: 'https://login.microsoftonline.com/authorize' } })
    renderApp('/settings/security')

    expect(await screen.findByText('Your account is not linked yet.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Link my account with Microsoft' }))
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://login.microsoftonline.com/authorize'))
    expect(api.post).toHaveBeenCalledWith('me/sso/link', { connection_id: 'c-1', origin: 'http://localhost:3000' })
  })

  it('shows why a link failed when the browser comes back', async () => {
    vi.stubGlobal('location', { ...window.location, assign, hash: '#sso_link=failed&reason=link_email_mismatch', pathname: '/settings/security' })
    mockRoutes(api, [['tenant/settings', SETTINGS], ['me/sso', { data: { connections: [{ id: 'c-1', name: 'Microsoft', provider: 'entra' }], identities: [] } }]], {
      permissions: tenantWide(['core.settings.edit']),
    })
    renderApp('/settings/security')

    expect(await screen.findByText('Your account was not linked.')).toBeInTheDocument()
    expect(screen.getByText('The identity provider signed in someone else. Sign in there with your own account, then try again.')).toBeInTheDocument()
  })

  it('lists a user’s links for an SSO manager and unlinks one', async () => {
    mockRoutes(api, [
      [`users/${OWNER.id}`, { data: { ...OWNER, status: 'active', roles: [] } }],
      [`users/${OWNER.id}/sso-identities`, { data: [LINK] }],
    ], { permissions: tenantWide(['core.user.view', 'core.sso.manage']) })
    api.post.mockResolvedValue({ data: null })
    renderApp(`/settings/users/${OWNER.id}`)

    expect(await screen.findByText('amina@acme.co.ke with Microsoft')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Unlink amina@acme.co.ke' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith(`users/${OWNER.id}/sso-identities/i-1/archive`))
  })
})
