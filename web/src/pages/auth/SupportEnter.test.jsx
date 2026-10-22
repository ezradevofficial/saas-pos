import { QueryClient } from '@tanstack/react-query'
import { Suspense } from 'react'
import { render, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { AppProviders } from '@/App'
import { api } from '@/api/client'
import { mockApi, resetSession } from '@/test/renderApp'
import SupportEnter from './SupportEnter'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

// A sibling that suspends on its first render, so React throws away the first
// (uncommitted) render of the page and renders it again from scratch.
let pending = null
function SuspendOnce() {
  if (pending === null) pending = new Promise((resolve) => setTimeout(resolve, 10))
  if (pending.done !== true) {
    pending.then(() => { pending.done = true })
    throw pending
  }
  return null
}

function renderEnter() {
  const router = createMemoryRouter(
    [{ path: '/support/enter', element: <Suspense fallback={null}><SupportEnter /><SuspendOnce /></Suspense> }, { path: '/', element: <p>Back office</p> }],
    { initialEntries: ['/support/enter'] },
  )
  return render(
    <AppProviders queryClient={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <RouterProvider router={router} />
    </AppProviders>,
  )
}

// RBAC-13c: the console opens /support/enter#code=… in a new tab. The Phase 8
// walkthrough once found the page saying the link had no code: the code was
// read and the fragment wiped inside the state initializer, so a render React
// threw away (a suspending sibling, StrictMode's double call) left the next
// render with nothing to read.
describe('Support session entry (RBAC-13)', () => {
  beforeEach(() => {
    resetSession()
    window.sessionStorage.clear()
    vi.clearAllMocks()
    pending = null
    mockApi(api)
  })

  it('exchanges the code from the fragment once, even when a first render is thrown away, and wipes the fragment', async () => {
    api.post.mockResolvedValue({ data: { token: 'support-token' } })
    // RBAC-13c: the browser's own sign-in, which the support tab must leave alone.
    window.localStorage.setItem('app.token', 'tenant-token')
    window.history.replaceState(null, '', '/support/enter#code=t-1.secret')
    renderEnter()

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('auth/support-session', { code: 't-1.secret' }))
    expect(api.post).toHaveBeenCalledTimes(1)
    expect(window.location.hash).toBe('')
    await waitFor(() => expect(window.sessionStorage.getItem('app.token')).toBe('support-token'))
    expect(window.localStorage.getItem('app.token')).toBe('tenant-token')
  })
})
