import { fireEvent, render, screen, waitFor, within } from '@testing-library/react-native';
import { memoryBackend, createRiderCredentials } from '../device/credentials';
import { ServicesContext } from '../services/services';
import { NetworkError } from '../sync/api';
import { testDatabase } from '../test/testDatabase';
import { HousekeepingScreen } from './HousekeepingScreen';
import { createHousekeeping } from './housekeeping';

// HOT-29, NFR-04: the housekeeper's durable offline queue (schema v17, hotel_queue): queueing,
// replay with idempotent ids, and the server winning when a room changed meanwhile.

const task = (n, number, floor, status, roomStatus, extra = {}) => ({
  id: `t-${n}`,
  branch_id: 'b-1',
  room: { id: `r-${n}`, number, floor, status: roomStatus },
  kind: 'checkout_clean',
  status,
  business_date: '2026-10-27',
  assigned_to: null,
  notes: null,
  ...extra,
});

const TASKS = [
  task(1, '204', 2, 'pending', 'vacant_dirty'),
  task(2, '101', 1, 'in_progress', 'vacant_dirty', { kind: 'stayover' }),
  task(3, '102', 1, 'pending', 'vacant_clean', { kind: 'inspection' }),
];

function fakeApi(tasks) {
  const api = {
    offline: false,
    reply: null,
    posts: [],
    async get(path) {
      if (api.offline) throw new NetworkError(new Error('offline'));
      if (path === 'hotel/housekeeping/my-tasks') return { status: 200, body: { data: tasks } };
      return { status: 404, body: null };
    },
    async post(path, body) {
      if (api.offline) throw new NetworkError(new Error('offline'));
      api.posts.push({ path, body });
      if (api.reply) return api.reply(path, body);
      return { status: 200, body: { data: {} } };
    },
  };
  return api;
}

const credentials = { clear: jest.fn() };
const make = (api, database = testDatabase()) => ({ database, housekeeping: createHousekeeping({ api, credentials, database }) });

async function renderScreen(housekeeping) {
  const riderCredentials = createRiderCredentials(memoryBackend());
  await riderCredentials.save({ token: 'user-token', user: { id: 'u-1', name: 'Juma Housekeeper' } });
  const rider = { user: () => riderCredentials.user(), signOut: jest.fn() };
  await render(
    <ServicesContext.Provider value={{ housekeeping, rider }}>
      <HousekeepingScreen onSignedOut={jest.fn()} />
    </ServicesContext.Provider>,
  );
}

describe('the housekeeper’s offline queue (HOT-29, NFR-04)', () => {
  it('queues a change made offline, shows it as pending and keeps it across a restart', async () => {
    const api = fakeApi(TASKS);
    const { database, housekeeping } = make(api);
    await housekeeping.refresh();
    api.offline = true;

    await expect(housekeeping.start(TASKS[0])).resolves.toEqual({ ok: true, queued: true });
    expect(api.posts).toEqual([]);

    const offline = await housekeeping.refresh();
    expect(offline.error).toBe('offline');
    expect(offline.tasks.find((entry) => entry.id === 't-1')).toMatchObject({ status: 'in_progress', pending: true });

    // A new service over the same database (the app restarted) still has it.
    const restarted = createHousekeeping({ api, credentials, database });
    const queue = await restarted.queue();
    expect(queue.pending).toHaveLength(1);
    expect(queue.pending[0]).toMatchObject({ kind: 'start', subject_id: 't-1', state: 'pending' });
    expect(queue.pending[0].op_id).toEqual(expect.any(String));
  });

  it('replays the queue in order on reconnect with the same ids, once', async () => {
    const api = fakeApi(TASKS);
    const { housekeeping } = make(api);
    await housekeeping.refresh();
    api.offline = true;
    await housekeeping.start(TASKS[0]);
    await housekeeping.setRoomStatus(TASKS[2], 'vacant_dirty');
    const queued = (await housekeeping.queue()).pending;
    expect(queued.map((entry) => entry.kind)).toEqual(['start', 'room_status']);

    api.offline = false;
    await housekeeping.refresh();
    expect(api.posts).toEqual([
      { path: 'hotel/housekeeping/tasks/t-1/start', body: { op_id: queued[0].op_id } },
      { path: 'hotel/rooms/r-3/status', body: { status: 'vacant_dirty', expected_status: 'vacant_clean', op_id: queued[1].op_id } },
    ]);
    expect((await housekeeping.queue()).pending).toEqual([]);

    // Nothing is sent a second time.
    await housekeeping.refresh();
    expect(api.posts).toHaveLength(2);
  });

  it('sends the same op id again when the answer was lost', async () => {
    const api = fakeApi(TASKS);
    const { housekeeping } = make(api);
    await housekeeping.refresh();
    api.reply = () => {
      throw new NetworkError(new Error('connection lost after the request left'));
    };
    await housekeeping.finish(TASKS[1]);
    const first = (await housekeeping.queue()).pending[0];
    const sentFirst = api.posts[0].body.op_id;
    expect(sentFirst).toBe(first.op_id);

    api.reply = null;
    await housekeeping.flush();
    expect(api.posts[api.posts.length - 1]).toEqual({ path: 'hotel/housekeeping/tasks/t-2/finish', body: { op_id: first.op_id } });
    expect((await housekeeping.queue()).pending).toEqual([]);
  });

  it('keeps a change queued when the server fails, and parks it as a conflict when the server wins', async () => {
    const api = fakeApi(TASKS);
    const { housekeeping } = make(api);
    await housekeeping.refresh();
    api.offline = true;
    await housekeeping.setRoomStatus(TASKS[0], 'vacant_clean');
    api.offline = false;

    api.reply = async () => ({ status: 503, body: null });
    await housekeeping.flush();
    expect((await housekeeping.queue()).pending).toHaveLength(1);

    // The room has moved on since the housekeeper looked: not applied, flagged, never retried.
    api.reply = async () => ({ status: 409, body: { code: 'room_status_changed' } });
    await housekeeping.flush();
    const queue = await housekeeping.queue();
    expect(queue.pending).toEqual([]);
    expect(queue.parked).toHaveLength(1);
    expect(queue.parked[0]).toMatchObject({ state: 'conflict', code: 'room_status_changed', room_number: '204' });

    const posts = api.posts.length;
    await housekeeping.flush();
    expect(api.posts).toHaveLength(posts);

    await housekeeping.dismiss(queue.parked[0].id);
    expect((await housekeeping.queue()).parked).toEqual([]);
  });

  it('sends a new change after the waiting ones so the order holds', async () => {
    const api = fakeApi(TASKS);
    const { housekeeping } = make(api);
    await housekeeping.refresh();
    api.offline = true;
    await housekeeping.start(TASKS[0]);
    await expect(housekeeping.finish(TASKS[1])).resolves.toEqual({ ok: true, queued: true });
    api.offline = false;
    await housekeeping.setRoomStatus(TASKS[2], 'vacant_dirty');
    expect(api.posts.map((post) => post.path)).toEqual(['hotel/housekeeping/tasks/t-1/start', 'hotel/housekeeping/tasks/t-2/finish', 'hotel/rooms/r-3/status']);
  });

  it('shows a change made offline as waiting to sync, then sends it when back online', async () => {
    const api = fakeApi(TASKS);
    const { housekeeping } = make(api);
    await renderScreen(housekeeping);
    await screen.findByTestId('task-t-1');
    api.offline = true;
    await fireEvent.press(within(screen.getByTestId('task-t-1')).getByRole('button', { name: 'Start' }));
    expect(await screen.findByText(/Saved on this phone/)).toBeTruthy();
    expect(within(screen.getByTestId('task-t-1')).getByText('Waiting to sync')).toBeTruthy();
    expect(api.posts).toEqual([]);

    api.offline = false;
    await fireEvent.press(screen.getByRole('button', { name: 'Refresh' }));
    await waitFor(() => expect(api.posts).toHaveLength(1));
    await waitFor(() => expect(within(screen.getByTestId('task-t-1')).queryByText('Waiting to sync')).toBeNull());
  });

  it('tells the housekeeper when the server won, until they dismiss it', async () => {
    const api = fakeApi(TASKS);
    const { housekeeping } = make(api);
    await renderScreen(housekeeping);
    await screen.findByTestId('task-t-1');
    api.offline = true;
    await fireEvent.press(within(screen.getByTestId('task-t-1')).getByRole('button', { name: 'Mark clean' }));
    api.offline = false;
    api.reply = async () => ({ status: 409, body: { code: 'room_status_changed' } });
    await fireEvent.press(screen.getByRole('button', { name: 'Refresh' }));

    expect(await screen.findByText(/Room 204 changed while you were offline/)).toBeTruthy();
    await fireEvent.press(screen.getByRole('button', { name: 'Dismiss' }));
    await waitFor(() => expect(screen.queryByText(/Room 204 changed while you were offline/)).toBeNull());
  });
});
