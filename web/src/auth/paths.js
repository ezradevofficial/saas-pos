export const ENROL_PATH = '/two-factor/enrol'

/** Only same-site paths are followed after sign-in ("//evil.example" is not). */
export function safeNext(next) {
  return typeof next === 'string' && next.startsWith('/') && !next.startsWith('//') ? next : '/'
}
