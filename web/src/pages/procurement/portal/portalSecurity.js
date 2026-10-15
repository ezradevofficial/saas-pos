// PRC-07, PRC-23 (owner decision 2026-10-14, ADR 019): the supplier portal
// stays on the back office's origin, so its pages lock themselves down:
// no Referer ever leaves them (the link token is in the URL), and a strict
// Content-Security-Policy allows no inline or foreign script and lets the
// page talk only to the API. Policies only tighten once applied, so the
// tags are added once and kept for the rest of the tab.
import { apiUrl } from '@/config'

/** The portal's Content-Security-Policy for an API at `api`. */
export function portalCsp(api = apiUrl, { dev = false } = {}) {
  const origin = new URL(api, window.location.origin).origin
  // Vite's dev server talks to the page over a websocket on its own origin.
  const devConnect = dev ? ` ws://${window.location.host} wss://${window.location.host}` : ''
  return [
    "default-src 'self'",
    "script-src 'self'",
    // Tenant themes set token values as inline style attributes (BR-01).
    "style-src 'self' 'unsafe-inline'",
    `img-src 'self' data: blob: ${origin}`,
    "font-src 'self' data:",
    `connect-src ${origin}${devConnect}`,
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'none'",
  ].join('; ')
}

function addMeta(attribute, name, content) {
  if (document.head.querySelector(`meta[${attribute}="${name}"]`)) return
  const meta = document.createElement('meta')
  meta.setAttribute(attribute, name)
  meta.setAttribute('content', content)
  document.head.appendChild(meta)
}

/** Add the portal's referrer and content security policies to the page (once). */
export function lockDownPortal() {
  addMeta('name', 'referrer', 'no-referrer')
  addMeta('http-equiv', 'Content-Security-Policy', portalCsp(apiUrl, { dev: Boolean(import.meta.env.DEV) }))
}
