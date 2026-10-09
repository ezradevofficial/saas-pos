import { createHash } from 'node:crypto';
import recorded from './fixtures/device-api.json';

const clone = (value) => JSON.parse(JSON.stringify(value));
const encode = (text) => Buffer.from(text).toString('base64url');
const decode = (text) => Buffer.from(String(text), 'base64url').toString();
const canonical = (value) =>
  Array.isArray(value)
    ? `[${value.map(canonical).join(',')}]`
    : value && typeof value === 'object'
      ? `{${Object.keys(value)
          .sort()
          .map((key) => `${JSON.stringify(key)}:${canonical(value[key])}`)
          .join(',')}}`
      : JSON.stringify(value);

/** The recorded answers of the PHP device endpoints (api/modules/POS/tests/DeviceApiShapesTest.php). */
export const shapes = recorded;

const pullPage = (key) => recorded.pull_first.body.entities[key];

/** A row shaped like the server's, from the recorded one. */
export const itemRow = (id, extra = {}) => ({ ...clone(pullPage('items').upserts[0]), id, code: `C-${id}`, name: `Item ${id}`, ...extra });
export const priceRow = (id, itemId, amountMinor, extra = {}) => ({ ...clone(pullPage('item_prices').upserts[0]), id, item_id: itemId, amount_minor: String(amountMinor), ...extra });
export const rateRow = (id, mid, extra = {}) => ({ ...clone(pullPage('exchange_rates').upserts[0]), id, mid, ...extra });

/** A sale as the till uploads it, from the recorded request (ids, numbers and shift replaced). */
export function saleBody({ id, shiftId, seq, soldAt, lineId, paymentId, unitPriceMinor }) {
  const sale = clone(recorded.sales_request.sales[0]);
  sale.id = id;
  sale.shift_id = shiftId;
  sale.receipt_seq = seq;
  sale.receipt_number = `R-L01-${String(seq).padStart(6, '0')}`;
  sale.sold_at = new Date(soldAt).toISOString();
  sale.lines[0].id = lineId;
  sale.payments[0].id = paymentId;
  if (unitPriceMinor) sale.lines[0].unit_price_minor = sale.lines[0].list_price_minor = String(unitPriceMinor);
  return sale;
}

export function shiftBody({ id, openedAt, closing = null }) {
  return { ...clone(recorded.shifts_request.shifts[0]), id, opened_at: new Date(openedAt).toISOString(), closing };
}

const PATHS = {
  'pos/shifts': 'shifts',
  'pos/sales': 'sales',
  'pos/cash-movements': 'movements',
  'pos/voids': 'voids',
  'pos/refunds': 'refunds',
};

/**
 * NFR-04: the device API behind `fetch`, answering with the shapes the PHP
 * endpoints recorded. It keeps an incremental change log per entity
 * (opaque cursors `i1.{version}.{xid}.{seq}` like the server's), snapshot
 * entities (hash cursors), and stores uploads idempotently: a resend gets
 * the stored answer, a resend with another body `payload_mismatch`, a
 * sale whose shift is not stored `shift_unknown` (retryable), as the
 * server does. Tests switch it offline, make answers get lost after the
 * server stored them, and script failures per path.
 */
export function recordedServer({ now }) {
  const state = {
    online: true,
    pageSize: 500,
    seq: 0,
    entities: {},
    records: new Map(), // id → { path, hash, result, payload }
    firstStores: new Map(), // id → times stored (must stay 1)
    arrivals: [], // [path, id, closing?] in the order the server stored or answered them
    requests: [],
    script: [], // [{ path, once, answer: () => response | 'network' | 'lost' }]
  };

  const define = (key, { mode = 'incremental', version = 1, module = 'core', rows = [] } = {}) => {
    state.entities[key] = { mode, version, module, rows: new Map(), log: [] };
    rows.forEach((row) => upsert(key, row));
  };
  const upsert = (key, row) => {
    const entity = state.entities[key];
    entity.rows.set(String(row.id), clone(row));
    entity.log.push({ seq: ++state.seq, id: String(row.id) });
  };
  const remove = (key, id) => {
    const entity = state.entities[key];
    entity.rows.delete(String(id));
    entity.log.push({ seq: ++state.seq, id: String(id) });
  };

  const json = (status, body, headers = {}) => ({
    status,
    headers: { get: (name) => headers[name.toLowerCase()] ?? null },
    text: async () => (body === undefined ? '' : JSON.stringify(body)),
  });

  function bootstrap() {
    const body = clone(recorded.bootstrap.body);
    body.server_time = new Date(now()).toISOString();
    body.entities = Object.entries(state.entities).map(([key, entity]) => ({ key, mode: entity.mode, module: entity.module, version: entity.version }));
    body.page_size = state.pageSize;
    return json(200, body);
  }

  function pull(params) {
    const keys = params.getAll('entities[]');
    const limit = Number(params.get('limit') ?? state.pageSize);
    const out = {};
    for (const key of keys) {
      const entity = state.entities[key];
      const raw = params.get(`cursors[${key}]`);
      let cursor = null;
      if (raw) {
        const match = /^(i|s)1\.(\d+)\.(.+)$/.exec(decode(raw));
        if (!match || (match[1] === 'i') !== (entity.mode === 'incremental')) {
          const body = clone(recorded.pull_invalid_cursor.body);
          body.entity = key;
          body.errors = { [`cursors.${key}`]: body.errors['cursors.items'] };
          return json(422, body);
        }
        cursor = { version: Number(match[2]), value: match[3] };
      }
      const reset = cursor !== null && cursor.version !== entity.version;
      const page = { ...clone(pullPage(entity.mode === 'incremental' ? 'items' : 'exchange_rates')), reset };
      if (entity.mode === 'incremental') {
        const from = cursor && !reset ? Number(cursor.value.split('.')[1]) : 0;
        const changes = entity.log.filter((change) => change.seq > from);
        const slice = changes.slice(0, limit);
        const ids = [...new Set(slice.map((change) => change.id))];
        const last = slice.length ? slice[slice.length - 1].seq : from;
        Object.assign(page, {
          upserts: ids.filter((id) => entity.rows.has(id)).map((id) => clone(entity.rows.get(id))),
          tombstones: ids.filter((id) => !entity.rows.has(id)),
          cursor: encode(`i1.${entity.version}.900.${last}`),
          has_more: changes.length > limit,
        });
      } else {
        const rows = [...entity.rows.values()].map(clone);
        const hash = createHash('sha256').update(canonical(rows)).digest('hex');
        const unchanged = cursor && !reset && cursor.value === hash;
        Object.assign(page, { replace: !unchanged, upserts: unchanged ? [] : rows, tombstones: [], cursor: encode(`s1.${entity.version}.${hash}`), has_more: false });
      }
      out[key] = page;
    }
    return json(200, { server_time: new Date(now()).toISOString(), entities: out });
  }

  function storedResult(path, record) {
    const at = new Date(now()).toISOString().replace(/\.\d+Z$/, '+00:00');
    if (path === 'pos/sales') return { ...clone(recorded.sales_stored.body.results[0]), id: record.id, receipt_number: record.receipt_number, received_at: at };
    if (path === 'pos/shifts') return { ...clone(recorded.shifts_stored.body.results[0]), id: record.id, shift_status: record.closing ? 'closed' : 'open', received_at: at };
    return { id: record.id, status: 'stored', received_at: at };
  }

  function rejection(id, template) {
    return { ...clone(template), id };
  }

  function upload(path, body) {
    const records = body[PATHS[path]];
    const results = records.map((record) => {
      const hash = canonical(record);
      const known = state.records.get(record.id);
      if (path === 'pos/shifts' && known) {
        // A shift is sent again with its closing: the server closes it and answers the shift.
        if (record.closing && !known.closed) {
          known.closed = true;
          known.result = storedResult(path, record);
          state.arrivals.push([path, record.id, true]);
        }
        return known.result;
      }
      if (known) {
        return known.hash === hash ? known.result : rejection(record.id, recorded.sales_payload_mismatch.body.results[0]);
      }
      if ((path === 'pos/sales' || path === 'pos/cash-movements') && !state.records.has(record.shift_id)) {
        return rejection(record.id, recorded.sales_retryable.body.results[0]);
      }
      if ((path === 'pos/voids' || path === 'pos/refunds') && !state.records.has(record.sale_id)) {
        return rejection(record.id, { ...recorded.sales_retryable.body.results[0], error: { code: 'sale_unknown', message: 'Not yet.', field: 'sale_id', retryable: true } });
      }
      const result = storedResult(path, record);
      state.records.set(record.id, { path, hash, result, payload: clone(record), closed: Boolean(record.closing) });
      state.firstStores.set(record.id, (state.firstStores.get(record.id) ?? 0) + 1);
      state.arrivals.push([path, record.id, Boolean(record.closing)]);
      return result;
    });
    if (results.some((result) => result.status === 'stored')) return json(200, { results });
    return json(422, { ...clone(recorded.sales_retryable.body), results });
  }

  async function fetchImpl(url, init = {}) {
    const parsed = new URL(url);
    const path = parsed.pathname.replace(/^\/api\/v1\//, '');
    const method = init.method ?? 'GET';
    const body = init.body ? JSON.parse(init.body) : undefined;
    state.requests.push({ method, path, params: parsed.searchParams, body, at: now() });

    if (!state.online) throw new TypeError('Network request failed');

    const scripted = state.script.findIndex((entry) => entry.path === path);
    let lose = false;
    if (scripted !== -1) {
      const entry = state.script[scripted];
      if (entry.once !== false) state.script.splice(scripted, 1);
      const answer = entry.answer();
      if (answer === 'network') throw new TypeError('Network request failed');
      if (answer === 'lost') lose = true;
      else if (answer) return answer;
    }

    let response;
    if (method === 'GET' && path === 'sync/bootstrap') response = bootstrap();
    else if (method === 'GET' && path === 'sync/pull') response = pull(parsed.searchParams);
    else if (method === 'POST' && PATHS[path]) response = upload(path, body);
    else response = json(404, { code: 'not_found', message: 'Not found' });

    // The server did the work, but the answer never reached the till.
    if (lose) throw new TypeError('Network request failed');
    return response;
  }

  return {
    state,
    fetch: fetchImpl,
    define,
    upsert,
    remove,
    json,
    goOffline: () => void (state.online = false),
    goOnline: () => void (state.online = true),
    /** Answer the next request to `path` with `answer()` (a response, 'network', or 'lost'). */
    once: (path, answer) => state.script.push({ path, answer, once: true }),
    requestsTo: (path) => state.requests.filter((request) => request.path === path),
  };
}
