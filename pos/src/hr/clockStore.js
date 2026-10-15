import { Q } from '@nozbe/watermelondb';
import { OUTBOX } from '../sync/store';

/**
 * HR-22, HR-23, NFR-04: the till's clock-in data over WatermelonDB.
 *
 * - `hr_clock_staff`: synced (entity `hr_clock_staff`, a snapshot); read only here.
 * - `hr_punches`: clock-ins and clock-outs made on this till. A punch is
 *   written in the same transaction as its outbox row (engine.enqueue with
 *   `prepare`), never changed afterwards; its upload state is its outbox
 *   row's (pending, failed = rejected by the server, acknowledged or pruned
 *   = stored). A rejected punch is never sent again (the engine never
 *   resends a failed row) and never decides the next kind.
 * - `hr_clock_attempts`: wrong clock-in PINs per employee on this till;
 *   each failure or lockout also queues a report (`hr.clock_attempts`).
 */
export const PUNCH_KIND = 'hr.punches';
export const ATTEMPT_KIND = 'hr.clock_attempts';

const CHUNK = 500;

function chunks(list, size = CHUNK) {
  const out = [];
  for (let i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
  return out;
}

const toPunch = (record) => ({ id: record.id, employeeId: record._raw.employee_id, kind: record._raw.kind, occurredAt: record._raw.occurred_at });

export function createClockStore(database) {
  const table = (name) => database.get(name);

  async function findOne(tableName, id) {
    try {
      return await table(tableName).find(id);
    } catch {
      return null;
    }
  }

  async function outboxEntries(ids) {
    const found = [];
    for (const part of chunks([...new Set(ids)])) {
      found.push(...(await table('outbox').query(Q.where('kind', PUNCH_KIND), Q.where('record_id', Q.oneOf(part))).fetch()));
    }
    return found;
  }

  const store = {
    /** The clock-in staff, by name (the server's rows: id, number, name, version, pin). */
    async clockStaff() {
      const rows = await table('hr_clock_staff').query(Q.sortBy('name', Q.asc)).fetch();
      return rows.map((row) => row.data).filter(Boolean);
    },

    async clockStaffMember(employeeId) {
      return (await findOne('hr_clock_staff', employeeId))?.data ?? null;
    },

    // -- Wrong PINs ------------------------------------------------------------

    async attempt(employeeId) {
      const record = await findOne('hr_clock_attempts', employeeId);
      return record ? { employeeId, failedAttempts: record._raw.failed_attempts, locked: Boolean(record._raw.locked), pinStamp: record._raw.pin_stamp } : null;
    },

    /** The count's write, prepared (inside a write: engine.enqueue's `prepare`, or saveAttempt). */
    async prepareAttempt({ employeeId, failedAttempts, locked, pinStamp }) {
      const record = await findOne('hr_clock_attempts', employeeId);
      const columns = { failed_attempts: failedAttempts, locked: Boolean(locked), pin_stamp: pinStamp };
      return record
        ? record.prepareUpdate((row) => Object.entries(columns).forEach(([key, value]) => row._setRaw(key, value)))
        : table('hr_clock_attempts').prepareCreateFromDirtyRaw({ id: employeeId, ...columns });
    },

    async saveAttempt(attempt) {
      await database.write(async () => database.batch(await store.prepareAttempt(attempt)));
    },

    // -- Punches ---------------------------------------------------------------

    /** A punch to create inside engine.enqueue's write (with its outbox row). */
    preparePunch({ id, employeeId, kind, occurredAt }) {
      return table('hr_punches').prepareCreateFromDirtyRaw({ id, employee_id: employeeId, kind, occurred_at: occurredAt });
    },

    /** The employee's latest punch at or after `since` (ms) that the server has not rejected, or null. */
    async lastPunchSince(employeeId, since) {
      const rows = await table('hr_punches').query(Q.where('employee_id', employeeId), Q.where('occurred_at', Q.gte(since)), Q.sortBy('occurred_at', Q.desc)).fetch();
      const entries = new Map((await outboxEntries(rows.map((row) => row.id))).map((entry) => [entry._raw.record_id, entry]));
      const accepted = rows.find((row) => uploadState(entries.get(row.id)).upload !== 'rejected');
      return accepted ? toPunch(accepted) : null;
    },

    /** Punches at or after `since`, newest first, with their upload state. */
    async punchesSince(since) {
      const rows = await table('hr_punches').query(Q.where('occurred_at', Q.gte(since)), Q.sortBy('occurred_at', Q.desc)).fetch();
      const entries = new Map((await outboxEntries(rows.map((row) => row.id))).map((entry) => [entry._raw.record_id, entry]));
      return rows.map((row) => {
        const entry = entries.get(row.id);
        return { ...toPunch(row), ...uploadState(entry) };
      });
    },

    /** The punch with its upload state, or null. */
    async punch(id) {
      const record = await findOne('hr_punches', id);
      if (!record) return null;
      const [entry] = await outboxEntries([id]);
      return { ...toPunch(record), ...uploadState(entry) };
    },

    /**
     * Forget punches made before `before` (ms) that the server holds (their
     * outbox row acknowledged or already pruned). Pending and not accepted
     * ones stay.
     */
    async prunePunches(before) {
      const old = await table('hr_punches').query(Q.where('occurred_at', Q.lt(before))).fetch();
      if (!old.length) return 0;
      const entries = new Map((await outboxEntries(old.map((row) => row.id))).map((entry) => [entry._raw.record_id, entry]));
      const done = old.filter((row) => {
        const entry = entries.get(row.id);
        return !entry || entry._raw.status === OUTBOX.ACKNOWLEDGED;
      });
      for (const part of chunks(done)) await database.write(() => database.batch(...part.map((row) => row.prepareDestroyPermanently())));
      return done.length;
    },
  };

  return store;
}

/** pending | stored | rejected (with the server's code). */
function uploadState(entry) {
  if (!entry || entry._raw.status === OUTBOX.ACKNOWLEDGED) return { upload: 'stored', code: null };
  if (entry._raw.status === OUTBOX.FAILED) {
    let code = null;
    try {
      code = JSON.parse(entry._raw.last_error ?? 'null')?.code ?? null;
    } catch {
      code = null;
    }
    return { upload: 'rejected', code };
  }
  return { upload: 'pending', code: null };
}
