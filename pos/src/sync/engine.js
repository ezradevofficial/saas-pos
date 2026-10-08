import { NetworkError } from './api';
import { entityDefinition } from './entities';
import { pushKind } from './pushKinds';
import { OUTBOX } from './store';

/**
 * NFR-04, ADR 004: the till's sync engine. Plain JavaScript over an API
 * client (src/sync/api.js) and a store (src/sync/store.js), so it runs in
 * Jest without React Native.
 *
 * - bootstrap(): GET sync/bootstrap: entities, page size, PIN rules, the
 *   server clock (skew), whether the device has its secret.
 * - pull(): GET sync/pull per entity from its cursor, looping while
 *   has_more; reset starts an entity over, snapshots replace, tombstones
 *   remove. Each page is applied with its cursor in one write.
 * - push(): uploads the outbox in enqueue order, in batches of one kind.
 *   stored → acknowledged; rejected and retryable, 5xx, 429 → retried with
 *   exponential backoff and jitter; rejected otherwise, 409, 422 → failed
 *   with the reason, kept for review, the queue goes on. A batch refused
 *   as a whole is split to find the record at fault.
 * - 401/403 anywhere: the device lost its access (unpaired or suspended).
 *   The engine stops and says so; nothing local is deleted.
 * - No answer at all (offline): nothing changes locally; the scheduler
 *   tries again on reconnect.
 */
export const AUTH = { OK: 'ok', LOST: 'lost' };
export const NETWORK = { UNKNOWN: 'unknown', ONLINE: 'online', OFFLINE: 'offline' };

const DEFAULTS = { baseBackoffMs: 5000, maxBackoffMs: 15 * 60 * 1000, maxPages: 10000, reportBatch: 100 };

export class AuthLostError extends Error {
  constructor(status, code) {
    super('auth_lost');
    this.name = 'AuthLostError';
    this.status = status;
    this.code = code;
  }
}

export function createSyncEngine({ api, store, now = () => Date.now(), random = Math.random, options = {}, log = () => {} }) {
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

  /** Throws AuthLostError for 401/403; marks the device online for any answer. */
  function checkAnswer(response) {
    update({ network: NETWORK.ONLINE });
    if (response.status === 401 || response.status === 403) {
      const code = response.body?.code ?? (response.status === 401 ? 'unauthenticated' : 'forbidden');
      update({ auth: AUTH.LOST, authCode: code });
      store.setMeta({ auth: AUTH.LOST, authCode: code }).catch(() => {});
      throw new AuthLostError(response.status, code);
    }
    return response;
  }

  async function call(method, path, options) {
    try {
      return checkAnswer(await api.request(method, path, options));
    } catch (error) {
      if (error instanceof NetworkError) update({ network: NETWORK.OFFLINE });
      throw error;
    }
  }

  function skewFrom(serverTime, startedAt, endedAt) {
    const server = Date.parse(serverTime ?? '');
    return Number.isFinite(server) ? Math.round(server - (startedAt + endedAt) / 2) : null;
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
    const skewMs = skewFrom(body.server_time, startedAt, now()) ?? status.skewMs;
    const offered = (body.entities ?? []).filter((entity) => entityDefinition(entity.key));
    const skipped = (body.entities ?? []).filter((entity) => !entityDefinition(entity.key)).map((entity) => entity.key);
    if (skipped.length) log('sync: entities not known to this app version', skipped);

    // Entities the server no longer offers (a module switched off) leave the device.
    const known = await store.entityStates();
    for (const key of Object.keys(known)) {
      if (!offered.some((entity) => entity.key === key)) await store.dropEntity(key);
    }

    if (body.device) await store.saveDevice(body.device);
    await store.setMeta({
      entities: offered.map(({ key, mode, module, version }) => ({ key, mode, module, version })),
      pageSize: body.page_size,
      maxPageSize: body.max_page_size,
      pin: body.pin ?? null,
      skewMs,
      bootstrappedAt: now(),
      secretIssued: Boolean(body.device_secret_issued),
      auth: AUTH.OK,
      authCode: null,
    });
    update({ skewMs, secretMissing: !body.device_secret_issued, auth: AUTH.OK, authCode: null });
    return body;
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
    const wanted = meta.entities.filter((entity) => (!mode || entity.mode === mode) && (!keys || keys.includes(entity.key))).map((entity) => entity.key);
    const limit = meta.pageSize ?? undefined;
    const pending = new Set(wanted);
    const counts = Object.fromEntries(wanted.map((key) => [key, { upserts: 0, tombstones: 0, pages: 0 }]));
    let pages = 0;

    while (pending.size && pages < settings.maxPages) {
      pages += 1;
      const entities = [...pending];
      const cursors = await store.cursors(entities);
      const startedAt = now();
      const response = await call('GET', 'sync/pull', { query: { entities, cursors, limit } });

      if (response.status === 422 && response.body?.code === 'invalid_cursor') {
        // A cursor the server does not accept: start that entity over.
        const entity = response.body.entity ?? Object.keys(response.body.errors ?? {})[0]?.replace(/^cursors\./, '');
        if (entity && pending.has(entity)) {
          await store.dropEntity(entity);
          continue;
        }
      }
      if (response.status !== 200 || !response.body?.entities) throw serverError(response);

      const skewMs = skewFrom(response.body.server_time, startedAt, now());
      if (skewMs !== null) update({ skewMs });
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
    }

    const pulledAt = now();
    await store.setMeta({ lastPulledAt: pulledAt, skewMs: status.skewMs });
    update({ lastPulledAt: pulledAt });
    return counts;
  }

  // -- Push -----------------------------------------------------------------

  /** Upload what is due. Resolves with { acknowledged, failed, retried }. */
  async function push() {
    const summary = { acknowledged: 0, failed: 0, retried: 0 };
    // Rows settled in this run are not picked again, even when due at once.
    const seen = new Set();

    for (;;) {
      const due = (await store.due(now())).filter((entry) => !seen.has(entry.id));
      if (!due.length) break;
      const batch = takeBatch(due);
      batch.forEach((entry) => seen.add(entry.id));
      await sendBatch(batch, summary);
    }

    if (summary.acknowledged) {
      const at = now();
      await store.setMeta({ lastPushedAt: at });
      update({ lastPushedAt: at });
    }
    await refreshCounts();
    return summary;
  }

  function takeBatch(due) {
    const first = due[0];
    const kind = pushKind(first.kind);
    if (!kind) return [first];
    const batch = [];
    const ids = new Set();
    for (const entry of due) {
      if (entry.kind !== first.kind || batch.length >= kind.batchSize || ids.has(entry.recordId)) break;
      batch.push(entry);
      ids.add(entry.recordId);
    }
    return batch;
  }

  async function sendBatch(batch, summary) {
    const kind = pushKind(batch[0].kind);
    if (!kind) {
      await settleFailed(batch, { code: 'unknown_kind', message: batch[0].kind }, summary);
      return;
    }

    const response = await call('POST', kind.path, { body: { [kind.bodyKey]: batch.map((entry) => entry.payload) } });
    const results = Array.isArray(response.body?.results) ? response.body.results : null;

    if ((response.status === 200 || response.status === 422) && results) {
      const byId = new Map(results.map((result) => [String(result.id), result]));
      const outcomes = batch.map((entry) => {
        const result = byId.get(String(entry.recordId));
        if (result?.status === 'stored') {
          summary.acknowledged += 1;
          return { id: entry.id, status: OUTBOX.ACKNOWLEDGED, result, error: null };
        }
        if (result?.status === 'rejected' && !result.error?.retryable) {
          summary.failed += 1;
          return { id: entry.id, status: OUTBOX.FAILED, error: { status: response.status, ...result.error } };
        }
        summary.retried += 1;
        return {
          id: entry.id,
          status: OUTBOX.PENDING,
          error: result?.error ?? { code: 'no_result' },
          nextAttemptAt: now() + backoff(entry.attempts),
        };
      });
      await store.settle(outcomes, now());
      return;
    }

    if (response.status >= 500 || response.status === 429 || response.status === 408) {
      summary.retried += batch.length;
      await store.settle(
        batch.map((entry) => ({
          id: entry.id,
          status: OUTBOX.PENDING,
          error: { status: response.status, code: response.body?.code ?? 'server_error' },
          nextAttemptAt: now() + backoff(entry.attempts),
        })),
        now(),
      );
      return;
    }

    // Refused as a whole (409, 422 validation, 413...): find the record at fault.
    const error = { status: response.status, code: response.body?.code ?? null, message: response.body?.message ?? null, errors: response.body?.errors ?? null };
    if (batch.length > 1) {
      for (const entry of batch) await sendBatch([entry], summary);
      return;
    }
    await settleFailed(batch, error, summary);
  }

  async function settleFailed(batch, error, summary) {
    summary.failed += batch.length;
    await store.settle(batch.map((entry) => ({ id: entry.id, status: OUTBOX.FAILED, error })), now());
  }

  // -- Offline PIN attempts (AUTH-06) ----------------------------------------

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
      if (response.status !== 200) throw serverError(response);
      await store.markPinAttemptsReported(reports);
    }
    return unreported.length;
  }

  // -- One full run ------------------------------------------------------------

  /**
   * Push, report PIN attempts, then pull (all, or `pull: 'incremental' |
   * 'snapshot' | false`). One run at a time: a call during a run waits for
   * it. While access is lost, only `force` tries again (app start, the
   * "Try again" action).
   */
  function sync({ pull: pullWhat = 'all', force = false, bootstrap: rebootstrap = false } = {}) {
    if (running) return running;
    if (status.auth === AUTH.LOST && !force) return Promise.resolve({ skipped: 'auth_lost' });
    running = (async () => {
      update({ syncing: true, lastError: null });
      try {
        if (rebootstrap || force || !(await store.meta()).entities) await bootstrap();
        const pushed = await push();
        await reportPinAttempts();
        const pulled = pullWhat ? await pull(pullWhat === 'all' ? {} : { mode: pullWhat }) : null;
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

  /** Persist a record for upload. Never sends: the next push does. */
  async function enqueue(kind, recordId, payload) {
    if (!pushKind(kind)) throw new Error(`Unknown push kind [${kind}]`);
    const entry = await store.enqueue(kind, recordId, payload, now());
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
      secretMissing: meta.secretIssued === false,
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
    getStatus: () => status,
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    store,
  };
}

function serverError(response) {
  const error = new Error(response.body?.code ?? `http_${response.status}`);
  error.status = response.status;
  error.code = response.body?.code ?? `http_${response.status}`;
  error.body = response.body;
  return error;
}
