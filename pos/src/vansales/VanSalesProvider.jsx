import { useCallback, useEffect, useMemo, useState } from 'react';
import { usePosActions, usePosCart } from '../pos/PosProvider';
import { useServices } from '../services/services';
import { useSyncStatus } from '../sync/useSyncStatus';
import { VanSalesContext } from './context';

/**
 * VAN-05, VAN-06, VAN-27: the van, routes, the open run and its visits,
 * loaded from the device and reloaded after every local write and pull.
 * `enabled` is true when the pulled `van_sales_van` row exists.
 *
 * While a run is open, a sale in the cart carries `ext.van_sales.run_id`, so
 * every sale of the day is linked to the run, with or without a stop.
 */
const EMPTY = { van: null, routes: [], runs: [], loads: [], run: null, visits: [], rejected: [], loaded: false };

export function VanSalesProvider({ children }) {
  const { vanSales } = useServices();
  const { lastPulledAt } = useSyncStatus();
  const [state, setState] = useState(EMPTY);

  const reload = useCallback(async () => {
    const [van, routes, runs, loads] = await Promise.all([vanSales.van(), vanSales.records.routes(), vanSales.runs(), vanSales.records.loads()]);
    const run = runs.find((view) => view.status === 'open') ?? null;
    const [visits, rejected] = await Promise.all([run ? vanSales.visits(run.id) : [], vanSales.rejected()]);
    setState({ van, routes, runs, loads, run, visits, rejected, loaded: true });
  }, [vanSales]);

  useEffect(() => {
    let active = true;
    reload().catch(() => active && setState((current) => ({ ...current, loaded: true })));
    const unsubscribe = vanSales.records.subscribe(() => active && reload().catch(() => {}));
    return () => {
      active = false;
      unsubscribe();
    };
  }, [vanSales, reload, lastPulledAt]);

  const enabled = Boolean(state.van);
  const { cart } = usePosCart();
  const actions = usePosActions();
  const runId = state.run?.id ?? null;
  const linked = cart.ext?.van_sales?.run_id;
  useEffect(() => {
    if (!enabled || !runId || !cart.lines.length || linked === runId) return;
    actions.setExtension('van_sales', { ...cart.ext?.van_sales, run_id: runId });
  }, [enabled, runId, linked, cart.lines.length, cart.ext, actions]);

  const value = useMemo(() => ({ ...state, enabled, service: vanSales, reload }), [state, enabled, vanSales, reload]);
  return <VanSalesContext.Provider value={value}>{children}</VanSalesContext.Provider>;
}
