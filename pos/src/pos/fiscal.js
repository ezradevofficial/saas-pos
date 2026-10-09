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

/**
 * POS-10, TPL-03: the fiscal state a printed receipt shows. The upload
 * answer first; then, online and once uploaded (not off or waiting), the
 * server's current answer: for a sale, `sale`; for a refund, its entry in
 * the sale's `refunds` (found through the refund's `sale_id`). The answer
 * carries the authority's references and the QR drawn by the server
 * (`qr_svg`), even when the till already knows it was accepted.
 *
 * @returns {Promise<{state: string, invoiceNumber: string|null, remote: object|null}>}
 */
export async function receiptFiscal({ api, entry, kind = 'sale', record, online }) {
  const local = { ...fiscalState(entry), remote: null };
  if (!online || local.state === 'off' || local.state === 'waiting') return local;
  const saleId = kind === 'refund' ? record.sale_id : record.id;
  if (!saleId) return local;
  const remote = await fetchFiscal(api, saleId);
  if (!remote) return local;
  if (kind === 'refund') {
    const found = (remote.refunds ?? []).find((refund) => refund.id === record.id);
    if (!found) return local;
    return { ...fiscalState(entry, { sale: found.fiscal ?? null }), remote: found.fiscal ?? null };
  }
  return { ...fiscalState(entry, remote), remote: remote.sale ?? null };
}
