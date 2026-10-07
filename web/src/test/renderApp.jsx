// Renders the real routes at a path with fresh providers. Tests mock the API
// client's `api` object with vi.mock (see mockApi) and keep the real token
// storage, so `signedIn()` simply stores a token.
import { QueryClient } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { AppProviders } from '@/App'
import { ApiError, clearToken, setCompanyId, setToken } from '@/api/client'
import { routes } from '@/routes'

export const OWNER = {
  id: 'u-1',
  tenant_id: 't-1',
  name: 'Amina Otieno',
  email: 'amina@example.com',
  phone: null,
  locale: 'en',
  status: 'active',
  two_factor_enabled: false,
}

export const ALL_CORE = ['core.company.view', 'core.user.view', 'core.role.view'].map((name) => ({
  name,
  scopes: [{ type: 'tenant', id: 't-1' }],
}))

export function apiError(status, code, message, errors) {
  return new ApiError({ status, code, message, errors })
}

/** Default GET answers for a signed-in user; `extra` adds or replaces paths. */
export function mockApi(api, { user = OWNER, permissions = ALL_CORE, modules = ['core'], companies = [], extra = {} } = {}) {
  api.get.mockImplementation(async (path) => {
    if (path in extra) {
      const answer = extra[path]
      if (answer instanceof Error) throw answer
      return typeof answer === 'function' ? answer() : answer
    }
    if (path === 'me') return { data: user }
    if (path === 'me/permissions') return { permissions, modules }
    if (path.startsWith('companies')) return { data: companies, meta: { total: companies.length } }
    throw apiError(404, 'not_found', 'Not found.')
  })
}

export function signedIn(token = 'test-token') {
  setToken(token)
}

export function resetSession() {
  clearToken()
  setCompanyId(null)
  try {
    window.localStorage.clear()
  } catch {
    // ignore
  }
}

export function renderApp(path = '/') {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  const router = createMemoryRouter(routes, { initialEntries: [path] })
  const utils = render(
    <AppProviders queryClient={queryClient}>
      <RouterProvider router={router} />
    </AppProviders>,
  )
  return { ...utils, router, queryClient }
}
