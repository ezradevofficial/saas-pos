// The one way the web app talks to the API: base `VITE_API_URL` + `/api/v1`,
// the bearer token, the UI language and the chosen company on every request,
// and the error envelope `{message, code, errors?}` turned into ApiError.
import { apiUrl } from '@/config'
import i18n from '@/i18n'

const TOKEN_KEY = 'app.token'
const COMPANY_KEY = 'app.companyId'

// localStorage can be blocked (private mode, storage policies); values then
// live in memory for this tab only.
const memory = new Map()

function read(key) {
  try {
    const value = window.localStorage.getItem(key)
    if (value !== null) return value
  } catch {
    // Fall back to memory below.
  }
  return memory.get(key) ?? null
}

function write(key, value) {
  if (value === null || value === undefined || value === '') memory.delete(key)
  else memory.set(key, String(value))
  try {
    if (value === null || value === undefined || value === '') window.localStorage.removeItem(key)
    else window.localStorage.setItem(key, String(value))
  } catch {
    // Kept in memory.
  }
}

export const getToken = () => read(TOKEN_KEY)
export const setToken = (token) => write(TOKEN_KEY, token)
export const clearToken = () => write(TOKEN_KEY, null)
export const getCompanyId = () => read(COMPANY_KEY)
export const setCompanyId = (id) => write(COMPANY_KEY, id)

export class ApiError extends Error {
  constructor({ status, code, message, errors = {}, data = {} }) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.errors = errors ?? {}
    this.data = data ?? {}
  }
}

// AuthProvider listens for 'unauthenticated' (token gone) and
// 'two_factor_enrollment_required' (the token may only enrol a second factor).
const listeners = new Set()

export function onAuthEvent(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function emit(event) {
  for (const listener of listeners) listener(event)
}

function headersFor(body) {
  const token = getToken()
  const companyId = getCompanyId()
  const headers = {
    Accept: 'application/json',
    'Accept-Language': i18n.resolvedLanguage ?? i18n.language ?? 'en',
  }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (token) headers.Authorization = `Bearer ${token}`
  if (companyId) headers['X-Company-Id'] = companyId
  return headers
}

async function send(method, path, body, headers) {
  try {
    return await fetch(`${apiUrl}/api/v1/${path.replace(/^\//, '')}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError({ status: 0, code: 'network_error', message: i18n.t('errors.network') })
  }
}

async function request(method, path, body) {
  const token = getToken()
  const response = await send(method, path, body, headersFor(body))

  if (response.status === 204) return null

  let data = null
  try {
    data = await response.json()
  } catch {
    data = null
  }

  if (response.ok) return data
  return failure(response, data, token)
}

/**
 * A file from the API (e.g. the access review CSV) with the bearer token:
 * `{ blob, filename }`, the name from Content-Disposition.
 */
async function download(path) {
  const token = getToken()
  const response = await send('GET', path, undefined, headersFor())
  if (response.ok) {
    const disposition = response.headers.get('Content-Disposition') ?? ''
    const filename = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)?.[1] ?? null
    return { blob: await response.blob(), filename: filename ? decodeURIComponent(filename) : null }
  }
  let data = null
  try {
    data = await response.json()
  } catch {
    data = null
  }
  return failure(response, data, token)
}

function failure(response, data, token) {
  const { message, code, errors, ...rest } = data ?? {}
  const error = new ApiError({
    status: response.status,
    code: code ?? 'http_error',
    message: message || i18n.t('errors.generic'),
    errors,
    data: rest,
  })

  if (response.status === 401 && token) {
    clearToken()
    emit('unauthenticated')
  } else if (response.status === 403 && error.code === 'two_factor_enrollment_required') {
    emit('two_factor_enrollment_required')
  }

  throw error
}

export const api = {
  get: (path) => request('GET', path),
  post: (path, body = {}) => request('POST', path, body),
  patch: (path, body = {}) => request('PATCH', path, body),
  delete: (path) => request('DELETE', path),
  download,
}

/** A short device label for the sessions list ("Chrome on macOS"). */
export function deviceName() {
  const agent = typeof navigator === 'undefined' ? '' : navigator.userAgent
  const browser = /Edg\//.test(agent)
    ? 'Edge'
    : /Firefox\//.test(agent)
      ? 'Firefox'
      : /Chrome\//.test(agent)
        ? 'Chrome'
        : /Safari\//.test(agent)
          ? 'Safari'
          : ''
  const system = /Android/.test(agent)
    ? 'Android'
    : /iPhone|iPad/.test(agent)
      ? 'iOS'
      : /Mac OS X/.test(agent)
        ? 'macOS'
        : /Windows/.test(agent)
          ? 'Windows'
          : /Linux/.test(agent)
            ? 'Linux'
            : ''
  // Unknown browsers send no name; the sessions list shows "Unknown device".
  return [browser, system].filter(Boolean).join(' · ')
}
