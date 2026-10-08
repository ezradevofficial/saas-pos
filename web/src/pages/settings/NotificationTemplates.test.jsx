import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const SUBJECT = 'Test message from {sender_name}'
const BODY = 'Hello {recipient_name}'
const SAMPLES = { recipient_name: 'Amina Otieno', sender_name: 'Joseph Mwangi', message: 'Stock count at 5 pm' }

// One text per channel (no per-language versions); the default comes in the admin's language (NOT-03).
function typeWith({ overridden = true } = {}) {
  const templates = ['all', 'in_app', 'email', 'sms'].map((channel) => {
    const own = overridden && channel === 'all'
    return {
      channel,
      subject: SUBJECT,
      body: own ? 'Hi {recipient_name}, from {sender_name}' : BODY,
      source: own ? 'all' : 'default',
      overridden: own,
      updated_at: null,
      default: { locale: 'en', subject: SUBJECT, body: BODY },
    }
  })
  return {
    event_type: 'core.notification.test',
    label: 'Test message',
    module: 'core',
    channels: ['in_app', 'email', 'sms'],
    placeholders: Object.entries(SAMPLES).map(([name, sample]) => ({ name, sample })),
    templates,
  }
}

const OTHER = { ...typeWith({ overridden: false }), event_type: 'core.approval.requested', label: 'Approval requested' }

const EVENT_TYPES = [
  {
    event_type: 'core.notification.test',
    label: 'Test message',
    module: 'core',
    channels: [
      { channel: 'in_app', label: 'In-app', default: true, mandatory: false, available: true },
      { channel: 'email', label: 'Email', default: true, mandatory: false, available: true },
      { channel: 'sms', label: 'SMS', default: false, mandatory: false, available: false },
    ],
    mandatory_allowed: true,
    mandatory_channels: [],
    placeholders: [],
  },
]

const render = (text) => text.replace(/\{(\w+)\}/g, (_, name) => SAMPLES[name] ?? `{${name}}`)
const previews = () => api.post.mock.calls.filter(([path]) => path === 'notification-templates/preview')

function setup({ permissions = ['core.notification_template.view', 'core.notification_template.edit', 'core.notification_settings.edit'] } = {}) {
  mockRoutes(
    api,
    [
      ['notification-templates', { data: [typeWith(), OTHER] }],
      ['notification-event-types', { data: EVENT_TYPES }],
    ],
    { permissions: tenantWide(['core.company.view', ...permissions]) },
  )
  api.post.mockImplementation(async (path, body) => {
    if (path === 'notification-templates/preview') {
      if (/\{bogus\}/.test(`${body.subject ?? ''} ${body.body ?? ''}`)) {
        throw apiError(422, 'validation_failed', 'The given data was invalid.', {
          body: ['This text uses placeholders this notification doesn’t have: {bogus}. Use only: {recipient_name}, {sender_name}, {message}.'],
        })
      }
      return { data: { subject: render(body.subject ?? SUBJECT), body: render(body.body ?? BODY) } }
    }
    if (path === 'notification-templates/reset') return { data: typeWith({ overridden: false }) }
    return null
  })
  api.put.mockImplementation(async (path, body) => {
    if (path === 'notification-settings') return { data: [{ ...EVENT_TYPES[0], mandatory_channels: body.event_types[0].mandatory_channels }] }
    return { data: typeWith() }
  })
}

const bodyBox = () => screen.getByRole('textbox', { name: 'Message' })

describe('notification templates (NOT-03, NOT-04)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists event types and shows the text in use with a live preview', async () => {
    setup()
    renderApp('/settings/notification-templates')
    const types = await screen.findByRole('navigation', { name: 'Notification types' })
    expect(within(types).getByRole('button', { name: 'Test message' })).toHaveAttribute('aria-current', 'true')
    expect(within(types).getByRole('button', { name: 'Approval requested' })).toBeInTheDocument()

    expect(bodyBox()).toHaveValue('Hi {recipient_name}, from {sender_name}')
    expect(screen.getByText('Your text for all channels')).toBeInTheDocument()
    const preview = await screen.findByTestId('template-preview')
    expect(preview).toHaveTextContent('Hi Amina Otieno, from Joseph Mwangi')
    expect(preview).toHaveTextContent('Test message from Joseph Mwangi')
  })

  it('has one text for everyone, shown next to the built-in default, with no language tabs', async () => {
    setup()
    renderApp('/settings/notification-templates')
    await screen.findByTestId('template-preview')

    expect(screen.queryByRole('tab', { name: 'French' })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'English' })).not.toBeInTheDocument()
    expect(screen.getByText('Written once, in your organisation’s language. Everyone receives this text.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Built-in default' })).toBeInTheDocument()
    expect(screen.getByTestId('template-default')).toHaveTextContent(BODY)
    expect(screen.getByTestId('template-default')).toHaveTextContent(SUBJECT)
    expect(bodyBox()).toHaveValue('Hi {recipient_name}, from {sender_name}')

    // A channel still on the default shows it in the editor, not twice.
    chooseOption('Channel', 'Email')
    await waitFor(() => expect(bodyBox()).toHaveValue(BODY))
    expect(screen.queryByTestId('template-default')).not.toBeInTheDocument()
  })

  it('inserts a placeholder at the cursor of the field last used', async () => {
    setup()
    renderApp('/settings/notification-templates')
    await screen.findByTestId('template-preview')

    const body = bodyBox()
    fireEvent.change(body, { target: { value: 'Hello there' } })
    fireEvent.focus(body)
    body.setSelectionRange(6, 6)
    fireEvent.click(screen.getByRole('button', { name: 'Insert {sender_name}' }))
    expect(body).toHaveValue('Hello {sender_name}there')

    const subject = screen.getByRole('textbox', { name: 'Subject' })
    fireEvent.change(subject, { target: { value: 'News' } })
    fireEvent.focus(subject)
    subject.setSelectionRange(4, 4)
    fireEvent.click(screen.getByRole('button', { name: 'Insert {message}' }))
    expect(subject).toHaveValue('News{message}')
  })

  it('asks for the preview once typing pauses, not on every keystroke', async () => {
    setup()
    renderApp('/settings/notification-templates')
    await screen.findByTestId('template-preview')
    const before = previews().length

    fireEvent.change(bodyBox(), { target: { value: 'H' } })
    fireEvent.change(bodyBox(), { target: { value: 'Hi' } })
    fireEvent.change(bodyBox(), { target: { value: 'Hi {sender_name}' } })

    await waitFor(() => expect(screen.getByTestId('template-preview')).toHaveTextContent('Hi Joseph Mwangi'))
    const asked = previews().slice(before).map(([, body]) => body.body)
    expect(asked).toEqual(['Hi {sender_name}'])
    expect(previews().at(-1)[1]).toEqual({
      event_type: 'core.notification.test',
      channel: 'all',
      subject: SUBJECT,
      body: 'Hi {sender_name}',
    })
  })

  it('shows an unknown placeholder under the message', async () => {
    setup()
    renderApp('/settings/notification-templates')
    await screen.findByTestId('template-preview')
    fireEvent.change(bodyBox(), { target: { value: 'Hello {bogus}' } })
    expect(await screen.findByText(/placeholders this notification doesn’t have: \{bogus\}/)).toBeInTheDocument()
    expect(bodyBox()).toHaveAttribute('aria-invalid', 'true')
  })

  it('saves the one text for the chosen channel, without a language', async () => {
    setup()
    renderApp('/settings/notification-templates')
    await screen.findByTestId('template-preview')
    chooseOption('Channel', 'Email')
    await waitFor(() => expect(bodyBox()).toHaveValue(BODY))

    fireEvent.change(bodyBox(), { target: { value: 'Bonjour {recipient_name}' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith('notification-templates', {
        event_type: 'core.notification.test',
        channel: 'email',
        subject: SUBJECT,
        body: 'Bonjour {recipient_name}',
      }),
    )
    expect(await screen.findByText('Template saved')).toBeInTheDocument()
  })

  it('goes back to the default text after confirming in the page', async () => {
    setup()
    renderApp('/settings/notification-templates')
    await screen.findByTestId('template-preview')

    fireEvent.click(screen.getByRole('button', { name: 'Use default' }))
    expect(screen.getByText('Use the default text?')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Keep my text' }))
    expect(screen.queryByText('Use the default text?')).not.toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalledWith('notification-templates/reset', expect.anything())

    fireEvent.click(screen.getByRole('button', { name: 'Use default' }))
    const confirm = screen.getByText('Use the default text?').closest('[role="status"]')
    fireEvent.click(within(confirm).getByRole('button', { name: 'Use default' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('notification-templates/reset', { event_type: 'core.notification.test', channel: 'all' }))
    await waitFor(() => expect(bodyBox()).toHaveValue(BODY))
    expect(screen.getByText('Default text')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Use default' })).not.toBeInTheDocument()
  })

  it('makes a channel required for everyone (NOT-04)', async () => {
    setup()
    renderApp('/settings/notification-templates')
    const card = (await screen.findByRole('heading', { name: 'Required channels' })).closest('[data-slot="card"]')
    fireEvent.click(within(card).getByRole('switch', { name: 'Email' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith('notification-settings', {
        event_types: [{ event_type: 'core.notification.test', mandatory_channels: ['email'] }],
      }),
    )
    await waitFor(() => expect(within(card).getByRole('switch', { name: 'Email' })).toBeChecked())
  })

  it('is read-only without the edit permission, and has no required channels without settings.edit', async () => {
    setup({ permissions: ['core.notification_template.view'] })
    renderApp('/settings/notification-templates')
    await screen.findByTestId('template-preview')
    expect(bodyBox()).toHaveAttribute('readonly')
    expect(screen.queryByRole('button', { name: 'Save template' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Insert/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Required channels' })).not.toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('notification-event-types')
  })
})
