// NFR-10: Sentry stays off without a DSN and scrubs every event with the shared rules.
import { initSentry, sentryOptions, setSentryTenant } from './sentry'

describe('Sentry (web)', () => {
  it('does not load the SDK without a DSN', async () => {
    const load = vi.fn()
    expect(await initSentry({ VITE_SENTRY_DSN: '' }, load)).toBe(false)
    expect(load).not.toHaveBeenCalled()
  })

  it('starts with the release, no personal data and the scrubber, and tags the tenant id', async () => {
    const sdk = { init: vi.fn(), setTag: vi.fn() }
    setSentryTenant('t-1')
    expect(await initSentry({ VITE_SENTRY_DSN: 'https://public@example.invalid/1', VITE_SENTRY_RELEASE: 'abc123', MODE: 'production' }, async () => sdk)).toBe(true)

    const options = sdk.init.mock.calls[0][0]
    expect(options).toMatchObject({ dsn: 'https://public@example.invalid/1', release: 'abc123', environment: 'production', sendDefaultPii: false, tracesSampleRate: 0 })
    expect(sdk.setTag).toHaveBeenCalledWith('tenant_id', 't-1')

    const event = options.beforeSend({ message: 'Sign-in failed for amina@example.com', user: { id: 'u-1', email: 'amina@example.com' }, tags: { tenant_id: 't-1' } })
    expect(event.message).toBe('Sign-in failed for [email]')
    expect(event.user).toEqual({ id: 'u-1' })
    expect(event.tags.tenant_id).toBe('t-1')
    expect(options.beforeBreadcrumb({ category: 'fetch', data: { url: '/x?token=abc' } }).data.url).toBe('/x?token=[redacted]')

    setSentryTenant(null)
    expect(sdk.setTag).toHaveBeenLastCalledWith('tenant_id', undefined)
  })

  it('reads its settings from the build environment', () => {
    expect(sentryOptions({ VITE_SENTRY_DSN: 'x', MODE: 'staging', VITE_SENTRY_TRACES_SAMPLE_RATE: '0.1' })).toMatchObject({ environment: 'staging', tracesSampleRate: 0.1 })
  })
})
