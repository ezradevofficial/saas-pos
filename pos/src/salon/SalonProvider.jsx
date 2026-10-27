import { useCallback, useEffect, useMemo, useState } from 'react';
import { useServices } from '../services/services';
import { useSyncStatus } from '../sync/useSyncStatus';
import { SalonContext } from './context';

/**
 * SAL-29: the salon's data (staff, services, the week's schedule, the queue, packages) and what the till made since,
 * loaded from the device and reloaded after every local write and pull, so the day works with no connection.
 * `enabled` is true when the pulled `salon_settings` row exists.
 */
const EMPTY = { settings: null, staff: [], resources: [], services: [], packages: [], appointments: [], queue: [], rejected: [], loaded: false };

export function SalonProvider({ children }) {
  const { salon } = useServices();
  const { lastPulledAt } = useSyncStatus();
  const [state, setState] = useState(EMPTY);

  const reload = useCallback(async () => {
    setState({ ...(await salon.load()), loaded: true });
  }, [salon]);

  useEffect(() => {
    let active = true;
    reload().catch(() => active && setState((current) => ({ ...current, loaded: true })));
    const unsubscribe = salon.records.subscribe(() => active && reload().catch(() => {}));
    return () => {
      active = false;
      unsubscribe();
    };
  }, [salon, reload, lastPulledAt]);

  const value = useMemo(() => ({ ...state, enabled: Boolean(state.settings), service: salon, reload }), [state, salon, reload]);
  return <SalonContext.Provider value={value}>{children}</SalonContext.Provider>;
}
