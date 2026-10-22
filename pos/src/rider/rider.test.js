import { createRiderCredentials, memoryBackend } from '../device/credentials';
import { createApiClient, NetworkError } from '../sync/api';
import { createSyncStore } from '../sync/store';
import { testDatabase } from '../test/testDatabase';
import { createLocationTracker } from './locationTracker';
import { createRider, pickPictureSize } from './rider';

// ECOM-28, ECOM-29, NFR-04: the rider's status updates are kept on the phone first and
// upload idempotently: offline they wait, a lost answer is resent with the same id,
// the server's refusals are kept for the rider, and nothing is applied twice.

const DELIVERY = { id: 'd-1', status: 'assigned', order_number: 'WEB-A-2026-00001', customer_name: 'Wanjiku', customer_phone: '+254712345678', address: { line: 'Mpaka Road 12' }, currency: 'KES', collect_minor: '136000' };

/** A server that keeps what it applied by update id, as RiderUpdates does. */
function fakeRiderServer() {
  const applied = new Map();
  const server = {
    offline: false,
    loseAnswer: false,
    posts: [],
    deliveries: [DELIVERY],
    refuse: {},
    async get(path) {
      if (server.offline) throw new NetworkError(new Error('offline'));
      if (path === 'ecommerce/rider/deliveries') return { status: 200, body: { data: server.deliveries } };
      return { status: 404, body: null };
    },
    async post(path, body) {
      if (server.offline) throw new NetworkError(new Error('offline'));
      server.posts.push({ path, body });
      if (path === 'auth/sign-in') {
        const user = body.login.startsWith('amina') ? { id: 'u-2', name: 'Amina Rider' } : { id: 'u-1', name: 'Juma Rider' };
        return { status: 200, body: { token: `token-${user.id}`, user } };
      }
      if (path !== 'ecommerce/rider/updates') return { status: 204, body: null };
      const results = body.updates.map((update) => {
        if (applied.has(update.id)) return applied.get(update.id);
        // As RiderUpdates does: a code-less delivered needs that delivery's photo uploaded first.
        const unproven = update.status === 'delivered' && !update.code && !server.photos.some((photo) => photo.path.includes(update.delivery_id));
        const code = update.kind === 'location' ? server.refuseLocation : unproven ? 'proof_required' : server.refuse[update.status];
        const result = code ? { id: update.id, status: 'rejected', error: { code, message: 'No', retryable: false } } : { id: update.id, status: 'stored' };
        applied.set(update.id, result);
        return result;
      });
      if (server.loseAnswer) {
        server.loseAnswer = false;
        throw new NetworkError(new Error('timeout after the server applied it'));
      }
      return { status: results.some((r) => r.status === 'stored') ? 200 : 422, body: { results } };
    },
    photos: [],
    photoAnswer: null,
    async upload(path, form) {
      server.posts.push({ path, form });
      if (server.offline) throw new NetworkError(new Error('offline'));
      if (server.photoAnswer) return server.photoAnswer;
      server.photos.push({ path, form });
      return { status: 201, body: { data: { id: 'proof-1', proof_kind: 'photo' } } };
    },
    refuseLocation: null,
    applied,
  };
  return server;
}

let ids = 0;

function setup() {
  const server = fakeRiderServer();
  const store = createSyncStore(testDatabase());
  const credentials = createRiderCredentials(memoryBackend());
  const makeForm = (photo) => ({ photo: { uri: photo.uri, name: 'proof.jpg', type: 'image/jpeg' } });
  const deleteFile = jest.fn(async () => {});
  const rider = createRider({ api: server, store, credentials, makeForm, deleteFile, makeId: () => `0192a5a0-0000-7000-8000-${String(++ids).padStart(12, '0')}` });
  return { server, store, credentials, rider, deleteFile };
}

const sentUpdates = (server) => server.posts.filter((p) => p.path === 'ecommerce/rider/updates').flatMap((p) => p.body.updates);

describe('rider queue (ECOM-28, NFR-04)', () => {
  it('keeps each rider’s queue and deliveries apart and uploads only the signed-in rider’s', async () => {
    const { server, store, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    await rider.refresh();
    server.offline = true;
    const juma = await rider.record('d-1', 'picked_up');
    expect((await rider.snapshot()).deliveries).toHaveLength(1);

    // Juma signs out on a shared phone: his deliveries leave the phone, his queue waits for him.
    await rider.signOut();
    expect(JSON.stringify(await store.meta())).not.toContain('Wanjiku');

    // Amina signs in on the same phone: she sees none of Juma's, and uploads none of them.
    server.offline = false;
    await rider.signIn({ login: 'amina@example.test', password: 'secret-password' });
    server.deliveries = [];
    await rider.refresh();
    const state = await rider.snapshot();
    expect([state.pending, state.deliveries]).toEqual([0, []]);
    const sent = () => server.posts.filter((p) => p.path === 'ecommerce/rider/updates').flatMap((p) => p.body.updates.map((u) => u.id));
    expect(sent()).not.toContain(juma.id);

    // Juma back: his update goes up.
    await rider.signOut();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    await rider.flush();
    expect(sent()).toContain(juma.id);
  });


  it('signs in with the rider’s own token and keeps the deliveries for offline use', async () => {
    const { server, credentials, rider } = setup();
    expect((await rider.signIn({ login: 'juma@example.test', password: 'secret-password' })).ok).toBe(true);
    expect(await credentials.token()).toBe('token-u-1');
    expect(await rider.refresh()).toEqual({ ok: true });

    server.offline = true;
    expect(await rider.refresh()).toEqual({ ok: false, error: 'offline' });
    const state = await rider.snapshot();
    expect(state.deliveries.map((d) => d.order_number)).toEqual(['WEB-A-2026-00001']);
    expect(state.network).toBe('offline');
  });

  it('keeps updates made offline on the phone, in order, and sends them once the server is back', async () => {
    const { server, store, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    await rider.refresh();
    server.offline = true;
    await rider.record('d-1', 'picked_up');
    await rider.record('d-1', 'delivered', { code: '123456', collected_minor: '136000' });
    await rider.flush();

    let state = await rider.snapshot();
    expect(state.pending).toBe(2);
    expect(state.deliveries[0]).toMatchObject({ status: 'delivered', queued: true });
    expect((await store.meta())['rider:u-1'].queue).toHaveLength(2);

    server.offline = false;
    await rider.flush();
    state = await rider.snapshot();
    expect(state.pending).toBe(0);
    expect(state.deliveries).toEqual([]);
    const sent = server.posts.filter((p) => p.path === 'ecommerce/rider/updates').flatMap((p) => p.body.updates);
    expect(sent.map((u) => u.status)).toEqual(['picked_up', 'delivered']);
    expect(sent[1]).toMatchObject({ code: '123456', collected_minor: '136000', kind: 'status', delivery_id: 'd-1' });
    expect(sent[1].order_number).toBeUndefined();
  });

  it('resends an update whose answer was lost with the same id, and the server applies it once', async () => {
    const { server, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    await rider.refresh();
    server.loseAnswer = true;
    await rider.record('d-1', 'picked_up');
    await rider.flush();
    expect((await rider.snapshot()).pending).toBe(1);

    await rider.flush();
    const uploads = server.posts.filter((p) => p.path === 'ecommerce/rider/updates');
    expect(uploads).toHaveLength(2);
    expect(uploads[0].body.updates[0].id).toBe(uploads[1].body.updates[0].id);
    expect(server.applied.size).toBe(1);
    expect((await rider.snapshot()).pending).toBe(0);
  });

  it('keeps a refused update with its reason for the rider, and drops it from the queue', async () => {
    const { server, store, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    server.deliveries = [{ ...DELIVERY, status: 'picked_up' }];
    await rider.refresh();
    server.refuse.delivered = 'code_invalid';
    await rider.record('d-1', 'delivered', { code: '000000' });
    await rider.flush();

    const state = await rider.snapshot();
    expect(state.pending).toBe(0);
    expect(state.rejected).toEqual([expect.objectContaining({ error: 'code_invalid', order_number: 'WEB-A-2026-00001', delivery_id: 'd-1' })]);
    // The customer's code is never kept once the update was answered.
    expect(state.rejected[0].code).toBeUndefined();
    expect(JSON.stringify(await store.meta())).not.toContain('"code":');
    await rider.dismiss(state.rejected[0].id);
    expect((await rider.snapshot()).rejected).toEqual([]);
  });

  it('signs the rider out on a 401 and keeps the queue on the phone', async () => {
    const { server, store, credentials, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    await rider.refresh();
    server.post = async () => ({ status: 401, body: { code: 'unauthenticated' } });
    const seen = [];
    rider.subscribe((state) => seen.push(state));
    await rider.record('d-1', 'picked_up');
    await rider.flush();
    expect(await credentials.token()).toBeNull();
    expect(seen.some((state) => state.signedOut)).toBe(true);
    // The queue waits on the phone for the rider's next sign-in; the deliveries are gone.
    const kept = (await store.meta())['rider:u-1'];
    expect([kept.queue.length, kept.deliveries]).toEqual([1, []]);
  });
});

describe('rider location (ECOM-29, NFR-04, KE DPA minimum data)', () => {
  const HERE = { latitude: -1.2648, longitude: 36.8025 };

  it('keeps at most one unsent point per delivery while offline, and sends only the newest', async () => {
    const { server, store, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    server.deliveries = [DELIVERY, { ...DELIVERY, id: 'd-2', order_number: 'WEB-A-2026-00002' }];
    await rider.refresh();
    server.offline = true;
    for (let i = 0; i < 5; i++) await rider.recordLocation({ ...HERE, latitude: HERE.latitude + i / 1000, occurred_at: `2026-10-22T08:0${i}:00.000Z` });
    await rider.flush();

    const queue = (await store.meta())['rider:u-1'].queue;
    expect(queue.map((u) => [u.delivery_id, u.kind, u.occurred_at])).toEqual([
      ['d-1', 'location', '2026-10-22T08:04:00.000Z'],
      ['d-2', 'location', '2026-10-22T08:04:00.000Z'],
    ]);
    // Points are not "updates waiting" for the rider.
    expect((await rider.snapshot()).pending).toBe(0);

    server.offline = false;
    await rider.flush();
    const sent = sentUpdates(server);
    expect(sent).toHaveLength(2);
    expect(sent[0]).toEqual({ id: queue[0].id, delivery_id: 'd-1', kind: 'location', latitude: HERE.latitude + 0.004, longitude: HERE.longitude, occurred_at: '2026-10-22T08:04:00.000Z' });
    expect(new Set(sent.map((u) => u.id)).size).toBe(2);
    expect((await store.meta())['rider:u-1'].queue).toEqual([]);
  });

  it('queues no point without an active delivery or a valid position', async () => {
    const { server, store, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    server.deliveries = [];
    await rider.refresh();
    expect(await rider.recordLocation(HERE)).toBe(0);
    server.deliveries = [DELIVERY];
    await rider.refresh();
    expect(await rider.recordLocation({ latitude: 91, longitude: 0 })).toBe(0);
    expect((await store.meta())['rider:u-1'].queue ?? []).toEqual([]);
  });

  it('drops a refused point without showing it to the rider', async () => {
    const { server, store, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    await rider.refresh();
    server.refuseLocation = 'delivery_not_active';
    await rider.recordLocation(HERE);
    await rider.flush();
    expect((await rider.snapshot()).rejected).toEqual([]);
    expect((await store.meta())['rider:u-1'].queue).toEqual([]);
    expect(sentUpdates(server)).toHaveLength(1);
  });

  it('takes the unsent point of a delivery that ends off the phone', async () => {
    const { server, store, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    server.deliveries = [{ ...DELIVERY, status: 'picked_up' }];
    await rider.refresh();
    server.offline = true;
    await rider.recordLocation(HERE);
    await rider.record('d-1', 'failed', { note: 'Gate closed' });
    expect((await store.meta())['rider:u-1'].queue.map((u) => u.kind)).toEqual(['status']);
  });
});

describe('rider location tracker (ECOM-29)', () => {
  function fakeLocation() {
    return {
      Accuracy: { Balanced: 3 },
      getCurrentPositionAsync: jest.fn(async () => ({ coords: { latitude: -1.28, longitude: 36.82 }, timestamp: Date.parse('2026-10-22T09:00:00Z') })),
    };
  }
  function fakeTimers() {
    const timers = {
      live: new Set(),
      setInterval: jest.fn((fn, ms) => {
        const id = { fn, ms };
        timers.live.add(id);
        return id;
      }),
      clearInterval: jest.fn((id) => timers.live.delete(id)),
    };
    return timers;
  }

  it('starts only with an active delivery, every 60 s at balanced accuracy, and stops when none is left or on sign-out', async () => {
    const { server, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    server.deliveries = [];
    await rider.refresh();
    const location = fakeLocation();
    const timers = fakeTimers();
    const tracker = createLocationTracker({ rider, location, timers });
    await tracker.start();
    tracker.setPermitted(true);
    expect(tracker.running()).toBe(false);

    server.deliveries = [{ ...DELIVERY, status: 'picked_up' }];
    await rider.refresh();
    expect(tracker.running()).toBe(true);
    expect([...timers.live][0].ms).toBe(60000);
    const sent = await waitForSent(server, 1);
    expect(sent[0]).toMatchObject({ kind: 'location', delivery_id: 'd-1', latitude: -1.28, longitude: 36.82, occurred_at: '2026-10-22T09:00:00.000Z' });
    expect(location.getCurrentPositionAsync).toHaveBeenCalledWith({ accuracy: 3 });

    // In the background nothing is read.
    tracker.setForeground(false);
    expect(tracker.running()).toBe(false);
    tracker.setForeground(true);
    expect(tracker.running()).toBe(true);

    // Delivered: no active delivery is left, the tracker stops.
    await rider.record('d-1', 'delivered', { code: '123456' });
    await rider.flush();
    expect(tracker.running()).toBe(false);
    expect(timers.live.size).toBe(0);

    server.deliveries = [DELIVERY];
    await rider.refresh();
    expect(tracker.running()).toBe(true);
    await rider.signOut();
    expect(tracker.running()).toBe(false);
    tracker.dispose();
  });

  it('never runs without permission and stops when the screen goes', async () => {
    const { rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    await rider.refresh();
    const tracker = createLocationTracker({ rider, location: fakeLocation(), timers: fakeTimers() });
    await tracker.start();
    expect(tracker.running()).toBe(false);
    tracker.setPermitted(true);
    expect(tracker.running()).toBe(true);
    tracker.dispose();
    expect(tracker.running()).toBe(false);
  });
});

describe('rider sign-out (L7, ECOM-29)', () => {
  async function queuedOffline() {
    const context = setup();
    await context.rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    context.server.deliveries = [{ ...DELIVERY, status: 'picked_up' }, { ...DELIVERY, id: 'd-2', status: 'assigned' }];
    await context.rider.refresh();
    context.server.offline = true;
    await context.rider.record('d-2', 'picked_up');
    expect(await context.rider.recordLocation({ latitude: -1.28, longitude: 36.82 })).toBe(2);
    const queue = (await context.store.meta())['rider:u-1'].queue;
    expect(queue.map((u) => u.kind).sort()).toEqual(['location', 'location', 'status']);
    return context;
  }

  it('drops the rider’s queued location points on sign-out and keeps the status updates', async () => {
    const { store, rider } = await queuedOffline();
    await rider.signOut();
    const kept = (await store.meta())['rider:u-1'];
    expect(kept.queue).toEqual([expect.objectContaining({ kind: 'status', delivery_id: 'd-2', status: 'picked_up' })]);
    expect(kept.deliveries).toEqual([]);
  });

  it('drops the queued location points when a 401 signs the rider out', async () => {
    const { server, store, credentials, rider } = await queuedOffline();
    server.offline = false;
    server.get = async () => ({ status: 401, body: { code: 'unauthenticated' } });
    server.post = async () => ({ status: 401, body: { code: 'unauthenticated' } });
    await rider.flush();
    expect(await credentials.token()).toBeNull();
    const kept = (await store.meta())['rider:u-1'];
    expect(kept.queue.map((u) => u.kind)).toEqual(['status']);
  });
});

async function waitForSent(server, length) {
  for (let i = 0; i < 50; i++) {
    const sent = sentUpdates(server).filter((update) => update.kind === 'location');
    if (sent.length >= length) return sent;
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
  throw new Error('no location point was sent');
}

describe('rider photo proof (ECOM-28)', () => {
  async function pickedUp() {
    const context = setup();
    await context.rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    context.server.deliveries = [{ ...DELIVERY, status: 'picked_up' }];
    await context.rider.refresh();
    return context;
  }

  it('uploads the photo before the code-less delivered update, and the server takes both', async () => {
    const { server, store, rider } = await pickedUp();
    server.offline = true;
    await rider.record('d-1', 'delivered', { photo_uri: 'file:///cache/proof-1.jpg', collected_minor: '136000', code: '999999' });
    const kept = (await store.meta())['rider:u-1'];
    expect(kept.photos).toEqual([expect.objectContaining({ delivery_id: 'd-1', uri: 'file:///cache/proof-1.jpg', taken_at: expect.any(String) })]);
    expect((await rider.snapshot()).pending).toBe(2);

    server.offline = false;
    server.posts = [];
    await rider.flush();
    expect(server.posts.map((p) => p.path)).toEqual(['ecommerce/rider/deliveries/d-1/photo', 'ecommerce/rider/updates']);
    expect(server.posts[0].form).toEqual({ photo: { uri: 'file:///cache/proof-1.jpg', name: 'proof.jpg', type: 'image/jpeg' } });
    const [delivered] = sentUpdates(server);
    expect(delivered).toMatchObject({ status: 'delivered', collected_minor: '136000' });
    expect(delivered.code).toBeUndefined();
    expect(delivered.photo_id).toBeUndefined();
    const state = await rider.snapshot();
    expect([state.pending, state.rejected, state.deliveries]).toEqual([0, [], []]);
    expect((await store.meta())['rider:u-1'].photos).toEqual([]);
  });

  it('keeps the photo and its update on a network error or a 5xx, and sends them later', async () => {
    const { server, store, rider } = await pickedUp();
    server.offline = true;
    await rider.record('d-1', 'delivered', { photo_uri: 'file:///cache/proof-1.jpg' });
    await rider.flush();
    let kept = (await store.meta())['rider:u-1'];
    expect([kept.photos.length, kept.queue.length]).toEqual([1, 1]);

    server.offline = false;
    server.photoAnswer = { status: 503, body: null };
    server.posts = [];
    await rider.flush();
    // The status update waits behind its photo.
    expect(server.posts.map((p) => p.path)).toEqual(['ecommerce/rider/deliveries/d-1/photo']);
    kept = (await store.meta())['rider:u-1'];
    expect([kept.photos.length, kept.queue.length]).toEqual([1, 1]);

    server.photoAnswer = null;
    await rider.flush();
    expect((await rider.snapshot()).pending).toBe(0);
    expect(sentUpdates(server).map((u) => u.status)).toEqual(['delivered']);
    expect((await rider.snapshot()).rejected).toEqual([]);
  });

  it('shows a refused photo to the rider and takes its delivered update off the queue', async () => {
    const { server, store, rider } = await pickedUp();
    server.offline = true;
    await rider.record('d-1', 'delivered', { photo_uri: 'file:///cache/proof-1.jpg' });
    server.offline = false;
    server.photoAnswer = { status: 422, body: { code: 'code_locked', message: 'Locked' } };
    server.posts = [];
    await rider.flush();
    expect(server.posts.map((p) => p.path)).toEqual(['ecommerce/rider/deliveries/d-1/photo']);
    const state = await rider.snapshot();
    expect(state.rejected).toEqual([expect.objectContaining({ kind: 'photo', error: 'code_locked', order_number: 'WEB-A-2026-00001', delivery_id: 'd-1' })]);
    expect(state.pending).toBe(0);
    // Back to on the way, so the rider can deliver again.
    expect(state.deliveries[0]).toMatchObject({ id: 'd-1', status: 'picked_up', queued: false });
    expect((await store.meta())['rider:u-1'].photos).toEqual([]);
  });

  it('names a validation refusal of the photo photo_invalid', async () => {
    const { server, rider } = await pickedUp();
    server.photoAnswer = { status: 422, body: { message: 'The photo is too large.', errors: { photo: ['The photo is too large.'] } } };
    await rider.record('d-1', 'delivered', { photo_uri: 'file:///cache/proof-1.jpg' });
    await rider.flush();
    expect((await rider.snapshot()).rejected).toEqual([expect.objectContaining({ error: 'photo_invalid' })]);
  });

  it('signs out on a 401 and keeps the photo for the next sign-in', async () => {
    const { server, store, credentials, rider } = await pickedUp();
    server.photoAnswer = { status: 401, body: { code: 'unauthenticated' } };
    await rider.record('d-1', 'delivered', { photo_uri: 'file:///cache/proof-1.jpg' });
    await rider.flush();
    expect(await credentials.token()).toBeNull();
    const kept = (await store.meta())['rider:u-1'];
    expect([kept.photos.length, kept.queue.length, kept.deliveries]).toEqual([1, 1, []]);
  });

  it('deletes the photo file once it is uploaded, and never while it is queued (L7)', async () => {
    const { server, store, rider, deleteFile } = await pickedUp();
    server.offline = true;
    await rider.record('d-1', 'delivered', { photo_uri: 'file:///cache/proof-1.jpg' });
    await rider.flush();
    expect(deleteFile).not.toHaveBeenCalled();

    server.offline = false;
    server.photoAnswer = { status: 503, body: null };
    await rider.flush();
    server.photoAnswer = { status: 429, body: null };
    await rider.flush();
    expect(deleteFile).not.toHaveBeenCalled();
    expect((await store.meta())['rider:u-1'].photos).toHaveLength(1);

    server.photoAnswer = null;
    await rider.flush();
    expect(deleteFile).toHaveBeenCalledTimes(1);
    expect(deleteFile).toHaveBeenCalledWith('file:///cache/proof-1.jpg');
  });

  it('deletes the photo file once it is refused (L7)', async () => {
    const { server, rider, deleteFile } = await pickedUp();
    server.photoAnswer = { status: 422, body: { code: 'code_locked', message: 'Locked' } };
    await rider.record('d-1', 'delivered', { photo_uri: 'file:///cache/proof-1.jpg' });
    await rider.flush();
    expect(deleteFile).toHaveBeenCalledWith('file:///cache/proof-1.jpg');
    expect((await rider.snapshot()).rejected).toEqual([expect.objectContaining({ kind: 'photo', error: 'code_locked' })]);
  });

  it('keeps the queue moving when a photo file cannot be deleted (L7)', async () => {
    const { server, rider, deleteFile } = await pickedUp();
    deleteFile.mockRejectedValue(new Error('gone'));
    await rider.record('d-1', 'delivered', { photo_uri: 'file:///cache/proof-1.jpg' });
    await rider.flush();
    expect(deleteFile).toHaveBeenCalled();
    expect(sentUpdates(server).map((u) => u.status)).toEqual(['delivered']);
    expect((await rider.snapshot()).pending).toBe(0);
  });

  it('keeps the photo file on a 401 (L7)', async () => {
    const { server, rider, deleteFile } = await pickedUp();
    server.photoAnswer = { status: 401, body: { code: 'unauthenticated' } };
    await rider.record('d-1', 'delivered', { photo_uri: 'file:///cache/proof-1.jpg' });
    await rider.flush();
    expect(deleteFile).not.toHaveBeenCalled();
  });

  it('picks a moderate picture size', () => {
    expect(pickPictureSize(['3840x2160', '1920x1080', '1280x720', '640x480'])).toBe('1280x720');
    expect(pickPictureSize(['Photo', 'High', 'Medium', 'Low'])).toBe('Medium');
    expect(pickPictureSize([])).toBeUndefined();
  });
});

describe('api upload (ECOM-28)', () => {
  it('posts the form with the bearer token and language, sets no Content-Type, and never throws on a status', async () => {
    const calls = [];
    const fetchImpl = async (url, init) => {
      calls.push({ url, init });
      return { status: 409, text: async () => JSON.stringify({ code: 'delivery_status_invalid' }), headers: { get: () => null } };
    };
    const api = createApiClient({ baseUrl: 'https://api.test/', getToken: async () => 'rider-token', getLocale: () => 'fr', fetchImpl });
    const form = { marker: true };
    const answer = await api.upload('ecommerce/rider/deliveries/d-1/photo', form);
    expect(answer).toMatchObject({ status: 409, body: { code: 'delivery_status_invalid' } });
    expect(calls[0].url).toBe('https://api.test/api/v1/ecommerce/rider/deliveries/d-1/photo');
    expect(calls[0].init.body).toBe(form);
    expect(calls[0].init.headers).toEqual({ Accept: 'application/json', 'Accept-Language': 'fr', Authorization: 'Bearer rider-token' });

    const offline = createApiClient({
      baseUrl: 'https://api.test',
      getToken: async () => null,
      fetchImpl: async () => {
        throw new TypeError('Network request failed');
      },
    });
    await expect(offline.upload('x', form)).rejects.toBeInstanceOf(NetworkError);
  });
});
