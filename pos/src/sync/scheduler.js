import { NETWORK } from './engine';

/**
 * NFR-04: when the till syncs.
 * - on start: a full run (bootstrap, push, pull), even after access was lost;
 * - on reconnect (NetInfo): a full run;
 * - while online, on a tick: uploads when anything waits or a retry is
 *   due, the catalogue (incremental entities) every `incrementalMs`, the
 *   small snapshots (staff, taxes, rates, settings) every `snapshotMs`;
 * - as soon as a record is queued while online (after `pushDelayMs`), so a
 *   sale reaches the server, and the fiscal authority, without waiting for
 *   the tick.
 * Errors never escape: the engine's status says what happened.
 */
export const DEFAULT_INTERVALS = { tickMs: 30 * 1000, incrementalMs: 5 * 60 * 1000, snapshotMs: 15 * 60 * 1000, pushDelayMs: 300 };

export function createSyncScheduler({ engine, netInfo, intervals = {}, now = () => Date.now(), timers = globalThis, log = () => {} }) {
  const settings = { ...DEFAULT_INTERVALS, ...intervals };
  let lastIncremental = 0;
  let lastSnapshot = 0;
  let timer = null;
  let unsubscribe = null;
  let online = null;
  let unwatch = null;
  let pushTimer = null;
  let lastPending = 0;

  // A newly queued record: one upload pass shortly after, batched with anything queued meanwhile.
  function onStatus(status) {
    const pending = status?.pending ?? 0;
    const grew = pending > lastPending;
    lastPending = pending;
    if (!grew || online === false || pushTimer) return;
    pushTimer = timers.setTimeout(() => {
      pushTimer = null;
      if (online !== false) run({ pull: false });
    }, settings.pushDelayMs);
  }

  async function run(options) {
    try {
      const started = now();
      const result = await engine.sync(options);
      if (!result?.error && !result?.skipped) {
        if (!options.pull || options.pull === 'all') {
          lastIncremental = started;
          lastSnapshot = started;
        } else if (options.pull === 'incremental') lastIncremental = started;
        else if (options.pull === 'snapshot') lastSnapshot = started;
      }
      return result;
    } catch (error) {
      log('sync failed', error);
      return { error };
    }
  }

  // Never rejects: a timer callback has nobody to catch it.
  async function tick() {
    if (online === false) return null;
    const time = now();
    if (time - lastSnapshot >= settings.snapshotMs) return run({ pull: 'all' });
    if (time - lastIncremental >= settings.incrementalMs) return run({ pull: 'incremental' });
    // Uploads: the engine sends only what is due (and holds groups), so a pass is cheap.
    if (engine.getStatus().pending) return run({ pull: false });
    return null;
  }

  function onNetwork(state) {
    const reachable = Boolean(state?.isConnected) && state?.isInternetReachable !== false;
    const was = online;
    online = reachable;
    engine.setNetwork(reachable ? NETWORK.ONLINE : NETWORK.OFFLINE);
    if (reachable && was === false) run({ pull: 'all' });
  }

  return {
    async start() {
      try {
        await engine.load();
      } catch (error) {
        log('sync status could not be loaded', error);
      }
      if (netInfo) unsubscribe = netInfo.addEventListener(onNetwork);
      lastPending = engine.getStatus()?.pending ?? 0;
      unwatch = engine.subscribe?.(onStatus) ?? null;
      const first = run({ pull: 'all', force: true });
      timer = timers.setInterval(() => {
        tick();
      }, settings.tickMs);
      return first;
    },
    stop() {
      if (timer) timers.clearInterval(timer);
      timer = null;
      unsubscribe?.();
      unsubscribe = null;
      unwatch?.();
      unwatch = null;
      if (pushTimer) timers.clearTimeout(pushTimer);
      pushTimer = null;
    },
    /** "Sync now" and "Try again": a full run, even after access was lost. */
    syncNow: () => run({ pull: 'all', force: true }),
    tick,
    onNetwork,
  };
}
