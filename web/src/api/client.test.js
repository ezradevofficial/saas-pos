import i18n from '@/i18n'
import { api, ApiError, clearToken, deviceName, getCompanyId, getToken, onAuthEvent, setCompanyId, setToken } from './client'

function respond(status, body, headers = {}) {
  return Promise.resolve(
    new Response(body === undefined ? null : JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json', ...headers },
    }),
  )
}

describe('api client', () => {
  let fetchMock

  beforeEach(() => {
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    clearToken()
    setCompanyId(null)
    window.localStorage.clear()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    i18n.changeLanguage('en')
  })

  it('calls the versioned API with JSON, the language and the bearer token', async () => {
    setToken('secret')
    setCompanyId('c-1')
    await i18n.changeLanguage('fr')
    fetchMock.mockReturnValue(respond(200, { ok: true }))

    const data = await api.post('auth/sign-in', { login: 'a@b.co' })

    expect(data).toEqual({ ok: true })
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe(`${import.meta.env.VITE_API_URL}/api/v1/auth/sign-in`)
    expect(init.method).toBe('POST')
    expect(init.body).toBe(JSON.stringify({ login: 'a@b.co' }))
    expect(init.headers).toMatchObject({
      Accept: 'application/json',
      'Accept-Language': 'fr',
      'Content-Type': 'application/json',
      Authorization: 'Bearer secret',
      'X-Company-Id': 'c-1',
    })
  })

  it('stores the token and company in localStorage', () => {
    setToken('t-1')
    setCompanyId('c-9')
    expect(window.localStorage.getItem('app.token')).toBe('t-1')
    expect(window.localStorage.getItem('app.companyId')).toBe('c-9')
    expect(getToken()).toBe('t-1')
    expect(getCompanyId()).toBe('c-9')
  })

  it('keeps the token in memory when storage is blocked', () => {
    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    const read = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    setToken('memory-token')
    expect(getToken()).toBe('memory-token')
    spy.mockRestore()
    read.mockRestore()
  })

  it('turns the error envelope into an ApiError', async () => {
    fetchMock.mockReturnValue(
      respond(422, { message: 'Check the form.', code: 'validation_failed', errors: { login: ['Required.'] } }),
    )

    const error = await api.post('auth/sign-in', {}).catch((e) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 422, code: 'validation_failed', message: 'Check the form.' })
    expect(error.errors).toEqual({ login: ['Required.'] })
  })

  it('keeps extra envelope keys such as challenge_id', async () => {
    fetchMock.mockReturnValue(respond(403, { message: 'Verify first.', code: 'unverified', challenge_id: 'ch-1' }))
    const error = await api.post('auth/sign-in', {}).catch((e) => e)
    expect(error.data.challenge_id).toBe('ch-1')
  })

  it('clears the token and reports a 401 from an authenticated request', async () => {
    setToken('expired')
    const listener = vi.fn()
    const stop = onAuthEvent(listener)
    fetchMock.mockReturnValue(respond(401, { message: 'Sign in again.', code: 'unauthenticated' }))

    await expect(api.get('me')).rejects.toMatchObject({ status: 401 })

    expect(getToken()).toBeNull()
    expect(listener).toHaveBeenCalledWith('unauthenticated')
    stop()
  })

  it('reports a token that may only enrol a second factor', async () => {
    setToken('enrol-only')
    const listener = vi.fn()
    const stop = onAuthEvent(listener)
    fetchMock.mockReturnValue(respond(403, { message: 'Set up two-step sign-in.', code: 'two_factor_enrollment_required' }))

    await expect(api.get('me/permissions')).rejects.toMatchObject({ code: 'two_factor_enrollment_required' })

    expect(listener).toHaveBeenCalledWith('two_factor_enrollment_required')
    expect(getToken()).toBe('enrol-only')
    stop()
  })

  it('returns null for 204 responses', async () => {
    setToken('t')
    fetchMock.mockReturnValue(Promise.resolve(new Response(null, { status: 204 })))
    await expect(api.delete('auth/sessions/1')).resolves.toBeNull()
  })

  it('gives a translated message when the network fails', async () => {
    fetchMock.mockReturnValue(Promise.reject(new TypeError('Failed to fetch')))
    const error = await api.get('companies').catch((e) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(0)
    expect(error.message).toBe(i18n.t('errors.network'))
  })

  it('names the device from the user agent, or sends nothing when unknown', () => {
    const agent = vi.spyOn(navigator, 'userAgent', 'get')
    agent.mockReturnValue('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0 Safari/537.36')
    expect(deviceName()).toBe('Chrome · macOS')
    agent.mockReturnValue('curl/8.0')
    expect(deviceName()).toBe('')
    agent.mockRestore()
  })
})
