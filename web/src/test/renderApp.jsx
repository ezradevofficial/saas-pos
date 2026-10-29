// Renders the real routes at a path with fresh providers. Tests mock the API
// client's `api` object with vi.mock (see mockApi) and keep the real token
// storage, so `signedIn()` simply stores a token.
import { QueryClient } from '@tanstack/react-query'
import { Suspense } from 'react'
import { render } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { AppProviders } from '@/App'
import { ApiError, clearToken, setCompanyId, setToken } from '@/api/client'
import { resetShellBootstrap } from '@/api/shellBootstrap'
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
  tenant: { id: 't-1', name: 'Amani Retail Group', default_locale: 'en' },
}

export const ALL_CORE = ['core.company.view', 'core.user.view', 'core.role.view'].map((name) => ({
  name,
  scopes: [{ type: 'tenant', id: 't-1' }],
}))

export function apiError(status, code, message, errors) {
  return new ApiError({ status, code, message, errors })
}

const isGrantable = (path) => path.startsWith('roles?per_page=200&grantable_at=')

/** Default GET answers for a signed-in user; `extra` adds or replaces paths. */
export function mockApi(api, { user = OWNER, permissions = ALL_CORE, modules = ['core'], companies = [], extra = {} } = {}) {
  api.get.mockImplementation(async (path) => {
    if (path in extra) {
      const answer = extra[path]
      if (answer instanceof Error) throw answer
      return typeof answer === 'function' ? answer() : answer
    }
    // Role pickers ask which roles may be granted at a place (security review 5):
    // `roles:grantable` answers them (a function gets the path), else the role list.
    if (isGrantable(path)) {
      const answer = extra['roles:grantable'] ?? extra['roles?per_page=200']
      if (answer !== undefined) return typeof answer === 'function' ? answer(path) : answer
    }
    if (path === 'me') return { data: user }
    if (path === 'me/permissions') return { permissions, modules }
    // The bell in the app shell (NOT-01).
    if (path === 'notifications/unread-count') return { data: { unread: 0 } }
    // The approvals badge in the sidebar (APR-04).
    if (path === 'approvals?status=waiting&per_page=1&page=1') return { data: [], meta: { total: 0 } }
    if (path.startsWith('companies')) return { data: companies, meta: { total: companies.length } }
    // The switcher's companies for a user without core.company.view (RBAC-04).
    if (path === 'me/companies') return { data: companies }
    throw apiError(404, 'not_found', 'Not found.')
  })
}

export function signedIn(token = 'test-token') {
  setToken(token)
}

export function resetSession() {
  resetShellBootstrap()
  clearToken()
  setCompanyId(null)
  try {
    window.localStorage.clear()
    // RBAC-13c: a support session tab's token lives in sessionStorage.
    window.sessionStorage.clear()
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
      <Suspense fallback={null}>
        <RouterProvider router={router} />
      </Suspense>
    </AppProviders>,
  )
  return { ...utils, router, queryClient }
}

/**
 * GET answers matched by exact path or RegExp, before mockApi's defaults
 * (which answer every `companies…` path with the company list).
 */
export function mockRoutes(api, routes, options = {}) {
  mockApi(api, options)
  const fallback = api.get.getMockImplementation()
  api.get.mockImplementation(async (path) => {
    // As mockApi: role pickers' grantable roles default to the role list.
    if (isGrantable(path) && !routes.some(([match]) => match === 'roles:grantable' || (typeof match !== 'string' && match.test(path)))) {
      const list = routes.find(([match]) => match === 'roles?per_page=200')
      if (list) return typeof list[1] === 'function' ? list[1](path) : list[1]
    }
    if (isGrantable(path)) {
      const own = routes.find(([match]) => match === 'roles:grantable')
      if (own) return typeof own[1] === 'function' ? own[1](path) : own[1]
    }
    for (const [match, answer] of routes) {
      if (typeof match === 'string' ? path === match : match.test(path)) {
        if (answer instanceof Error) throw answer
        return typeof answer === 'function' ? answer(path) : answer
      }
    }
    return fallback(path)
  })
}

/** Every listed permission, tenant-wide. */
export const tenantWide = (names) => names.map((name) => ({ name, scopes: [{ type: 'tenant', id: 't-1' }] }))

/** A DR Congo company (CDF, Kinshasa time). */
export const CD_COMPANY = {
  id: 'c-1',
  name: 'Kin Market',
  country: 'CD',
  base_currency: 'CDF',
  base_currency_locked: false,
  timezone: 'Africa/Kinshasa',
  archived_at: null,
}
