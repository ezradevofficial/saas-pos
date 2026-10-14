import { createHmac, pbkdf2Sync } from 'node:crypto';
import { computeVerifier, PIN_SCHEME, verifyOffline } from '../auth/pinCrypto';
import { createCredentials, memoryBackend, secretEntry } from '../device/credentials';
import { toBase64Url } from '../lib/bytes';
import { createSyncEngine } from '../sync/engine';
import { OUTBOX, createSyncStore } from '../sync/store';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { CLOCK_PREFIX, createClockGate, nextKind, startOfLocalDay } from './clockGate';
import { PUNCH_KIND, createClockStore } from './clockStore';

// HR-22, HR-23, NFR-04: clock-in on the till with HR's clock-in PIN, offline, and the punches' upload.

const SECRET = Buffer.alloc(32, 9);
const SALT = Buffer.from('0123456789abcdef');
const ITERATIONS = 100000;
const AMINA = '0192a5a0-0000-7000-8000-000000000101';
const BARAKA = '0192a5a0-0000-7000-8000-000000000102';

/** The server's ClockPins::material, with Node's crypto. */
function material(employeeId, pin, { salt = SALT, kid = 'k1' } = {}) {
  const key = pbkdf2Sync(pin, salt, ITERATIONS, 32, 'sha256');
  const verifier = createHmac('sha256', SECRET).update(Buffer.concat([Buffer.from(`hr-clock:v1:${employeeId}:`), key])).digest('base64url');
  return { scheme: PIN_SCHEME, kid, salt: salt.toString('base64url'), iterations: ITERATIONS, verifier };
}

const row = (id, name, number, pin, extra = {}) => ({ id, number, name, version: 1, pin: material(id, pin), ...extra });

async function setup({ at = new Date(2026, 9, 14, 8, 2).getTime() } = {}) {
  const clock = { at };
  const now = () => clock.at;
  const server = fakeServer({ now });
  server.define('hr_clock_staff', { mode: 'snapshot', module: 'hr', rows: [row(AMINA, 'Amina Otieno', 'E-001', '2749'), row(BARAKA, 'Baraka Mwangi', 'E-002', '508163')] });
  const database = testDatabase();
  const store = createSyncStore(database);
  const credentials = createCredentials(memoryBackend(secretEntry(SECRET.toString('base64url'), 'k1')));
  const engine = createSyncEngine({ api: server, store, credentials, now, random: () => 0 });
  await engine.pull();
  const clockStore = createClockStore(database);
  const gate = createClockGate({ clockStore, engine, credentials });
  return { clock, server, store, engine, clockStore, gate };
}

describe('clock-in PIN verifier', () => {
  it.each(['noble', 'webcrypto'])('matches the server scheme with the hr-clock prefix (%s)', async (engine) => {
    const expected = material(AMINA, '482913').verifier;
    const actual = await computeVerifier({ prefix: CLOCK_PREFIX, userId: AMINA, input: '482913', salt: SALT, iterations: ITERATIONS, deviceSecret: SECRET, engine });
    expect(toBase64Url(actual)).toBe(expected);
  });

  it('checks clock-in material only with the hr-clock prefix, never as a till sign-in PIN', async () => {
    const deviceSecret = { secret: SECRET.toString('base64url'), kid: 'k1' };
    const clockMaterial = material(AMINA, '482913');
    await expect(verifyOffline({ material: clockMaterial, prefix: CLOCK_PREFIX, userId: AMINA, input: '482913', deviceSecret })).resolves.toEqual({ ok: true });
    await expect(verifyOffline({ material: clockMaterial, userId: AMINA, input: '482913', deviceSecret })).resolves.toEqual({ ok: false, reason: 'incorrect' });
  });
});

describe('next punch kind', () => {
  it('is in first, then alternates', () => {
    expect(nextKind(null)).toBe('in');
    expect(nextKind({ kind: 'in' })).toBe('out');
    expect(nextKind({ kind: 'out' })).toBe('in');
  });

  it('alternates per employee within the local day and starts again with in the next day', async () => {
    const { clock, gate, clockStore } = await setup();

    const kinds = [];
    for (const [employeeId, pin] of [[AMINA, '2749'], [BARAKA, '508163'], [AMINA, '2749'], [AMINA, '2749']]) {
      clock.at += 60000;
      const answer = await gate.punch({ employeeId, input: pin });
      kinds.push(`${employeeId === AMINA ? 'A' : 'B'}:${answer.punch.kind}`);
    }
    expect(kinds).toEqual(['A:in', 'B:in', 'A:out', 'A:in']);

    clock.at = new Date(2026, 9, 15, 7, 55).getTime();
    await expect(gate.nextKindFor(AMINA)).resolves.toBe('in');
    await expect(gate.punch({ employeeId: AMINA, input: '2749' })).resolves.toMatchObject({ ok: true, punch: { kind: 'in', occurredAt: clock.at } });
    await expect(clockStore.lastPunchSince(AMINA, startOfLocalDay(clock.at))).resolves.toMatchObject({ kind: 'in' });
  });
});

describe('clock-in lockout', () => {
  it('locks the employee on this till after 5 wrong PINs, even for the right one, and never stores the PIN', async () => {
    const { gate, clockStore, store } = await setup();

    const answers = [];
    for (let i = 0; i < 5; i++) answers.push(await gate.punch({ employeeId: AMINA, input: '0000' }));
    expect(answers.map((answer) => answer.attemptsLeft ?? answer.reason)).toEqual([4, 3, 2, 1, 'locked']);
    await expect(gate.punch({ employeeId: AMINA, input: '2749' })).resolves.toEqual({ ok: false, reason: 'locked' });
    // Another employee is not affected.
    await expect(gate.punch({ employeeId: BARAKA, input: '508163' })).resolves.toMatchObject({ ok: true });

    const board = await gate.board();
    expect(board.map((member) => [member.name, member.lockedHere])).toEqual([
      ['Amina Otieno', true],
      ['Baraka Mwangi', false],
    ]);
    await expect(clockStore.attempt(AMINA)).resolves.toMatchObject({ failedAttempts: 5, locked: true });
    // Nothing about the PIN is kept, and nothing of it queued.
    const outbox = await store.entries();
    expect(JSON.stringify(outbox)).not.toContain('2749');
    expect(outbox).toHaveLength(1);
  });

  it('unlocks when a new PIN arrives through sync', async () => {
    const { gate, server, engine } = await setup();
    for (let i = 0; i < 5; i++) await gate.punch({ employeeId: AMINA, input: '0000' });

    // The same PIN synced again (another snapshot change) keeps the lock.
    server.upsert('hr_clock_staff', row(BARAKA, 'Baraka Mwangi', 'E-002', '111111', { version: 2 }));
    await engine.pull();
    await expect(gate.punch({ employeeId: AMINA, input: '2749' })).resolves.toEqual({ ok: false, reason: 'locked' });

    server.upsert('hr_clock_staff', row(AMINA, 'Amina Otieno', 'E-001', '7351', { version: 2 }));
    await engine.pull();
    await expect(gate.punch({ employeeId: AMINA, input: '2749' })).resolves.toMatchObject({ ok: false, reason: 'incorrect', attemptsLeft: 4 });
    await expect(gate.punch({ employeeId: AMINA, input: '7351' })).resolves.toMatchObject({ ok: true, punch: { kind: 'in' } });
  });

  it('unlocks for a PIN cleared and set again (version 1 again, a new salt)', async () => {
    const { gate, server, engine } = await setup();
    for (let i = 0; i < 5; i++) await gate.punch({ employeeId: AMINA, input: '0000' });

    server.upsert('hr_clock_staff', { ...row(AMINA, 'Amina Otieno', 'E-001', '7351'), pin: material(AMINA, '7351', { salt: Buffer.from('aaaaaaaaaaaaaaaa') }) });
    await engine.pull();
    await expect(gate.punch({ employeeId: AMINA, input: '7351' })).resolves.toMatchObject({ ok: true });
  });

  it('starts the count again after a right PIN', async () => {
    const { gate } = await setup();
    for (let i = 0; i < 4; i++) await gate.punch({ employeeId: AMINA, input: '0000' });
    await expect(gate.punch({ employeeId: AMINA, input: '2749' })).resolves.toMatchObject({ ok: true });
    await expect(gate.punch({ employeeId: AMINA, input: '0000' })).resolves.toMatchObject({ reason: 'incorrect', attemptsLeft: 4 });
  });

  it('counts nothing when the till cannot check the PIN (another secret)', async () => {
    const { gate, server, engine } = await setup();
    server.upsert('hr_clock_staff', { ...row(AMINA, 'Amina Otieno', 'E-001', '2749'), pin: material(AMINA, '2749', { kid: 'k2' }) });
    await engine.pull();
    await expect(gate.punch({ employeeId: AMINA, input: '2749' })).resolves.toEqual({ ok: false, reason: 'no_secret' });
    await expect(gate.punch({ employeeId: 'nobody', input: '2749' })).resolves.toEqual({ ok: false, reason: 'not_found' });
  });
});

describe('punch upload', () => {
  it('queues punches offline for days and uploads them once online, in batches', async () => {
    const { clock, gate, server, engine, clockStore } = await setup();
    server.goOffline();

    for (let day = 0; day < 7; day++) {
      clock.at = new Date(2026, 9, 14 + day, 8, 0).getTime();
      await gate.punch({ employeeId: AMINA, input: '2749' });
      clock.at = new Date(2026, 9, 14 + day, 17, 0).getTime();
      await gate.punch({ employeeId: AMINA, input: '2749' });
    }
    await engine.sync();
    expect(engine.getStatus()).toMatchObject({ pending: 14, network: 'offline' });
    const today = await clockStore.punchesSince(startOfLocalDay(clock.at));
    expect(today.map((punch) => [punch.kind, punch.upload])).toEqual([
      ['out', 'pending'],
      ['in', 'pending'],
    ]);

    const tried = server.requestsTo('hr/till/punches').length;
    server.goOnline();
    await engine.sync({ force: true });

    expect(engine.getStatus()).toMatchObject({ pending: 0, failed: 0 });
    const sent = server.requestsTo('hr/till/punches').slice(tried);
    expect(sent).toHaveLength(1);
    expect(sent[0].body.punches).toHaveLength(14);
    expect(sent[0].body.punches[0]).toEqual({ id: expect.stringMatching(/^[0-9a-f-]{36}$/), employee_id: AMINA, kind: 'in', occurred_at: new Date(2026, 9, 14, 8, 0).toISOString() });
    expect(sent[0].body.punches.map((punch) => punch.kind).join('')).toBe('inout'.repeat(7));
    await expect(clockStore.punchesSince(startOfLocalDay(clock.at))).resolves.toEqual([expect.objectContaining({ upload: 'stored' }), expect.objectContaining({ upload: 'stored' })]);
  });

  it('sends at most 100 punches per request', async () => {
    const { server, engine } = await setup();
    for (let i = 0; i < 150; i++) {
      const id = `0192a5a0-0000-7000-8000-${String(i).padStart(12, '0')}`;
      await engine.enqueue(PUNCH_KIND, id, { id, employee_id: AMINA, kind: i % 2 ? 'out' : 'in', occurred_at: new Date().toISOString() });
    }
    await engine.push();
    expect(server.requestsTo('hr/till/punches').map((request) => request.body.punches.length)).toEqual([100, 50]);
  });

  it('takes a punch the server already holds (a lost answer) as stored: one local record, never sent again', async () => {
    const { gate, server, engine, store, clockStore } = await setup();
    const answer = await gate.punch({ employeeId: AMINA, input: '2749' });
    const payload = (await store.entryFor(PUNCH_KIND, answer.punch.id)).payload;
    // The first upload reached the server, its answer did not reach the till.
    server.state.stored.set(answer.punch.id, { path: 'hr/till/punches', record: payload, received_at: new Date().toISOString() });

    await engine.push();
    await engine.push({ force: true });

    expect(server.requestsTo('hr/till/punches')).toHaveLength(1);
    expect(server.state.stored.size).toBe(1);
    await expect(store.entries()).resolves.toEqual([expect.objectContaining({ status: OUTBOX.ACKNOWLEDGED, result: expect.objectContaining({ status: 'stored' }) })]);
    const punches = await clockStore.punchesSince(0);
    expect(punches).toEqual([expect.objectContaining({ id: answer.punch.id, upload: 'stored' })]);
  });

  it('keeps a rejected punch with its code and never sends it again', async () => {
    const { clock, gate, server, engine, clockStore } = await setup();
    const first = await gate.punch({ employeeId: AMINA, input: '2749' });
    clock.at += 60000;
    const second = await gate.punch({ employeeId: BARAKA, input: '508163' });
    server.state.reject.set(first.punch.id, 'not_at_device');

    await engine.push();
    await engine.push({ force: true });
    await engine.sync({ force: true });

    const sent = server.requestsTo('hr/till/punches');
    expect(sent).toHaveLength(1);
    expect(engine.getStatus()).toMatchObject({ pending: 0, failed: 1 });
    await expect(clockStore.punch(first.punch.id)).resolves.toMatchObject({ upload: 'rejected', code: 'not_at_device' });
    await expect(clockStore.punch(second.punch.id)).resolves.toMatchObject({ upload: 'stored' });
    const board = await gate.board();
    expect(board.find((member) => member.id === AMINA).today).toMatchObject({ kind: 'in', upload: 'rejected' });
  });

  it('retries after a network error and keeps punches while HR is off, without holding back POS uploads', async () => {
    const { gate, server, engine } = await setup();
    await gate.punch({ employeeId: AMINA, input: '2749' });
    server.goOffline();
    await expect(engine.push()).rejects.toThrow('network_error');
    await engine.refreshCounts();
    expect(engine.getStatus()).toMatchObject({ pending: 1, network: 'offline' });

    server.goOnline();
    server.state.hrInactive = true;
    const saleId = '0192a5a0-0000-7000-8000-00000000aaaa';
    await engine.enqueue('pos.voids', saleId, { id: saleId });
    const summary = await engine.push({ force: true });
    expect(summary).toMatchObject({ acknowledged: 1, retried: 1, stopped: null });
    expect(engine.getStatus()).toMatchObject({ pending: 1, moduleInactive: false });

    server.state.hrInactive = false;
    await engine.push({ force: true });
    expect(engine.getStatus()).toMatchObject({ pending: 0, failed: 0 });
    expect(server.state.stored.size).toBe(2);
  });

  it('forgets old punches the server holds, keeps those not accepted', async () => {
    const { clock, gate, server, engine, clockStore } = await setup();
    const kept = await gate.punch({ employeeId: AMINA, input: '2749' });
    await gate.punch({ employeeId: BARAKA, input: '508163' });
    server.state.reject.set(kept.punch.id, 'period_closed');
    await engine.push();

    clock.at += 40 * 24 * 60 * 60 * 1000;
    await engine.push();
    await gate.board();
    await expect(clockStore.punchesSince(0)).resolves.toEqual([expect.objectContaining({ id: kept.punch.id, upload: 'rejected' })]);
  });
});
