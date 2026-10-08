// One sentence for an ApiError outside a form field: known codes get the
// web's own wording (what happened and what to do next), then the first
// field message, then the API's translated message. Codes are never shown.
import i18n from '@/i18n'

const KNOWN = new Set([
  'last_owner',
  'cannot_grant',
  'invitation_stale',
  'contact_unverified',
  'system_role',
  'device_not_pairable',
  'device_not_suspended',
  'forbidden',
])

/**
 * @param {unknown} error an ApiError (or null)
 * @param {Record<string, string>} [overrides] code -> sentence for this context
 */
export function errorMessage(error, overrides = {}) {
  if (!error) return null
  const code = error.code
  if (code && overrides[code]) return overrides[code]
  if (KNOWN.has(code)) return i18n.t(`errors.codes.${code}`)
  if (error.status === 403) return i18n.t('errors.codes.forbidden')
  const first = Object.values(error.errors ?? {})[0]
  if (first) return Array.isArray(first) ? first[0] : String(first)
  return error.message || i18n.t('errors.generic')
}
