import { NetworkError } from '../../sync/api';

/**
 * POS-03, M-Pesa from the till through the server's payment intents
 * (docs/integrations.md, core Payments):
 *
 * - STK push: POST payments/intents {mode: 'stk'} with the customer's
 *   phone; the server asks Daraja to prompt the customer, who confirms
 *   with their PIN. The till polls GET payments/intents/{id}: `pending`
 *   and `unknown` (the provider did not answer yet; the server keeps
 *   checking) mean "still checking"; `succeeded` adds the payment
 *   (confirmed, the M-Pesa receipt as provider_reference); `failed`,
 *   `cancelled` and `timeout` say so and the cashier may retry or take a
 *   typed code. Whole shillings, KES only. Online only.
 * - Typed code (`manual`): the customer paid to the till number and the
 *   cashier types the code. Online, it is registered as a manual intent so
 *   the server verifies it (a code already used is refused at once);
 *   offline it is kept on the sale and checked when the sale uploads.
 *
 * The intent id is the sale payment's id: the server confirms that
 * payment when the money arrives. `user_id` is the signed-in person, who
 * must be staff of this till's location. Offered only when the synced
 * method says so (`capabilities.stk`, `capabilities.manual_code`).
 */
export const STK_PUSH_ENABLED = true;

export const INTENT = { PENDING: 'pending', UNKNOWN: 'unknown', SUCCEEDED: 'succeeded', FAILED: 'failed', CANCELLED: 'cancelled', TIMEOUT: 'timeout' };
const STILL_CHECKING = new Set([INTENT.PENDING, INTENT.UNKNOWN]);

/** Whether the till may push a payment request for this method now. */
export function canPush(method, online) {
  return STK_PUSH_ENABLED && Boolean(online) && Boolean(method?.capabilities?.stk);
}

/** An M-Pesa transaction code as typed by the cashier: letters and digits, 8 to 12 characters. */
export function isMpesaCode(text) {
  return /^[A-Z0-9]{8,12}$/.test(String(text ?? '').trim().toUpperCase());
}

function problem(response) {
  const error = new Error(response.body?.code ?? `http_${response.status}`);
  error.code = response.body?.code ?? `http_${response.status}`;
  error.status = response.status;
  error.messageText = response.body?.message ?? null;
  return error;
}

/**
 * Starts an STK push. Resolves the intent ({ id, status, … }); rejects
 * with `code` (e.g. too_many_requests, not_staff_here, validation) or a
 * NetworkError when the server cannot be reached.
 */
export async function startStkPush({ api, id, methodId, amountMinor, currency, phone, saleId, userId }) {
  const response = await api.post('payments/intents', {
    id,
    payment_method_id: methodId,
    purpose: 'sale',
    mode: 'stk',
    amount_minor: String(amountMinor),
    currency,
    phone,
    reference_type: 'pos.sale',
    reference: saleId,
    user_id: userId,
  });
  if (response.status !== 200 && response.status !== 201) throw problem(response);
  return response.body.data;
}

/**
 * Registers a typed code online so the server verifies it. Resolves the
 * intent, or null when offline (the code stays on the sale); rejects with
 * `code` when the server refuses it (receipt_used: the code paid another
 * sale).
 */
export async function registerTypedCode({ api, id, methodId, amountMinor, currency, receipt, saleId, userId }) {
  try {
    const response = await api.post('payments/intents', {
      id,
      payment_method_id: methodId,
      purpose: 'sale',
      mode: 'manual',
      amount_minor: String(amountMinor),
      currency,
      receipt,
      reference_type: 'pos.sale',
      reference: saleId,
      user_id: userId,
    });
    if (response.status === 200 || response.status === 201) return response.body.data;
    // Refusals about the code itself stop the cashier; anything else (server trouble) leaves it to the upload.
    if (response.status === 409 || response.status === 422) throw problem(response);
    return null;
  } catch (error) {
    if (error instanceof NetworkError) return null;
    throw error;
  }
}

/**
 * Polls the intent until it is final (or `stop()` is called). Calls
 * onUpdate(intent) on every answer; a lost answer (offline, 5xx) counts as
 * still checking. Resolves the final intent, or null when stopped.
 */
export function pollIntent({ api, id, onUpdate = () => {}, intervalMs = 3000, timers = globalThis }) {
  let stopped = false;
  let timer = null;
  const done = new Promise((resolve) => {
    const tick = async () => {
      if (stopped) return resolve(null);
      let intent = null;
      try {
        const response = await api.get(`payments/intents/${id}`);
        if (response.status === 200) intent = response.body?.data ?? null;
      } catch (error) {
        if (!(error instanceof NetworkError)) throw error;
      }
      if (stopped) return resolve(null);
      if (intent) onUpdate(intent);
      if (intent && !STILL_CHECKING.has(intent.status)) return resolve(intent);
      timer = timers.setTimeout(tick, intervalMs);
      return undefined;
    };
    tick();
  });
  return {
    done,
    stop() {
      stopped = true;
      if (timer) timers.clearTimeout(timer);
    },
  };
}

/** The words for an intent's state: 'checking' | 'paid' | 'failed'. */
export function intentState(intent) {
  if (!intent || STILL_CHECKING.has(intent.status)) return 'checking';
  return intent.status === INTENT.SUCCEEDED ? 'paid' : 'failed';
}
