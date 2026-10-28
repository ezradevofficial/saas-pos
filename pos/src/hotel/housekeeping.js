import { Q } from '@nozbe/watermelondb';
import { uuidv7 } from '../lib/random';
import { NetworkError } from '../sync/api';

/**
 * HOT-29: a housekeeper on a phone. They sign in as themselves (the same
 * user token the rider screen uses, never the till's), see their open tasks
 * and the unassigned ones of their property, and start, finish or mark a
 * room clean or dirty. The server returns room and status only, never guest
 * data, and nothing else is kept here.
 *
 * Offline (NFR-04): with a `database` the phone keeps a durable queue
 * (`hotel_queue`, schema v17). A change made while the server cannot be
 * reached is saved there with an `op_id` (a UUID made on the phone), shown
 * as pending on the list, and replayed in the order made when the phone is
 * back online. The server runs an `op_id` once, so a replay after a lost
 * response changes nothing twice. The server wins: when a room or task has
 * changed since (409), the change is not applied; it is parked as a conflict
 * until the housekeeper dismisses it. A room status change carries the
 * status the housekeeper saw (`expected_status`) so the server can tell.
 * Without a `database` every action is one request and fails offline.
 *
 * The last list stays in memory (not on disk) so the housekeeper can still
 * read it in a dead zone; a 401 signs them out.
 */
export const PERMISSION = 'hotel.housekeeping.update';

const parse = (text) => {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
};

let lastSeq = 0;
/** Strictly increasing, so changes replay in the order they were made. */
const nextSeq = () => {
  lastSeq = Math.max(lastSeq + 1, Date.now());
  return lastSeq;
};

/** The request one change makes. `withOp` is false for a phone without a queue (the body is then what it always was). */
function requestOf(op, withOp) {
  const extra = withOp ? { op_id: op.op_id } : {};
  if (op.kind === 'start' || op.kind === 'finish') return { path: `hotel/housekeeping/tasks/${encodeURIComponent(op.subject_id)}/${op.kind}`, body: extra };
  return {
    path: `hotel/rooms/${encodeURIComponent(op.subject_id)}/status`,
    body: { status: op.status, ...(withOp && op.expected_status ? { expected_status: op.expected_status } : {}), ...extra },
  };
}

/** The list as the housekeeper should see it: the server's tasks with their queued changes laid over, marked pending. */
export function overlay(tasks, pending) {
  let view = tasks.map((task) => ({ ...task, room: task.room ? { ...task.room } : task.room }));
  for (const op of pending) {
    view = view.map((task) => {
      if (op.kind === 'start' && task.id === op.subject_id) return { ...task, status: 'in_progress', pending: true };
      if (op.kind === 'finish' && task.id === op.subject_id) return { ...task, status: 'done', pending: true };
      if (op.kind === 'room_status' && task.room?.id === op.subject_id) return { ...task, room: { ...task.room, status: op.status }, pending: true };
      return task;
    });
  }
  return view;
}

export function createHousekeeping({ api, credentials, database = null, newId = () => uuidv7() }) {
  let last = [];
  const table = () => database.get('hotel_queue');

  async function call(run) {
    let response;
    try {
      response = await run();
    } catch (error) {
      if (error instanceof NetworkError) return { ok: false, error: 'offline' };
      throw error;
    }
    if (response.status === 401) {
      last = [];
      await credentials.clear();
      return { ok: false, error: 'signed_out' };
    }
    if (response.status === 403) return { ok: false, error: 'forbidden', status: 403 };
    if (response.status === 404) return { ok: false, error: 'unavailable', status: 404 };
    // The server wins: the room or task is not as the housekeeper last saw it.
    if (response.status === 409) return { ok: false, error: 'conflict', status: 409, code: response.body?.code ?? null };
    if (response.status >= 200 && response.status < 300) return { ok: true, body: response.body };
    return { ok: false, error: 'failed', status: response.status, code: response.body?.code ?? null };
  }

  const rowOf = (record) => ({ id: record.id, kind: record._raw.kind, subject_id: record._raw.subject_id, state: record._raw.state, seq: record._raw.seq, ...parse(record._raw.data) });
  const rowsIn = async (...states) => (await table().query(Q.where('state', Q.oneOf(states)), Q.sortBy('seq', Q.asc)).fetch()).map(rowOf);

  async function enqueue(op) {
    const seq = nextSeq();
    const { kind, subject_id: subjectId, ...rest } = op;
    await database.write(() =>
      database.batch(table().prepareCreateFromDirtyRaw({ id: op.op_id, kind, subject_id: subjectId, state: 'pending', seq, created_at: Date.now(), data: JSON.stringify(rest) })),
    );
  }

  async function settle(id, state, code = null) {
    await database.write(async () => {
      const record = await table().find(id);
      if (state === 'done') {
        await database.batch(record.prepareDestroyPermanently());
        return;
      }
      await database.batch(
        record.prepareUpdate((row) => {
          row._setRaw('state', state);
          row._setRaw('data', JSON.stringify({ ...parse(row._raw.data), code }));
        }),
      );
    });
  }

  /** Sends the queued changes in the order made. Stops when the server cannot be reached; parks what the server refuses. */
  async function flush() {
    if (!database) return { ok: true, sent: 0, parked: 0 };
    let sent = 0;
    let parked = 0;
    for (const row of await rowsIn('pending')) {
      const { path, body } = requestOf(row, true);
      const result = await call(() => api.post(path, body));
      if (result.ok) {
        await settle(row.id, 'done');
        sent += 1;
      } else if (result.error === 'offline' || result.error === 'signed_out' || (result.error === 'failed' && (result.status ?? 500) >= 500)) {
        return { ok: false, error: result.error, sent, parked };
      } else {
        // The server refused it for good (conflict, forbidden, gone, invalid): never retried.
        await settle(row.id, result.error === 'conflict' ? 'conflict' : 'failed', result.code ?? result.error);
        parked += 1;
      }
    }
    return { ok: true, sent, parked };
  }

  /** One change: sent now when the server can be reached, else queued; changes already waiting go first so the order holds. */
  async function act(op) {
    if (!database) {
      const { path, body } = requestOf(op, false);
      return call(() => api.post(path, body));
    }
    const change = { ...op, op_id: newId(), queued_at: new Date().toISOString() };
    if ((await rowsIn('pending')).length > 0) {
      const flushed = await flush();
      if (flushed.error === 'signed_out') return { ok: false, error: 'signed_out' };
      if ((await rowsIn('pending')).length > 0) {
        await enqueue(change);
        return { ok: true, queued: true };
      }
    }
    const { path, body } = requestOf(change, true);
    const result = await call(() => api.post(path, body));
    if (result.error === 'offline') {
      await enqueue(change);
      return { ok: true, queued: true };
    }
    return result;
  }

  async function view() {
    return database ? overlay(last, await rowsIn('pending')) : last;
  }

  return {
    /** Whether the signed-in user holds hotel.housekeeping.update (GET me/permissions); false when unknown. */
    async allowed() {
      const result = await call(() => api.get('me/permissions'));
      if (!result.ok) return false;
      return (result.body?.permissions ?? []).some((permission) => (typeof permission === 'string' ? permission : permission?.name) === PERMISSION);
    },

    cached: () => last,

    /** The housekeeper's open tasks (queued changes sent first); offline, the last list stays, with queued changes shown as pending. */
    async refresh() {
      if (database) {
        const flushed = await flush();
        if (flushed.error === 'signed_out') return { ok: false, error: 'signed_out', tasks: [] };
      }
      const result = await call(() => api.get('hotel/housekeeping/my-tasks'));
      if (!result.ok) return { ok: false, error: result.error, tasks: await view() };
      last = result.body?.data ?? [];
      return { ok: true, tasks: await view() };
    },

    flush,

    /** Changes waiting to go (`pending`) and ones the server refused (`parked`: conflict or failed), oldest first. */
    async queue() {
      if (!database) return { pending: [], parked: [] };
      return { pending: await rowsIn('pending'), parked: await rowsIn('conflict', 'failed') };
    },

    /** The housekeeper has seen a parked change. */
    async dismiss(id) {
      if (database) await settle(id, 'done');
    },

    start: (task) => act({ kind: 'start', subject_id: task.id, room_number: task.room?.number ?? null }),
    finish: (task) => act({ kind: 'finish', subject_id: task.id, room_number: task.room?.number ?? null }),
    /** status: 'vacant_clean' | 'vacant_dirty' (a housekeeper's two). */
    setRoomStatus: (task, status) =>
      act({ kind: 'room_status', subject_id: task.room.id, status, expected_status: task.room.status ?? null, room_number: task.room.number ?? null }),
  };
}

/** Tasks grouped by floor (rooms without one last), each group sorted by room number as people read it (101, 102, 1010). */
export function groupByFloor(tasks) {
  const groups = new Map();
  for (const task of tasks) {
    const floor = task.room?.floor == null || task.room.floor === '' ? '' : String(task.room.floor);
    groups.set(floor, [...(groups.get(floor) ?? []), task]);
  }
  const byNumber = (a, b) => String(a.room?.number ?? '').localeCompare(String(b.room?.number ?? ''), undefined, { numeric: true });
  return [...groups.entries()]
    .sort(([a], [b]) => (a === '' ? 1 : b === '' ? -1 : a.localeCompare(b, undefined, { numeric: true })))
    .map(([floor, list]) => ({ floor, tasks: [...list].sort(byNumber) }));
}
