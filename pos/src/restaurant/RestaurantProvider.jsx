import { useCallback, useEffect, useMemo, useState } from 'react';
import { usePosData } from '../pos/PosProvider';
import { useServices } from '../services/services';
import { useSyncStatus } from '../sync/useSyncStatus';
import { settingsOf } from './closing';
import { RestaurantContext } from './context';
import { isFloorMode } from './permissions';

/**
 * REST-02, REST-03, REST-17: the floor as the screens see it, loaded from
 * the device (the synced floor tables and the open orders kept on this
 * till) and reloaded after every local write and every pull.
 *
 * `enabled` is true when the tenant's POS mode is a floor mode
 * (settings.pos_mode: restaurant, bar or hotel). When the settings row does
 * not name the mode, the module's own data decides: the server serves the
 * Restaurant entities only to tenants of those modes (REST-01), so a till
 * holding floor or settings rows is a floor till. Retail tills hold none.
 */
const EMPTY = { tables: [], stations: [], groups: [], settingsRows: [], orders: [], loaded: false };

export function RestaurantProvider({ children }) {
  const { restaurant } = useServices();
  const { catalogue } = usePosData();
  const { lastPulledAt } = useSyncStatus();
  const mode = catalogue?.settings?.pos_mode ?? null;
  const [floor, setFloor] = useState(EMPTY);
  const hasModuleData = floor.settingsRows.length > 0 || floor.tables.length > 0;
  const enabled = isFloorMode(mode) || (!mode && hasModuleData);

  const reload = useCallback(async () => {
    const { orders } = restaurant;
    const [tables, stations, groups, settingsRows, open] = await Promise.all([orders.tables(), orders.stations(), orders.modifierGroups(), orders.settingsRows(), orders.open()]);
    setFloor({ tables, stations, groups, settingsRows, orders: open, loaded: true });
  }, [restaurant]);

  useEffect(() => {
    let active = true;
    reload();
    const unsubscribe = restaurant.orders.subscribe(() => active && reload());
    return () => {
      active = false;
      unsubscribe();
    };
  }, [restaurant, reload, lastPulledAt]);

  useEffect(() => {
    if (!enabled) return;
    // A part whose sale was saved but whose order was not marked (a crash between the two) is marked now.
    restaurant.recoverParts().catch(() => {});
  }, [enabled, restaurant, lastPulledAt]);

  const settings = useMemo(() => settingsOf(floor.settingsRows, catalogue?.settings?.company?.id), [floor.settingsRows, catalogue]);
  const value = useMemo(() => ({ enabled, mode: mode ?? (enabled ? 'restaurant' : null), service: restaurant, settings, reload, ...floor }), [enabled, mode, restaurant, settings, reload, floor]);
  return <RestaurantContext.Provider value={value}>{children}</RestaurantContext.Provider>;
}
