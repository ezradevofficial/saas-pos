import { NetworkError } from '../sync/api';

/**
 * HOT-29: a housekeeper on a phone. They sign in as themselves (the same
 * user token the rider screen uses, never the till's), see their open tasks
 * and the unassigned ones of their property, and start, finish or mark a
 * room clean or dirty. The server returns room and status only, never guest
 * data, and nothing else is kept here.
 *
 * Online only: each action is one request and the screen says plainly when
 * the phone is offline. The last list stays in memory (not on disk) so the
 * housekeeper can still read it in a dead zone; a 401 signs them out.
 */
export const PERMISSION = 'hotel.housekeeping.update';

export function createHousekeeping({ api, credentials }) {
  let last = [];

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
    if (response.status === 403) return { ok: false, error: 'forbidden' };
    if (response.status === 404) return { ok: false, error: 'unavailable' };
    if (response.status >= 200 && response.status < 300) return { ok: true, body: response.body };
    return { ok: false, error: 'failed', code: response.body?.code ?? null };
  }

  return {
    /** Whether the signed-in user holds hotel.housekeeping.update (GET me/permissions); false when unknown. */
    async allowed() {
      const result = await call(() => api.get('me/permissions'));
      if (!result.ok) return false;
      return (result.body?.permissions ?? []).some((permission) => (typeof permission === 'string' ? permission : permission?.name) === PERMISSION);
    },

    cached: () => last,

    /** The housekeeper's open tasks; offline, the last list stays. */
    async refresh() {
      const result = await call(() => api.get('hotel/housekeeping/my-tasks'));
      if (!result.ok) return { ok: false, error: result.error, tasks: last };
      last = result.body?.data ?? [];
      return { ok: true, tasks: last };
    },

    start: (taskId) => call(() => api.post(`hotel/housekeeping/tasks/${encodeURIComponent(taskId)}/start`, {})),
    finish: (taskId) => call(() => api.post(`hotel/housekeeping/tasks/${encodeURIComponent(taskId)}/finish`, {})),
    /** status: 'vacant_clean' | 'vacant_dirty' (a housekeeper's two). */
    setRoomStatus: (roomId, status) => call(() => api.post(`hotel/rooms/${encodeURIComponent(roomId)}/status`, { status })),
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
