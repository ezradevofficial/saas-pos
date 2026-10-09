import { Q } from '@nozbe/watermelondb';
import { entityDefinition, registeredEntities } from './entities';

/**
 * NFR-04: everything the sync engine reads and writes, over WatermelonDB.
 * Each pulled page is applied with its cursor in one write, so a crash
 * never leaves data ahead of or behind its cursor. Outbox rows are written
 * before anything is sent and only change state from a server answer, so
 * nothing waiting to upload is ever lost.
 */
export const OUTBOX = { PENDING: 'pending', FAILED: 'failed', ACKNOWLEDGED: 'acknowledged' };

const META_ID = '__meta';
const DEVICE_ID = 'this';
const CHUNK = 500;

const json = (value) => (value === undefined ? null : JSON.stringify(value));
const parse = (text) => {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
};

function chunks(list, size = CHUNK) {
  const out = [];
  for (let i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
  return out;
}

export function createSyncStore(database) {
  const table = (name) => database.get(name);

  async function findByIds(tableName, ids) {
    const found = [];
    for (const part of chunks([...new Set(ids)])) {
      found.push(...(await table(tableName).query(Q.where('id', Q.oneOf(part))).fetch()));
    }
    return found;
  }

  async function findOne(tableName, id) {
    try {
      return await table(tableName).find(id);
    } catch {
      return null;
    }
  }

  function prepareUpsert(tableName, existing, id, columns) {
    if (existing) {
      return existing.prepareUpdate((record) => {
        for (const [key, value] of Object.entries(columns)) record._setRaw(key, value);
      });
    }
    return table(tableName).prepareCreateFromDirtyRaw({ id, ...columns });
  }

  async function prepareMeta(patch) {
    const record = await findOne('sync_state', META_ID);
    const data = { ...(parse(record?._raw.data) ?? {}), ...patch };
    return prepareUpsert('sync_state', record, META_ID, { data: json(data) });
  }

  /** Rebuild the child rows (an item's barcodes) of rows written, and remove those of rows leaving. */
  async function prepareChildren(definition, upserts, leavingIds) {
    const operations = [];
    const parents = [...upserts.map((row) => String(row.id)), ...leavingIds];
    for (const child of definition.children) {
      const wanted = new Map();
      for (const row of upserts) {
        for (const { id, ...columns } of child.rows(row)) wanted.set(id, { ...columns, [child.parentColumn]: String(row.id) });
      }
      const current = [];
      for (const part of chunks(parents)) {
        current.push(...(await table(child.table).query(Q.where(child.parentColumn, Q.oneOf(part))).fetch()));
      }
      const currentById = new Map(current.map((record) => [record.id, record]));
      for (const record of current) {
        if (!wanted.has(record.id)) operations.push(record.prepareDestroyPermanently());
      }
      for (const [id, columns] of wanted) operations.push(prepareUpsert(child.table, currentById.get(id), id, columns));
    }
    return operations;
  }

  /**
   * Remove an entity's rows last written before page `before` (and their
   * children), CHUNK rows per write so a big table never sits in memory,
   * then clear the entity's pending reset. Safe to repeat after a crash.
   */
  async function sweep(key, definition, before) {
    let removed = 0;
    for (;;) {
      const batch = await table(definition.table).query(Q.where('seen_at', Q.lt(before)), Q.take(CHUNK)).fetch();
      if (!batch.length) break;
      const children = [];
      for (const child of definition.children) {
        children.push(...(await table(child.table).query(Q.where(child.parentColumn, Q.oneOf(batch.map((record) => record.id)))).fetch()));
      }
      await database.write(() => database.batch(...[...batch, ...children].map((record) => record.prepareDestroyPermanently())));
      removed += batch.length;
    }
    await database.write(async () => {
      const state = await findOne('sync_state', key);
      const data = parse(state?._raw.data);
      if (state && data?.resetSince != null) {
        await database.batch(state.prepareUpdate((row) => row._setRaw('data', json({ ...data, resetSince: null }))));
      }
    });
    return removed;
  }

  const store = {
    database,

    // -- Pulled data -------------------------------------------------------

    async cursors(keys) {
      const rows = await findByIds('sync_state', keys);
      return Object.fromEntries(rows.filter((row) => row._raw.cursor).map((row) => [row.id, row._raw.cursor]));
    },

    async entityStates() {
      const rows = await table('sync_state').query().fetch();
      return Object.fromEntries(
        rows.filter((row) => row.id !== META_ID).map((row) => [row.id, { cursor: row._raw.cursor, pulledAt: row._raw.pulled_at, ...(parse(row._raw.data) ?? {}) }]),
      );
    },

    /**
     * Apply one entity's page from GET sync/pull and move its cursor, in one
     * write. Rows are stamped with the page's number (`seen_at`, a counter
     * per entity, never a clock). reset (the server starts the entity again)
     * and replace (a snapshot) keep the old rows until the last page of the
     * run (has_more false) has arrived; then rows the server did not send
     * again are removed (sweep).
     */
    async applyPage(key, page, pulledAt) {
      const definition = entityDefinition(key);
      if (!definition) throw new Error(`Unregistered sync entity [${key}]`);
      const upserts = Array.isArray(page.upserts) ? page.upserts : [];
      const tombstones = Array.isArray(page.tombstones) ? page.tombstones.map(String) : [];
      const upsertIds = upserts.map((row) => String(row.id));
      const keep = new Set(upsertIds);

      const state = await findOne('sync_state', key);
      const previous = parse(state?._raw.data) ?? {};
      const pageSeq = (previous.pageSeq ?? 0) + 1;
      const resetSince = page.reset || page.replace ? pageSeq : (previous.resetSince ?? null);

      const leaving = (await findByIds(definition.table, tombstones)).filter((record) => !keep.has(record.id));
      const existing = new Map((await findByIds(definition.table, upsertIds)).map((record) => [record.id, record]));

      const operations = leaving.map((record) => record.prepareDestroyPermanently());
      for (const row of upserts) {
        const id = String(row.id);
        operations.push(prepareUpsert(definition.table, existing.get(id), id, { ...definition.columns(row), data: JSON.stringify(row), seen_at: pageSeq }));
      }
      operations.push(...(await prepareChildren(definition, upserts, leaving.map((record) => record.id))));
      operations.push(prepareUpsert('sync_state', state, key, { cursor: page.cursor ?? null, pulled_at: pulledAt, data: json({ pageSeq, resetSince }) }));

      await database.write(() => database.batch(...operations));

      const swept = !page.has_more && resetSince !== null ? await sweep(key, definition, resetSince) : 0;
      return { upserts: upserts.length, tombstones: tombstones.length, swept };
    },

    /** Forget an entity's cursor and rows (an entity the server no longer offers, or a refused cursor). */
    async dropEntity(key) {
      const definition = entityDefinition(key);
      if (definition) await sweep(key, definition, Number.MAX_SAFE_INTEGER);
      await database.write(async () => {
        const state = await findOne('sync_state', key);
        if (state) await database.batch(state.prepareDestroyPermanently());
      });
    },

    /** Drop every synced entity and the engine metadata; the outbox and PIN attempts stay. */
    async resetSyncedData() {
      for (const definition of registeredEntities()) await store.dropEntity(definition.key);
      for (;;) {
        const rows = await table('sync_state').query(Q.take(CHUNK)).fetch();
        if (!rows.length) break;
        await database.write(() => database.batch(...rows.map((row) => row.prepareDestroyPermanently())));
      }
    },

    // -- Engine metadata (clock skew, bootstrap, last sync times) -----------

    async meta() {
      return parse((await findOne('sync_state', META_ID))?._raw.data) ?? {};
    },

    /** Merge `patch` into the engine metadata, read and written in one write (no lost update). */
    async setMeta(patch) {
      await database.write(async () => database.batch(await prepareMeta(patch)));
    },

    // -- Outbox ------------------------------------------------------------

    /**
     * Persist a record to upload. Resolves once it is in the database:
     * call before showing the sale as done, never send before this.
     * `group` (a shift id) keeps a group's rows in order: a later row is not
     * sent while an earlier one of the group waits. The sequence number is
     * taken inside the write, so concurrent calls keep their order.
     * `prepare` (async, inside the write) returns prepared operations that
     * commit atomically with the outbox row (the local sale and its receipt
     * counter): a crash never leaves a sale without its upload or the
     * reverse.
     */
    async enqueue(kind, recordId, payload, now = Date.now(), { group = null, prepare = null } = {}) {
      let created;
      await database.write(async () => {
        // The record's own local rows (a sale, its receipt counter) commit with its outbox row, or not at all.
        const extra = prepare ? await prepare() : [];
        const last = await table('outbox').query(Q.sortBy('seq', Q.desc), Q.take(1)).fetch();
        const seq = (last[0]?._raw.seq ?? 0) + 1;
        created = await table('outbox').create((row) => {
          row._setRaw('seq', seq);
          row._setRaw('kind', kind);
          row._setRaw('record_id', String(recordId));
          row._setRaw('group_key', group == null ? null : String(group));
          row._setRaw('payload', JSON.stringify(payload));
          row._setRaw('status', OUTBOX.PENDING);
          row._setRaw('attempts', 0);
          row._setRaw('next_attempt_at', 0);
          row._setRaw('created_at', now);
          row._setRaw('updated_at', now);
        });
        if (extra.length) await database.batch(...extra);
      });
      return toEntry(created);
    },

    /** Pending rows in queue order, without their payloads (light, for scheduling). */
    async pendingQueue(limit = 5000) {
      const rows = await table('outbox').query(Q.where('status', OUTBOX.PENDING), Q.sortBy('seq', Q.asc), Q.take(limit)).fetch();
      return rows.map((record) => ({
        id: record.id,
        seq: record._raw.seq,
        kind: record._raw.kind,
        recordId: record._raw.record_id,
        group: record._raw.group_key ?? null,
        attempts: record._raw.attempts,
        nextAttemptAt: record._raw.next_attempt_at,
      }));
    },

    /** The latest outbox entry of a record (its upload state and the server's answer), or null. */
    async entryFor(kind, recordId) {
      const rows = await table('outbox').query(Q.where('kind', kind), Q.where('record_id', String(recordId)), Q.sortBy('seq', Q.desc), Q.take(1)).fetch();
      return rows[0] ? toEntry(rows[0]) : null;
    },

    async entriesByIds(ids) {
      const byId = new Map((await findByIds('outbox', ids)).map((record) => [record.id, toEntry(record)]));
      return ids.map((id) => byId.get(id)).filter(Boolean);
    },

    async entries(status) {
      const rows = await table('outbox').query(...(status ? [Q.where('status', status)] : []), Q.sortBy('seq', Q.asc)).fetch();
      return rows.map(toEntry);
    },

    async counts() {
      const [pending, failed] = await Promise.all([
        table('outbox').query(Q.where('status', OUTBOX.PENDING)).fetchCount(),
        table('outbox').query(Q.where('status', OUTBOX.FAILED)).fetchCount(),
      ]);
      return { pending, failed };
    },

    /** Earliest time a pending row may be sent again, or null. */
    async nextAttemptAt() {
      const rows = await table('outbox').query(Q.where('status', OUTBOX.PENDING), Q.sortBy('next_attempt_at', Q.asc), Q.take(1)).fetch();
      return rows[0]?._raw.next_attempt_at ?? null;
    },

    /** Apply per-row outcomes: [{id, status, result?, error?, nextAttemptAt?, countAttempt?}]. */
    async settle(outcomes, now = Date.now()) {
      await database.write(async () => {
        const records = new Map((await findByIds('outbox', outcomes.map((outcome) => outcome.id))).map((record) => [record.id, record]));
        const operations = [];
        for (const outcome of outcomes) {
          const record = records.get(outcome.id);
          if (!record || record._raw.status === OUTBOX.ACKNOWLEDGED) continue;
          operations.push(
            record.prepareUpdate((row) => {
              row._setRaw('status', outcome.status);
              if (outcome.countAttempt !== false) row._setRaw('attempts', (row._raw.attempts ?? 0) + 1);
              if (outcome.nextAttemptAt != null) row._setRaw('next_attempt_at', outcome.nextAttemptAt);
              if (outcome.result !== undefined) row._setRaw('result', json(outcome.result));
              if (outcome.error !== undefined) row._setRaw('last_error', json(outcome.error));
              row._setRaw('updated_at', now);
            }),
          );
        }
        if (operations.length) await database.batch(...operations);
      });
    },

    /** Put a failed row back in the queue (after someone fixed the cause). */
    async requeue(id, now = Date.now()) {
      await store.settle([{ id, status: OUTBOX.PENDING, nextAttemptAt: 0, countAttempt: false }], now);
    },

    /** Delete acknowledged rows settled before `before` (the server holds them), a chunk at a time. */
    async pruneAcknowledged(before) {
      let removed = 0;
      for (;;) {
        const batch = await table('outbox').query(Q.where('status', OUTBOX.ACKNOWLEDGED), Q.where('updated_at', Q.lt(before)), Q.take(CHUNK)).fetch();
        if (!batch.length) return removed;
        await database.write(() => database.batch(...batch.map((record) => record.prepareDestroyPermanently())));
        removed += batch.length;
      }
    },

    // -- This device -------------------------------------------------------

    async device() {
      const record = await findOne('device', DEVICE_ID);
      return record
        ? {
            id: record._raw.device_id,
            name: record._raw.name,
            locationId: record._raw.location_id,
            status: record._raw.status,
            pairedAt: record._raw.paired_at,
          }
        : null;
    },

    async saveDevice(device) {
      await database.write(async () => {
        const record = await findOne('device', DEVICE_ID);
        await database.batch(
          prepareUpsert('device', record, DEVICE_ID, {
            device_id: device.id,
            name: device.name ?? '',
            location_id: device.location_id ?? device.locationId ?? '',
            status: device.status ?? 'active',
            paired_at: device.paired_at ?? device.pairedAt ?? null,
          }),
        );
      });
    },

    // -- Offline PIN attempts (AUTH-06) ------------------------------------

    async pinAttempt(userId) {
      const record = await findOne('local_pin_attempts', userId);
      return record
        ? {
            userId,
            failedAttempts: record._raw.failed_attempts,
            reportFailed: record._raw.report_failed ?? 0,
            locked: record._raw.locked,
            occurredAt: record._raw.occurred_at,
            pinVersion: record._raw.pin_version,
            reported: record._raw.reported,
          }
        : null;
    },

    /**
     * failedAttempts: the count on this till now. reportFailed: what the
     * server has still to hear (kept when a right PIN resets the count).
     */
    async savePinAttempt({ userId, failedAttempts, reportFailed, locked, occurredAt, pinVersion, reported }) {
      await database.write(async () => {
        const record = await findOne('local_pin_attempts', userId);
        await database.batch(
          prepareUpsert('local_pin_attempts', record, userId, {
            failed_attempts: failedAttempts,
            report_failed: reportFailed ?? failedAttempts,
            locked: Boolean(locked),
            occurred_at: occurredAt ?? null,
            pin_version: pinVersion ?? 0,
            reported: Boolean(reported),
          }),
        );
      });
    },

    async unreportedPinAttempts() {
      const rows = await table('local_pin_attempts').query(Q.where('reported', false)).fetch();
      return rows.map((row) => ({
        userId: row.id,
        failedAttempts: row._raw.report_failed ?? row._raw.failed_attempts,
        locked: row._raw.locked,
        occurredAt: row._raw.occurred_at,
      }));
    },

    /** Mark reports sent (or dropped by the server), unless the row changed since (a newer attempt). */
    async markPinAttemptsReported(reports) {
      await database.write(async () => {
        const records = await findByIds('local_pin_attempts', reports.map((report) => report.userId));
        const sent = new Map(reports.map((report) => [report.userId, report]));
        const operations = records
          .filter((record) => {
            const report = sent.get(record.id);
            return report && (record._raw.report_failed ?? record._raw.failed_attempts) === report.failedAttempts && record._raw.locked === report.locked;
          })
          .map((record) => record.prepareUpdate((row) => row._setRaw('reported', true)));
        if (operations.length) await database.batch(...operations);
      });
    },

    // -- Reads for screens -------------------------------------------------

    async staff() {
      const rows = await table('staff').query(Q.sortBy('name', Q.asc)).fetch();
      return rows.map((row) => row.data).filter(Boolean);
    },

    async staffMember(userId) {
      return (await findOne('staff', userId))?.data ?? null;
    },

    async settings() {
      return (await findOne('settings', 'device'))?.data ?? null;
    },

    /** Remove everything (pairing as another device, once nothing waits to upload). */
    async reset() {
      await database.write(() => database.unsafeResetDatabase());
    },
  };

  return store;
}

function toEntry(record) {
  const raw = record._raw;
  return {
    id: record.id,
    seq: raw.seq,
    kind: raw.kind,
    recordId: raw.record_id,
    group: raw.group_key ?? null,
    payload: parse(raw.payload),
    status: raw.status,
    attempts: raw.attempts,
    nextAttemptAt: raw.next_attempt_at,
    lastError: parse(raw.last_error),
    result: parse(raw.result),
    createdAt: raw.created_at,
    updatedAt: raw.updated_at,
  };
}
