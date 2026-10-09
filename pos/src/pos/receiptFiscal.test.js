import { NetworkError } from '../sync/api';
import { OUTBOX } from '../sync/store';
import { receiptFiscal } from './fiscal';

// POS-10, TPL-03: printed receipts get the authority's answer for sales and refunds.
const acknowledged = (fiscal) => ({ status: OUTBOX.ACKNOWLEDGED, result: { fiscal } });
const QR = 'data:image/svg+xml;base64,QQ==';
const remote = {
  sale_id: 's1',
  transmits: true,
  sale: { status: 'accepted', invoice_number: 41, authority: { qr: 'https://etims.example/v' }, qr_svg: QR },
  refunds: [{ id: 'r1', receipt_number: 'RF-1', fiscal: { status: 'accepted', invoice_number: 42, authority: { qr: 'https://etims.example/r' }, qr_svg: QR } }],
  void: null,
};
const apiAnswering = (body) => ({ get: jest.fn(async () => ({ status: 200, body: { data: body } })) });

describe('receiptFiscal', () => {
  it('fetches the answer for a refund through its sale and finds the refund', async () => {
    const api = apiAnswering(remote);
    const state = await receiptFiscal({ api, entry: acknowledged('pending'), kind: 'refund', record: { id: 'r1', sale_id: 's1' }, online: true });
    expect(api.get).toHaveBeenCalledWith('pos/sales/s1/fiscal');
    expect(state).toEqual({ state: 'accepted', invoiceNumber: 42, remote: remote.refunds[0].fiscal });
  });

  it('fetches even when the till already knows the sale was accepted (to get the QR)', async () => {
    const api = apiAnswering(remote);
    const state = await receiptFiscal({ api, entry: acknowledged('accepted'), kind: 'sale', record: { id: 's1' }, online: true });
    expect(state.remote.qr_svg).toBe(QR);
  });

  it('does not ask while offline, before upload, or when the company does not transmit', async () => {
    const api = apiAnswering(remote);
    expect((await receiptFiscal({ api, entry: acknowledged('pending'), record: { id: 's1' }, online: false })).state).toBe('pending');
    expect((await receiptFiscal({ api, entry: null, record: { id: 's1' }, online: true })).state).toBe('waiting');
    expect((await receiptFiscal({ api, entry: acknowledged(null), record: { id: 's1' }, online: true })).state).toBe('off');
    expect(api.get).not.toHaveBeenCalled();
  });

  it('keeps the local state when the network fails or the refund is not listed', async () => {
    const failing = { get: jest.fn(async () => { throw new NetworkError('down'); }) };
    expect(await receiptFiscal({ api: failing, entry: acknowledged('pending'), record: { id: 's1' }, online: true })).toEqual({ state: 'pending', invoiceNumber: null, remote: null });
    const state = await receiptFiscal({ api: apiAnswering(remote), entry: acknowledged('pending'), kind: 'refund', record: { id: 'held', sale_id: 's1' }, online: true });
    expect(state.state).toBe('pending');
  });
});
