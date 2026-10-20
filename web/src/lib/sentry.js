// NFR-10: error tracking for the web app. Off unless VITE_SENTRY_DSN is set
// at build time; then the SDK is loaded as its own chunk (never in the
// entry bundle, NFR-02). Every event, transaction and breadcrumb goes
// through the shared scrubber (@app/scrub, the same rules as the API and
// the POS), the SDK never attaches personal data, and the only tag that
// identifies anyone is the tenant id. The release is the git sha the
// deploy builds (VITE_SENTRY_RELEASE).
import { scrubBreadcrumb, scrubEvent } from '@app/scrub'

let sentry = null
let tenantId = null

/** The options handed to Sentry.init (exported for the test). */
export function sentryOptions(env = import.meta.env) {
  return {
    dsn: env.VITE_SENTRY_DSN,
    release: env.VITE_SENTRY_RELEASE || undefined,
    environment: env.VITE_SENTRY_ENVIRONMENT || env.MODE,
    sendDefaultPii: false,
    tracesSampleRate: Number(env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0) || 0,
    beforeSend: scrubEvent,
    beforeSendTransaction: scrubEvent,
    beforeBreadcrumb: scrubBreadcrumb,
  }
}

/** Starts Sentry when a DSN is configured; resolves to whether it started. */
export async function initSentry(env = import.meta.env, load = () => import('@sentry/react')) {
  if (!env.VITE_SENTRY_DSN) return false
  const module = await load()
  module.init(sentryOptions(env))
  sentry = module
  if (tenantId) sentry.setTag('tenant_id', tenantId)
  return true
}

/** The signed-in user's tenant (an id, never a name) on every later event. */
export function setSentryTenant(id) {
  tenantId = id ?? null
  sentry?.setTag('tenant_id', tenantId ?? undefined)
}
