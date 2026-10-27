import { NetworkError } from '../sync/api';

/**
 * SAL-29: a stand-in for the Salon device endpoint on top of fakeServer, written from docs/modules/salon.md (not with
 * the till's upload code): `POST salon/device/sync` stores records by id (the same id again answers the same), answers one
 * result per record in order, and refuses a record for good when a test says so (`reject(id, code)`, for instance
 * `slot_taken`). The pulled entities are offered through `define()`.
 */
export function salonServer(server) {
  const state = { stored: { appointments: new Map(), events: new Map(), queue: new Map() }, batches: [], rejections: new Map(), loseAnswers: 0 };
  const KINDS = ['appointments', 'events', 'queue'];

  server.state.handler = (method, path, body) => {
    if (method === 'POST' && path === 'salon/device/sync') {
      state.batches.push({ ids: KINDS.flatMap((kind) => (body[kind] ?? []).map((record) => record.id)) });
      const results = { appointments: [], events: [], queue: [] };
      for (const kind of KINDS) {
        for (const record of body[kind] ?? []) {
          const code = state.rejections.get(record.id);
          if (code) {
            results[kind].push({ id: record.id, status: 'rejected', code });
            continue;
          }
          if (!state.stored[kind].has(record.id)) state.stored[kind].set(record.id, record);
          results[kind].push({ id: record.id, status: 'stored' });
        }
      }
      if (state.loseAnswers > 0) {
        state.loseAnswers -= 1;
        throw new NetworkError(new Error('answer lost'));
      }
      const anyStored = KINDS.some((kind) => results[kind].some((result) => result.status === 'stored'));
      return anyStored ? { status: 200, body: { results } } : { status: 422, body: { code: 'upload_rejected', results } };
    }
    return null;
  };

  return {
    state,
    define({ settings = null, staff = [], services = [], schedule = [], queue = [], packages = [] } = {}) {
      server.define('salon_settings', { mode: 'snapshot', module: 'salon', rows: settings ? [settings] : [] });
      server.define('salon_staff', { mode: 'snapshot', module: 'salon', rows: staff });
      server.define('salon_services', { mode: 'snapshot', module: 'salon', rows: services });
      server.define('salon_schedule', { mode: 'snapshot', module: 'salon', rows: schedule });
      server.define('salon_queue', { mode: 'snapshot', module: 'salon', rows: queue });
      server.define('salon_packages', { mode: 'snapshot', module: 'salon', rows: packages });
    },
    count: (kind) => state.stored[kind].size,
    reject: (id, code) => void state.rejections.set(id, code),
    loseNextAnswer: () => void (state.loseAnswers += 1),
  };
}
