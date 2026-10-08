import { act, screen, waitFor } from '@testing-library/react'
import { api, getToken } from '@/api/client'
import { mockApi, OWNER, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

/** What the browser does in this tab when another tab changes the token. */
function otherTab(token) {
  if (token === null) window.localStorage.removeItem('app.token')
  else window.localStorage.setItem('app.token', token)
  act(() => {
    window.dispatchEvent(new StorageEvent('storage', { key: 'app.token', newValue: token, storageArea: window.localStorage }))
  })
}

describe('auth across tabs', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
  })

  it('follows a sign-in as someone else in another tab, dropping cached data', async () => {
    signedIn('token-a')
    mockApi(api)
    const { queryClient } = renderApp('/')
    expect(await screen.findByRole('heading', { level: 1, name: /Amina/ })).toBeInTheDocument()
    queryClient.setQueryData(['users', 'list'], { data: ['from user A'] })

    mockApi(api, { user: { ...OWNER, id: 'u-2', name: 'Baraka Mwangi' } })
    otherTab('token-b')

    expect(await screen.findByRole('heading', { level: 1, name: /Baraka/ })).toBeInTheDocument()
    expect(queryClient.getQueryData(['users', 'list'])).toBeUndefined()
    expect(getToken()).toBe('token-b')
  })

  it('signs this tab out when another tab signs out', async () => {
    signedIn('token-a')
    mockApi(api)
    const { router, queryClient } = renderApp('/settings/sessions')
    await screen.findByRole('heading', { level: 1, name: 'Sessions' })

    otherTab(null)

    await waitFor(() => expect(router.state.location.pathname).toBe('/sign-in'))
    expect(queryClient.getQueryData(['me'])).toBeUndefined()
    expect(getToken()).toBeNull()
  })

  it('ignores changes to other keys', async () => {
    signedIn('token-a')
    mockApi(api)
    renderApp('/')
    await screen.findByRole('heading', { level: 1, name: /Amina/ })
    const calls = api.get.mock.calls.length

    act(() => {
      window.dispatchEvent(new StorageEvent('storage', { key: 'app.theme', newValue: 'dark', storageArea: window.localStorage }))
    })

    expect(screen.getByRole('heading', { level: 1, name: /Amina/ })).toBeInTheDocument()
    expect(api.get.mock.calls.length).toBe(calls)
  })
})
