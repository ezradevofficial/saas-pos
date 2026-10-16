import { createRiderCredentials, memoryBackend } from '../device/credentials';
import { NetworkError } from '../sync/api';
import { createSyncStore } from '../sync/store';
import { testDatabase } from '../test/testDatabase';
import { createRider } from './rider';

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
      if (path === 'auth/sign-in') return { status: 200, body: { token: 'user-token', user: { id: 'u-1', name: 'Juma Rider' } } };
      if (path !== 'ecommerce/rider/updates') return { status: 204, body: null };
      const results = body.updates.map((update) => {
        if (applied.has(update.id)) return applied.get(update.id);
        const code = server.refuse[update.status];
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
    applied,
  };
  return server;
}

let ids = 0;

function setup() {
  const server = fakeRiderServer();
  const store = createSyncStore(testDatabase());
  const credentials = createRiderCredentials(memoryBackend());
  const rider = createRider({ api: server, store, credentials, makeId: () => `0192a5a0-0000-7000-8000-${String(++ids).padStart(12, '0')}` });
  return { server, store, credentials, rider };
}

describe('rider queue (ECOM-28, NFR-04)', () => {
  it('signs in with the rider’s own token and keeps the deliveries for offline use', async () => {
    const { server, credentials, rider } = setup();
    expect((await rider.signIn({ login: 'juma@example.test', password: 'secret-password' })).ok).toBe(true);
    expect(await credentials.token()).toBe('user-token');
    expect(await rider.refresh()).toEqual({ ok: true });

    server.offline = true;
    expect(await rider.refresh()).toEqual({ ok: false, error: 'offline' });
    const state = await rider.snapshot();
    expect(state.deliveries.map((d) => d.order_number)).toEqual(['WEB-A-2026-00001']);
    expect(state.network).toBe('offline');
  });

  it('keeps updates made offline on the phone, in order, and sends them once the server is back', async () => {
    const { server, store, rider } = setup();
    await rider.refresh();
    server.offline = true;
    await rider.record('d-1', 'picked_up');
    await rider.record('d-1', 'delivered', { code: '123456', collected_minor: '136000' });
    await rider.flush();

    let state = await rider.snapshot();
    expect(state.pending).toBe(2);
    expect(state.deliveries[0]).toMatchObject({ status: 'delivered', queued: true });
    expect((await store.meta()).riderQueue).toHaveLength(2);

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
    const { server, rider } = setup();
    server.deliveries = [{ ...DELIVERY, status: 'picked_up' }];
    await rider.refresh();
    server.refuse.delivered = 'code_invalid';
    await rider.record('d-1', 'delivered', { code: '000000' });
    await rider.flush();

    const state = await rider.snapshot();
    expect(state.pending).toBe(0);
    expect(state.rejected).toEqual([expect.objectContaining({ error: 'code_invalid', order_number: 'WEB-A-2026-00001', delivery_id: 'd-1' })]);
    await rider.dismiss(state.rejected[0].id);
    expect((await rider.snapshot()).rejected).toEqual([]);
  });

  it('signs the rider out on a 401 and keeps the queue on the phone', async () => {
    const { server, credentials, rider } = setup();
    await rider.signIn({ login: 'juma@example.test', password: 'secret-password' });
    await rider.refresh();
    server.post = async () => ({ status: 401, body: { code: 'unauthenticated' } });
    const seen = [];
    rider.subscribe((state) => seen.push(state));
    await rider.record('d-1', 'picked_up');
    await rider.flush();
    expect(await credentials.token()).toBeNull();
    expect(seen.some((state) => state.signedOut)).toBe(true);
    expect((await rider.snapshot()).pending).toBe(1);
  });
});
