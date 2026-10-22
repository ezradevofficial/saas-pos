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
 *   sign-in, except its location points (see below).
 *
 * A phone may be shared: everything is kept per rider (meta key
 * `rider:{user id}`: queue, rejected, deliveries, fetchedAt), only the
 * signed-in rider's queue is uploaded, and signing out (or a 401) drops
 * their cached deliveries (customers' names, phones and addresses) and their
 * unsent location points from the phone; status updates and photos stay
 * queued for their next sign-in. A
 * customer's delivery code is kept only until its update is answered.
 *
 * Location (ECOM-29, KE DPA minimum data): while a delivery is active the
 * screen's location tracker queues `location` updates here. At most one
 * unsent point per delivery is kept (a newer one replaces it), so a long
 * offline spell never piles points up on the phone; a refused point is
 * dropped without telling the rider.
 *
 * Photo proof (ECOM-28): a delivery photo is kept on the phone ({id,
 * delivery_id, uri, taken_at}) and uploaded before the status updates,
 * because the server accepts a `delivered` update without a code only once
 * that delivery's photo is in. A refused photo is shown to the rider and its
 * code-less `delivered` update leaves the queue with it, so the rider can
 * deliver again. The photo's file is deleted from the phone as soon as it is
 * uploaded or refused (`deleteFile(uri)`, failures ignored); a photo still
 * queued (offline, a 5xx or 429, a 401) keeps its file.
 */
export const BATCH = 50;
export const ACTIVE = ['assigned', 'picked_up'];
const FINAL = ['delivered', 'failed'];

export function createRider({ api, store, credentials, now = () => Date.now(), makeId = uuidv7, makeForm = defaultForm, deleteFile = async () => {} }) {
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
      photos: mine.photos ?? [],
      fetchedAt: mine.fetchedAt ?? null,
    };
  }

  /** Merge $patch into $userId's state (call inside locked()). */
  async function write(userId, patch) {
    if (!userId) return;
    const current = (await store.meta())[keyOf(userId)] ?? {};
    await store.setMeta({ [keyOf(userId)]: { ...current, ...patch } });
  }

  /**
   * On sign-out: the signed-in rider's cached deliveries and unsent location
   * points leave the phone (minimum data); their status updates and photos
   * stay queued for their next sign-in.
   */
  async function forgetSession() {
    await locked(async () => {
      const { userId, queue } = await read();
      await write(userId, { deliveries: [], fetchedAt: null, queue: queue.filter((update) => update.kind !== 'location') });
    });
  }

  /** The photo's file leaves the phone; a failure never holds the queue up. */
  async function dropFile(uri) {
    if (!uri) return;
    try {
      await deleteFile(uri);
    } catch {
      // Left for the OS to clear (the camera writes to the app's cache).
    }
  }

  async function emit() {
    const state = await snapshot();
    listeners.forEach((listener) => listener(state));
  }

  /** The deliveries as the rider sees them: the server's list with queued changes applied. */
  async function snapshot() {
    const { queue, rejected, deliveries, fetchedAt, photos } = await read();
    const shown = deliveries
      .map((delivery) => {
        const mine = queue.filter((update) => update.delivery_id === delivery.id && update.kind === 'status');
        const last = mine[mine.length - 1];
        return last ? { ...delivery, status: last.status, queued: true } : { ...delivery, queued: false };
      })
      .filter((delivery) => ACTIVE.includes(delivery.status) || delivery.queued);
    // Location points are not counted: the rider waits on status changes and photos only.
    const pending = queue.filter((update) => update.kind !== 'location').length + photos.length;
    return { deliveries: shown, pending, rejected, fetchedAt, network: flushing ? 'syncing' : network };
  }

  async function signedOut() {
    await forgetSession();
    await credentials.clear();
    listeners.forEach((listener) => listener({ signedOut: true }));
  }

  /**
   * Upload the queued photos one by one. Returns null when they are all
   * settled, or the flush's answer when it has to stop (offline, a 5xx or
   * 429, signed out): the status updates then wait, since a code-less
   * `delivered` needs its photo first.
   */
  async function flushPhotos() {
    for (;;) {
      const { photos, userId } = await read();
      if (!userId || !photos.length) return null;
      const photo = photos[0];
      let response;
      try {
        response = await api.upload(`ecommerce/rider/deliveries/${encodeURIComponent(photo.delivery_id)}/photo`, makeForm(photo));
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
      if (response.status >= 500 || response.status === 429) {
        network = 'offline';
        return { sent: false, status: response.status };
      }
      const accepted = response.status >= 200 && response.status < 300;
      const settled = await locked(async () => {
        const current = await read();
        if (current.userId !== userId) return false;
        const patch = { photos: current.photos.filter((kept) => kept.id !== photo.id) };
        if (!accepted) {
          // Refused for good (not picked up, code locked, not a usable image, not the rider's): shown to the
          // rider, and the delivered update waiting on this photo goes too, so the delivery can be delivered again.
          const body = response.body ?? {};
          const code = body.code ?? (body.errors?.photo ? 'photo_invalid' : response.status === 404 ? 'delivery_unknown' : 'failed');
          patch.queue = current.queue.filter((update) => update.photo_id !== photo.id);
          patch.rejected = [
            ...current.rejected,
            { id: photo.id, delivery_id: photo.delivery_id, kind: 'photo', order_number: photo.order_number ?? null, error: code, message: body.message ?? null },
          ].slice(-20);
        }
        await write(userId, patch);
        return true;
      });
      // Uploaded or refused for good: the file is no longer needed on the phone.
      if (settled) await dropFile(photo.uri);
      network = 'online';
      await emit();
    }
  }

  /** Upload the photos, then the queue in batches, until both are empty or the server is not reached. */
  function flush() {
    if (flushing) return flushing;
    flushing = (async () => {
      try {
        const stopped = await flushPhotos();
        if (stopped) return stopped;
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
              // A refused location point (the delivery ended, or anything else) is dropped without a word.
              else if (result?.status === 'rejected' && update.kind === 'location') stored.add(update.id);
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
      await forgetSession();
      await credentials.clear();
      // The location tracker stops on this.
      listeners.forEach((listener) => listener({ signedOut: true }));
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
      // ECOM-28: a photo proof (extra.photo_uri) takes the code's place; the photo is queued first, then this update.
      const photo =
        status === 'delivered' && extra.photo_uri
          ? { id: makeId(now()), delivery_id: deliveryId, uri: String(extra.photo_uri), taken_at: extra.taken_at ?? new Date(now()).toISOString() }
          : null;
      const update = {
        id: makeId(now()),
        delivery_id: deliveryId,
        kind: 'status',
        status,
        occurred_at: new Date(now()).toISOString(),
        ...pick(photo ? { ...extra, code: null } : extra, ['code', 'collected_minor', 'note']),
      };
      await locked(async () => {
        const { queue, deliveries, photos, userId } = await read();
        if (!userId) throw new Error('rider_signed_out');
        // Kept on the phone only (never sent): the order number to name a refusal by.
        const number = deliveries.find((delivery) => delivery.id === deliveryId)?.order_number ?? null;
        // A delivery that ends takes its unsent location point with it (minimum data).
        const kept = FINAL.includes(status) ? queue.filter((queued) => !(queued.kind === 'location' && queued.delivery_id === deliveryId)) : queue;
        const patch = { queue: [...kept, { ...update, order_number: number, ...(photo ? { photo_id: photo.id } : {}) }] };
        if (photo) patch.photos = [...photos, { ...photo, order_number: number }];
        await write(userId, patch);
      });
      await emit();
      flush().catch(() => {});
      return update;
    },

    /**
     * ECOM-29: queue the rider's position for each active delivery. An unsent
     * point for the same delivery is replaced, never added to. Resolves to
     * the number of deliveries the point was queued for.
     */
    async recordLocation({ latitude, longitude, occurred_at: occurredAt }) {
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) return 0;
      const at = occurredAt ?? new Date(now()).toISOString();
      const count = await locked(async () => {
        const current = await read();
        if (!current.userId) return 0;
        const active = (await snapshot()).deliveries.filter((delivery) => ACTIVE.includes(delivery.status));
        if (!active.length) return 0;
        const ids = new Set(active.map((delivery) => delivery.id));
        const points = active.map((delivery) => ({ id: makeId(now()), delivery_id: delivery.id, kind: 'location', latitude, longitude, occurred_at: at }));
        await write(current.userId, { queue: [...current.queue.filter((update) => !(update.kind === 'location' && ids.has(update.delivery_id))), ...points] });
        return points.length;
      });
      if (count) flush().catch(() => {});
      return count;
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
  const { error: _error, message: _message, order_number: _number, photo_id: _photo, ...wire } = update;
  return wire;
}

/** ECOM-28: the multipart body of a photo upload (React Native's FormData takes a file as {uri, name, type}). */
function defaultForm(photo) {
  const form = new FormData();
  form.append('photo', { uri: photo.uri, name: 'proof.jpg', type: 'image/jpeg' });
  return form;
}

/**
 * ECOM-28: a moderate picture size from the camera's list ("1920x1080" on
 * Android; presets and sizes on iOS): the largest whose long side is at most
 * 1600 px, else the "Medium" preset, else the camera's default (undefined).
 */
export function pickPictureSize(sizes = []) {
  let best;
  let bestArea = 0;
  for (const size of sizes ?? []) {
    const match = /^(\d+)x(\d+)$/.exec(String(size));
    if (!match) continue;
    const width = Number(match[1]);
    const height = Number(match[2]);
    if (Math.max(width, height) > 1600 || width * height <= bestArea) continue;
    best = size;
    bestArea = width * height;
  }
  if (best) return best;
  return (sizes ?? []).includes('Medium') ? 'Medium' : undefined;
}
