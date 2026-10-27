import { fireEvent, render, screen, waitFor, within } from '@testing-library/react-native';
import { memoryBackend, createRiderCredentials } from '../device/credentials';
import { ServicesContext } from '../services/services';
import { NetworkError } from '../sync/api';
import { HousekeepingScreen } from './HousekeepingScreen';
import { createHousekeeping, groupByFloor } from './housekeeping';

// HOT-29: a housekeeper's phone list. Room, status and task kind only; big buttons;
// the right endpoints; a plain message when offline.

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

function fakeApi(tasks) {
  const api = {
    offline: false,
    tasks,
    posts: [],
    async get(path) {
      if (api.offline) throw new NetworkError(new Error('offline'));
      if (path === 'hotel/housekeeping/my-tasks') return { status: 200, body: { data: api.tasks } };
      if (path === 'me/permissions') return { status: 200, body: { permissions: [{ name: 'hotel.housekeeping.update', scopes: [] }], modules: ['hotel'], owner: false } };
      return { status: 404, body: null };
    },
    async post(path, body) {
      if (api.offline) throw new NetworkError(new Error('offline'));
      api.posts.push({ path, body });
      return { status: 200, body: { data: {} } };
    },
  };
  return api;
}

async function setup(tasks) {
  const api = fakeApi(tasks);
  const riderCredentials = createRiderCredentials(memoryBackend());
  await riderCredentials.save({ token: 'user-token', user: { id: 'u-1', name: 'Juma Housekeeper' } });
  const housekeeping = createHousekeeping({ api, credentials: riderCredentials });
  const rider = { user: () => riderCredentials.user(), signOut: jest.fn(async () => riderCredentials.clear()) };
  const onSignedOut = jest.fn();
  await render(
    <ServicesContext.Provider value={{ housekeeping, rider }}>
      <HousekeepingScreen onSignedOut={onSignedOut} />
    </ServicesContext.Provider>,
  );
  return { api, housekeeping, rider, onSignedOut, riderCredentials };
}

const TASKS = [
  task(1, '204', 2, 'pending', 'vacant_dirty'),
  task(2, '101', 1, 'in_progress', 'vacant_dirty', { kind: 'stayover' }),
  task(3, '102', 1, 'pending', 'vacant_clean', { kind: 'inspection' }),
];

describe('the housekeeping screen (HOT-29)', () => {
  it('lists the tasks grouped by floor with room, room status and task kind', async () => {
    await setup(TASKS);
    expect(await screen.findByTestId('task-t-1')).toBeTruthy();
    const headers = screen.getAllByRole('header').map((node) => node.props.children);
    expect(headers).toEqual(expect.arrayContaining(['Floor 1', 'Floor 2']));
    expect(headers.indexOf('Floor 1')).toBeLessThan(headers.indexOf('Floor 2'));
    const card = within(screen.getByTestId('task-t-1'));
    expect(card.getByText('Room 204')).toBeTruthy();
    expect(card.getByText('Dirty')).toBeTruthy();
    expect(card.getByText('Check-out clean')).toBeTruthy();
    expect(within(screen.getByTestId('task-t-2')).getByText('Stay-over clean')).toBeTruthy();
  });

  it('shows no guest data: only what the server sent for room and task', async () => {
    await setup([task(1, '204', 2, 'pending', 'occupied', { guest_name: 'Should Never Show', notes: 'Extra towels' })]);
    await screen.findByTestId('task-t-1');
    expect(screen.queryByText('Should Never Show')).toBeNull();
    expect(screen.getByText('Extra towels')).toBeTruthy();
  });

  it('uses 48px touch targets for every action', async () => {
    await setup(TASKS);
    await screen.findByTestId('task-t-1');
    const buttons = screen.getAllByRole('button');
    expect(buttons.length).toBeGreaterThan(4);
    for (const button of buttons) expect(JSON.stringify(button.props.className ?? '')).toMatch(/\b(h-12|min-h-12)\b/);
  });

  it('starts a task, finishes a started one, and marks a room clean or dirty with the right calls', async () => {
    const { api } = await setup(TASKS);
    await screen.findByTestId('task-t-1');

    await fireEvent.press(within(screen.getByTestId('task-t-1')).getByRole('button', { name: 'Start' }));
    await waitFor(() => expect(api.posts).toContainEqual({ path: 'hotel/housekeeping/tasks/t-1/start', body: {} }));

    await fireEvent.press(within(screen.getByTestId('task-t-2')).getByRole('button', { name: 'Finish' }));
    await waitFor(() => expect(api.posts).toContainEqual({ path: 'hotel/housekeeping/tasks/t-2/finish', body: {} }));

    await fireEvent.press(within(screen.getByTestId('task-t-1')).getByRole('button', { name: 'Mark clean' }));
    await waitFor(() => expect(api.posts).toContainEqual({ path: 'hotel/rooms/r-1/status', body: { status: 'vacant_clean' } }));

    await fireEvent.press(within(screen.getByTestId('task-t-3')).getByRole('button', { name: 'Mark dirty' }));
    await waitFor(() => expect(api.posts).toContainEqual({ path: 'hotel/rooms/r-3/status', body: { status: 'vacant_dirty' } }));
  });

  it('says plainly when offline, keeps the last list and does not act', async () => {
    const { api } = await setup(TASKS);
    await screen.findByTestId('task-t-1');
    api.offline = true;
    await fireEvent.press(screen.getByRole('button', { name: 'Refresh' }));
    expect(await screen.findByText(/You are offline/)).toBeTruthy();
    expect(screen.getByTestId('task-t-1')).toBeTruthy();
    await fireEvent.press(within(screen.getByTestId('task-t-1')).getByRole('button', { name: 'Start' }));
    await waitFor(() => expect(screen.getByText(/You are offline/)).toBeTruthy());
    expect(api.posts).toEqual([]);
  });

  it('signs out', async () => {
    const { rider, onSignedOut } = await setup(TASKS);
    await screen.findByTestId('task-t-1');
    await fireEvent.press(screen.getByRole('button', { name: 'Sign out' }));
    await waitFor(() => expect(onSignedOut).toHaveBeenCalled());
    expect(rider.signOut).toHaveBeenCalled();
  });
});

describe('the housekeeping service (HOT-29)', () => {
  it('asks who may use it from the user’s own permissions', async () => {
    const api = fakeApi([]);
    const housekeeping = createHousekeeping({ api, credentials: { clear: jest.fn() } });
    await expect(housekeeping.allowed()).resolves.toBe(true);
    api.get = async () => ({ status: 200, body: { permissions: [{ name: 'hotel.room.view', scopes: [] }] } });
    await expect(housekeeping.allowed()).resolves.toBe(false);
    api.get = async () => ({ status: 403, body: null });
    await expect(housekeeping.allowed()).resolves.toBe(false);
  });

  it('drops the token on a 401', async () => {
    const clear = jest.fn();
    const housekeeping = createHousekeeping({ api: { get: async () => ({ status: 401, body: null }) }, credentials: { clear } });
    await expect(housekeeping.refresh()).resolves.toMatchObject({ ok: false, error: 'signed_out' });
    expect(clear).toHaveBeenCalled();
  });

  it('groups by floor and sorts rooms the way people read them', () => {
    const groups = groupByFloor([task(1, '1010', 10, 'pending', 'vacant_dirty'), task(2, '102', 1, 'pending', 'vacant_dirty'), task(3, '101', 1, 'pending', 'vacant_dirty'), task(4, 'Annex', null, 'pending', 'vacant_dirty')]);
    expect(groups.map((group) => group.floor)).toEqual(['1', '10', '']);
    expect(groups[0].tasks.map((entry) => entry.room.number)).toEqual(['101', '102']);
  });
});
