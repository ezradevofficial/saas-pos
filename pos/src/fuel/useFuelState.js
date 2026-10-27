import { useCallback, useEffect, useState } from 'react';
import { usePosData } from '../pos/PosProvider';
import { useServices } from '../services/services';
import { useSyncStatus } from '../sync/useSyncStatus';

/**
 * FUEL-22, FUEL-31: the forecourt as the fuel screens see it, loaded from
 * the device: the open shift (kept across restarts), what this till has sold
 * on it so far, and the uploads the server kept for review. Reloaded after
 * every pull, every change of the outbox and every local write.
 */
export function useFuelState() {
  const { fuel: services } = useServices();
  const { catalogue } = usePosData();
  const sync = useSyncStatus();
  const [state, setState] = useState({ loaded: false, shift: null, running: null, problems: [] });

  const reload = useCallback(async () => {
    const shift = await services.shifts.current();
    const [running, problems] = await Promise.all([shift ? services.shifts.running(shift) : null, services.shifts.problems()]);
    setState({ loaded: true, shift, running, problems });
  }, [services]);

  useEffect(() => {
    let active = true;
    reload().catch(() => active && setState((current) => ({ ...current, loaded: true })));
    return () => {
      active = false;
    };
  }, [reload, sync.lastPulledAt, sync.pending, sync.failed]);

  return { ...state, reload, fuel: catalogue?.ext?.fuel ?? null, services };
}
