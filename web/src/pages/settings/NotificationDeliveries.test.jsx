import { screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const delivery = (id, status, extra = {}) => ({
  id,
  user: { id: 'u-2', name: 'Joseph Mwangi' },
  user_id: 'u-2',
  event_type: 'core.notification.test',
  event_label: 'Test message',
  channel: 'email',
  status,
  reason: null,
  reason_label: null,
  recipient: 'joseph@example.com',
  attempts: 1,
  error: null,
  sent_at: null,
  created_at: '2026-10-08T09:00:00Z',
  ...extra,
})

const ROWS = [
  delivery('d-1', 'delivered', { sent_at: '2026-10-08T09:00:05Z' }),
  delivery('d-2', 'failed', { attempts: 3, error: 'Mailbox unavailable' }),
  delivery('d-3', 'skipped', { channel: 'sms', recipient: null, reason: 'no_phone', reason_label: 'The user has no verified phone number.' }),
]

const ADMIN = tenantWide(['core.company.view', 'core.notification_delivery.view'])
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })

function setup() {
  mockRoutes(
    api,
    [
      [/^notification-deliveries\?status=failed/, page([ROWS[1]])],
      [/^notification-deliveries\?(status=failed&)?channel=sms/, page([ROWS[2]])],
      [/^notification-deliveries\?sort=-created_at/, page(ROWS)],
    ],
    { permissions: ADMIN },
  )
}

describe('notification delivery log (NOT-06)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists deliveries with status as a dot and a word, and the reason', async () => {
    setup()
    renderApp('/settings/notification-deliveries')
    const table = await screen.findByRole('table')
    await within(table).findByText('Delivered')
    const failed = within(table).getByText('Failed').closest('tr')
    expect(within(failed).getByText('Mailbox unavailable')).toBeInTheDocument()
    expect(within(failed).getByText('3')).toBeInTheDocument()
    expect(within(failed).getByText('Failed').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')
    expect(within(table).getByText('The user has no verified phone number.')).toBeInTheDocument()
  })

  it('filters by status and channel through the API', async () => {
    setup()
    const { router } = renderApp('/settings/notification-deliveries')
    await screen.findByText('Delivered')

    chooseOption('Status', 'Failed')
    await waitFor(() => expect(router.state.location.search).toBe('?status=failed'))
    await waitFor(() => expect(screen.queryByText('Delivered')).not.toBeInTheDocument())
    expect(api.get).toHaveBeenCalledWith('notification-deliveries?status=failed&sort=-created_at&per_page=25&page=1')

    chooseOption('Channel', 'SMS')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('notification-deliveries?status=failed&channel=sms&sort=-created_at&per_page=25&page=1'))
  })

  it('refuses the page without core.notification_delivery.view at tenant scope (RBAC-09)', async () => {
    mockRoutes(api, [], { permissions: [{ name: 'core.notification_delivery.view', scopes: [{ type: 'company', id: 'c-1' }] }] })
    renderApp('/settings/notification-deliveries')
    expect(await screen.findByRole('heading', { level: 1 })).not.toHaveTextContent('Delivery log')
    expect(api.get).not.toHaveBeenCalledWith(expect.stringMatching(/^notification-deliveries/))
  })
})
