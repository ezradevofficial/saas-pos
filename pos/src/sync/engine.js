import { NetworkError } from './api';
import { entityDefinition } from './entities';
import { pushKind } from './pushKinds';
import { OUTBOX } from './store';

/**
 * NFR-04, ADR 004: the till's sync engine. Plain JavaScript over an API
 * client (src/sync/api.js) and a store (src/sync/store.js), so it runs in
 * Jest without React Native.
 *
 * A run (sync()): bootstrap when needed, push, pull, then report offline
 * PIN attempts (a report failure never aborts the run).
 *
 * - bootstrap(): GET sync/bootstrap: entities, page size, PIN rules, the
 *   server clock (skew), the current device secret's kid (a pending
 *   rotated secret the server already activated is promoted here).
 * - pull(): GET sync/pull per entity from its cursor, looping while
 *   has_more. reset and snapshot replace keep old rows until the last
 *   page; tombstones remove. A refused cursor starts the entity over; a
 *   refused entity list, or a pull whose `entities_fingerprint` differs
 *   from bootstrap's (a module switched on or off mid-session), bootstraps
 *   again once, and the entities now offered are pulled in the same run.
 * - push(): uploads the outbox in enqueue order, one run of a kind per
 *   request. A row of a group (a shift) waits while an earlier row of the
 *   group is still pending.
 *     stored → acknowledged;
 *     rejected and not retryable, or a 409/422 with a JSON `code` → failed
 *       with the reason, kept for review (a batch refused as a whole is
 *       split to find the record at fault); the queue goes on;
 *     5xx, 429, 408 → retried later (Retry-After honoured), the run stops;
 *     anything else (400, 404, non-JSON, 403 module_inactive) → retried later
 *       (a module other than the POS: its rows only, the run goes on).
 *   Retries use exponential backoff with jitter. A forced run ignores the
 *   backoff, and so does a retry time beyond the longest backoff (the
 *   clock was corrected).
 * - Access lost: a 401, or a 403 naming the device's token or status. The
 *   engine stops and says so; nothing local is deleted.
 * - No answer at all (offline): nothing changes locally; the scheduler
 *   tries again on reconnect.
 * - Subscription (SUB-05): bootstrap and pull carry when the POS stops
 *   (`pos_stops_at`) or that it has (`pos_stopped`); a 402 upload says so
 *   too. Both are kept in the store, so sellingPaused() holds offline and
 *   after a restart; a later answer without them resumes selling.
 */
export const AUTH = { OK: 'ok', LOST: 'lost' };
export const NETWORK = { UNKNOWN: 'unknown', ONLINE: 'online', OFFLINE: 'offline' };

/** 403 codes that mean the device itself is refused (others are about the request). */
export const DEVICE_ACCESS_CODES = new Set(['device_suspended', 'device_unpaired', 'device_token_required', 'device_token_invalid']);

const DAY = 24 * 60 * 60 * 1000;
const DEFAULTS = { baseBackoffMs: 5000, maxBackoffMs: 15 * 60 * 1000, maxPages: 10000, reportBatch: 100, keepAcknowledgedMs: 30 * DAY };

export class AuthLostError extends Error {
  constructor(status, code) {
    super('auth_lost');
    this.name = 'AuthLostError';
    this.status = status;
    this.code = code;
  }
}

/** Retry-After in ms (seconds or an HTTP date), or 0. */
export function retryAfterMs(value, now) {
  if (value == null || value === '') return 0;
  const seconds = Number(value);
  if (Number.isFinite(seconds)) return Math.max(0, seconds * 1000);
  const at = Date.parse(value);
  return Number.isFinite(at) ? Math.max(0, at - now) : 0;
}

const hasJsonCode = (response) => typeof response.body?.code === 'string' && response.body.code !== '';

export function createSyncEngine({ api, store, credentials = null, now = () => Date.now(), random = Math.random, options = {}, log = () => {}, afterPull = null }) {
  const settings = { ...DEFAULTS, ...options };
  const listeners = new Set();
  let status = {
    network: NETWORK.UNKNOWN,
    syncing: false,
    pending: 0,
    failed: 0,
    lastSyncedAt: null,
    lastPushedAt: null,
    lastPulledAt: null,
    auth: AUTH.OK,
    authCode: null,
    moduleInactive: false,
    // SUB-05: the subscription is overdue past the POS window (a 402, or the server says so);
    // uploads wait until it is paid and selling is paused (sellingPaused()).
    subscriptionStopped: false,
    // SUB-05: when the server will stop the POS (ms), from bootstrap and pull; checked offline.
    posStopsAt: null,
    skewMs: 0,
    secretMissing: false,
    lastError: null,
  };
  let running = null;

  function update(patch) {
    status = { ...status, ...patch };
    listeners.forEach((listener) => listener(status));
  }

  async function refreshCounts() {
    const counts = await store.counts();
    update(counts);
    return counts;
  }

  /** Throws AuthLostError when the device's access is gone; any answer means online. */
  async function checkAnswer(response) {
    update({ network: NETWORK.ONLINE });
    const code = typeof response.body?.code === 'string' ? response.body.code : null;
    if (response.status === 401 || (response.status === 403 && DEVICE_ACCESS_CODES.has(code))) {
      const authCode = code ?? 'unauthenticated';
      update({ auth: AUTH.LOST, authCode });
      await store.setMeta({ auth: AUTH.LOST, authCode });
      throw new AuthLostError(response.status, authCode);
    }
    return response;
  }

  async function call(method, path, options) {
    let response;
    try {
      response = await api.request(method, path, options);
    } catch (error) {
      if (error instanceof NetworkError) update({ network: NETWORK.OFFLINE });
      throw error;
    }
    return checkAnswer(response);
  }

  function skewFrom(serverTime, startedAt, endedAt) {
    const server = Date.parse(serverTime ?? '');
    return Number.isFinite(server) ? Math.round(server - (startedAt + endedAt) / 2) : null;
  }

  /**
   * SUB-05: the subscription as bootstrap and pull report it. A stop time
   * is kept (the till checks it offline); `pos_stopped` pauses selling at
   * once; an answer without either (paid again) lifts the pause.
   */
  async function applySubscription(body) {
    if (!body || !Object.prototype.hasOwnProperty.call(body, 'subscription')) return;
    const info = body.subscription;
    const stopsAt = info?.pos_stops_at ? Date.parse(info.pos_stops_at) : NaN;
    const patch = { subscriptionStopped: Boolean(info?.pos_stopped), posStopsAt: Number.isFinite(stopsAt) ? stopsAt : null };
    if (patch.subscriptionStopped === status.subscriptionStopped && patch.posStopsAt === status.posStopsAt) return;
    update(patch);
    await store.setMeta(patch);
  }

  function backoff(attempts) {
    const exponential = Math.min(settings.maxBackoffMs, settings.baseBackoffMs * 2 ** Math.max(0, attempts));
    return Math.round(exponential / 2 + (exponential / 2) * random());
  }

  // -- Bootstrap ------------------------------------------------------------

  async function bootstrap() {
    const startedAt = now();
    const response = await call('GET', 'sync/bootstrap');
    if (response.status !== 200 || !response.body) throw serverError(response);
    const body = response.body;
    if (!Array.isArray(body.entities)) throw serverError({ status: response.status, body: { code: 'invalid_bootstrap' } });
    const skewMs = skewFrom(body.server_time, startedAt, now()) ?? status.skewMs;
    const offered = body.entities.filter((entity) => entity && typeof entity.key === 'string' && entityDefinition(entity.key));
    const skipped = body.entities.filter((entity) => !entityDefinition(entity?.key)).map((entity) => entity?.key);
    if (skipped.length) log('sync: entities not known to this app version', skipped);

    // Entities the server no longer offers (a module switched off) leave the device.
    const known = await store.entityStates();
    for (const key of Object.keys(known)) {
      if (!offered.some((entity) => entity.key === key)) await store.dropEntity(key);
    }

    const secretKid = secretKidOf(body);
    const secretMissing = await reconcileSecret(secretKid);

    if (body.device) await store.saveDevice(body.device);
    await store.setMeta({
      entities: offered.map(({ key, mode, module, version }) => ({ key, mode, module, version })),
      entitiesFingerprint: typeof body.entities_fingerprint === 'string' ? body.entities_fingerprint : null,
      pageSize: body.page_size,
      maxPageSize: body.max_page_size,
      pin: body.pin ?? null,
      skewMs,
      bootstrappedAt: now(),
      secretKid,
      secretMissing,
      auth: AUTH.OK,
      authCode: null,
    });
    update({ skewMs, secretMissing, auth: AUTH.OK, authCode: null });
    await applySubscription(body);
    return body;
  }

  /**
   * AUTH-06: a rotated secret whose activation answer was lost is promoted
   * when the server says it is current. Resolves whether the till lacks
   * the server's current secret (offline sign-in and overrides then fail:
   * unpair and pair again).
   */
  async function reconcileSecret(serverKid) {
    if (serverKid === null) return true;
    if (!credentials) return false;
    const pending = await credentials.pending();
    if (pending && pending.kid === serverKid) await credentials.promotePending();
    const current = await credentials.secret();
    return !current || (serverKid !== 'unknown' && current.kid !== null && current.kid !== serverKid);
  }

  // -- Pull -----------------------------------------------------------------

  /**
   * Pull every offered entity, or those of `mode` ('incremental' |
   * 'snapshot'), or the `keys` given. Resolves with per-entity counts.
   */
  async function pull({ mode, keys } = {}) {
    let meta = await store.meta();
    if (!meta.entities) {
      await bootstrap();
      meta = await store.meta();
    }
    const choose = (entities) => entities.filter((entity) => (!mode || entity.mode === mode) && (!keys || keys.includes(entity.key))).map((entity) => entity.key);
    let wanted = choose(meta.entities);
    const pending = new Set(wanted);
    const counts = Object.fromEntries(wanted.map((key) => [key, { upserts: 0, tombstones: 0, pages: 0 }]));
    let pages = 0;
    let rebootstrapped = false;

    while (pending.size && pages < settings.maxPages) {
      pages += 1;
      const entities = [...pending];
      const cursors = await store.cursors(entities);
      const startedAt = now();
      const response = await call('GET', 'sync/pull', { query: { entities, cursors, limit: meta.pageSize ?? undefined } });

      if (response.status === 422 && response.body?.code === 'invalid_cursor') {
        // A cursor the server does not accept: start that entity over.
        const entity = response.body.entity ?? Object.keys(response.body.errors ?? {})[0]?.replace(/^cursors\./, '');
        if (entity && pending.has(entity)) {
          await store.dropEntity(entity);
          continue;
        }
      }
      if (response.status === 422 && !rebootstrapped) {
        // The entity list is out of date (a module switched off, an entity removed): ask again, once.
        rebootstrapped = true;
        await bootstrap();
        meta = await store.meta();
        wanted = choose(meta.entities);
        for (const key of [...pending]) if (!wanted.includes(key)) pending.delete(key);
        continue;
      }
      if (response.status !== 200 || !response.body?.entities) throw serverError(response);

      const skewMs = skewFrom(response.body.server_time, startedAt, now());
      if (skewMs !== null) update({ skewMs });
      await applySubscription(response.body);
      const pulledAt = now();

      for (const key of entities) {
        const page = response.body.entities[key];
        if (!page) {
          pending.delete(key);
          continue;
        }
        const applied = await store.applyPage(key, page, pulledAt);
        counts[key].upserts += applied.upserts;
        counts[key].tombstones += applied.tombstones;
        counts[key].pages += 1;
        if (!page.has_more) pending.delete(key);
      }

      // NFR-04: the server offers other entities than at bootstrap (a module switched on or off
      // mid-session, a source version changed): bootstrap again, once, and pull what is new.
      const fingerprint = response.body.entities_fingerprint;
      if (typeof fingerprint === 'string' && fingerprint !== meta.entitiesFingerprint && !rebootstrapped) {
        rebootstrapped = true;
        await bootstrap();
        meta = await store.meta();
        wanted = choose(meta.entities);
        for (const key of [...pending]) if (!wanted.includes(key)) pending.delete(key);
        for (const key of wanted) {
          if (counts[key]) continue;
          counts[key] = { upserts: 0, tombstones: 0, pages: 0 };
          pending.add(key);
        }
      }
    }

    // BR-02, LAY-05: what follows a pull (the media cache), before screens re-read; never fails the pull.
    if (afterPull) {
      try {
        await afterPull(counts);
      } catch (error) {
        log('sync: after pull failed', error);
      }
    }

    const pulledAt = now();
    await store.setMeta({ lastPulledAt: pulledAt, skewMs: status.skewMs });
    update({ lastPulledAt: pulledAt });
    return counts;
  }

  // -- Push -----------------------------------------------------------------

  /** Upload what is due (everything pending when forced). Resolves with a summary. */
  async function push({ force = false } = {}) {
    const summary = { acknowledged: 0, failed: 0, retried: 0, stopped: null };
    // Rows settled in this run are not sent again in it; still pending, they hold their group.
    const seen = new Set();

    for (;;) {
      const time = now();
      const isDue = (entry) => force || entry.nextAttemptAt <= time || entry.nextAttemptAt > time + settings.maxBackoffMs;
      const held = new Set();
      const candidates = [];
      for (const entry of await store.pendingQueue()) {
        const waiting = seen.has(entry.id) || !isDue(entry) || (entry.group && held.has(entry.group));
        if (waiting) {
          if (entry.group) held.add(entry.group);
          continue;
        }
        candidates.push(entry);
      }
      if (!candidates.length) break;

      const batch = takeBatch(candidates);
      batch.forEach((entry) => seen.add(entry.id));
      const stop = await sendBatch(await store.entriesByIds(batch.map((entry) => entry.id)), summary);
      if (stop) {
        summary.stopped = stop;
        break;
      }
    }

    if (summary.acknowledged) {
      const at = now();
      await store.setMeta({ lastPushedAt: at });
      update({ lastPushedAt: at, moduleInactive: false, subscriptionStopped: false });
      await store.setMeta({ subscriptionStopped: false });
    }
    await store.pruneAcknowledged(now() - settings.keepAcknowledgedMs);
    await refreshCounts();
    return summary;
  }

  function takeBatch(candidates) {
    const first = candidates[0];
    const kind = pushKind(first.kind);
    if (!kind) return [first];
    const batch = [];
    const ids = new Set();
    for (const entry of candidates) {
      if (entry.kind !== first.kind || batch.length >= kind.batchSize || ids.has(entry.recordId)) break;
      batch.push(entry);
      ids.add(entry.recordId);
    }
    return batch;
  }

  const retryLater = (entries, error, extraMs = 0) =>
    store.settle(
      entries.map((entry) => ({ id: entry.id, status: OUTBOX.PENDING, error, nextAttemptAt: now() + Math.max(backoff(entry.attempts), extraMs) })),
      now(),
    );

  /** Send one batch; resolves a reason to stop the run, or null. */
  async function sendBatch(batch, summary) {
    const kind = pushKind(batch[0].kind);
    if (!kind) {
      summary.failed += batch.length;
      await store.settle(batch.map((entry) => ({ id: entry.id, status: OUTBOX.FAILED, error: { code: 'unknown_kind', message: entry.kind } })), now());
      return null;
    }

    const response = await call('POST', kind.path, { body: { [kind.bodyKey]: batch.map((entry) => entry.payload) } });
    const results = kind.readResults ? kind.readResults(response.body) : Array.isArray(response.body?.results) ? response.body.results : null;

    if ((response.status === 200 || response.status === 422) && results) {
      const byId = new Map(results.map((result) => [String(result.id), result]));
      const outcomes = batch.map((entry) => {
        const result = byId.get(String(entry.recordId));
        if (result?.status === 'stored') {
          summary.acknowledged += 1;
          return { id: entry.id, status: OUTBOX.ACKNOWLEDGED, result, error: null };
        }
        if (result?.status === 'rejected' && result.error && !result.error.retryable) {
          summary.failed += 1;
          return { id: entry.id, status: OUTBOX.FAILED, error: { status: response.status, ...result.error } };
        }
        summary.retried += 1;
        return { id: entry.id, status: OUTBOX.PENDING, error: result?.error ?? { code: 'no_result' }, nextAttemptAt: now() + backoff(entry.attempts) };
      });
      await store.settle(outcomes, now());
      return null;
    }

    const error = { status: response.status, code: response.body?.code ?? null, message: response.body?.message ?? null, errors: response.body?.errors ?? null };

    if (response.status >= 500 || response.status === 429 || response.status === 408) {
      summary.retried += batch.length;
      await retryLater(batch, error, retryAfterMs(response.retryAfter, now()));
      return `http_${response.status}`;
    }

    if (response.status === 403 && error.code === 'module_inactive') {
      // RBAC-08: the tenant does not have this module now. Keep everything; try later.
      summary.retried += batch.length;
      await retryLater(batch, error);
      // Another module's rows (HR punches) wait on their own; the POS keeps uploading.
      if (kind.module !== 'pos') return null;
      update({ moduleInactive: true });
      return 'module_inactive';
    }

    if (response.status === 402 && (error.code === 'subscription_pos_stopped' || error.code === 'subscription_overdue')) {
      // SUB-05: the subscription is overdue and the POS window is over. Keep everything; it
      // uploads once the invoice is paid. The till says why.
      update({ subscriptionStopped: true });
      await store.setMeta({ subscriptionStopped: true });
      summary.retried += batch.length;
      await retryLater(batch, error);
      return error.code;
    }

    if ((response.status === 409 || response.status === 422) && hasJsonCode(response)) {
      // Refused as a whole: find the record at fault, then keep it for review.
      if (batch.length > 1) {
        for (const entry of batch) {
          const stop = await sendBatch([entry], summary);
          if (stop) return stop;
        }
        return null;
      }
      summary.failed += 1;
      await store.settle([{ id: batch[0].id, status: OUTBOX.FAILED, error }], now());
      return null;
    }

    // 400, 404, a non-JSON answer, another 403: nothing proves the records wrong; try later.
    summary.retried += batch.length;
    await retryLater(batch, error);
    return null;
  }

  // -- Offline PIN attempts (AUTH-06) ----------------------------------------

  /**
   * Report wrong PINs counted offline. Reports the server skips (not staff
   * here) or refuses (422) are dropped: they can never succeed.
   */
  async function reportPinAttempts() {
    const unreported = await store.unreportedPinAttempts();
    for (let i = 0; i < unreported.length; i += settings.reportBatch) {
      const reports = unreported.slice(i, i + settings.reportBatch);
      const response = await call('POST', 'pos/pin/attempts', {
        body: {
          reports: reports.map((report) => ({
            user_id: report.userId,
            failed_attempts: report.failedAttempts,
            locked: Boolean(report.locked),
            occurred_at: report.occurredAt ?? null,
          })),
        },
      });
      if (response.status !== 200 && response.status !== 422) throw serverError(response);
      await store.markPinAttemptsReported(reports);
    }
    return unreported.length;
  }

  // -- One full run ------------------------------------------------------------

  /**
   * Push, pull (all, or `pull: 'incremental' | 'snapshot' | false`), then
   * report PIN attempts. One run at a time: a call during a run gets the
   * same promise. While access is lost, only `force` tries again (app
   * start, "Try again"); `force` also ignores upload backoff.
   */
  function sync({ pull: pullWhat = 'all', force = false, bootstrap: rebootstrap = false } = {}) {
    if (running) return running;
    if (status.auth === AUTH.LOST && !force) return Promise.resolve({ skipped: 'auth_lost' });
    running = (async () => {
      update({ syncing: true, lastError: null });
      try {
        if (rebootstrap || force || !(await store.meta()).entities) await bootstrap();
        const pushed = await push({ force });
        const pulled = pullWhat ? await pull(pullWhat === 'all' ? {} : { mode: pullWhat }) : null;
        try {
          await reportPinAttempts();
        } catch (error) {
          log('sync: PIN attempt report failed', error);
        }
        const at = now();
        await store.setMeta({ lastSyncedAt: at });
        update({ lastSyncedAt: at });
        return { pushed, pulled };
      } catch (error) {
        update({ lastError: error?.code ?? error?.message ?? 'error' });
        if (error instanceof NetworkError || error instanceof AuthLostError) return { error };
        throw error;
      } finally {
        update({ syncing: false });
        await refreshCounts().catch(() => {});
        running = null;
      }
    })();
    return running;
  }

  /**
   * Persist a record for upload; never sends (the next push does).
   * `payload.id` must be the record id (the server answers by it).
   * `group` (a shift id) keeps the group's rows in order. `prepare`
   * returns local rows written in the same transaction (store.enqueue).
   */
  async function enqueue(kind, recordId, payload, { group = null, prepare = null } = {}) {
    if (!pushKind(kind)) throw new Error(`Unknown push kind [${kind}]`);
    if (!payload || String(payload.id) !== String(recordId)) throw new Error('The payload id must be the record id');
    const entry = await store.enqueue(kind, recordId, payload, now(), { group, prepare });
    await refreshCounts();
    return entry;
  }

  /** Restore status from the database (after a restart). */
  async function load() {
    const meta = await store.meta();
    update({
      skewMs: meta.skewMs ?? 0,
      lastSyncedAt: meta.lastSyncedAt ?? null,
      lastPushedAt: meta.lastPushedAt ?? null,
      lastPulledAt: meta.lastPulledAt ?? null,
      auth: meta.auth ?? AUTH.OK,
      authCode: meta.authCode ?? null,
      secretMissing: Boolean(meta.secretMissing),
      subscriptionStopped: Boolean(meta.subscriptionStopped),
      posStopsAt: Number.isFinite(meta.posStopsAt) ? meta.posStopsAt : null,
    });
    await refreshCounts();
    return status;
  }

  return {
    bootstrap,
    pull,
    push,
    reportPinAttempts,
    sync,
    enqueue,
    load,
    refreshCounts,
    setNetwork: (network) => update({ network }),
    /** The server's clock as this device best knows it (ms). */
    serverNow: () => now() + (status.skewMs ?? 0),
    /**
     * SUB-05: selling is paused: the server refused uploads (402) or said
     * the POS is stopped, or this till's clock is past the known stop time.
     * Sales already taken stay queued and keep retrying.
     */
    sellingPaused: (at = now() + (status.skewMs ?? 0)) => status.subscriptionStopped || (status.posStopsAt !== null && status.posStopsAt !== undefined && at >= status.posStopsAt),
    getStatus: () => status,
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    store,
  };
}

/** Bootstrap names the current secret's kid (device_secret_kid); older servers sent device_secret_issued. */
function secretKidOf(body) {
  if ('device_secret_kid' in body) return body.device_secret_kid ?? null;
  return body.device_secret_issued ? 'unknown' : null;
}

function serverError(response) {
  const error = new Error(response.body?.code ?? `http_${response.status}`);
  error.status = response.status;
  error.code = response.body?.code ?? `http_${response.status}`;
  error.body = response.body;
  return error;
}
