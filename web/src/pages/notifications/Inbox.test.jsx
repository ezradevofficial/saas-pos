import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const note = (id, subject, { read = false, archived = false, link = null } = {}) => ({
  id,
  event_type: 'core.notification.test',
  event_label: 'Test message',
  subject,
  body: `Body of ${subject}`,
  link,
  read_at: read ? '2026-10-08T10:00:00Z' : null,
  archived_at: archived ? '2026-10-08T11:00:00Z' : null,
  created_at: '2026-10-08T09:00:00Z',
})

const ROLE = note('n-1', 'Role changed', { link: '/settings/roles' })
const RATE = note('n-2', 'Rate missing')
const WELCOME = note('n-3', 'Welcome', { read: true })
const OLD = note('n-4', 'Old news', { read: true, archived: true })

const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: data.length ? 1 : null, to: data.length, unread: 2 } })

function setup() {
  mockRoutes(api, [
    ['notifications/unread-count', { data: { unread: 2 } }],
    [/^notifications\?status=active&sort=-created_at/, page([ROLE, RATE, WELCOME])],
    [/^notifications\?status=unread&sort=-created_at/, page([ROLE, RATE])],
    [/^notifications\?status=archived&sort=-created_at/, page([OLD])],
    // The bell's popover, when opened.
    [/^notifications\?status=/, page([])],
  ])
  api.post.mockResolvedValue({ data: {} })
}

const table = () => screen.getByRole('table')
const rowOf = (text) => within(table()).getByText(text).closest('tr')
const openMenu = (name) => fireEvent.pointerDown(screen.getByRole('button', { name }), { button: 0, ctrlKey: false })

describe('notifications inbox (NOT-01)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows every notification not archived, with read state as a dot and a word', async () => {
    setup()
    renderApp('/notifications')
    await screen.findByText('Role changed')
    expect(screen.getByRole('tab', { name: 'All' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tab', { name: /Unread/ })).toHaveTextContent('2')
    expect(within(rowOf('Role changed')).getByText('Unread')).toBeInTheDocument()
    expect(within(rowOf('Welcome')).getByText('Read')).toBeInTheDocument()
    expect(within(rowOf('Welcome')).queryByRole('button', { name: /Mark .* as read/ })).not.toBeInTheDocument()
  })

  it('switches to the unread and archived tabs', async () => {
    setup()
    const { router } = renderApp('/notifications')
    await screen.findByText('Welcome')

    fireEvent.mouseDown(screen.getByRole('tab', { name: /Unread/ }), { button: 0 })
    await waitFor(() => expect(router.state.location.search).toBe('?tab=unread'))
    await waitFor(() => expect(screen.queryByText('Welcome')).not.toBeInTheDocument())
    expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^notifications\?status=unread&/))

    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Archived' }), { button: 0 })
    expect(await screen.findByText('Old news')).toBeInTheDocument()
    // Archived notifications have no row actions.
    expect(within(rowOf('Old news')).queryByRole('button', { name: /Archive/ })).not.toBeInTheDocument()
  })

  it('opens a notification from its row and marks it read', async () => {
    setup()
    const { router } = renderApp('/notifications')
    fireEvent.click(await screen.findByText('Role changed'))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('notifications/n-1/read'))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/roles'))
  })

  it('marks one read and archives another from the row actions, staying on the page', async () => {
    setup()
    const { router } = renderApp('/notifications')
    await screen.findByText('Rate missing')

    fireEvent.click(screen.getByRole('button', { name: 'Mark “Rate missing” as read' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('notifications/n-2/read'))

    fireEvent.click(screen.getByRole('button', { name: 'Archive “Welcome”' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('notifications/n-3/archive'))
    expect(router.state.location.pathname).toBe('/notifications')
  })

  it('marks all as read from the page header', async () => {
    setup()
    renderApp('/notifications')
    await screen.findByText('Role changed')
    // The bell's popover is closed: the only such button is the page's.
    fireEvent.click(screen.getByRole('button', { name: 'Mark all as read' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('notifications/read-all'))
  })

  it('exports the current tab (EXP-01)', async () => {
    setup()
    api.download.mockResolvedValue({ blob: new Blob(['x']), filename: 'notifications.xlsx' })
    URL.createObjectURL = vi.fn(() => 'blob:notifications')
    URL.revokeObjectURL = vi.fn()
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    renderApp('/notifications?tab=unread')
    await screen.findByText('Rate missing')

    openMenu('Export')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Excel (.xlsx)' }))
    await waitFor(() => expect(api.download).toHaveBeenCalledTimes(1))
    const params = new URLSearchParams(api.download.mock.calls[0][0].split('?')[1])
    expect(params.get('status')).toBe('unread')
    expect(params.get('format')).toBe('xlsx')
    expect(params.getAll('columns[]')).toEqual(['read', 'subject', 'type', 'created_at'])
    click.mockRestore()
  })
})
