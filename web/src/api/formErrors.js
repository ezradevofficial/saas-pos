// Splits an ApiError for a form: messages for the form's own fields go under
// those fields; anything else (other fields, or an error without field
// errors) becomes one message for an Alert. Codes are never shown.
import i18n from '@/i18n'

/**
 * @param {unknown} error
 * @param {string[]} fields form field names
 * @param {Record<string, string>} [aliases] API field -> form field (e.g. email -> contact)
 * @returns {{ fields: Record<string, string>, form: string | null }}
 */
export function formErrors(error, fields, aliases = {}) {
  if (!error) return { fields: {}, form: null }

  const fieldMessages = {}
  const other = []
  for (const [key, messages] of Object.entries(error.errors ?? {})) {
    const target = aliases[key] ?? key
    const message = Array.isArray(messages) ? messages[0] : String(messages)
    if (fields.includes(target)) fieldMessages[target] ??= message
    else other.push(message)
  }

  const hasFieldErrors = Object.keys(fieldMessages).length > 0
  let form = null
  if (other.length > 0) form = other.join(' ')
  else if (!hasFieldErrors) form = error.message || i18n.t('errors.generic')

  return { fields: fieldMessages, form }
}
