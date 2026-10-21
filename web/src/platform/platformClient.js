// The platform console's own API client (ADR 013). It keeps the staff token
// apart from a tenant session (its own storage key) and never sends the
// tenant token or company header, so the two sessions cannot mix. The token
// lives in sessionStorage: it ends with the tab and is never shared with
// other tabs. In production the console is served on its own subdomain
// (PLATFORM_URL), so tenant pages never share its origin.
import { ApiError } from '@/api/client'
import { apiUrl } from '@/config'
import i18n from '@/i18n'

export const PLATFORM_TOKEN_KEY = 'app.platformToken'

const memory = new Map()

export function getPlatformToken() {
  try {
    const value = window.sessionStorage.getItem(PLATFORM_TOKEN_KEY)
    if (value !== null) return value
  } catch {
    // Fall back to memory.
  }
  return memory.get(PLATFORM_TOKEN_KEY) ?? null
}

export function setPlatformToken(token) {
  if (token) memory.set(PLATFORM_TOKEN_KEY, token)
  else memory.delete(PLATFORM_TOKEN_KEY)
  try {
    if (token) window.sessionStorage.setItem(PLATFORM_TOKEN_KEY, token)
    else window.sessionStorage.removeItem(PLATFORM_TOKEN_KEY)
  } catch {
    // Kept in memory.
  }
}

const listeners = new Set()

/** The console listens for 'unauthenticated' (the staff token is gone or expired). */
export function onPlatformAuthEvent(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

async function request(method, path, body) {
  const token = getPlatformToken()
  const headers = { Accept: 'application/json', 'Accept-Language': i18n.resolvedLanguage ?? i18n.language ?? 'en' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (token) headers.Authorization = `Bearer ${token}`

  let response
  try {
    response = await fetch(`${apiUrl}/api/v1/${path.replace(/^\//, '')}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError({ status: 0, code: 'network_error', message: i18n.t('errors.network') })
  }

  if (response.status === 204) return null
  let data = null
  try {
    data = await response.json()
  } catch {
    data = null
  }
  if (response.ok) return data

  const { message, code, errors, ...rest } = data ?? {}
  if (response.status === 401 && token) {
    setPlatformToken(null)
    for (const listener of listeners) listener('unauthenticated')
  }
  throw new ApiError({ status: response.status, code: code ?? 'http_error', message: message || i18n.t('errors.generic'), errors, data: rest })
}

/** ADM-06: a CSV (or other file) from the staff API, saved under `filename`. */
async function download(path, filename) {
  const token = getPlatformToken()
  const response = await fetch(`${apiUrl}/api/v1/${path.replace(/^\//, '')}`, {
    headers: { Accept: 'text/csv', 'Accept-Language': i18n.resolvedLanguage ?? i18n.language ?? 'en', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
  })
  if (!response.ok) throw new ApiError({ status: response.status, code: 'http_error', message: i18n.t('errors.generic') })
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

export const platformApi = {
  download,
  get: (path) => request('GET', path),
  post: (path, body = {}) => request('POST', path, body),
  patch: (path, body = {}) => request('PATCH', path, body),
}
