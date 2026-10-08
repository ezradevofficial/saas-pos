import { Q } from '@nozbe/watermelondb';
import { createCredentials, memoryBackend, secretEntry } from '../device/credentials';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { AUTH, createSyncEngine, NETWORK } from './engine';
import { createSyncStore, OUTBOX } from './store';

// NFR-04, ADR 004: the sync engine against an in-memory server.

const item = (id, extra = {}) => ({
  id,
  code: `C-${id}`,
  name: `Item ${id}`,
  category_id: null,
  sellable: true,
  reason: null,
  barcodes: [{ barcode: `600${id}`, uom_id: 'u1' }],
  uoms: [],
  images: [],
  updated_at: '2026-10-01T00:00:00Z',
  ...extra,
});

function setup({ server: serverOptions, clock, credentials = null } = {}) {
  let time = clock ?? Date.parse('2026-10-08T10:00:00Z');
  const now = () => time;
  const server = fakeServer({ now, ...serverOptions });
  const database = testDatabase();
  const store = createSyncStore(database);
  const make = () => createSyncEngine({ api: server, store, credentials, now, random: () => 0.5 });
  return { server, database, store, engine: make(), make, now, advance: (ms) => (time += ms) };
}

const rows = async (database, table) => (await database.get(table).query().fetch()).map((record) => record.id).sort();

describe('sync engine: pull', () => {
  it('pages through an incremental entity until has_more is false', async () => {
    const { server, engine, database } = setup();
    server.define('items', { rows: Array.from({ length: 7 }, (_, i) => item(`i${i}`)) });
    server.state.pageSizeDefault = 3;

    const counts = await engine.pull();

    expect(counts.items).toEqual({ upserts: 7, tombstones: 0, pages: 3 });
    expect(await rows(database, 'items')).toHaveLength(7);
    expect(server.requestsTo('sync/pull')).toHaveLength(3);
    const stored = await database.get('items').find('i3');
    expect(stored.code).toBe('C-i3');
    expect(stored.data.barcodes[0].barcode).toBe('600i3');
    expect(await database.get('item_barcodes').query(Q.where('barcode', '600i3')).fetchCount()).toBe(1);
  });

  it('resumes from its cursor and applies tombstones and changes after ties in a page', async () => {
    const { server, engine, database } = setup();
    server.define('items', { rows: [item('a'), item('b'), item('c')] });
    await engine.pull();

    server.upsert('items', item('a', { name: 'Renamed' }));
    server.upsert('items', item('a', { name: 'Renamed twice' })); // two changes, one id
    server.remove('items', 'b');
    server.upsert('items', item('d'));
    const counts = await engine.pull();

    expect(counts.items).toEqual({ upserts: 2, tombstones: 1, pages: 1 });
    expect(await rows(database, 'items')).toEqual(['a', 'c', 'd']);
    expect((await database.get('items').find('a')).name).toBe('Renamed twice');
    expect(await database.get('item_barcodes').query(Q.where('item_id', 'b')).fetchCount()).toBe(0);
    // The second pull sent the first pull's cursor.
    const [, second] = server.requestsTo('sync/pull');
    expect(second.query.cursors.items).toBeTruthy();
  });

  it('starts an entity over on reset, dropping rows the server no longer has', async () => {
    const { server, engine, database } = setup();
    server.define('items', { rows: [item('a'), item('b')] });
    await engine.pull();

    // The server's source version changes and its data is rebuilt without b.
    server.define('items', { version: 2, rows: [item('a', { name: 'v2' }), item('c')] });
    server.state.pageSizeDefault = 1;
    await engine.pull();

    expect(await rows(database, 'items')).toEqual(['a', 'c']);
    expect((await database.get('items').find('a')).name).toBe('v2');
    expect(await rows(database, 'item_barcodes')).toEqual(['a:0', 'c:0']);
  });

  it('replaces a snapshot entity and skips it when unchanged', async () => {
    const { server, engine, database } = setup();
    server.define('staff', { mode: 'snapshot', rows: [{ id: 'u1', name: 'Amina', locked: false }, { id: 'u2', name: 'Baraka', locked: false }] });
    await engine.pull();
    expect(await rows(database, 'staff')).toEqual(['u1', 'u2']);

    const unchanged = await engine.pull();
    expect(unchanged.staff.upserts).toBe(0);

    server.remove('staff', 'u2');
    server.upsert('staff', { id: 'u3', name: 'Chloé', locked: true });
    await engine.pull();
    expect(await rows(database, 'staff')).toEqual(['u1', 'u3']);
    expect((await database.get('staff').find('u3')).locked).toBe(true);
  });

  it('starts an entity over when the server refuses its cursor', async () => {
    const { server, engine, database, store } = setup();
    server.define('items', { rows: [item('a')] });
    await engine.pull();
    // A cursor of the other kind is refused (422 invalid_cursor).
    server.define('items', { mode: 'snapshot', rows: [item('z')] });
    await engine.bootstrap();
    await engine.pull();

    expect(await rows(database, 'items')).toEqual(['z']);
    expect(Object.keys(await store.cursors(['items']))).toEqual(['items']);
  });

  it('skips entities this app does not know and drops entities no longer offered', async () => {
    const { server, engine, database } = setup();
    server.define('items', { rows: [item('a')] });
    server.define('future_thing', { rows: [{ id: 'x' }] });
    await engine.pull();
    expect(server.requestsTo('sync/pull')[0].query.entities).toEqual(['items']);

    delete server.state.entities.items;
    await engine.bootstrap();
    expect(await rows(database, 'items')).toEqual([]);
  });

  it('flags a device with no current secret (unpair and pair again)', async () => {
    const { server, engine, store } = setup();
    server.state.secretIssued = false;
    await engine.bootstrap();
    expect(engine.getStatus().secretMissing).toBe(true);
    expect((await store.meta()).secretKid).toBeNull();

    server.state.secretIssued = true;
    await engine.bootstrap();
    expect(engine.getStatus().secretMissing).toBe(false);
    expect((await store.meta()).secretKid).toBe('k1');
  });

  it('measures clock skew from server_time', async () => {
    const { server, engine, now } = setup();
    server.state.serverTime = now() + 90_000;
    server.define('items', { rows: [] });

    await engine.bootstrap();

    expect(engine.getStatus().skewMs).toBe(90_000);
    expect(engine.serverNow()).toBe(now() + 90_000);
  });
});

describe('sync engine: push', () => {
  const sale = (id) => ({ id, total: 100 });

  it('persists before sending and acknowledges stored records', async () => {
    const { server, engine, store } = setup();
    server.define('items', { rows: [] });
    await engine.enqueue('pos.sales', 's1', sale('s1'));
    await engine.enqueue('pos.sales', 's2', sale('s2'));
    expect(server.requestsTo('pos/sales')).toHaveLength(0);
    expect(engine.getStatus().pending).toBe(2);

    const summary = await engine.push();

    expect(summary).toMatchObject({ acknowledged: 2, failed: 0, retried: 0 });
    expect(server.requestsTo('pos/sales')).toHaveLength(1);
    expect(server.requestsTo('pos/sales')[0].body.sales.map((s) => s.id)).toEqual(['s1', 's2']);
    expect(await store.counts()).toEqual({ pending: 0, failed: 0 });
    expect(engine.getStatus().lastPushedAt).not.toBeNull();
  });

  it('keeps everything while offline and sends it once back online', async () => {
    const { server, engine, store } = setup();
    server.goOffline();
    await engine.enqueue('pos.sales', 's1', sale('s1'));

    const offline = await engine.sync();
    expect(offline.error.name).toBe('NetworkError');
    expect(engine.getStatus().network).toBe(NETWORK.OFFLINE);
    const [entry] = await store.entries();
    expect(entry).toMatchObject({ status: OUTBOX.PENDING, attempts: 0, nextAttemptAt: 0 });

    server.goOnline();
    server.define('items', { rows: [] });
    await engine.sync();
    expect(server.state.stored.has('s1')).toBe(true);
    expect(engine.getStatus()).toMatchObject({ network: NETWORK.ONLINE, pending: 0 });
  });

  it('treats a duplicate upload as acknowledged (idempotent resend)', async () => {
    const { server, engine, store } = setup();
    // The first upload reached the server but its answer was lost.
    server.state.stored.set('s1', { received_at: '2026-10-08T09:00:00Z' });
    await engine.enqueue('pos.sales', 's1', sale('s1'));

    await engine.push();

    expect(server.state.stored.size).toBe(1);
    const [entry] = await store.entries();
    expect(entry.status).toBe(OUTBOX.ACKNOWLEDGED);
    expect(entry.result.received_at).toBe('2026-10-08T09:00:00Z');
  });

  it('marks a refused record failed with its reason without blocking the queue', async () => {
    const { server, engine, store } = setup();
    server.state.reject.set('bad', { code: 'sale_underpaid', message: 'The payments do not cover the total.', field: 'payments', retryable: false });
    await engine.enqueue('pos.sales', 'bad', sale('bad'));
    await engine.enqueue('pos.sales', 'good', sale('good'));

    const summary = await engine.push();

    expect(summary).toMatchObject({ acknowledged: 1, failed: 1, retried: 0 });
    const failed = await store.entries(OUTBOX.FAILED);
    expect(failed).toHaveLength(1);
    expect(failed[0]).toMatchObject({ recordId: 'bad', lastError: { code: 'sale_underpaid', field: 'payments' } });
    expect(engine.getStatus().failed).toBe(1);
  });

  it('retries a retryable rejection later with backoff', async () => {
    const { server, engine, store, advance } = setup();
    server.state.reject.set('v1', { code: 'sale_unknown', message: 'Not yet', retryable: true });
    await engine.enqueue('pos.voids', 'v1', { id: 'v1' });

    await engine.push();
    const [entry] = await store.entries();
    expect(entry).toMatchObject({ status: OUTBOX.PENDING, attempts: 1 });
    // base 5s * 2^0, jitter between half and all of it (random 0.5 → 3.75s).
    expect(entry.nextAttemptAt - Date.parse('2026-10-08T10:00:00Z')).toBe(3750);

    await engine.push(); // not due yet
    expect(server.requestsTo('pos/voids')).toHaveLength(1);

    server.state.reject.delete('v1');
    advance(4000);
    await engine.push();
    expect((await store.entries())[0].status).toBe(OUTBOX.ACKNOWLEDGED);
  });

  it('isolates the record at fault when a whole batch is refused (409/422)', async () => {
    const { server, engine, store } = setup();
    server.state.uploadOverride = (path, body) => {
      if (body.sales.some((s) => s.id === 'broken')) {
        return { status: 422, body: { code: 'validation_failed', message: 'The given data was invalid.', errors: { 'sales.0.lines': ['Required'] } } };
      }
      if (body.sales.some((s) => s.id === 'clash')) return { status: 409, body: { code: 'id_conflict', message: 'Taken' } };
      return null;
    };
    await engine.enqueue('pos.sales', 'ok1', sale('ok1'));
    await engine.enqueue('pos.sales', 'broken', sale('broken'));
    await engine.enqueue('pos.sales', 'clash', sale('clash'));
    await engine.enqueue('pos.sales', 'ok2', sale('ok2'));

    const summary = await engine.push();

    expect(summary).toMatchObject({ acknowledged: 2, failed: 2, retried: 0 });
    const failed = await store.entries(OUTBOX.FAILED);
    expect(failed.map((entry) => [entry.recordId, entry.lastError.status, entry.lastError.code])).toEqual([
      ['broken', 422, 'validation_failed'],
      ['clash', 409, 'id_conflict'],
    ]);
  });

  it('backs off on server errors', async () => {
    const { server, engine, store } = setup();
    server.state.uploadOverride = () => ({ status: 503, body: { message: 'Down' } });
    await engine.enqueue('pos.sales', 's1', sale('s1'));

    const summary = await engine.push();

    expect(summary.retried).toBe(1);
    expect((await store.entries())[0]).toMatchObject({ status: OUTBOX.PENDING, attempts: 1, lastError: { status: 503 } });
  });

  it('uploads kinds in enqueue order, one run of a kind per request', async () => {
    const { server, engine } = setup();
    await engine.enqueue('pos.shifts', 'sh1', { id: 'sh1', status: 'open' });
    await engine.enqueue('pos.sales', 's1', sale('s1'));
    await engine.enqueue('pos.sales', 's2', sale('s2'));
    await engine.enqueue('pos.shifts', 'sh1', { id: 'sh1', status: 'closed' });

    await engine.push();

    expect(server.state.requests.map((request) => request.path)).toEqual(['pos/shifts', 'pos/sales', 'pos/shifts']);
  });

  it('keeps the outbox across a restart (a new engine over the same database)', async () => {
    const { server, engine, make, store } = setup();
    server.goOffline();
    await engine.enqueue('pos.sales', 's1', sale('s1'));
    await engine.enqueue('pos.sales', 's2', sale('s2'));
    await engine.sync();

    const restarted = make();
    await restarted.load();
    expect(restarted.getStatus().pending).toBe(2);

    server.goOnline();
    await restarted.push();
    expect([...server.state.stored.keys()]).toEqual(['s1', 's2']);
    expect(await store.counts()).toEqual({ pending: 0, failed: 0 });
  });

  it('refuses unknown kinds', async () => {
    const { engine } = setup();
    await expect(engine.enqueue('nope', 'x', {})).rejects.toThrow('Unknown push kind');
  });
});

describe('sync engine: access lost', () => {
  it.each([401, 403])('stops on %i, keeps unsent records and resumes only when forced', async (code) => {
    const { server, engine, store } = setup();
    server.define('items', { rows: [] });
    await engine.enqueue('pos.sales', 's1', { id: 's1' });
    server.state.authStatus = code;

    const result = await engine.sync();

    expect(result.error.name).toBe('AuthLostError');
    expect(engine.getStatus().auth).toBe(AUTH.LOST);
    expect((await store.entries())[0]).toMatchObject({ status: OUTBOX.PENDING, attempts: 0 });

    server.state.requests.length = 0;
    expect(await engine.sync()).toEqual({ skipped: 'auth_lost' });
    expect(server.state.requests).toHaveLength(0);

    // The admin resumes the device; the app retries (start or "Try again").
    server.state.authStatus = null;
    const forced = await engine.sync({ force: true });
    expect(forced.error).toBeUndefined();
    expect(engine.getStatus().auth).toBe(AUTH.OK);
    expect(server.state.stored.has('s1')).toBe(true);
  });

  it('remembers lost access across a restart', async () => {
    const { server, engine, make } = setup();
    server.state.authStatus = 401;
    await engine.sync();

    const restarted = make();
    await restarted.load();
    expect(restarted.getStatus()).toMatchObject({ auth: AUTH.LOST, authCode: 'unauthenticated' });
  });
});

describe('sync engine: PIN attempt reports', () => {
  it('reports offline attempts once', async () => {
    const { server, engine, store } = setup();
    await store.savePinAttempt({ userId: 'u1', failedAttempts: 5, locked: true, occurredAt: '2026-10-08T09:59:00Z', pinVersion: 1, reported: false });

    await engine.reportPinAttempts();
    await engine.reportPinAttempts();

    expect(server.state.pinReports).toEqual([{ user_id: 'u1', failed_attempts: 5, locked: true, occurred_at: '2026-10-08T09:59:00Z' }]);
  });

  it('reports after the pull and never aborts the run when reporting fails', async () => {
    const { server, engine, store } = setup();
    server.define('items', { rows: [item('a')] });
    await store.savePinAttempt({ userId: 'u1', failedAttempts: 2, locked: false, pinVersion: 1, reported: false });
    server.state.pinAnswer = () => ({ status: 500, body: { message: 'Down' } });

    const result = await engine.sync();

    expect(result.pulled.items.upserts).toBe(1);
    expect(server.state.requests.map((request) => request.path)).toEqual(['sync/bootstrap', 'sync/pull', 'pos/pin/attempts']);
    await expect(store.unreportedPinAttempts()).resolves.toHaveLength(1);
  });

  it('drops reports the server skips or refuses', async () => {
    const { server, engine, store } = setup();
    await store.savePinAttempt({ userId: 'gone', failedAttempts: 2, locked: false, pinVersion: 1, reported: false });
    server.state.pinAnswer = () => ({ status: 200, body: { data: [{ user_id: 'gone', skipped: 'not_staff_here' }] } });
    await engine.reportPinAttempts();
    await expect(store.unreportedPinAttempts()).resolves.toEqual([]);

    await store.savePinAttempt({ userId: 'other-tenant', failedAttempts: 1, locked: false, pinVersion: 1, reported: false });
    server.state.pinAnswer = () => ({ status: 422, body: { code: 'validation_failed' } });
    await engine.reportPinAttempts();
    await expect(store.unreportedPinAttempts()).resolves.toEqual([]);
  });
});

describe('sync engine: review fixes', () => {
  const sale = (id) => ({ id, total: 100 });

  it('promotes a rotated secret when bootstrap names its kid (lost activation answer)', async () => {
    const credentials = createCredentials(memoryBackend(secretEntry('b2xk', 'k0')));
    await credentials.savePending({ secret: 'bmV3', kid: 'k1' });
    const { engine } = setup({ credentials });

    await engine.bootstrap();

    await expect(credentials.secret()).resolves.toEqual({ secret: 'bmV3', kid: 'k1' });
    await expect(credentials.pending()).resolves.toBeNull();
    expect(engine.getStatus().secretMissing).toBe(false);
  });

  it('flags a secret that is not the server current one', async () => {
    const credentials = createCredentials(memoryBackend(secretEntry('b2xk', 'k0')));
    const { engine } = setup({ credentials });

    await engine.bootstrap();

    expect(engine.getStatus().secretMissing).toBe(true);
  });

  it('treats only 401 and device 403 codes as lost access; module_inactive keeps the till syncing', async () => {
    const { server, engine, store } = setup();
    server.define('items', { rows: [item('a')] });
    await engine.enqueue('pos.sales', 's1', sale('s1'));
    server.state.uploadOverride = () => ({ status: 403, body: { code: 'module_inactive', message: 'Off' } });

    const result = await engine.sync();

    expect(result.pushed.stopped).toBe('module_inactive');
    expect(result.pulled.items.upserts).toBe(1);
    expect(engine.getStatus()).toMatchObject({ auth: AUTH.OK, moduleInactive: true, pending: 1 });
    expect((await store.entries())[0]).toMatchObject({ status: OUTBOX.PENDING, attempts: 1 });

    server.state.uploadOverride = () => ({ status: 403, body: { code: 'forbidden', message: 'No' } });
    await engine.sync({ force: true });
    expect(engine.getStatus().auth).toBe(AUTH.OK);

    server.state.uploadOverride = null;
    await engine.sync({ force: true });
    expect(engine.getStatus()).toMatchObject({ moduleInactive: false, pending: 0 });
  });

  it.each([
    ['404', { status: 404, body: { code: 'not_found' } }],
    ['400', { status: 400, body: { message: 'Bad' } }],
    ['a non-JSON 422', { status: 422, body: null }],
    ['a 409 without a code', { status: 409, body: null }],
  ])('retries on %s instead of failing the record', async (_label, answer) => {
    const { server, engine, store } = setup();
    server.state.uploadOverride = () => answer;
    await engine.enqueue('pos.sales', 's1', sale('s1'));

    await engine.push();

    expect((await store.entries())[0]).toMatchObject({ status: OUTBOX.PENDING, attempts: 1 });
  });

  it('keeps enqueue order for concurrent calls', async () => {
    const { engine, store } = setup();
    await Promise.all(['a', 'b', 'c', 'd'].map((id) => engine.enqueue('pos.sales', id, sale(id))));

    const entries = await store.entries();
    expect(entries.map((entry) => entry.seq)).toEqual([1, 2, 3, 4]);
    expect(new Set(entries.map((entry) => entry.recordId)).size).toBe(4);
  });

  it('stops the run on 503 and honours Retry-After', async () => {
    const { server, engine, store, now } = setup();
    server.state.uploadOverride = (path) => (path === 'pos/sales' ? { status: 503, body: null, retryAfter: '120' } : null);
    await engine.enqueue('pos.sales', 's1', sale('s1'));
    await engine.enqueue('pos.voids', 'v1', { id: 'v1' });

    const summary = await engine.push();

    expect(summary.stopped).toBe('http_503');
    expect(server.requestsTo('pos/voids')).toHaveLength(0);
    const [first] = await store.entries();
    expect(first.nextAttemptAt - now()).toBe(120000);
  });

  it('holds later rows of a shift behind an earlier one still waiting', async () => {
    const { server, engine, store, advance } = setup();
    server.state.reject.set('s1', { code: 'shift_unknown', message: 'Later', retryable: true });
    await engine.enqueue('pos.sales', 's1', sale('s1'), { group: 'shift-1' });
    await engine.enqueue('pos.voids', 'v1', { id: 'v1' }, { group: 'shift-1' });
    await engine.enqueue('pos.sales', 'x1', sale('x1'), { group: 'shift-2' });

    await engine.push();

    expect(server.requestsTo('pos/voids')).toHaveLength(0);
    expect(server.state.stored.has('x1')).toBe(true);

    server.state.reject.delete('s1');
    advance(10 * 60 * 1000);
    await engine.push();
    expect([...server.state.stored.keys()]).toEqual(['x1', 's1', 'v1']);
    expect(await store.counts()).toEqual({ pending: 0, failed: 0 });
  });

  it('ignores backoff when forced, and treats a retry time beyond the longest backoff as due', async () => {
    const { server, engine, store, now } = setup();
    server.define('items', { rows: [] });
    await engine.enqueue('pos.sales', 's1', sale('s1'));
    const [entry] = await store.entries();
    await store.settle([{ id: entry.id, status: OUTBOX.PENDING, nextAttemptAt: now() + 60000 }]);

    await engine.push();
    expect(server.requestsTo('pos/sales')).toHaveLength(0);
    await engine.sync({ force: true });
    expect(server.state.stored.has('s1')).toBe(true);

    // A clock that ran ahead then was corrected: a retry a day away is due now.
    await engine.enqueue('pos.sales', 's2', sale('s2'));
    const second = (await store.entries(OUTBOX.PENDING))[0];
    await store.settle([{ id: second.id, status: OUTBOX.PENDING, nextAttemptAt: now() + 24 * 60 * 60 * 1000 }]);
    await engine.push();
    expect(server.state.stored.has('s2')).toBe(true);
  });

  it('keeps old rows during a paged reset until the last page, then removes what was not sent again', async () => {
    const { server, engine, database } = setup();
    server.state.pageSizeDefault = 1;
    server.define('items', { rows: [item('a'), item('b'), item('c')] });
    await engine.pull();

    server.define('items', { version: 2, rows: [item('a', { name: 'v2' }), item('d')] });
    // Only the first page of the reset arrives, then the connection drops.
    let pulls = 0;
    const request = server.request;
    server.request = async (method, path, options) => {
      if (path === 'sync/pull' && ++pulls === 2) server.goOffline();
      return request(method, path, options);
    };
    await expect(engine.pull()).rejects.toThrow('network_error');
    expect(await rows(database, 'items')).toEqual(['a', 'b', 'c']);

    server.goOnline();
    await engine.pull();
    expect(await rows(database, 'items')).toEqual(['a', 'd']);
    expect(await rows(database, 'item_barcodes')).toEqual(['a:0', 'd:0']);
  });

  it('bootstraps again when the server refuses the entity list', async () => {
    const { server, engine } = setup();
    server.define('items', { rows: [item('a')] });
    server.define('customers', { rows: [] });
    await engine.bootstrap();
    delete server.state.entities.customers;

    const counts = await engine.pull();

    expect(counts.items.upserts).toBe(1);
    expect(server.requestsTo('sync/bootstrap')).toHaveLength(2);
  });

  it('refuses a bootstrap without an entity list', async () => {
    const { server, engine } = setup();
    const request = server.request;
    server.request = async (...args) => {
      const answer = await request(...args);
      return args[1] === 'sync/bootstrap' ? { ...answer, body: { ...answer.body, entities: null } } : answer;
    };

    await expect(engine.bootstrap()).rejects.toThrow('invalid_bootstrap');
  });

  it('requires the payload id to be the record id', async () => {
    const { engine } = setup();
    await expect(engine.enqueue('pos.sales', 's1', { id: 's2' })).rejects.toThrow('payload id');
  });

  it('prunes acknowledged rows after 30 days, never pending or failed ones', async () => {
    const { server, engine, store, advance } = setup();
    server.state.reject.set('bad', { code: 'sale_underpaid', message: 'No', retryable: false });
    await engine.enqueue('pos.sales', 'ok', sale('ok'));
    await engine.enqueue('pos.sales', 'bad', sale('bad'));
    await engine.push();

    advance(31 * 24 * 60 * 60 * 1000);
    await engine.push();

    expect((await store.entries()).map((entry) => [entry.recordId, entry.status])).toEqual([['bad', OUTBOX.FAILED]]);
  });
});
