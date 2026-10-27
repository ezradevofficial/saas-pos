import { Q } from '@nozbe/watermelondb';

/**
 * VAN-27, NFR-04: what the rep's device keeps for Van sales (schema v13).
 *
 * - Synced, server wins: `van_sales_van`, `van_sales_routes`,
 *   `van_sales_accounts`, `van_sales_loads`, `van_sales_runs` (read here).
 * - Made on the device, in `vs_records` (one row per record, `kind` run |
 *   visit | collection | count): saved here first, in one WatermelonDB write
 *   that bumps `local.rev`, so nothing is lost if the app stops. `state` is
 *   `pending` until an upload of the same revision is stored, `rejected` when
 *   the server refused it for good (parked with its code, never deleted).
 *
 * A stored record: `{ id, kind, run_id, ...the fields the API takes, local }`.
 */
const parse = (text) => {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
};

const KIND_ORDER = { run: 0, visit: 1, collection: 2, count: 3 };

let lastSeq = 0;
/** Strictly increasing, so records upload in the order they were made. */
const nextSeq = () => {
  lastSeq = Math.max(lastSeq + 1, Date.now());
  return lastSeq;
};

const columnsOf = (record, state) => ({
  kind: record.kind,
  run_id: record.run_id ?? '',
  customer_id: record.customer_id ?? null,
  state,
  seq: record.local?.seq ?? nextSeq(),
  created_at: Date.parse(record.local?.created_at ?? '') || Date.now(),
  data: JSON.stringify(record),
});

export function createVsStore(database) {
  const table = () => database.get('vs_records');
  const listeners = new Set();
  const notify = () => listeners.forEach((listener) => listener());

  async function findOne(id) {
    try {
      return await table().find(id);
    } catch {
      return null;
    }
  }

  const rowsOf = async (name) => (await database.get(name).query().fetch()).map((record) => parse(record._raw.data)).filter(Boolean);
  const read = (records) => records.map((record) => parse(record._raw.data)).filter(Boolean);

  const store = {
    /** Calls `listener` after every local write (screens reload). Returns the unsubscribe. */
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },

    /** Tells the listeners a record was written outside add/update (a prepared write). */
    changed: notify,

    // -- Synced data (server wins) -------------------------------------------

    /** The van row, or null: the module is on this device only when it exists. */
    async van() {
      return (await rowsOf('van_sales_van'))[0] ?? null;
    },
    routes: () => rowsOf('van_sales_routes'),
    accounts: () => rowsOf('van_sales_accounts'),
    loads: () => rowsOf('van_sales_loads'),
    serverRuns: () => rowsOf('van_sales_runs'),
    async account(customerId) {
      const record = await database.get('van_sales_accounts').find(String(customerId)).catch(() => null);
      return record ? parse(record._raw.data) : null;
    },

    /** Locally fixes a pulled load sheet after a confirmation (the next pull replaces it with the server's). */
    async patchLoad(id, change) {
      let saved = null;
      await database.write(async () => {
        const record = await database.get('van_sales_loads').find(String(id)).catch(() => null);
        if (!record) return;
        saved = { ...parse(record._raw.data), ...change };
        await database.batch(record.prepareUpdate((row) => row._setRaw('data', JSON.stringify(saved))));
      });
      if (saved) notify();
      return saved;
    },

    // -- Records made on this device ------------------------------------------

    async get(id) {
      return parse((await findOne(id))?._raw.data);
    },

    /** Records of a kind (optionally of one run), oldest first. */
    async list(kind, { runId = null } = {}) {
      const conditions = [Q.where('kind', kind), Q.sortBy('seq', Q.asc)];
      if (runId) conditions.push(Q.where('run_id', runId));
      return read(await table().query(...conditions).fetch());
    },

    async ofCustomer(customerId, kind) {
      return read(await table().query(Q.where('customer_id', customerId), Q.where('kind', kind), Q.sortBy('seq', Q.asc)).fetch());
    },

    /** Saves a new record as waiting to upload (a second save with the same id is refused). */
    async add(record) {
      const next = { ...record, local: { ...record.local, rev: 1, seq: nextSeq(), created_at: new Date().toISOString() } };
      let created = false;
      await database.write(async () => {
        if (await findOne(record.id)) return;
        await database.batch(table().prepareCreateFromDirtyRaw({ id: record.id, ...columnsOf(next, 'pending') }));
        created = true;
      });
      if (created) notify();
      return created ? next : null;
    },

    /**
     * A record and a counter (or any other rows) in one write, so a receipt number is never
     * used without its record (and the reverse). `operations` are prepared WatermelonDB rows.
     */
    async prepareAdd(record) {
      const next = { ...record, local: { ...record.local, rev: 1, seq: nextSeq(), created_at: new Date().toISOString() } };
      return { record: next, operation: table().prepareCreateFromDirtyRaw({ id: record.id, ...columnsOf(next, 'pending') }) };
    },

    /**
     * Applies `change(record)` to the latest copy. By default it bumps `local.rev` and marks the
     * record pending; `settle` (an upload's answer) keeps the revision and decides the state from it.
     * Resolves the saved record, or null for an unknown id.
     */
    async update(id, change, { settle = null } = {}) {
      let saved = null;
      await database.write(async () => {
        const record = await findOne(id);
        if (!record) return;
        const current = parse(record._raw.data);
        const changed = change(current);
        if (!changed || changed === current) {
          saved = current;
          return;
        }
        let state = 'pending';
        let next = { ...changed, local: { ...changed.local, rev: current.local.rev } };
        if (settle) state = settle(next);
        else next = { ...next, local: { ...next.local, rev: (current.local.rev ?? 0) + 1 } };
        saved = next;
        await database.batch(record.prepareUpdate((row) => Object.entries(columnsOf(next, state)).forEach(([key, value]) => row._setRaw(key, value))));
      });
      if (saved) notify();
      return saved;
    },

    /** Records waiting to upload: runs, then visits, collections, counts, each in the order made. */
    async pending() {
      const rows = read(await table().query(Q.where('state', 'pending'), Q.sortBy('seq', Q.asc)).fetch());
      return rows.sort((a, b) => KIND_ORDER[a.kind] - KIND_ORDER[b.kind] || a.local.seq - b.local.seq);
    },

    pendingCount: () => table().query(Q.where('state', 'pending')).fetchCount(),

    async rejected() {
      return read(await table().query(Q.where('state', 'rejected'), Q.sortBy('seq', Q.asc)).fetch());
    },

    // -- What the device sold, for the stop screen and the count ----------------

    /** When `entity` was last pulled, on the server's clock (ms; 0 when never). */
    async pulledAt(entity) {
      const states = await database.get('sync_state').query(Q.where('id', Q.oneOf([entity, '__meta']))).fetch();
      const pulled = Number(states.find((row) => row.id === entity)?._raw?.pulled_at ?? 0);
      const skew = Number(parse(states.find((row) => row.id === '__meta')?._raw?.data)?.skewMs ?? 0);
      return pulled ? pulled + skew : 0;
    },

    /** Sales of this device the server may not have counted in a pulled balance yet: not acknowledged, or made after `since`. */
    async uncountedSales(since) {
      const unacknowledged = (await database.get('outbox').query(Q.where('kind', 'pos.sales'), Q.where('status', Q.notEq('acknowledged'))).fetch()).map((row) => row._raw.record_id);
      const found = await database.get('pos_sales').query(Q.or(Q.where('sold_at', Q.gte(since)), Q.where('id', Q.oneOf(unacknowledged)))).fetch();
      return found.map((record) => record.data).filter(Boolean);
    },

    /** Sales and refunds of this device from `since` (ms), for a run's figures. */
    async salesSince(since) {
      return (await database.get('pos_sales').query(Q.where('sold_at', Q.gte(since))).fetch()).map((record) => record.data).filter(Boolean);
    },
    async refundsSince(since) {
      const found = await database.get('pos_records').query(Q.where('kind', 'refund'), Q.where('created_at', Q.gte(since))).fetch();
      return found.map((record) => ({ ...record.data, recordKind: 'refund' }));
    },
  };

  return store;
}
