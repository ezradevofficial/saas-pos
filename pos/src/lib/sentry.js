// NFR-10: error tracking for the POS and mobile app. Off unless the build
// sets EXPO_PUBLIC_SENTRY_DSN; the SDK is only required then. Every event,
// transaction and breadcrumb goes through the shared scrubber (@app/scrub,
// the same rules as the API and the web app), the SDK never attaches
// personal data, and the only tag that identifies anyone is the tenant id
// (from the device's synced settings). The release is the git sha the build
// was made from (EXPO_PUBLIC_SENTRY_RELEASE).
import { scrubBreadcrumb, scrubEvent } from '@app/scrub';

let sentry = null;
let tenantId = null;

const env = () => ({
  dsn: process.env.EXPO_PUBLIC_SENTRY_DSN,
  release: process.env.EXPO_PUBLIC_SENTRY_RELEASE,
  environment: process.env.EXPO_PUBLIC_SENTRY_ENVIRONMENT,
  tracesSampleRate: process.env.EXPO_PUBLIC_SENTRY_TRACES_SAMPLE_RATE,
});

/** The options handed to Sentry.init (exported for the test). */
export function sentryOptions(values = env()) {
  return {
    dsn: values.dsn,
    release: values.release || undefined,
    environment: values.environment || 'production',
    sendDefaultPii: false,
    // Screenshots and view hierarchies can show customers and amounts: never.
    attachScreenshot: false,
    attachViewHierarchy: false,
    tracesSampleRate: Number(values.tracesSampleRate ?? 0) || 0,
    beforeSend: scrubEvent,
    beforeSendTransaction: scrubEvent,
    beforeBreadcrumb: scrubBreadcrumb,
  };
}

/** Starts Sentry when a DSN is configured; returns whether it started. */
export function initSentry(values = env(), load = () => require('@sentry/react-native')) {
  if (!values.dsn) return false;
  sentry = load();
  sentry.init(sentryOptions(values));
  if (tenantId) sentry.setTag('tenant_id', tenantId);
  return true;
}

/** The till's tenant (an id, never a name) on every later event. */
export function setSentryTenant(id) {
  tenantId = id ?? null;
  sentry?.setTag('tenant_id', tenantId ?? undefined);
}
