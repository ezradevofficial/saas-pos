import { NetworkError } from '../../sync/api';
import { OUTBOX } from '../../sync/store';
import { fiscalState } from '../fiscal';
import { canPush, intentState, pollIntent, registerTypedCode, startStkPush } from './stkPush';

// POS-03, POS-10: payment intents from the till, and the fiscal state of a sale.

const fakeApi = (answers) => {
  const calls = [];
  const answer = async (method, path, body) => {
    calls.push({ method, path, body });
    const next = answers.shift();
    if (next instanceof Error) throw next;
    return next;
  };
  return { calls, post: (path, body) => answer('POST', path, body), get: (path) => answer('GET', path) };
};

const args = { id: 'i1', methodId: 'm1', amountMinor: '25000', currency: 'KES', saleId: 's1', userId: 'u1' };

describe('STK push and typed codes', () => {
  it('offers a push only online for a method that can (capabilities.stk)', () => {
    expect(canPush({ capabilities: { stk: true } }, true)).toBe(true);
    expect(canPush({ capabilities: { stk: true } }, false)).toBe(false);
    expect(canPush({ capabilities: { stk: false, manual_code: true } }, true)).toBe(false);
  });

  it('sends the push with the signed-in user and refuses with the server’s code', async () => {
    const api = fakeApi([{ status: 201, body: { data: { id: 'i1', status: 'pending' } } }, { status: 422, body: { code: 'not_staff_here' } }]);
    await expect(startStkPush({ api, ...args, phone: '0722000418' })).resolves.toMatchObject({ status: 'pending' });
    expect(api.calls[0].body).toEqual({ id: 'i1', payment_method_id: 'm1', purpose: 'sale', mode: 'stk', amount_minor: '25000', currency: 'KES', phone: '0722000418', reference_type: 'pos.sale', reference: 's1', user_id: 'u1' });
    await expect(startStkPush({ api, ...args, phone: '0722000418' })).rejects.toMatchObject({ code: 'not_staff_here' });
  });

  it('registers a typed code online, refuses a used one, and keeps it offline', async () => {
    const api = fakeApi([{ status: 201, body: { data: { id: 'i1', status: 'succeeded', verification: 'unverified' } } }, { status: 422, body: { code: 'receipt_used' } }, new NetworkError(new Error('offline')), { status: 503, body: null }]);
    await expect(registerTypedCode({ api, ...args, receipt: 'QJK3ABC123' })).resolves.toMatchObject({ verification: 'unverified' });
    expect(api.calls[0].body).toMatchObject({ mode: 'manual', receipt: 'QJK3ABC123', user_id: 'u1' });
    await expect(registerTypedCode({ api, ...args, receipt: 'QJK3ABC123' })).rejects.toMatchObject({ code: 'receipt_used' });
    await expect(registerTypedCode({ api, ...args, receipt: 'QJK3ABC123' })).resolves.toBeNull();
    await expect(registerTypedCode({ api, ...args, receipt: 'QJK3ABC123' })).resolves.toBeNull();
  });

  it('keeps polling through unknown and lost answers until the intent is final', async () => {
    const api = fakeApi([
      { status: 200, body: { data: { status: 'unknown' } } },
      new NetworkError(new Error('offline')),
      { status: 200, body: { data: { status: 'pending' } } },
      { status: 200, body: { data: { status: 'failed', result_code: '1032' } } },
    ]);
    const timers = { setTimeout: (fn) => setImmediate(fn), clearTimeout: () => {} };
    const seen = [];
    const poll = pollIntent({ api, id: 'i1', onUpdate: (intent) => seen.push(intentState(intent)), timers });
    await expect(poll.done).resolves.toMatchObject({ status: 'failed' });
    expect(seen).toEqual(['checking', 'checking', 'failed']);
    expect(api.calls.every((call) => call.path === 'payments/intents/i1')).toBe(true);
  });

  it('stops polling when asked', async () => {
    const api = fakeApi([{ status: 200, body: { data: { status: 'pending' } } }]);
    const timers = { setTimeout: () => 1, clearTimeout: jest.fn() };
    const poll = pollIntent({ api, id: 'i1', timers });
    await new Promise((resolve) => setImmediate(resolve));
    poll.stop();
    expect(timers.clearTimeout).toHaveBeenCalled();
  });
});

describe('fiscal state (POS-10)', () => {
  const acked = (fiscal) => ({ status: OUTBOX.ACKNOWLEDGED, result: { id: 's1', status: 'stored', fiscal } });

  it('reads the upload answer, then the server’s fiscal endpoint', () => {
    expect(fiscalState(null)).toEqual({ state: 'waiting', invoiceNumber: null });
    expect(fiscalState({ status: OUTBOX.PENDING })).toEqual({ state: 'waiting', invoiceNumber: null });
    expect(fiscalState(acked(null))).toEqual({ state: 'off', invoiceNumber: null });
    expect(fiscalState(acked('pending'))).toEqual({ state: 'pending', invoiceNumber: null });
    expect(fiscalState(acked('pending'), { sale: { status: 'accepted', invoice_number: '42' } })).toEqual({ state: 'accepted', invoiceNumber: '42' });
    expect(fiscalState(acked('pending'), { sale: null })).toEqual({ state: 'off', invoiceNumber: null });
  });
});
