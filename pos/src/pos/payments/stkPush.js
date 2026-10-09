/**
 * M-Pesa STK push (Daraja) from the till: a HOOK, switched off.
 *
 * Phase 4 Task 3 builds the payment intents API on the server (initiate,
 * confirm, callbacks). Until it ships, the till takes M-Pesa as a
 * "manual M-Pesa" payment: the customer pays to the till number and the
 * cashier types the M-Pesa code (works offline; the code is the payment's
 * provider_reference). When the API exists:
 *
 * 1. set STK_PUSH_ENABLED to true (or read it from the payment method's
 *    synced settings);
 * 2. implement requestStkPush() against the intents endpoint (online
 *    only): it returns { intentId } and the payment is added as
 *    `status: 'pending'` with the intent as provider_reference;
 * 3. poll or listen for confirmation and set the payment `confirmed`
 *    ("payment adds itself once confirmed", PosPayment design).
 *
 * The payment screen already renders the STK panel when the flag is on.
 */
export const STK_PUSH_ENABLED = false;

export async function requestStkPush() {
  throw new Error('stk_push_not_available');
}

/** An M-Pesa transaction code as typed by the cashier: letters and digits, 8 to 12 characters. */
export function isMpesaCode(text) {
  return /^[A-Z0-9]{8,12}$/.test(String(text ?? '').trim().toUpperCase());
}
