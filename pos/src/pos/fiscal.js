import { NetworkError } from '../sync/api';
import { OUTBOX } from '../sync/store';

/**
 * POS-10: a sale's fiscal state on the till (KRA eTIMS, DRC DGI), from the
 * server's answer to its upload (`fiscal`: null when the company does not
 * transmit, else pending | accepted | rejected) and, online, from
 * GET pos/sales/{id}/fiscal (with the authority's invoice number).
 *
 * States: 'waiting' (not uploaded yet), 'pending', 'accepted', 'rejected',
 * 'off' (the company does not transmit).
 */
export function fiscalState(entry, remote = null) {
  const sale = remote?.sale;
  if (remote) {
    if (sale === null) return { state: 'off', invoiceNumber: null };
    if (sale) return { state: sale.status ?? 'pending', invoiceNumber: sale.invoice_number ?? null };
  }
  if (!entry || entry.status !== OUTBOX.ACKNOWLEDGED) return { state: 'waiting', invoiceNumber: null };
  const answer = entry.result?.fiscal;
  if (answer === null || answer === undefined) return { state: 'off', invoiceNumber: null };
  return { state: answer, invoiceNumber: null };
}

/** Asks the server for the sale's fiscal state now; null when offline or not answered. */
export async function fetchFiscal(api, saleId) {
  try {
    const response = await api.get(`pos/sales/${saleId}/fiscal`);
    return response.status === 200 ? (response.body?.data ?? null) : null;
  } catch (error) {
    if (error instanceof NetworkError) return null;
    throw error;
  }
}
