// HOOK-01: shared bits of Settings → Webhooks (docs/adr/021-webhooks.md).

export const WEBHOOKS_KEY = ['webhooks']
export const CATALOGUE_KEY = ['webhooks', 'catalogue']
export const DELIVERIES_KEY = ['webhook-deliveries']

export const DELIVERY_STATUSES = ['pending', 'sending', 'retrying', 'delivered', 'failed']

/** Status as a dot and a word: only a failure is red; waiting is neutral. */
export const DELIVERY_TONES = { pending: 'neutral', sending: 'info', retrying: 'warning', delivered: 'success', failed: 'danger' }

/** Event type keys (`sale.completed.v1`) as catalogue keys: a dot is i18next's separator. */
export function eventKey(type) {
  return String(type ?? '').replaceAll('.', '_')
}

/** The translated name of an event type, falling back to its key. */
export function eventLabel(t, type) {
  return t(`webhooks.events.${eventKey(type)}`, { defaultValue: type })
}

/** A subscription's state: on, off, or switched off after failures. */
export function subscriptionState(webhook) {
  if (webhook.active) return { key: 'active', tone: 'success' }
  if (webhook.disabled_reason === 'failing') return { key: 'failing', tone: 'danger' }
  return { key: 'off', tone: 'neutral' }
}

/** The translated reason a delivery did not go through (`status`, `refused.private_address`, ...). */
export function deliveryError(t, error) {
  if (!error) return null
  return t(`webhooks.errors.${error}`, { defaultValue: error })
}
