// PRC-07, PRC-23: the supplier portal's own way to the API. It never sends
// a tenant user's token (the portal refuses one): only the portal session
// a bidder's emailed link was exchanged for, kept in sessionStorage for
// this tab (memory when storage is blocked). Errors are ApiErrors, as the
// app's client gives them.
import { ApiError } from '@/api/client'
import { apiUrl } from '@/config'
import i18n from '@/i18n'

export const PORTAL_SESSION_KEY = 'app.portal.session'

let memory = null

export function getPortalSession() {
  try {
    const value = window.sessionStorage.getItem(PORTAL_SESSION_KEY)
    if (value !== null) return value
  } catch {
    // Fall back to memory below.
  }
  return memory
}

export function setPortalSession(token) {
  memory = token || null
  try {
    if (token) window.sessionStorage.setItem(PORTAL_SESSION_KEY, token)
    else window.sessionStorage.removeItem(PORTAL_SESSION_KEY)
  } catch {
    // Kept in memory.
  }
}

/**
 * `portal(method, path, { body, form, session })`: `path` under
 * /api/v1/supplier-portal/; `body` is sent as JSON, `form` (FormData) as a
 * multipart upload; the stored session is sent unless `session: false`.
 */
export async function portal(method, path, { body, form, session = true } = {}) {
  const headers = { Accept: 'application/json', 'Accept-Language': i18n.resolvedLanguage ?? i18n.language ?? 'en' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const token = session ? getPortalSession() : null
  if (token) headers.Authorization = `Bearer ${token}`
  let response
  try {
    response = await fetch(`${apiUrl}/api/v1/supplier-portal/${path.replace(/^\//, '')}`, {
      method,
      headers,
      body: form ?? (body === undefined ? undefined : JSON.stringify(body)),
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
  // A session that ended (signed out, revoked, expired) is forgotten.
  if (response.status === 401 && token) setPortalSession(null)
  throw new ApiError({ status: response.status, code: code ?? 'http_error', message: message || i18n.t('errors.generic'), errors, data: rest })
}
