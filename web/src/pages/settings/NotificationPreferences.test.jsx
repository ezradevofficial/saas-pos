import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, comboboxFor } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const channel = (name, label, enabled, mandatory = false) => ({ channel: name, label, enabled, mandatory })

const PREFERENCES = [
  {
    event_type: 'core.approval.requested',
    label: 'Approval requested',
    module: 'core',
    // The organisation requires email: it stays on and cannot wait for a digest.
    channels: [channel('in_app', 'In-app', true), channel('email', 'Email', true, true), channel('sms', 'SMS', false)],
    digest: 'immediate',
    digest_allowed: false,
  },
  {
    event_type: 'core.notification.test',
    label: 'Test message',
    module: 'core',
    channels: [channel('in_app', 'In-app', true), channel('email', 'Email', false)],
    digest: 'immediate',
    digest_allowed: true,
  },
]

const EVENT_TYPES = [
  {
    event_type: 'core.approval.requested',
    channels: [
      { channel: 'in_app', available: true },
      { channel: 'email', available: true },
      // No SMS provider yet (NOT-01): the switch is not offered.
      { channel: 'sms', available: false },
    ],
  },
  { event_type: 'core.notification.test', channels: [{ channel: 'in_app', available: true }, { channel: 'email', available: true }] },
]

function setup() {
  mockRoutes(api, [
    ['me/notification-preferences', { data: PREFERENCES }],
    ['notification-event-types', { data: EVENT_TYPES }],
  ])
  api.put.mockImplementation(async () => ({ data: PREFERENCES }))
}

const rowOf = (label) => screen.getByRole('heading', { name: label }).closest('li')

describe('my notification settings (NOT-04, NOT-05)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows mandatory channels on and locked, and hides channels with no provider', async () => {
    setup()
    renderApp('/settings/notifications')
    await screen.findByRole('heading', { name: 'Approval requested' })
    const row = rowOf('Approval requested')

    const email = within(row).getByRole('switch', { name: 'Email' })
    expect(email).toBeChecked()
    expect(email).toBeDisabled()
    expect(within(row).getByText('Required by your organisation')).toBeInTheDocument()
    expect(within(row).getByRole('switch', { name: 'In-app' })).toBeEnabled()
    await waitFor(() => expect(within(row).queryByRole('switch', { name: 'SMS' })).not.toBeInTheDocument())
    // Email must go at once: the timing is fixed.
    expect(comboboxFor(within(row).getByLabelText('Email timing'))).toBeDisabled()
  })

  it('saves channel switches and email timing, leaving mandatory channels out', async () => {
    setup()
    renderApp('/settings/notifications')
    await screen.findByRole('heading', { name: 'Test message' })
    const save = screen.getByRole('button', { name: 'Save changes' })
    expect(save).toBeDisabled()

    const row = rowOf('Test message')
    fireEvent.click(within(row).getByRole('switch', { name: 'Email' }))
    chooseOption(within(row).getByLabelText('Email timing'), 'Daily digest')
    fireEvent.click(within(rowOf('Approval requested')).getByRole('switch', { name: 'In-app' }))

    fireEvent.click(save)
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1))
    expect(api.put).toHaveBeenCalledWith('me/notification-preferences', {
      preferences: [
        { event_type: 'core.approval.requested', channels: { in_app: false, sms: false } },
        { event_type: 'core.notification.test', channels: { in_app: true, email: true }, digest: 'daily' },
      ],
    })
    expect(await screen.findByText('Notification settings saved')).toBeInTheDocument()
  })

  it('shows why a save failed', async () => {
    setup()
    api.put.mockRejectedValue(Object.assign(new Error('x'), { status: 422, code: 'validation_failed', message: 'Your organisation requires Email.', errors: {} }))
    renderApp('/settings/notifications')
    await screen.findByRole('heading', { name: 'Test message' })
    fireEvent.click(within(rowOf('Test message')).getByRole('switch', { name: 'In-app' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByText('Your organisation requires Email.')).toBeInTheDocument()
  })
})
