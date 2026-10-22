import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import * as data from './marketplaceData'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const SCOPES = [
  { scope: 'core.item.read', module: 'core', module_label: 'Core', resource: 'core.item', resource_label: 'Items', access: 'read', label: 'See items', active: true },
  { scope: 'core.party.write', module: 'core', module_label: 'Core', resource: 'core.party', resource_label: 'Contacts', access: 'write', label: 'See and change contacts', active: true },
]
const APP = {
  id: 'app-1', slug: 'stock-sync', status: 'active', name: 'Stock sync', tagline: 'Keeps stock in step', category: 'productivity', logo_url: null,
  developer: { name: 'Acme Labs', website: null, first_party: false }, pricing: { model: 'paid', currency: 'KES', monthly_minor: 50000, yearly_minor: 500000, available: true },
  installed: false, configured: true, description: 'Syncs items.', website: 'https://sync.example.com', support_email: 'help@example.com', privacy_url: null,
  version: 1, scopes: SCOPES, webhook_events: ['sale.completed.v1'], client_id: 'c-1', installation: null,
}
const CONNECTOR = { ...APP, id: 'app-2', name: 'Courier connector', developer: { name: 'Platform', first_party: true }, pricing: { model: 'free' }, configured: false }
const ROLES = { data: [
  { id: 'r-1', name: 'Integration', is_owner: false, permissions: ['core.item.view'] },
  { id: 'r-owner', name: 'Owner', is_owner: true, permissions: ['core.item.view'] },
], meta: { total: 2 } }
const QUERY = 'client_id=c-1&redirect_uri=https%3A%2F%2Fsync.example.com%2Fcb&response_type=code&state=xyz&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256'
const INSTALLATION = {
  id: 'i-1', app_id: 'app-1', status: 'active', name: 'Stock sync', logo_url: null, app_status: 'active', version: 1, listed_version: 2, needs_consent: true,
  scopes: SCOPES.slice(0, 1), new_scopes: SCOPES.slice(1), webhook_events: [], price: null, role: { id: 'r-1', name: 'Integration' }, scope: { type: 'tenant', id: 't-1', name: null },
  installed_at: '2026-10-16T08:00:00Z', installed_by: 'Amina Otieno', consented_at: '2026-10-16T08:00:00Z', uninstalled_at: null, usage: { days: [], total: 4 },
}

function setUp(permissions = ['core.marketplace.view', 'core.marketplace.install', 'core.role.assign', 'core.item.view']) {
  mockRoutes(api, [
    [/^marketplace\/apps\?/, { data: [APP, CONNECTOR] }],
    ['marketplace/apps/app-1', { data: APP }],
    ['marketplace/connectors', { data: [] }],
    [/^oauth\/authorize\?/, { data: { app: APP, can_install: true, can_pay: true } }],
    ['apps/installed', { data: [INSTALLATION] }],
    ['apps/installed/i-1', { data: INSTALLATION }],
    ['roles?per_page=200', ROLES],
    [/^(companies|branches|locations)\?/, { data: [], meta: { total: 0 } }],
  ], { permissions: tenantWide(permissions) })
}

describe('Marketplace (MKT-01, MKT-02)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
  })

  it('lists apps with their price, and a connector that is not set up', async () => {
    setUp()
    renderApp('/marketplace')

    expect(await screen.findByText('Stock sync')).toBeInTheDocument()
    expect(screen.getByText('By Acme Labs')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Stock sync/ })).toHaveTextContent('KES 500.00')
    expect(screen.getByText('Not set up yet')).toBeInTheDocument()
    expect(screen.getByText('Made by the platform team')).toBeInTheDocument()
  })

  it('shows what an app asks for in plain words', async () => {
    setUp()
    renderApp('/marketplace/app-1')

    expect(await screen.findByText('See items')).toBeInTheDocument()
    expect(screen.getByText('See and change contacts')).toBeInTheDocument()
    expect(screen.getByText('sale.completed.v1')).toBeInTheDocument()
    // Phase 8 Marketplace walkthrough: events in plain words, not only their keys.
    expect(screen.getByText('Sale completed')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open the app' })).toHaveAttribute('href', 'https://sync.example.com')
  })

  it('approves an app as a role and goes back to it with the code', async () => {
    setUp()
    const leave = vi.spyOn(data, 'leaveTo').mockImplementation(() => {})
    api.post.mockResolvedValue({ data: { redirect_to: 'https://sync.example.com/cb?code=abc&state=xyz', installation_id: 'i-1' } })
    renderApp(`/oauth/authorize?${QUERY}`)

    expect(await screen.findByText('Connect Stock sync')).toBeInTheDocument()
    expect(screen.getByText('See items')).toBeInTheDocument()
    const role = screen.getByLabelText(/^Role/)
    await waitForOption(role, 'Integration')
    chooseOption(role, 'Integration')
    expect(within(role.closest('div').parentElement).queryByText('Owner')).not.toBeInTheDocument()
    // Phase 8 Marketplace walkthrough: the single role row is named for the app, not "Role 1".
    expect(screen.getByRole('group', { name: 'Role for this app' })).toBeInTheDocument()
    expect(screen.queryByText('Role 1')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Approve and install' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('oauth/authorize', expect.objectContaining({
      client_id: 'c-1', code_challenge_method: 'S256', state: 'xyz', decision: 'approve', role_id: 'r-1',
    })))
    await waitFor(() => expect(leave).toHaveBeenCalledWith('https://sync.example.com/cb?code=abc&state=xyz'))
  })

  it('denies an app without choosing a role', async () => {
    setUp()
    const leave = vi.spyOn(data, 'leaveTo').mockImplementation(() => {})
    api.post.mockResolvedValue({ data: { redirect_to: 'https://sync.example.com/cb?error=access_denied&state=xyz' } })
    renderApp(`/oauth/authorize?${QUERY}`)

    fireEvent.click(await screen.findByRole('button', { name: 'Deny' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('oauth/authorize', expect.objectContaining({ decision: 'deny', role_id: null })))
    await waitFor(() => expect(leave).toHaveBeenCalled())
  })

  it('shows an installed app waiting for consent and uninstalls it', async () => {
    setUp()
    api.post.mockResolvedValue({ data: { ...INSTALLATION, status: 'uninstalled' } })
    renderApp('/settings/apps/i-1')

    expect(await screen.findByText('Version 2 asks for more')).toBeInTheDocument()
    expect(screen.getByText('4 requests in the last 30 days')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Uninstall' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Uninstall' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('apps/installed/i-1/uninstall'))
  })
})
