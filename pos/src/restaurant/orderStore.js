import { Q } from '@nozbe/watermelondb';
import { differsFrom, fromServer, isOpen, mergeServer } from './orderModel';

/**
 * REST-03, REST-04, REST-17, NFR-04: the open orders and tabs kept on this
 * till (table `rest_orders`, schema v11), and reads of the Restaurant
 * module's synced tables.
 *
 * Every change is saved here first, in one WatermelonDB write that reads
 * the latest record, applies the change and bumps `local.rev`; nothing is
 * lost if the app stops. `dirty` stays true until an upload of the same
 * revision is acknowledged (orderSync.js).
 */
const parse = (text) => {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
};

const columnsOf = (order, dirty) => ({
  kind: order.kind,
  status: order.local?.closed ? 'closed' : order.status,
  table_id: order.table_id ?? null,
  waiter_id: order.waiter_id ?? '',
  dirty: Boolean(dirty),
  opened_at: Date.parse(order.opened_at) || Date.now(),
  data: JSON.stringify(order),
});

export function createOrderStore(database) {
  const table = () => database.get('rest_orders');
  const listeners = new Set();
  const notify = () => listeners.forEach((listener) => listener());

  async function findOne(id) {
    try {
      return await table().find(id);
    } catch {
      return null;
    }
  }

  const synced = async (name) => (await database.get(name).query().fetch()).map((record) => parse(record._raw.data)).filter(Boolean);

  const store = {
    /** Calls `listener` after every write (screens reload). Returns the unsubscribe. */
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },

    // -- Synced floor data -------------------------------------------------

    async tables() {
      return (await synced('restaurant_tables')).sort((a, b) => String(a.area_name ?? '').localeCompare(String(b.area_name ?? '')) || (a.y ?? 0) - (b.y ?? 0) || (a.x ?? 0) - (b.x ?? 0) || String(a.name).localeCompare(String(b.name), undefined, { numeric: true }));
    },
    stations: () => synced('restaurant_stations'),
    modifierGroups: () => synced('restaurant_modifiers'),
    settingsRows: () => synced('restaurant_settings'),
    serverOrders: () => synced('restaurant_orders'),

    // -- Orders on this till -----------------------------------------------

    async get(id) {
      return parse((await findOne(id))?._raw.data);
    },

    async all() {
      return (await table().query(Q.sortBy('opened_at', Q.asc)).fetch()).map((record) => parse(record._raw.data)).filter(Boolean);
    },

    /** Orders still open (not paid, merged, cancelled or closed elsewhere). */
    async open() {
      return (await store.all()).filter(isOpen);
    },

    /** Orders with a change waiting to upload, merges first (the server applies a merge before the target's lines), then oldest first. */
    async dirty() {
      const rows = (await table().query(Q.where('dirty', true), Q.sortBy('opened_at', Q.asc)).fetch()).map((record) => parse(record._raw.data)).filter(Boolean);
      return [...rows.filter((order) => order.merged_into_id), ...rows.filter((order) => !order.merged_into_id)];
    },

    async dirtyCount() {
      return table().query(Q.where('dirty', true)).fetchCount();
    },

    /** Saves a new order (or replaces one by id) as waiting to upload. */
    async put(order) {
      await database.write(async () => {
        const existing = await findOne(order.id);
        const next = { ...order, local: { ...order.local, rev: (order.local?.rev ?? 0) + 1 } };
        await database.batch(existing ? existing.prepareUpdate((record) => Object.entries(columnsOf(next, true)).forEach(([key, value]) => record._setRaw(key, value))) : table().prepareCreateFromDirtyRaw({ id: order.id, ...columnsOf(next, true) }));
      });
      notify();
      return order.id;
    },

    /**
     * Applies `change(order)` to the latest copy and saves it. `change`
     * returns the new order (or the same one for no change). By default the
     * change bumps `local.rev` and marks the order dirty; `settle` (an
     * upload's answer) keeps the revision and decides dirtiness from it.
     * Resolves the saved order, or null for an unknown id.
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
        let next = changed;
        let dirty = true;
        if (settle) {
          dirty = settle(next);
          next = { ...next, local: { ...next.local, rev: current.local.rev } };
        } else next = { ...next, local: { ...next.local, rev: (current.local.rev ?? 0) + 1 } };
        saved = next;
        await database.batch(record.prepareUpdate((row) => Object.entries(columnsOf(next, dirty)).forEach(([key, value]) => row._setRaw(key, value))));
      });
      if (saved) notify();
      return saved;
    },

    /** Updates two orders in one write (a merge moves lines between them). */
    async updateBoth(idA, changeA, idB, changeB) {
      await database.write(async () => {
        const [a, b] = await Promise.all([findOne(idA), findOne(idB)]);
        if (!a || !b) return;
        const [currentA, currentB] = [parse(a._raw.data), parse(b._raw.data)];
        const [nextA, nextB] = [changeA(currentA), changeB(currentB)];
        const bump = (order, current) => ({ ...order, local: { ...order.local, rev: (current.local.rev ?? 0) + 1 } });
        const [savedA, savedB] = [bump(nextA, currentA), bump(nextB, currentB)];
        await database.batch(
          a.prepareUpdate((row) => Object.entries(columnsOf(savedA, true)).forEach(([key, value]) => row._setRaw(key, value))),
          b.prepareUpdate((row) => Object.entries(columnsOf(savedB, true)).forEach(([key, value]) => row._setRaw(key, value))),
        );
      });
      notify();
    },

    /**
     * REST-17: folds the server's open orders (the pulled `restaurant_orders`)
     * into the till's, in one write: a server order the till lacks is added;
     * one it has is merged (lines unioned, later header wins) and uploaded
     * only if the till holds more than the server; a clean order the server
     * once had and now lacks was closed elsewhere. Resolves a count.
     */
    async reconcile(serverOrders) {
      let changed = 0;
      await database.write(async () => {
        const byId = new Map(serverOrders.map((order) => [String(order.id), order]));
        const records = await table().query().fetch();
        const known = new Map(records.map((record) => [record.id, record]));
        const operations = [];
        for (const server of serverOrders) {
          const record = known.get(String(server.id));
          if (!record) {
            const order = fromServer(server);
            operations.push(table().prepareCreateFromDirtyRaw({ id: order.id, ...columnsOf(order, false) }));
            changed += 1;
            continue;
          }
          const current = parse(record._raw.data);
          const merged = mergeServer(current, server);
          const dirty = Boolean(record._raw.dirty) || differsFrom(merged, server);
          if (JSON.stringify(merged) === JSON.stringify(current) && dirty === Boolean(record._raw.dirty)) continue;
          // The merge is not a local change: the revision stays (an upload in flight keeps its meaning).
          operations.push(record.prepareUpdate((row) => Object.entries(columnsOf(merged, dirty)).forEach(([key, value]) => row._setRaw(key, value))));
          changed += 1;
        }
        for (const record of records) {
          if (byId.has(record.id) || record._raw.dirty) continue;
          const current = parse(record._raw.data);
          if (!current || !isOpen(current) || !current.local.seen_remote) continue;
          const closed = { ...current, local: { ...current.local, closed: 'remote' } };
          operations.push(record.prepareUpdate((row) => Object.entries(columnsOf(closed, false)).forEach(([key, value]) => row._setRaw(key, value))));
          changed += 1;
        }
        if (operations.length) await database.batch(...operations);
      });
      if (changed) notify();
      return changed;
    },
  };

  return store;
}
