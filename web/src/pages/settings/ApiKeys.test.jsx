import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const KEY = {
  id: 'k-1', name: 'Accounting sync', prefix: 'sk_live_abcdefgh12345678_…', mode: 'live', status: 'active',
  scopes: ['core.item.read', 'core.party.write'], role: { id: 'r-1', name: 'Integration' }, scope: { type: 'tenant', id: 't-1', name: null },
  created_by: { id: 'u-1', name: 'Amina Otieno' }, created_at: '2026-10-16T08:00:00Z', expires_at: null, last_used_at: null,
  rotated_at: null, previous_expires_at: null, revoked_at: null,
}
const SCOPES = [{ module: 'core', label: 'Core', resources: [
  { resource: 'core.item', label: 'Items', scopes: ['core.item.read', 'core.item.write'] },
  { resource: 'core.party', label: 'Contacts', scopes: ['core.party.read', 'core.party.write'] },
] }]
const USAGE = { data: Array.from({ length: 30 }, (_, i) => ({ day: new Date(Date.UTC(2026, 8, 17 + i)).toISOString().slice(0, 10), requests: i === 29 ? 12 : 0 })), meta: { total: 12 } }
const ROLES = { data: [
  { id: 'r-1', name: 'Integration', is_owner: false, permissions: ['core.item.view'] },
  { id: 'r-owner', name: 'Owner', is_owner: true, permissions: ['core.item.view'] },
], meta: { total: 2 } }

function setUp({ mode = 'live', keys = [KEY] } = {}) {
  mockRoutes(api, [
    ['api-keys?per_page=200', { data: keys, meta: { mode, total: keys.length } }],
    ['api-keys/scopes', { data: SCOPES }],
    ['api-keys/usage', USAGE],
    [/^api-keys\/k-1\/usage$/, { ...USAGE, meta: { total: 3 } }],
    ['roles?per_page=200', ROLES],
    ['sandbox/outbox', { data: [{ id: 'o-1', channel: 'email', recipient: 'dev@example.com', subject: 'You are invited', body: 'Accept: https://app.example/invitations/abc', source: 'notification', created_at: '2026-10-16T08:00:00Z' }] }],
    [/^(branches|locations)\?/, { data: [], meta: { total: 0 } }],
  ], { permissions: tenantWide(['core.api_key.manage', 'core.role.assign', 'core.item.view']) })
}

describe('API keys (API-02, API-03, API-05)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
  })

  it('lists keys with their prefix, status, role and use, and requests per day', async () => {
    setUp()
    renderApp('/settings/api-keys')

    expect(await screen.findByText('Accounting sync')).toBeInTheDocument()
    expect(screen.getByText('sk_live_abcdefgh12345678_…')).toBeInTheDocument()
    expect(screen.getByText('Active')).toBeInTheDocument()
    expect(screen.getByText('Integration')).toBeInTheDocument()
    expect(screen.getByText('2 scopes')).toBeInTheDocument()
    expect(screen.getByText('Never used')).toBeInTheDocument()
    expect(await screen.findByText('12 requests in the last 30 days')).toBeInTheDocument()
    expect(screen.queryByText('Developer sandbox')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Developer portal' })).toHaveAttribute('href', '/developers')
  })

  it('creates a key with a role and scopes and shows the secret once', async () => {
    setUp()
    api.post.mockResolvedValue({ data: { ...KEY, id: 'k-2', name: 'Shop sync' }, secret: 'sk_live_zzzzzzzzzzzzzzzz_secretsecretsecretsecretsecretsecret12' })
    renderApp('/settings/api-keys')

    fireEvent.click(await screen.findByRole('button', { name: 'Create key' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Shop sync' } })
    await waitForOption(within(dialog).getByLabelText(/^Role/), 'Integration')
    chooseOption(within(dialog).getByLabelText(/^Role/), 'Integration')
    // Items: read and write; contacts: read.
    fireEvent.click((await within(dialog).findAllByRole('button', { name: 'Read and write' }))[0])
    fireEvent.click(within(dialog).getAllByRole('button', { name: 'Read' })[1])
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create key' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('api-keys', {
        name: 'Shop sync', role_id: 'r-1', scope_type: 'tenant', scope_id: null, scopes: ['core.item.write', 'core.party.read'], expires_at: null,
      }),
    )
    expect(await screen.findByText('Copy your API key')).toBeInTheDocument()
    expect(screen.getByTestId('api-key-secret')).toHaveTextContent('sk_live_zzzzzzzzzzzzzzzz_secretsecretsecretsecretsecretsecret12')
    fireEvent.click(screen.getByRole('button', { name: 'I have stored the key' }))
    await waitFor(() => expect(screen.queryByTestId('api-key-secret')).not.toBeInTheDocument())
  })

  it('never offers an owner role to a key', async () => {
    setUp()
    renderApp('/settings/api-keys')

    fireEvent.click(await screen.findByRole('button', { name: 'Create key' }))
    const dialog = await screen.findByRole('dialog')
    await waitForOption(within(dialog).getByLabelText(/^Role/), 'Integration')
    fireEvent.click(within(dialog).getByLabelText(/^Role/))
    expect(within(screen.getByRole('listbox')).queryByRole('option', { name: 'Owner' })).not.toBeInTheDocument()
  })

  it('rotates with an overlap and revokes after confirming', async () => {
    setUp()
    api.post.mockImplementation(async (path) => (path.endsWith('/rotate') ? { data: KEY, secret: 'sk_live_abcdefgh12345678_newsecretnewsecretnewsecretnewsecret12' } : { data: { ...KEY, status: 'revoked' } }))
    renderApp('/settings/api-keys')

    fireEvent.click(await screen.findByRole('button', { name: 'Rotate Accounting sync' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Rotate key' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('api-keys/k-1/rotate', { overlap_hours: 24 }))
    expect(await screen.findByTestId('api-key-secret')).toHaveTextContent('newsecret')
    fireEvent.click(screen.getByRole('button', { name: 'I have stored the key' }))

    fireEvent.click(screen.getByRole('button', { name: 'Revoke Accounting sync' }))
    expect(await screen.findByText('Revoke Accounting sync?')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Revoke key' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('api-keys/k-1/revoke'))
  })

  it('offers the reset in a sandbox', async () => {
    setUp({ mode: 'test', keys: [] })
    api.post.mockResolvedValue({ data: { archived: { item: 12 } } })
    renderApp('/settings/api-keys')

    expect(await screen.findByText('Developer sandbox')).toBeInTheDocument()
    // API-05: what the sandbox would have sent.
    expect(await screen.findByText('You are invited')).toBeInTheDocument()
    expect(screen.getByText('Email to dev@example.com')).toBeInTheDocument()
    expect(screen.getByText('No API keys yet. Create one to connect another system.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Reset sample data' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Reset sandbox' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('sandbox/reset'))
    expect(await screen.findByText('The sandbox has fresh sample data.')).toBeInTheDocument()
  })

  it('is refused without the permission', async () => {
    mockRoutes(api, [], { permissions: tenantWide(['core.company.view']) })
    renderApp('/settings/api-keys')

    expect(await screen.findByRole('heading', { name: /permission|access/i })).toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('api-keys?per_page=200')
  })
})
