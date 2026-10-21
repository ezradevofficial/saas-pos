import { initSentry, sentryOptions, setSentryTenant } from './sentry';

// NFR-10: Sentry stays off without a DSN and scrubs every event with the shared rules.
describe('Sentry (POS)', () => {
  it('does not load the SDK without a DSN', () => {
    const load = jest.fn();
    expect(initSentry({ dsn: '' }, load)).toBe(false);
    expect(load).not.toHaveBeenCalled();
  });

  it('starts with the release, no personal data, no screenshots and the scrubber, and tags the tenant id', () => {
    const sdk = { init: jest.fn(), setTag: jest.fn() };
    setSentryTenant('t-1');
    expect(initSentry({ dsn: 'https://public@example.invalid/1', release: 'abc123' }, () => sdk)).toBe(true);

    const options = sdk.init.mock.calls[0][0];
    expect(options).toMatchObject({ release: 'abc123', sendDefaultPii: false, attachScreenshot: false, attachViewHierarchy: false, tracesSampleRate: 0 });
    expect(sdk.setTag).toHaveBeenCalledWith('tenant_id', 't-1');

    const event = options.beforeSend({ message: 'STK push to +254712345678 failed', user: { id: 'u-1', email: 'grace@example.com' }, extra: { pin: '1234' } });
    expect(event.message).toBe('STK push to [phone] failed');
    expect(event.user).toEqual({ id: 'u-1' });
    expect(event.extra.pin).toBe('[redacted]');
    setSentryTenant(null);
    expect(sdk.setTag).toHaveBeenLastCalledWith('tenant_id', undefined);
  });

  it('defaults the environment and sampling', () => {
    expect(sentryOptions({ dsn: 'x' })).toMatchObject({ environment: 'production', tracesSampleRate: 0 });
  });
});
