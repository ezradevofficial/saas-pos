import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { ALL_CORE, mockApi, OWNER, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const TENANT = [{ type: 'tenant', id: 't-1' }]
const MANAGER = [...ALL_CORE, { name: 'core.support_access.view', scopes: TENANT }, { name: 'core.support_access.manage', scopes: TENANT }]
const PENDING = {
  id: 'g-1', staff_name: 'Amina Support', staff_email: 'amina@example.com', reason: 'The stock count import fails', mode: 'write', hours: 4,
  include_sensitive: false, status: 'pending', active: false, requested_at: '2026-11-02T08:00:00Z', expires_at: null, requests: 0,
}
const ACTIVE = { ...PENDING, id: 'g-2', status: 'approved', active: true, mode: 'read', expires_at: '2026-11-02T12:00:00Z', requests: 3 }

// RBAC-13b, RBAC-13c: Settings → Security → Support access, and the bars of the shell.
describe('Support access', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('approves a request with the sensitive-data choice recorded', async () => {
    mockApi(api, { permissions: MANAGER, extra: { 'support-access': { data: [PENDING] }, 'support-access/active': { data: [] } } })
    api.post.mockResolvedValue({ data: { ...PENDING, status: 'approved' } })
    renderApp('/settings/security/support-access')

    const request = await screen.findByRole('article', { name: 'Amina Support asks for access' })
    expect(within(request).getByText('The stock count import fails')).toBeInTheDocument()
    fireEvent.click(within(request).getByRole('button', { name: 'Review and approve' }))

    const dialog = await screen.findByRole('dialog')
    const sensitive = within(dialog).getByLabelText(/Include payroll, HR and payment data/)
    expect(sensitive).not.toBeChecked()
    fireEvent.click(sensitive)
    fireEvent.click(within(dialog).getByRole('button', { name: 'Approve for 4 hours' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('support-access/g-1/approve', { include_sensitive: true, mode: 'write' }))
  })

  it('shows open sessions with a revoke, and the shell says support access is active', async () => {
    mockApi(api, { permissions: MANAGER, extra: { 'support-access': { data: [ACTIVE] }, 'support-access/active': { data: [ACTIVE] } } })
    api.post.mockResolvedValue({ data: { ...ACTIVE, status: 'revoked', active: false } })
    renderApp('/settings/security/support-access')

    expect(await screen.findByRole('status', { name: 'Support access active' })).toBeInTheDocument()
    fireEvent.click(await screen.findByRole('button', { name: 'Revoke' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('support-access/g-2/revoke', {}))
  })

  it('shows the support session bar to the principal and ends the session', async () => {
    const session = { grant_id: 'g-2', staff_name: 'Amina Support', mode: 'read', include_sensitive: false, expires_at: '2026-11-02T12:00:00Z' }
    mockApi(api, { user: { ...OWNER, name: 'Support: Amina Support', support_session: session }, permissions: ALL_CORE })
    api.post.mockResolvedValue({ data: { status: 'ended' } })
    renderApp('/')

    const bar = await screen.findByRole('status', { name: 'Support session' })
    expect(within(bar).getByText(/Read-only access to this account/)).toBeInTheDocument()
    fireEvent.click(within(bar).getByRole('button', { name: 'End session' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('support-session/end'))
  })

  it('is hidden from people who do not see support access', async () => {
    mockApi(api, { permissions: ALL_CORE })
    renderApp('/settings/security/support-access')

    expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent(/access/i)
    expect(api.get).not.toHaveBeenCalledWith('support-access')
  })
})
