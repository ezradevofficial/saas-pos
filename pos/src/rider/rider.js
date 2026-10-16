import { uuidv7 } from '../lib/random';
import { NetworkError } from '../sync/api';

/**
 * ECOM-28, ECOM-29, NFR-04: the rider screen's data. The rider signs in as
 * themselves (decision 7). Their open deliveries are kept on the phone, and
 * every status change goes into a queue persisted on the phone before the
 * screen shows it, then uploads in batches to `POST ecommerce/rider/updates`.
 * Each update carries the UUID v7 made here, so a resend after a lost answer
 * is answered the same way by the server and never applied twice.
 *
 * Outcomes per update:
 * - `stored`: done, leaves the queue.
 * - `rejected`: leaves the queue and is kept with the server's reason (a
 *   wrong delivery code, a delivery taken back), so the rider sees it; a
 *   new attempt is a new update with a new id.
 * - no answer (offline, a 5xx, a 429): stays queued and is sent again.
 * - 401: the rider is signed out; the queue stays on the phone for the next
 *   sign-in.
 *
 * A phone may be shared: everything is kept per rider (meta key
 * `rider:{user id}`: queue, rejected, deliveries, fetchedAt), only the
 * signed-in rider's queue is uploaded, and signing out drops their cached
 * deliveries (customers' names, phones and addresses) from the phone. A
 * customer's delivery code is kept only until its update is answered.
 */
export const BATCH = 50;

export function createRider({ api, store, credentials, now = () => Date.now(), makeId = uuidv7 }) {
  // One write at a time on the rider keys, so an update queued during an upload is never lost.
  let chain = Promise.resolve();
  const locked = (task) => {
    const run = chain.then(task, task);
    chain = run.catch(() => {});
    return run;
  };
  const listeners = new Set();
  let flushing = null;
  let network = 'online';

  const keyOf = (userId) => `rider:${userId}`;

  /** The signed-in rider's state (empty while nobody is signed in). */
  async function read() {
    const userId = (await credentials.user())?.id ?? null;
    const mine = userId ? ((await store.meta())[keyOf(userId)] ?? {}) : {};
    return {
      userId,
      queue: mine.queue ?? [],
      rejected: mine.rejected ?? [],
      deliveries: mine.deliveries ?? [],
      fetchedAt: mine.fetchedAt ?? null,
    };
  }

  /** Merge $patch into $userId's state (call inside locked()). */
  async function write(userId, patch) {
    if (!userId) return;
    const current = (await store.meta())[keyOf(userId)] ?? {};
    await store.setMeta({ [keyOf(userId)]: { ...current, ...patch } });
  }

  /** The signed-in rider's cached deliveries leave the phone (their queue stays for them). */
  async function forgetDeliveries() {
    await locked(async () => {
      const { userId } = await read();
      await write(userId, { deliveries: [], fetchedAt: null });
    });
  }

  async function emit() {
    const state = await snapshot();
    listeners.forEach((listener) => listener(state));
  }

  /** The deliveries as the rider sees them: the server's list with queued changes applied. */
  async function snapshot() {
    const { queue, rejected, deliveries, fetchedAt } = await read();
    const shown = deliveries
      .map((delivery) => {
        const mine = queue.filter((update) => update.delivery_id === delivery.id && update.kind === 'status');
        const last = mine[mine.length - 1];
        return last ? { ...delivery, status: last.status, queued: true } : { ...delivery, queued: false };
      })
      .filter((delivery) => delivery.status === 'assigned' || delivery.status === 'picked_up' || delivery.queued);
    return { deliveries: shown, pending: queue.length, rejected, fetchedAt, network: flushing ? 'syncing' : network };
  }

  async function signedOut() {
    await forgetDeliveries();
    await credentials.clear();
    listeners.forEach((listener) => listener({ signedOut: true }));
  }

  /** Upload the queue in batches until it is empty or the server is not reached. */
  function flush() {
    if (flushing) return flushing;
    flushing = (async () => {
      try {
        for (;;) {
          const { queue, userId } = await read();
          if (!userId || !queue.length) {
            network = 'online';
            return { sent: true };
          }
          const batch = queue.slice(0, BATCH);
          let response;
          try {
            response = await api.post('ecommerce/rider/updates', { updates: batch.map(toWire) });
          } catch (error) {
            if (error instanceof NetworkError) {
              network = 'offline';
              return { sent: false };
            }
            throw error;
          }
          if (response.status === 401) {
            await signedOut();
            return { sent: false, signedOut: true };
          }
          const results = response.body?.results;
          if (!Array.isArray(results)) {
            // A 5xx, a 429 or a validation refusal of the whole batch: keep it and try later.
            network = response.status >= 500 || response.status === 429 ? 'offline' : network;
            return { sent: false, status: response.status };
          }
          const removed = await locked(async () => {
            const current = await read();
            if (current.userId !== userId) return 0;
            const outcome = new Map(results.map((result) => [result.id, result]));
            const stored = new Set();
            const refused = [];
            for (const update of batch) {
              const result = outcome.get(update.id);
              if (result?.status === 'stored') stored.add(update.id);
              else if (result?.status === 'rejected' && !result.error?.retryable) {
                stored.add(update.id);
                // Kept to show the rider why, without the customer's code.
                const { code: _code, ...kept } = update;
                refused.push({ ...kept, error: result.error?.code ?? 'failed', message: result.error?.message ?? null });
              }
            }
            // An applied change is the delivery's status until the next fetch (delivered and failed ones drop off).
            const applied = new Map(batch.filter((update) => outcome.get(update.id)?.status === 'stored' && update.kind === 'status').map((update) => [update.delivery_id, update.status]));
            await write(userId, {
              deliveries: current.deliveries.map((delivery) => (applied.has(delivery.id) ? { ...delivery, status: applied.get(delivery.id) } : delivery)),
              queue: current.queue.filter((update) => !stored.has(update.id)),
              rejected: [...current.rejected, ...refused].slice(-20),
            });
            return stored.size;
          });
          network = 'online';
          await emit();
          // Nothing settled (only retryable answers): try again later rather than loop.
          if (!removed) return { sent: false };
        }
      } finally {
        flushing = null;
        await emit();
      }
    })();
    return flushing;
  }

  return {
    async signIn({ login, password }) {
      let response;
      try {
        response = await api.post('auth/sign-in', { login, password, device_name: 'rider' }, { auth: false });
      } catch (error) {
        if (error instanceof NetworkError) return { ok: false, error: 'offline' };
        throw error;
      }
      if (response.status === 200 && response.body?.token) {
        await credentials.save({ token: response.body.token, user: response.body.user });
        return { ok: true, user: response.body.user };
      }
      if (response.status === 200 && response.body?.status) return { ok: false, error: 'two_factor' };
      return { ok: false, error: response.body?.code ?? 'failed', message: response.body?.message ?? null };
    },

    async signOut() {
      try {
        await api.post('auth/sign-out', {});
      } catch {
        // offline: the token is dropped on the phone anyway
      }
      await forgetDeliveries();
      await credentials.clear();
    },

    user: () => credentials.user(),
    snapshot,

    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },

    /** Fetch the rider's open deliveries; offline, the kept list stays. */
    async refresh() {
      await flush();
      let response;
      try {
        response = await api.get('ecommerce/rider/deliveries');
      } catch (error) {
        if (error instanceof NetworkError) {
          network = 'offline';
          await emit();
          return { ok: false, error: 'offline' };
        }
        throw error;
      }
      if (response.status === 401) {
        await signedOut();
        return { ok: false, error: 'signed_out' };
      }
      if (response.status !== 200) return { ok: false, error: response.status === 403 ? 'forbidden' : 'failed' };
      await locked(async () => {
        const { userId } = await read();
        await write(userId, { deliveries: response.body?.data ?? [], fetchedAt: new Date(now()).toISOString() });
      });
      network = 'online';
      await emit();
      return { ok: true };
    },

    /**
     * Queue a status change. It is on the phone when this resolves; the
     * upload follows when the server is reached.
     */
    async record(deliveryId, status, extra = {}) {
      const update = {
        id: makeId(now()),
        delivery_id: deliveryId,
        kind: 'status',
        status,
        occurred_at: new Date(now()).toISOString(),
        ...pick(extra, ['code', 'collected_minor', 'note']),
      };
      await locked(async () => {
        const { queue, deliveries, userId } = await read();
        if (!userId) throw new Error('rider_signed_out');
        // Kept on the phone only (never sent): the order number to name a refusal by.
        const number = deliveries.find((delivery) => delivery.id === deliveryId)?.order_number ?? null;
        await write(userId, { queue: [...queue, { ...update, order_number: number }] });
      });
      await emit();
      flush().catch(() => {});
      return update;
    },

    /** Hide a refusal the rider has read. */
    async dismiss(id) {
      await locked(async () => {
        const { rejected, userId } = await read();
        await write(userId, { rejected: rejected.filter((update) => update.id !== id) });
      });
      await emit();
    },

    flush,
  };
}

function pick(values, keys) {
  const out = {};
  for (const key of keys) {
    const value = values[key];
    if (value !== undefined && value !== null && String(value).trim() !== '') out[key] = String(value).trim();
  }
  return out;
}

function toWire(update) {
  const { error: _error, message: _message, order_number: _number, ...wire } = update;
  return wire;
}
