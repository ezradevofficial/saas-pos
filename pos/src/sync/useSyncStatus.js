import { useSyncExternalStore } from 'react';
import { useServices } from '../app/services';
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
