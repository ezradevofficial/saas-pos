import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockApi, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const SESSIONS = [
  { id: 's-1', name: 'Chrome · macOS', ip: '10.0.0.1', user_agent: 'Mozilla/5.0', last_used_at: '2026-10-07T11:05:00Z', created_at: '2026-10-07T09:00:00Z', current: true },
  { id: 's-2', name: 'Safari · iOS', ip: '10.0.0.2', user_agent: 'Mozilla/5.0 (iPhone)', last_used_at: '2026-10-06T08:00:00Z', created_at: '2026-10-01T08:00:00Z', current: false },
]

describe('Sessions', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists sessions and marks this device', async () => {
    mockApi(api, { extra: { 'auth/sessions?per_page=25&page=1': { data: SESSIONS, meta: { last_page: 1, total: 2, from: 1, to: 2 } } } })
    renderApp('/settings/sessions')
    const row = (await screen.findByText('Chrome · macOS')).closest('tr')
    expect(within(row).getByText('This device')).toBeInTheDocument()
    const other = screen.getByText('Safari · iOS').closest('tr')
    expect(within(other).getByText('10.0.0.2')).toBeInTheDocument()
    expect(within(other).getByText(/6 Oct 2026/)).toBeInTheDocument()
    // The browser is a column the user can show (export key user_agent); hidden by default.
    expect(screen.queryByRole('columnheader', { name: 'Browser or app' })).not.toBeInTheDocument()
    fireEvent.pointerDown(screen.getByRole('button', { name: 'Columns' }), { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('menuitemcheckbox', { name: 'Browser or app' }))
    expect(screen.getByRole('columnheader', { name: 'Browser or app', hidden: true })).toBeInTheDocument()
  })

  it('signs out of another session', async () => {
    let sessions = SESSIONS
    mockApi(api, { extra: { 'auth/sessions?per_page=25&page=1': () => ({ data: sessions, meta: { last_page: 1, total: sessions.length, from: 1, to: sessions.length } }) } })
    api.delete.mockImplementation(async () => {
      sessions = [SESSIONS[0]]
      return null
    })
    renderApp('/settings/sessions')
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out of Safari · iOS' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('auth/sessions/s-2'))
    await waitFor(() => expect(screen.queryByText('Safari · iOS')).not.toBeInTheDocument())
  })

  it('asks before signing out of this device', async () => {
    mockApi(api, { extra: { 'auth/sessions?per_page=25&page=1': { data: SESSIONS, meta: { last_page: 1, total: 2, from: 1, to: 2 } } } })
    api.post.mockResolvedValue(null)
    const { router } = renderApp('/settings/sessions')

    fireEvent.click(await screen.findByRole('button', { name: 'Sign out of Chrome · macOS' }))
    const dialog = await screen.findByRole('dialog', { name: 'Sign out of this device?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Stay signed in' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(api.post).not.toHaveBeenCalledWith('auth/sign-out')

    fireEvent.click(screen.getByRole('button', { name: 'Sign out of Chrome · macOS' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Sign out' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/sign-in'))
    expect(api.post).toHaveBeenCalledWith('auth/sign-out')
    expect(api.delete).not.toHaveBeenCalled()
  })

  it('names a session without a device name "Unknown device"', async () => {
    mockApi(api, { extra: { 'auth/sessions?per_page=25&page=1': { data: [{ ...SESSIONS[1], name: '' }], meta: { last_page: 1, total: 1, from: 1, to: 1 } } } })
    renderApp('/settings/sessions')
    expect(await screen.findByText('Unknown device')).toBeInTheDocument()
  })
})
