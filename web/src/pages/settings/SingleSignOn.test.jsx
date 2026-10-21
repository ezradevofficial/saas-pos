import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const CONNECTION = {
  id: 'c-1', provider: 'entra', name: 'Microsoft', enabled: false, has_secret: true, tested_at: null, last_test: null,
  settings: { client_id: 'client-1', directory_id: 'dir-1' },
  sp: { redirect_uri: 'https://api.example.app/api/v1/auth/sso/oidc/callback' },
}
const SAML = {
  id: 'c-2', provider: 'saml', name: 'Okta SAML', enabled: true, has_secret: false, tested_at: '2026-10-16T08:00:00Z',
  last_test: { ok: true, email: 'owner@acme.co.ke', mfa: true, action: 'link', at: '2026-10-16T08:00:00Z' },
  settings: { idp_entity_id: 'https://idp.example.com', idp_sso_url: 'https://idp.example.com/sso', idp_certificates: ['CERT'] },
  sp: { entity_id: 'https://api.example.app/api/v1/auth/sso/saml/c-2', acs_url: 'https://api.example.app/api/v1/auth/sso/saml/c-2/acs' },
}
const DOMAIN = {
  id: 'd-1', domain: 'acme.co.ke', status: 'pending', failure: null, checked_at: null, verified_at: null,
  record: { type: 'TXT', name: '_platform-verify.acme.co.ke', value: 'platform-verify=abc' },
}
const OVERVIEW = {
  data: {
    settings: { enforce: false, jit_provisioning: false, jit_role_id: null, jit_scope_type: null, jit_scope_id: null },
    oidc_redirect_uri: CONNECTION.sp.redirect_uri,
    connections: [CONNECTION, SAML],
    domains: [DOMAIN],
  },
}

// AUTH-11, ADR 023: Settings, Single sign-on.
describe('Single sign-on settings', () => {
  const assign = vi.fn()

  beforeEach(() => {
    resetSession()
    signedIn()
    vi.clearAllMocks()
    mockRoutes(api, [['sso', OVERVIEW]], { permissions: tenantWide(['core.sso.manage', 'core.settings.edit']) })
  })

  afterEach(() => vi.unstubAllGlobals())

  it('shows domains with their TXT record, connections with the values to register, and no secret', async () => {
    renderApp('/settings/sso')

    expect(await screen.findByText('_platform-verify.acme.co.ke')).toBeInTheDocument()
    expect(screen.getByText('platform-verify=abc')).toBeInTheDocument()
    expect(screen.getByText('Waiting for DNS')).toBeInTheDocument()
    expect(screen.getByText(CONNECTION.sp.redirect_uri)).toBeInTheDocument()
    expect(screen.getByText(SAML.sp.acs_url)).toBeInTheDocument()
    expect(screen.getByText('Not tested yet. Test it before you enable it.')).toBeInTheDocument()
    expect(screen.getByText(/Test passed .* as owner@acme\.co\.ke\./)).toBeInTheDocument()
    expect(screen.getByText('The identity provider confirmed multi-factor authentication.')).toBeInTheDocument()
    // Enabling waits for a passing test.
    expect(screen.getByRole('button', { name: 'Enable Microsoft' })).toBeDisabled()
  })

  it('adds a domain and an Entra connection', async () => {
    api.post.mockResolvedValue({ data: {} })
    renderApp('/settings/sso')

    fireEvent.change(await screen.findByLabelText('Email domain', { exact: false }), { target: { value: 'acme-group.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add domain' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('sso/domains', { domain: 'acme-group.com' }))

    fireEvent.click(screen.getByRole('button', { name: 'Add connection' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Name', { exact: false }), { target: { value: 'Entra' } })
    fireEvent.change(within(dialog).getByLabelText('Application (client) ID', { exact: false }), { target: { value: 'client-2' } })
    fireEvent.change(within(dialog).getByLabelText('Client secret', { exact: false }), { target: { value: 's3cret' } })
    fireEvent.change(within(dialog).getByLabelText('Directory (tenant) ID', { exact: false }), { target: { value: 'dir-2' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save connection' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('sso/connections', { provider: 'entra', name: 'Entra', client_id: 'client-2', client_secret: 's3cret', directory_id: 'dir-2' }),
    )
  })

  it('starts a test sign-in and reports the outcome on return', async () => {
    vi.stubGlobal('location', { ...window.location, assign, origin: 'http://localhost:3000', hash: '', pathname: '/settings/sso' })
    api.post.mockResolvedValue({ data: { redirect_url: 'https://login.microsoftonline.com/authorize' } })
    renderApp('/settings/sso')

    fireEvent.click(await screen.findByRole('button', { name: 'Test Microsoft' }))
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://login.microsoftonline.com/authorize'))
    expect(api.post).toHaveBeenCalledWith('sso/connections/c-1/test', { origin: 'http://localhost:3000' })
  })

  it('shows a test that passed when the browser comes back', async () => {
    vi.stubGlobal('location', { ...window.location, assign, hash: '#test=ok', pathname: '/settings/sso' })
    renderApp('/settings/sso')

    expect(await screen.findByText('The test sign-in worked. Check the result below, then enable the connection.')).toBeInTheDocument()
  })

  it('saves the rules and shows why enforcing is refused', async () => {
    api.put.mockRejectedValue(Object.assign(new Error('x'), {
      status: 422, code: 'enforce_needs_setup', message: 'Enable a tested connection and verify an email domain before requiring single sign-on.',
      errors: { enforce: ['Enable a tested connection and verify an email domain before requiring single sign-on.'] }, data: {},
    }))
    renderApp('/settings/sso')

    fireEvent.click(await screen.findByRole('switch', { name: 'Require single sign-on' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save sign-in rules' }))

    expect(await screen.findByText('Enable a tested connection and verify an email domain before requiring single sign-on.')).toBeInTheDocument()
    expect(api.put).toHaveBeenCalledWith('sso/settings', expect.objectContaining({ enforce: true, jit_provisioning: false, jit_role_id: null }))
  })

  it('is linked from the security page for holders of core.sso.manage', async () => {
    mockRoutes(api, [['tenant/settings', { data: { password_min_length: 8, session_timeout_minutes: 60, default_locale: 'en' } }]], {
      permissions: tenantWide(['core.sso.manage', 'core.settings.edit']),
    })
    renderApp('/settings/security')

    expect(await screen.findByRole('link', { name: 'Set up single sign-on' })).toHaveAttribute('href', '/settings/sso')
  })
})
