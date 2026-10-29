// NFR-02: the app shell's payloads in one call (GET bootstrap) instead of a
// dozen. The parts seed the React Query caches under the keys the shell's
// own hooks use, so components keep working and refetch on their own after a
// mutation or an invalidation (a role change refetches me/permissions from
// its own endpoint, never from here).
//
// Two ways a part reaches a hook:
// - `fromShell(name, fetcher)` wraps the hook's own request: a hook that
//   mounts while the bootstrap is in flight waits for it and takes its part
//   once, instead of firing its own call. Without a bootstrap, or when the
//   part is missing (null: not for this user, or the call failed), it runs
//   its own request as before.
// - the cache is seeded for hooks that mount later (those that wait for
//   permissions first), under the same keys.
import { api, getCompanyId, getToken } from './client'

/** How long an unused part may still be taken (ms). Later reads always go to the API. */
export const PART_LIFE_MS = 5000

let run = null

const SEEDS = {
  subscription: () => [['subscription', 'status'], (part) => part],
  trial: () => [['onboarding', 'trial'], (part) => part?.data],
  unread: () => [['notifications', 'unread-count'], (part) => part],
  support_active: () => [['support-access', 'active'], (part) => part],
  approvals_waiting: () => [['approvals', 'waiting-count'], (part) => part],
  navigation: () => [['config', 'navigation', 'resolved'], (part) => part],
  usage: () => [['usage'], (part) => part],
  custom_form_types: () => [['custom-form-types', 'active'], (part) => part],
  hr_me: () => [['hr', 'me'], (part) => part],
}

function seed(queryClient, parts) {
  const set = (key, value) => {
    if (value !== null && value !== undefined) queryClient.setQueryData(key, value)
  }
  set(['me', 'permissions'], parts.permissions)
  for (const [name, entry] of Object.entries(SEEDS)) {
    const [key, shape] = entry()
    set(key, shape(parts[name]))
  }
  // The switcher's list depends on what the user may view (see useCompanies).
  const scopes = parts.permissions?.permissions?.find((permission) => permission.name === 'core.company.view')?.scopes ?? []
  const viewer = scopes.length > 0 && scopes.every((scope) => scope.type === 'tenant' || scope.type === 'company')
  set(['companies', 'switcher', viewer ? 'all' : 'mine'], viewer ? parts.companies : parts.my_companies)
}

/**
 * Starts the bootstrap for the current token (once per token) and seeds the
 * caches when it answers. Safe to call on every render. Never throws: a
 * failed bootstrap leaves every hook to fetch for itself.
 */
export function startShellBootstrap(queryClient) {
  const token = getToken()
  if (!token) return null
  if (run && run.token === token && run.queryClient === queryClient) return run

  const company = getCompanyId()
  const state = { token, queryClient, parts: null, promise: null, settled: false }
  state.promise = api
    .get(`bootstrap${company ? `?company=${encodeURIComponent(company)}` : ''}`)
    .then((response) => {
      const parts = response?.data
      if (!parts || typeof parts !== 'object' || getToken() !== token) return null
      state.parts = { ...parts, company }
      seed(queryClient, parts)
      setTimeout(() => {
        if (run === state) state.parts = null
      }, PART_LIFE_MS)
      return state.parts
    })
    .catch(() => null)
    .finally(() => {
      state.settled = true
    })
  run = state
  return state
}

/** Forgets the bootstrap (sign-out, or tests). */
export function resetShellBootstrap() {
  run = null
}

/**
 * The part `name` for a hook that mounted during the bootstrap, or the
 * hook's own request. A part is handed out once.
 */
export async function fromShell(name, fetcher, accept = () => true) {
  const state = run
  if (state && state.token === getToken()) {
    // Only a hook that was already waiting takes its part; a refetch later (after a role change, say) always asks the API.
    // The theme is not seeded, so it may also come from here shortly after.
    const early = !state.settled
    const parts = await state.promise
    const part = parts?.[name]
    if (part !== null && part !== undefined && (early || name.startsWith('theme')) && accept(parts) && state.parts) {
      state.parts = { ...state.parts, [name]: null }
      return part
    }
  }
  return fetcher()
}
