import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const CATALOGUE = {
  data: [
    { key: 'document.stage_changed.v1', module: 'core', fields: ['document_type', 'document_id'] },
    { key: 'sale.completed.v1', module: 'pos', fields: ['sale_id', 'receipt_number', 'totals.total_minor'] },
  ],
  meta: { backoff: [60, 300], disable_after: 10, retention_days: 30, rotation_overlap_hours: 24, max_subscriptions: 25 },
}
const HOOK = {
  id: 'w-1',
  url_display: 'https://hooks.example.com/in',
  description: 'Warehouse system',
  events: ['sale.completed.v1'],
  active: true,
  company_id: null,
  branch_id: null,
  principal: { type: 'user', id: 'u-1', name: 'Amina', active: true },
  consecutive_failures: 0,
  disabled_reason: null,
  disabled_at: null,
  secret_rotated_at: '2026-10-16T09:00:00Z',
  previous_secret_expires_at: null,
  last_delivery_at: '2026-10-16T10:00:00Z',
  created_at: '2026-10-16T09:00:00Z',
}
const FAILING = { ...HOOK, id: 'w-2', url_display: 'https://down.example.com/x', description: null, active: false, disabled_reason: 'failing', consecutive_failures: 10 }
const DELIVERY = {
  id: 'd-1',
  webhook: { id: 'w-1', url_display: 'https://hooks.example.com/in' },
  event_id: 'e-1',
  event_type: 'sale.completed.v1',
  status: 'failed',
  attempts: 7,
  response_status: 503,
  duration_ms: 120,
  error: 'status',
  next_attempt_at: null,
  last_attempt_at: '2026-10-16T10:00:00Z',
  delivered_at: null,
  replay_of: null,
  created_at: '2026-10-16T10:00:00Z',
}

function routes(extra = []) {
  mockRoutes(
    api,
    [
      ...extra,
      ['webhooks/catalogue', CATALOGUE],
      ['webhooks', { data: [HOOK, FAILING] }],
      ['webhooks/w-1', { data: HOOK }],
      [/^webhook-deliveries\?/, { data: [DELIVERY], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }],
      ['webhook-deliveries/d-1', { data: { ...DELIVERY, payload: { id: 'e-1', type: 'sale.completed.v1', data: { sale_id: 's-1' } }, response_body: 'busy' } }],
      [/^companies/, { data: [{ id: 'c-1', name: 'Acme' }] }],
      [/^branches/, { data: [{ id: 'b-1', name: 'Branch A' }] }],
    ],
    { permissions: tenantWide(['core.webhook.manage']) },
  )
}

describe('Settings → Webhooks (HOOK-01)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
    routes()
  })

  it('lists the endpoints with their state, and never their secret or query', async () => {
    renderApp('/settings/webhooks')

    expect(await screen.findByRole('heading', { name: 'Webhooks' })).toBeInTheDocument()
    const list = await screen.findByRole('list', { name: 'Webhooks' })
    expect(within(list).getByRole('link', { name: 'Open webhook https://hooks.example.com/in' })).toHaveAttribute('href', '/settings/webhooks/w-1')
    expect(within(list).getByText('On')).toBeInTheDocument()
    expect(within(list).getByText('Switched off after failures')).toBeInTheDocument()
    expect(within(list).getByText(/10 failures in a row/)).toBeInTheDocument()
  })

  it('adds a webhook with chosen events, then shows its secret once', async () => {
    api.post.mockResolvedValue({ data: { ...HOOK, id: 'w-3', secret: 'whsec_c2VjcmV0LXNob3duLW9uY2U=' } })
    renderApp('/settings/webhooks')

    fireEvent.click(await screen.findByRole('button', { name: 'Add webhook' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/Endpoint URL/), { target: { value: 'https://hooks.example.com/in?token=abc' } })
    fireEvent.click(await within(dialog).findByLabelText(/Sale completed/))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add webhook' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('webhooks', {
        url: 'https://hooks.example.com/in?token=abc',
        description: null,
        events: ['sale.completed.v1'],
        company_id: null,
        branch_id: null,
      }),
    )
    expect(await screen.findByTestId('webhook-signing-secret')).toHaveTextContent('whsec_c2VjcmV0LXNob3duLW9uY2U=')
    expect(screen.getByText('Copy this secret now')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'I saved the secret' }))
    await waitFor(() => expect(screen.queryByTestId('webhook-signing-secret')).not.toBeInTheDocument())
  })

  it('refuses to save without an event', async () => {
    renderApp('/settings/webhooks')

    fireEvent.click(await screen.findByRole('button', { name: 'Add webhook' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/Endpoint URL/), { target: { value: 'https://hooks.example.com/in' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add webhook' }))

    expect(await within(dialog).findByText('Choose at least one event.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('shows the delivery log and replays a delivery', async () => {
    api.post.mockResolvedValue({ data: { ...DELIVERY, id: 'd-2', status: 'pending', replay_of: 'd-1' } })
    renderApp('/settings/webhooks?tab=deliveries')

    const row = await screen.findByRole('row', { name: /Sale completed/ })
    expect(within(row).getByText('Failed')).toBeInTheDocument()
    expect(within(row).getByText('503')).toBeInTheDocument()
    fireEvent.click(row)

    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText(/"sale_id": "s-1"/)).toBeInTheDocument()
    expect(within(dialog).getByText('busy')).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Replay delivery' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('webhook-deliveries/d-1/replay'))
  })

  it('lists the events with their fields in the developer guide', async () => {
    renderApp('/settings/webhooks?tab=guide')

    expect(await screen.findByText('Receiving webhooks')).toBeInTheDocument()
    expect(screen.getByText(/Standard Webhooks specification/)).toBeInTheDocument()
    expect(await screen.findByText('sale.completed.v1')).toBeInTheDocument()
    expect(screen.getByText('sale_id, receipt_number, totals.total_minor')).toBeInTheDocument()
  })

  it('sends a test event and rotates the secret from the webhook page', async () => {
    api.post.mockImplementation(async (path) =>
      path.endsWith('rotate-secret') ? { data: { ...HOOK, secret: 'whsec_bmV3LXNlY3JldA==', previous_secret_expires_at: '2026-10-17T09:00:00Z' } } : { data: { ...DELIVERY, event_type: 'webhook.test.v1' } },
    )
    renderApp('/settings/webhooks/w-1')

    expect(await screen.findByRole('heading', { name: 'Warehouse system' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Send test event' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('webhooks/w-1/test'))

    fireEvent.click(screen.getByRole('button', { name: 'Rotate secret' }))
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent('keeps signing for 24 hours')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Rotate secret' }))
    expect(await screen.findByTestId('webhook-signing-secret')).toHaveTextContent('whsec_bmV3LXNlY3JldA==')
  })

  it('is refused to users without the webhook permission', async () => {
    resetSession()
    signedIn()
    mockRoutes(api, [], { permissions: tenantWide(['core.user.view']) })
    renderApp('/settings/webhooks')

    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Webhooks' })).not.toBeInTheDocument())
    expect(api.get).not.toHaveBeenCalledWith('webhooks')
  })
})
