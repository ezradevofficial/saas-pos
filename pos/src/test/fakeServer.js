import { createHash } from 'node:crypto';
import { NetworkError } from '../sync/api';

const encode = (text) => Buffer.from(text).toString('base64url');
const decode = (text) => Buffer.from(text, 'base64url').toString();

/**
 * An in-memory stand-in for the device API (ADR 004 addendum): bootstrap,
 * per-entity pull with opaque cursors (incremental change log with
 * tombstones, or hashed snapshots), batch uploads idempotent by record id,
 * and PIN attempt reports. Tests change its data and failure modes.
 */
export function fakeServer({ entities = {}, now = () => Date.now() } = {}) {
  const state = {
    online: true,
    authStatus: null,
    serverTime: null,
    secretIssued: true,
    pageSizeDefault: 500,
    entities: {},
    stored: new Map(),
    reject: new Map(),
    uploadOverride: null,
    requests: [],
    pinReports: [],
    settings: { id: 'device', location: { id: 'loc-1', name: 'Westlands shop' } },
    pairing: { code: 'ABCDEFGH', token: '1|device-token', secret: Buffer.alloc(32, 9).toString('base64url'), kid: 'k1' },
    seq: 0,
  };

  const server = {
    state,
    define(key, { mode = 'incremental', version = 1, module = 'core', rows = [] } = {}) {
      state.entities[key] = { mode, version, module, rows: new Map(), log: [] };
      rows.forEach((row) => server.upsert(key, row));
      return server;
    },
    upsert(key, row) {
      const entity = state.entities[key];
      entity.rows.set(String(row.id), row);
      entity.log.push({ seq: ++state.seq, id: String(row.id) });
    },
    remove(key, id) {
      const entity = state.entities[key];
      entity.rows.delete(String(id));
      entity.log.push({ seq: ++state.seq, id: String(id) });
    },
    bumpVersion(key) {
      state.entities[key].version += 1;
    },
    get: (path, options) => server.request('GET', path, options),
    post: (path, body, options) => server.request('POST', path, { ...options, body }),
    goOffline: () => void (state.online = false),
    goOnline: () => void (state.online = true),
    requestsTo: (path) => state.requests.filter((request) => request.path === path),

    async request(method, path, { query, body } = {}) {
      state.requests.push({ method, path, query, body });
      if (!state.online) throw new NetworkError(new Error('offline'));
      if (method === 'POST' && path === 'devices/pair') {
        if (body.code !== state.pairing.code) return { status: 422, body: { code: 'invalid_pairing_code', message: 'Invalid' } };
        return {
          status: 200,
          body: {
            token: state.pairing.token,
            device_secret: state.pairing.secret,
            device_secret_kid: state.pairing.kid,
            device: { id: 'device-1', name: body.device_name, location_id: 'loc-1', status: 'active' },
          },
        };
      }
      if (state.authStatus) return { status: state.authStatus, body: { code: state.authStatus === 401 ? 'unauthenticated' : 'device_inactive', message: 'No' } };
      const time = new Date(state.serverTime ?? now()).toISOString();

      if (method === 'GET' && path === 'sync/bootstrap') {
        return {
          status: 200,
          body: {
            server_time: time,
            device: { id: 'device-1', name: 'Till 1', location_id: 'loc-1', status: 'active' },
            device_secret_kid: state.secretIssued ? 'k1' : null,
            settings: state.settings,
            entities: Object.entries(state.entities).map(([key, entity]) => ({ key, mode: entity.mode, module: entity.module, version: entity.version })),
            page_size: state.pageSizeDefault,
            max_page_size: 1000,
            pin: { scheme: 'pbkdf2-sha256+hmac-sha256/v1', max_attempts: 5 },
          },
        };
      }

      if (method === 'GET' && path === 'sync/pull') {
        const out = {};
        const limit = Number(query?.limit ?? state.pageSizeDefault);
        for (const key of query.entities) {
          const entity = state.entities[key];
          if (!entity) return { status: 422, body: { code: 'validation_failed' } };
          const raw = query.cursors?.[key];
          let cursor = null;
          if (raw) {
            const match = /^(i|s)1\.(\d+)\.(.+)$/.exec(decode(raw));
            if (!match || (match[1] === 'i') !== (entity.mode === 'incremental')) {
              return { status: 422, body: { code: 'invalid_cursor', entity: key, errors: { [`cursors.${key}`]: ['Invalid'] } } };
            }
            cursor = { version: Number(match[2]), value: match[3] };
          }
          const reset = cursor !== null && cursor.version !== entity.version;
          if (entity.mode === 'incremental') {
            const from = cursor && !reset ? Number(cursor.value) : 0;
            const changes = entity.log.filter((change) => change.seq > from);
            const page = changes.slice(0, limit);
            const ids = [...new Set(page.map((change) => change.id))];
            const last = page.length ? page[page.length - 1].seq : from;
            out[key] = {
              mode: 'incremental',
              reset,
              replace: false,
              upserts: ids.filter((id) => entity.rows.has(id)).map((id) => entity.rows.get(id)),
              tombstones: ids.filter((id) => !entity.rows.has(id)),
              cursor: encode(`i1.${entity.version}.${last}`),
              has_more: changes.length > limit,
            };
          } else {
            const rows = [...entity.rows.values()];
            const hash = createHash('sha256').update(JSON.stringify(rows)).digest('hex');
            const unchanged = cursor && !reset && cursor.value === hash;
            out[key] = {
              mode: 'snapshot',
              reset,
              replace: !unchanged,
              upserts: unchanged ? [] : rows,
              tombstones: [],
              cursor: encode(`s1.${entity.version}.${hash}`),
              has_more: false,
            };
          }
        }
        return { status: 200, body: { server_time: time, entities: out } };
      }

      if (method === 'POST' && path === 'pos/pin/attempts') {
        state.pinReports.push(...body.reports);
        return { status: 200, body: { data: body.reports } };
      }

      if (method === 'POST' && path.startsWith('pos/')) {
        if (state.uploadOverride) {
          const answer = state.uploadOverride(path, body);
          if (answer) return answer;
        }
        const records = Object.values(body)[0];
        const results = records.map((record) => {
          const rejection = state.reject.get(record.id);
          if (rejection) return { id: record.id, status: 'rejected', error: rejection };
          const first = !state.stored.has(record.id);
          if (first) state.stored.set(record.id, { path, record, received_at: time });
          return { id: record.id, status: 'stored', received_at: state.stored.get(record.id).received_at };
        });
        const anyStored = results.some((result) => result.status === 'stored');
        return anyStored
          ? { status: 200, body: { results } }
          : { status: 422, body: { code: 'upload_rejected', message: 'Nothing stored', results } };
      }

      return { status: 404, body: { code: 'not_found' } };
    },
  };

  Object.entries(entities).forEach(([key, definition]) => server.define(key, definition));
  return server;
}
