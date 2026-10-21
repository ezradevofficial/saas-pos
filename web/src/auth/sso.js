// AUTH-11, ADR 023: the browser's side of single sign-on. The API answers
// the identity provider's URL and a binding secret; the secret stays in this
// tab (sessionStorage) and is sent back with the ticket the callback leaves
// in the URL fragment, so a ticket only works in the browser that started.
import { api } from '@/api/client'

const BINDING_KEY = 'app.sso.binding'
const memory = new Map()

export function saveBinding(binding) {
  memory.set(BINDING_KEY, binding)
  try {
    window.sessionStorage.setItem(BINDING_KEY, binding)
  } catch {
    // Kept in memory for this page only.
  }
}

/** The binding of the sign-in this tab started, read once. */
export function takeBinding() {
  let value = memory.get(BINDING_KEY) ?? null
  try {
    value = window.sessionStorage.getItem(BINDING_KEY) ?? value
    window.sessionStorage.removeItem(BINDING_KEY)
  } catch {
    // Memory only.
  }
  memory.delete(BINDING_KEY)
  return value
}

/** POST auth/sso/start with the page's origin; answers { redirect_url, binding } or { connections }. */
export async function startSso({ email, host, connectionId }) {
  const response = await api.post('auth/sso/start', {
    ...(email ? { email } : { host }),
    ...(connectionId ? { connection_id: connectionId } : {}),
    origin: window.location.origin,
  })
  return response.data
}

/** Leave for the identity provider. */
export function goToIdp({ redirect_url: url, binding }) {
  if (binding) saveBinding(binding)
  window.location.assign(url)
}

/** Ticket, error code or test outcome in a URL fragment (`#ticket=…`). */
export function readFragment(hash) {
  const params = new URLSearchParams((hash ?? '').replace(/^#/, ''))
  return { ticket: params.get('ticket'), error: params.get('error'), test: params.get('test') }
}

export const SSO_REASONS = [
  'state_invalid',
  'idp_error',
  'connection_unavailable',
  'token_exchange_failed',
  'signature_invalid',
  'issuer_mismatch',
  'audience_mismatch',
  'nonce_mismatch',
  'expired',
  'token_invalid',
  'email_missing',
  'email_unverified',
  'hosted_domain_mismatch',
  'saml_invalid',
  'in_response_to_mismatch',
  'replayed',
  'domain_not_verified',
  'no_account',
  'identity_conflict',
  'deactivated',
  'jit_stale',
  'ticket_invalid',
  'owner_link_required',
  'link_email_mismatch',
  'name_id_transient',
]

/** The sentence for a failure code from the callback (unknown codes read as the IdP's). */
export function ssoReason(t, code) {
  return t(`auth.sso.errors.${SSO_REASONS.includes(code) ? code : 'idp_error'}`)
}
