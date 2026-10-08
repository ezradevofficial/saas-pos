import { createHmac, pbkdf2Sync } from 'node:crypto';
import { createCredentials, memoryBackend } from '../device/credentials';
import { NetworkError } from '../sync/api';
import { createSyncEngine } from '../sync/engine';
import { createSyncStore } from '../sync/store';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { PIN_SCHEME } from './pinCrypto';
import { createPinGate } from './pinGate';

// AUTH-06, AUTH-07: offline sign-in and lockout after 5 wrong PINs.

const SECRET = Buffer.alloc(32, 9);
const SALT = Buffer.from('fedcba9876543210');
const ITERATIONS = 100000;

function materialFor(userId, pin, kid = 'k1') {
  const key = pbkdf2Sync(pin, SALT, ITERATIONS, 32, 'sha256');
  const verifier = createHmac('sha256', SECRET).update(Buffer.concat([Buffer.from(`pin:v1:${userId}:`), key])).digest('base64url');
  return { scheme: PIN_SCHEME, kid, salt: SALT.toString('base64url'), iterations: ITERATIONS, verifier };
}

async function setup({ staff, online = false, verify } = {}) {
  const database = testDatabase();
  const store = createSyncStore(database);
  const server = fakeServer();
  server.define('staff', { mode: 'snapshot', rows: staff });
  await createSyncEngine({ api: server, store, now: () => Date.now() }).pull();
  const credentials = createCredentials(memoryBackend({ 'device.secret': SECRET.toString('base64url'), 'device.secret_kid': 'k1' }));
  const calls = [];
  const api = {
    post: async (path, body) => {
      calls.push({ path, body });
      if (!online) throw new NetworkError(new Error('offline'));
      return verify(body);
    },
  };
  return { store, gate: createPinGate({ store, api, credentials }), calls };
}

const amina = (extra = {}) => ({ id: 'u1', name: 'Amina', offline: true, pin: materialFor('u1', '274915'), card: null, pin_version: 1, failed_attempts: 0, locked: false, must_change: false, ...extra });

describe('PIN gate', () => {
  it('signs in offline with the right PIN', async () => {
    const { gate } = await setup({ staff: [amina()] });

    await expect(gate.signIn({ userId: 'u1', input: '274915' })).resolves.toMatchObject({ ok: true, checked: 'offline', user: { name: 'Amina' } });
  });

  it('locks after 5 wrong PINs, even the right one, and records it for the server', async () => {
    const { gate, store } = await setup({ staff: [amina()] });

    const answers = [];
    for (let i = 0; i < 5; i++) answers.push(await gate.signIn({ userId: 'u1', input: '000000' }));

    expect(answers.map((answer) => answer.attemptsLeft ?? answer.reason)).toEqual([4, 3, 2, 1, 'locked']);
    await expect(gate.signIn({ userId: 'u1', input: '274915' })).resolves.toEqual({ ok: false, reason: 'locked' });
    await expect(store.unreportedPinAttempts()).resolves.toEqual([expect.objectContaining({ userId: 'u1', failedAttempts: 5, locked: true })]);
  });

  it('resets the count after a right PIN', async () => {
    const { gate } = await setup({ staff: [amina()] });
    await gate.signIn({ userId: 'u1', input: '000000' });
    await gate.signIn({ userId: 'u1', input: '274915' });

    await expect(gate.signIn({ userId: 'u1', input: '000000' })).resolves.toMatchObject({ attemptsLeft: 4 });
  });

  it('starts from the server count and honours the server lock', async () => {
    const { gate } = await setup({ staff: [amina({ failed_attempts: 3 }), { ...amina(), id: 'u2', locked: true }] });

    await expect(gate.signIn({ userId: 'u1', input: '000000' })).resolves.toMatchObject({ attemptsLeft: 1 });
    await expect(gate.signIn({ userId: 'u2', input: '274915' })).resolves.toEqual({ ok: false, reason: 'locked' });
  });

  it('unlocks when the server sends a new PIN version', async () => {
    const { gate, store } = await setup({ staff: [amina()] });
    await store.savePinAttempt({ userId: 'u1', failedAttempts: 5, locked: true, pinVersion: 1, reported: true });
    await expect(gate.signIn({ userId: 'u1', input: '274915' })).resolves.toMatchObject({ reason: 'locked' });

    const fresh = await setup({ staff: [amina({ pin_version: 2 })] });
    await fresh.store.savePinAttempt({ userId: 'u1', failedAttempts: 5, locked: true, pinVersion: 1, reported: true });
    await expect(fresh.gate.signIn({ userId: 'u1', input: '274915' })).resolves.toMatchObject({ ok: true });
  });

  it('checks staff without offline material online, and says so when offline', async () => {
    const owner = amina({ id: 'u9', offline: false, pin: null });
    const offline = await setup({ staff: [owner] });
    await expect(offline.gate.signIn({ userId: 'u9', input: '274915' })).resolves.toEqual({ ok: false, reason: 'online_required' });

    const online = await setup({
      staff: [owner],
      online: true,
      verify: (body) => (body.pin === '274915' ? { status: 200, body: { data: {} } } : { status: 422, body: { code: 'pin_incorrect', attempts_left: 2 } }),
    });
    await expect(online.gate.signIn({ userId: 'u9', input: '111111' })).resolves.toMatchObject({ reason: 'incorrect', attemptsLeft: 2 });
    await expect(online.gate.signIn({ userId: 'u9', input: '274915' })).resolves.toMatchObject({ ok: true, checked: 'online' });
    expect(online.calls.map((call) => call.body)).toEqual([{ user_id: 'u9', pin: '111111' }, { user_id: 'u9', pin: '274915' }]);
  });

  it('falls back online when the material is for another secret', async () => {
    const { gate, calls } = await setup({ staff: [amina({ pin: materialFor('u1', '274915', 'k0') })], online: true, verify: () => ({ status: 200, body: {} }) });

    await expect(gate.signIn({ userId: 'u1', input: '274915' })).resolves.toMatchObject({ ok: true, checked: 'online' });
    expect(calls).toHaveLength(1);
  });

  it('reports must_change', async () => {
    const { gate } = await setup({ staff: [amina({ must_change: true })] });

    await expect(gate.signIn({ userId: 'u1', input: '274915' })).resolves.toMatchObject({ ok: true, mustChange: true });
  });
});
