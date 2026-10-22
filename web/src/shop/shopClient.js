// ECOM-05 to ECOM-08: the storefront's own way to the API. It never reads or
// sends a tenant user's token (the shop API refuses one): only the
// customer's session (`ecs_…`) for this store, kept in sessionStorage for
// this tab (memory when storage is blocked). The store is named by the
// host the page is served on (X-Shop-Host) and its handle. Errors are
// ApiErrors, as the app's client gives them.
import { ApiError } from '@/api/client'
import { apiUrl } from '@/config'
import i18n from '@/i18n'

const memory = new Map()
const sessionKey = (store) => `app.shop.session.${store}`

export function getShopSession(store) {
  try {
    const value = window.sessionStorage.getItem(sessionKey(store))
    if (value !== null) return value
  } catch {
    // Fall back to memory below.
  }
  return memory.get(store) ?? null
}

export function setShopSession(store, token) {
  if (token) memory.set(store, token)
  else memory.delete(store)
  try {
    if (token) window.sessionStorage.setItem(sessionKey(store), token)
    else window.sessionStorage.removeItem(sessionKey(store))
  } catch {
    // Kept in memory.
  }
}

/**
 * `shop(store, method, path, { body, headers })`: `path` under
 * /api/v1/shop/{store}/; `body` is sent as JSON; the customer's session is
 * sent when there is one.
 */
async function send(store, method, path, { body, headers: extra = {}, accept = 'application/json' } = {}) {
  const headers = {
    Accept: accept,
    'Accept-Language': i18n.resolvedLanguage ?? i18n.language ?? 'en',
    'X-Shop-Host': window.location.host,
    ...extra,
  }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const token = getShopSession(store)
  if (token) headers.Authorization = `Bearer ${token}`
  const suffix = path ? `/${path.replace(/^\//, '')}` : ''
  let response
  try {
    response = await fetch(`${apiUrl}/api/v1/shop/${encodeURIComponent(store)}${suffix}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'omit', referrerPolicy: 'no-referrer' })
  } catch {
    throw new ApiError({ status: 0, code: 'network_error', message: i18n.t('errors.network') })
  }
  return { response, token }
}

async function failure(store, response, token) {
  let data = null
  try {
    data = await response.json()
  } catch {
    data = null
  }
  const { message, code, errors, ...rest } = data ?? {}
  // A session that ended (signed out, expired) is forgotten.
  if (response.status === 401 && token) setShopSession(store, null)
  return new ApiError({ status: response.status, code: code ?? 'http_error', message: message || i18n.t('errors.generic'), errors, data: rest })
}

export async function shop(store, method, path, options = {}) {
  const { response, token } = await send(store, method, path, options)
  if (!response.ok) throw await failure(store, response, token)
  try {
    return await response.json()
  } catch {
    return null
  }
}

/**
 * ECOM-28: a file the shop API serves (the proof-of-delivery photo) as a
 * Blob, with the same host, language and session as `shop()`.
 */
export async function shopBlob(store, path) {
  const { response, token } = await send(store, 'GET', path, { accept: 'image/*' })
  if (!response.ok) throw await failure(store, response, token)
  return response.blob()
}

/** A fresh idempotency key for one checkout (ECOM-13). */
export function newOrderKey() {
  const bytes = new Uint8Array(16)
  window.crypto.getRandomValues(bytes)
  return `web-${Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')}`
}

/**
 * ECOM-08: the answer to a sign-in code challenge (the server asks after a
 * few codes from one connection): a `solution` such that
 * sha256("{nonce}:{solution}") starts with `difficulty` zeros. A few
 * thousand hashes in the browser, nothing sent anywhere else.
 */
export async function solveChallenge({ nonce, difficulty }) {
  const zeros = '0'.repeat(difficulty)
  const encoder = new TextEncoder()
  for (let solution = 0; solution < 50_000_000; solution++) {
    const digest = new Uint8Array(await window.crypto.subtle.digest('SHA-256', encoder.encode(`${nonce}:${solution}`)))
    const hex = Array.from(digest.slice(0, Math.ceil(difficulty / 2)), (b) => b.toString(16).padStart(2, '0')).join('')
    if (hex.startsWith(zeros)) return String(solution)
  }
  throw new ApiError({ status: 0, code: 'challenge_unsolved', message: i18n.t('errors.generic') })
}

/** ECOM-08: ask for a sign-in code, answering the server's challenge once when it asks for one. */
export async function requestCode(store, contact) {
  try {
    return await shop(store, 'POST', 'auth/code', { body: { contact } })
  } catch (error) {
    const challenge = error?.data?.challenge
    if (error?.status !== 428 || error.code !== 'challenge_required' || !challenge) throw error
    const solution = await solveChallenge(challenge)
    return shop(store, 'POST', 'auth/code', { body: { contact, challenge: { id: challenge.id, solution } } })
  }
}
