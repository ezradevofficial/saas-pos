export const ENROL_PATH = '/two-factor/enrol'

// C0 control characters and DEL.
// oxlint-disable-next-line no-control-regex
const CONTROL = /[\u0000-\u001f\u007f]/

/**
 * Only same-origin paths are followed after sign-in. The candidate is
 * resolved the way the browser would ("/\evil.example" and "/\t/evil.example"
 * point at another host) and kept only when the origin is ours, before and
 * after percent-decoding; control characters and backslashes, raw or
 * encoded, are refused outright.
 */
export function safeNext(next) {
  if (typeof next !== 'string' || !next.startsWith('/')) return '/'
  let decoded = next
  try {
    decoded = decodeURIComponent(next)
  } catch {
    return '/'
  }
  if (CONTROL.test(next) || CONTROL.test(decoded) || decoded.includes('\\')) return '/'
  try {
    const origin = window.location.origin
    const url = new URL(next, origin)
    // Also refuse what would leave the site once decoded (double handling).
    if (url.origin !== origin || new URL(decoded, origin).origin !== origin) return '/'
    return `${url.pathname}${url.search}${url.hash}`
  } catch {
    return '/'
  }
}
