// NFR-10: removes secrets and personal data from error reports and logs
// before they leave the app. One rules file (../rules.json) drives this
// module (web and POS) and the API's Scrubber, and ../fixtures.json is run
// by both, so the three apps redact the same things.
//
// - A value under a sensitive key (password, token, pin, card, email,
//   phone, ID number, address and so on) is replaced whole, unless it is
//   null or a boolean.
// - Every other string has secret- and PII-looking substrings replaced
//   (bearer tokens, JWTs, emails, card-length numbers, phone numbers,
//   secrets in query strings).
// - Keys listed in `keep` (event ids, release, tenant_id, request_id) are
//   left exactly as they are.
'use strict'

const rules = require('../rules.json')

const MAX_DEPTH = 12

/** Words of a key: "apiKey", "API_KEY" and "x-api-key" all give [..., "api", "key"]. */
function keyWords(key) {
  return String(key)
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
    .toLowerCase()
    .split(/[^a-z0-9]+/)
    .filter(Boolean)
}

function containsPhrase(words, phrase) {
  // "^" first: the key is exactly these words ("code", never "status_code").
  if (phrase[0] === '^') return words.length === phrase.length - 1 && phrase.slice(1).every((word, i) => words[i] === word)
  for (let i = 0; i + phrase.length <= words.length; i++) {
    let match = true
    for (let j = 0; j < phrase.length; j++) {
      if (words[i + j] !== phrase[j]) {
        match = false
        break
      }
    }
    if (match) return true
  }
  return false
}

const keep = new Set(rules.keep)
const valueRules = rules.values.map((rule) => ({
  name: rule.name,
  regex: new RegExp(rule.pattern, `g${rule.flags}`),
  replace: rule.replace,
}))

/** Whether a value stored under this key is always redacted. */
function isSensitiveKey(key) {
  const words = keyWords(key)
  return rules.keys.some((phrase) => containsPhrase(words, phrase))
}

/** A string with secret- and PII-looking parts replaced. */
function scrubString(value) {
  let out = String(value)
  for (const rule of valueRules) {
    rule.regex.lastIndex = 0
    out = out.replace(rule.regex, rule.replace)
  }
  return out
}

/** A deep copy of any JSON-like value, scrubbed. */
function scrub(value, key = null, depth = 0) {
  if (key !== null && keep.has(String(key))) return value
  if (value === null || value === undefined || typeof value === 'boolean') return value
  if (key !== null && isSensitiveKey(key)) return rules.replacement
  if (typeof value === 'string') return scrubString(value)
  // Long integers can be phone or account numbers (M-Pesa's PhoneNumber): scrubbed as text.
  if (typeof value === 'number' && Number.isInteger(value) && Math.abs(value) >= 100000000) {
    const text = scrubString(String(value))
    return text === String(value) ? value : text
  }
  if (typeof value !== 'object') return value
  if (depth >= MAX_DEPTH) return rules.replacement
  if (Array.isArray(value)) return value.map((item) => scrub(item, null, depth + 1))
  if (value instanceof Date) return value
  const out = {}
  for (const [k, v] of Object.entries(value)) out[k] = scrub(v, k, depth + 1)
  return out
}

/**
 * Sentry `beforeSend` / `beforeSendTransaction`: the event scrubbed, with no
 * user other than an id (tenant id goes in tags; never names or emails).
 */
function scrubEvent(event) {
  if (!event || typeof event !== 'object') return event
  const out = scrub(event)
  if (out.user) {
    if (out.user.id) out.user = { id: out.user.id }
    else delete out.user
  }
  return out
}

/** Sentry `beforeBreadcrumb`: the breadcrumb scrubbed (console messages, URLs, data). */
function scrubBreadcrumb(breadcrumb) {
  return breadcrumb ? scrub(breadcrumb) : breadcrumb
}

module.exports = { rules, scrub, scrubString, scrubEvent, scrubBreadcrumb, isSensitiveKey, keyWords }
