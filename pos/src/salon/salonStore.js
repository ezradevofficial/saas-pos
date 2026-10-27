import { Q } from '@nozbe/watermelondb';

/**
 * SAL-29, NFR-04: what the salon till keeps (schema v16).
 *
 * - Synced, server wins: `salon_settings`, `salon_staff` (staff and resources), `salon_services`,
 *   `salon_schedule`, `salon_queue`, `salon_packages` (read here).
 * - Made on the device, in `salon_records` (one row per record, `kind` appointment | event | queue | mark): saved
 *   in one WatermelonDB write that bumps `local.rev`, so nothing is lost if the app stops. `state` is `pending`
 *   until an upload of the same revision is stored, `rejected` when the server refused it for good (parked
 *   with its code, never deleted), `local` for a `mark` (a fact that is never uploaded).
 */
const parse = (text) => {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
};

const KIND_ORDER = { appointment: 0, event: 1, queue: 2, mark: 3 };

let lastSeq = 0;
/** Strictly increasing, so records upload in the order they were made. */
const nextSeq = () => {
  lastSeq = Math.max(lastSeq + 1, Date.now());
  return lastSeq;
};

const columnsOf = (record, state) => ({
  kind: record.kind,
  appointment_id: record.appointment_id ?? record.id ?? '',
  state,
  seq: record.local?.seq ?? nextSeq(),
  created_at: Date.parse(record.local?.created_at ?? '') || Date.now(),
  data: JSON.stringify(record),
});

export function createSalonStore(database) {
  const table = () => database.get('salon_records');
  const listeners = new Set();
  const notify = () => listeners.forEach((listener) => listener());
  const read = (records) => records.map((record) => parse(record._raw.data)).filter(Boolean);
  const rowsOf = async (name) => (await database.get(name).query().fetch()).map((record) => parse(record._raw.data)).filter(Boolean);

  async function findOne(id) {
    try {
      return await table().find(id);
    } catch {
      return null;
    }
  }

  const store = {
    /** Calls `listener` after every local write (screens reload). Returns the unsubscribe. */
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    changed: notify,

    // -- Synced data (server wins) -------------------------------------------

    /** The company's salon rules, or null: the module is on this till only when the row exists. */
    async settings() {
      return (await rowsOf('salon_settings'))[0] ?? null;
    },
    staffRows: () => rowsOf('salon_staff'),
    services: () => rowsOf('salon_services'),
    schedule: () => rowsOf('salon_schedule'),
    queue: () => rowsOf('salon_queue'),
    packages: () => rowsOf('salon_packages'),

    /** When `entity` was last pulled, on the server's clock (ms; 0 when never). */
    async pulledAt(entity) {
      const states = await database.get('sync_state').query(Q.where('id', Q.oneOf([entity, '__meta']))).fetch();
      const pulled = Number(states.find((row) => row.id === entity)?._raw?.pulled_at ?? 0);
      const skew = Number(parse(states.find((row) => row.id === '__meta')?._raw?.data)?.skewMs ?? 0);
      return pulled ? pulled + skew : 0;
    },

    // -- Records made on this device ------------------------------------------

    async get(id) {
      return parse((await findOne(id))?._raw.data);
    },

    /** Records of a kind (optionally of one appointment), oldest first. */
    async list(kind, { appointmentId = null } = {}) {
      const conditions = [Q.where('kind', kind), Q.sortBy('seq', Q.asc)];
      if (appointmentId) conditions.push(Q.where('appointment_id', appointmentId));
      return read(await table().query(...conditions).fetch());
    },

    /** Saves a new record (a second save with the same id is refused: null). `state` is pending unless it is a mark. */
    async add(record) {
      const next = { ...record, local: { ...record.local, rev: 1, seq: nextSeq(), created_at: new Date().toISOString() } };
      let created = false;
      await database.write(async () => {
        if (await findOne(record.id)) return;
        await database.batch(table().prepareCreateFromDirtyRaw({ id: record.id, ...columnsOf(next, record.kind === 'mark' ? 'local' : 'pending') }));
        created = true;
      });
      if (created) notify();
      return created ? next : null;
    },

    /**
     * Applies `change(record)` to the latest copy: bumps `local.rev` and marks it pending; `settle` (an upload's
     * answer) keeps the revision and decides the state from it. Resolves the saved record, or null for an unknown id.
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

    /** Records waiting to upload: appointments, then steps, then queue changes, each in the order made. */
    async pending() {
      const rows = read(await table().query(Q.where('state', 'pending'), Q.sortBy('seq', Q.asc)).fetch());
      return rows.sort((a, b) => KIND_ORDER[a.kind] - KIND_ORDER[b.kind] || a.local.seq - b.local.seq);
    },

    pendingCount: () => table().query(Q.where('state', 'pending')).fetchCount(),

    async rejected() {
      return read(await table().query(Q.where('state', 'rejected'), Q.sortBy('seq', Q.asc)).fetch());
    },

    /** Sales of this device the server may not have counted in a pulled entity yet: not acknowledged, or made after `since`. */
    async uncountedSales(since) {
      const unacknowledged = (await database.get('outbox').query(Q.where('kind', 'pos.sales'), Q.where('status', Q.notEq('acknowledged'))).fetch()).map((row) => row._raw.record_id);
      const found = await database.get('pos_sales').query(Q.or(Q.where('sold_at', Q.gte(since)), Q.where('id', Q.oneOf(unacknowledged)))).fetch();
      return found.map((record) => record.data).filter((sale) => sale && sale.local?.status !== 'voided');
    },
  };

  return store;
}
