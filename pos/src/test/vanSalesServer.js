import { NetworkError } from '../sync/api';

/**
 * VAN-27: a stand-in for the Van sales device endpoints on top of fakeServer, written from
 * docs/modules/van-sales.md (not with the till's upload code): `POST van-sales/device/sync` stores
 * records by id (the same id again answers the same), answers one result per record in order, and
 * `POST van-sales/device/loads/{id}/confirm` confirms a released sheet. The pulled entities are
 * offered through `define()`.
 *
 * Tests can lose an answer after the server stored (`loseNextAnswer`), make the server stop part
 * way through a request (`storeOnly(n)`: it stores and answers the first n records, then fails),
 * refuse a record for good (`reject(id, code)`) and set the answer to counts (`countStatus`).
 */
export function vanSalesServer(server) {
  const state = {
    stored: { runs: new Map(), visits: new Map(), collections: new Map(), counts: new Map() },
    requests: [],
    batches: [],
    rejections: new Map(),
    loseAnswers: 0,
    storeLimit: null,
    countStatus: 'pending',
    confirms: [],
  };
  const KINDS = ['runs', 'visits', 'collections', 'counts'];

  function answerFor(kind, record) {
    const rejected = state.rejections.get(record.id);
    if (rejected) return { id: record.id, status: 'rejected', code: rejected };
    const held = state.stored[kind].get(record.id);
    // The last write wins by updated_at for a visit; any other kind keeps its first copy.
    if (!held || (kind === 'visits' && String(record.updated_at) > String(held.updated_at))) state.stored[kind].set(record.id, record);
    const result = { id: record.id, status: 'stored' };
    if (kind === 'counts') {
      result.reconciliation = { id: `rec-${record.id}`, status: state.countStatus, stock_variance_lines: 0, tenders: record.tenders.map((tender) => ({ ...tender, expected_minor: tender.counted_minor, variance_minor: '0' })) };
    }
    return result;
  }

  server.state.handler = (method, path, body) => {
    if (method === 'POST' && path === 'van-sales/device/sync') {
      const total = KINDS.reduce((sum, kind) => sum + (body[kind]?.length ?? 0), 0);
      state.requests.push(path);
      state.batches.push({ total, ids: KINDS.flatMap((kind) => (body[kind] ?? []).map((record) => record.id)) });
      const results = { runs: [], visits: [], collections: [], counts: [] };
      let handled = 0;
      for (const kind of KINDS) {
        for (const record of body[kind] ?? []) {
          if (state.storeLimit !== null && handled >= state.storeLimit) continue;
          results[kind].push(answerFor(kind, record));
          handled += 1;
        }
      }
      if (state.storeLimit !== null && handled < total) {
        state.storeLimit = null;
        throw new NetworkError(new Error('connection dropped part way'));
      }
      if (state.loseAnswers > 0) {
        state.loseAnswers -= 1;
        throw new NetworkError(new Error('answer lost'));
      }
      const anyStored = KINDS.some((kind) => results[kind].some((result) => result.status === 'stored'));
      return anyStored ? { status: 200, body: { results } } : { status: 422, body: { code: 'upload_rejected', results } };
    }
    const confirm = /^van-sales\/device\/loads\/(.+)\/confirm$/.exec(path);
    if (method === 'POST' && confirm) {
      state.confirms.push({ id: confirm[1], body });
      return { status: 200, body: { data: { id: confirm[1], status: 'confirmed' } } };
    }
    return null;
  };

  return {
    state,
    define({ van = null, routes = [], accounts = [], loads = [], runs = [] } = {}) {
      server.define('van_sales_van', { mode: 'snapshot', module: 'van_sales', rows: van ? [van] : [] });
      server.define('van_sales_routes', { mode: 'incremental', module: 'van_sales', rows: routes });
      server.define('van_sales_accounts', { mode: 'incremental', module: 'van_sales', rows: accounts });
      server.define('van_sales_loads', { mode: 'incremental', module: 'van_sales', rows: loads });
      server.define('van_sales_runs', { mode: 'incremental', module: 'van_sales', rows: runs });
    },
    /** How many records the server holds of a kind. */
    count: (kind) => state.stored[kind].size,
    reject: (id, code) => void state.rejections.set(id, code),
    loseNextAnswer: () => void (state.loseAnswers += 1),
    storeOnly: (n) => void (state.storeLimit = n),
    setCountStatus: (status) => void (state.countStatus = status),
  };
}
