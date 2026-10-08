import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const note = (id, subject, { read = false, link = null, created = '2026-10-08T09:00:00Z' } = {}) => ({
  id,
  event_type: 'core.notification.test',
  event_label: 'Test message',
  subject,
  body: `Body of ${subject}`,
  link,
  read_at: read ? '2026-10-08T10:00:00Z' : null,
  archived_at: null,
  created_at: created,
})

const UNREAD = [note('n-1', 'Role changed', { link: '/settings/users' }), note('n-2', 'Rate missing')]
const READ = note('n-3', 'Welcome', { read: true })

function setup({ unread = 2 } = {}) {
  let count = unread
  mockRoutes(api, [
    ['notifications/unread-count', () => ({ data: { unread: count } })],
    ['notifications?status=unread&per_page=8&page=1', { data: UNREAD, meta: { total: 2 } }],
    // The newest first: a read one is newer than the unread ones.
    ['notifications?status=active&per_page=8&page=1', { data: [READ, ...UNREAD], meta: { total: 3 } }],
  ])
  api.post.mockImplementation(async (path) => {
    if (path === 'notifications/read-all') {
      count = 0
      return { data: { updated: 2, unread: 0 } }
    }
    if (path.endsWith('/read')) {
      count = Math.max(0, count - 1)
      return { data: {} }
    }
    return null
  })
}

const bell = async (name) => (await screen.findAllByRole('button', { name }))[0]

describe('notification bell (NOT-01)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the unread count in its label and badge', async () => {
    setup({ unread: 3 })
    renderApp('/')
    const button = await bell('Notifications, 3 unread')
    expect(within(button).getByTestId('unread-count')).toHaveTextContent('3')
    // A quiet chip: the accent is kept for each screen's one decisive action.
    expect(within(button).getByTestId('unread-count')).toHaveClass('bg-surface-300', 'text-ink')
    expect(within(button).getByTestId('unread-count')).not.toHaveClass('bg-accent')
  })

  it('has no count when everything is read', async () => {
    setup({ unread: 0 })
    renderApp('/')
    const button = await bell('Notifications')
    expect(within(button).queryByTestId('unread-count')).not.toBeInTheDocument()
  })

  it('asks for the count again when the window regains focus', async () => {
    setup()
    renderApp('/')
    await bell('Notifications, 2 unread')
    const calls = () => api.get.mock.calls.filter(([path]) => path === 'notifications/unread-count').length
    const before = calls()
    fireEvent.focus(window)
    await waitFor(() => expect(calls()).toBeGreaterThan(before))
  })

  it('lists the latest notifications, unread first', async () => {
    setup()
    renderApp('/')
    fireEvent.click(await bell('Notifications, 2 unread'))
    const list = await screen.findByRole('list', { name: 'Latest notifications' })
    const items = within(list).getAllByRole('button')
    expect(items.map((item) => item.textContent)).toEqual([
      expect.stringContaining('Role changed'),
      expect.stringContaining('Rate missing'),
      expect.stringContaining('Welcome'),
    ])
    expect(within(items[0]).getByText('Unread')).toBeInTheDocument()
    expect(within(items[2]).queryByText('Unread')).not.toBeInTheDocument()
  })

  it('marks a notification read and opens its link', async () => {
    setup()
    const { router } = renderApp('/')
    fireEvent.click(await bell('Notifications, 2 unread'))
    const list = await screen.findByRole('list', { name: 'Latest notifications' })
    fireEvent.click(within(list).getByRole('button', { name: /Role changed/ }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('notifications/n-1/read'))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/users'))
  })

  it('does not mark a read notification again', async () => {
    setup()
    renderApp('/')
    fireEvent.click(await bell('Notifications, 2 unread'))
    const list = await screen.findByRole('list', { name: 'Latest notifications' })
    fireEvent.click(within(list).getByRole('button', { name: /Welcome/ }))
    expect(api.post).not.toHaveBeenCalled()
  })

  it('marks all as read and clears the count', async () => {
    setup()
    renderApp('/')
    fireEvent.click(await bell('Notifications, 2 unread'))
    fireEvent.click(await screen.findByRole('button', { name: 'Mark all as read' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('notifications/read-all'))
    expect(await bell('Notifications')).toBeInTheDocument()
  })

  it('opens the inbox from See all', async () => {
    setup()
    mockRoutes(api, [
      ['notifications/unread-count', { data: { unread: 2 } }],
      ['notifications?status=unread&per_page=8&page=1', { data: UNREAD }],
      [/^notifications\?status=active/, { data: [READ, ...UNREAD], meta: { total: 3, last_page: 1, from: 1, to: 3 } }],
    ])
    const { router } = renderApp('/')
    fireEvent.click(await bell('Notifications, 2 unread'))
    fireEvent.click(await screen.findByRole('button', { name: 'See all' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/notifications'))
  })
})
