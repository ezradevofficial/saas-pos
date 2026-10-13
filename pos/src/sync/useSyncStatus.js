import { useEffect, useReducer, useSyncExternalStore } from 'react';
import { useServices } from '../services/services';
import { AUTH, NETWORK } from './engine';

/** The SyncStatus component's state for an engine status. */
export function syncState(status) {
  if (status.auth === AUTH.LOST || status.network === NETWORK.OFFLINE) return 'offline';
  if (status.syncing) return 'syncing';
  return 'online';
}

/**
 * NFR-04: the engine's live status for screens: { state, pending, failed,
 * lastSyncedAt, auth, ... }. Feed `state` and `pending` to <SyncStatus>.
 */
export function useSyncStatus() {
  const { engine } = useServices();
  const status = useSyncExternalStore(engine.subscribe, engine.getStatus, engine.getStatus);
  return { ...status, state: syncState(status) };
}

// setTimeout's longest delay (about 24.8 days); a later stop is re-checked then.
const MAX_DELAY_MS = 2 ** 31 - 1;

/**
 * SUB-05: whether selling is paused because the subscription stopped the
 * POS (engine.sellingPaused()). Re-renders when the known stop time passes,
 * offline included.
 */
export function useSellingPaused() {
  const { engine } = useServices();
  const status = useSyncExternalStore(engine.subscribe, engine.getStatus, engine.getStatus);
  const [, tick] = useReducer((n) => n + 1, 0);
  const paused = engine.sellingPaused?.() ?? false;
  const stopsAt = status.posStopsAt ?? null;

  useEffect(() => {
    if (paused || stopsAt === null) return undefined;
    const timer = setTimeout(tick, Math.min(MAX_DELAY_MS, Math.max(0, stopsAt - engine.serverNow()) + 1000));
    return () => clearTimeout(timer);
  }, [engine, paused, stopsAt]);

  return paused;
}
