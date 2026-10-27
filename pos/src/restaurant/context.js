import { createContext, useContext, useEffect, useState } from 'react';
import { useServices } from '../services/services';

/**
 * The floor as the screens see it (RestaurantProvider fills it): `enabled`
 * is false on tills whose POS mode is not a floor mode, so retail screens
 * can ask without a provider above them.
 */
export const RestaurantContext = createContext({ enabled: false, mode: null });

export const useRestaurant = () => useContext(RestaurantContext);

/** One order by id, reloaded after every local write and sync (null while loading or when unknown). */
export function useOrder(orderId) {
  const { restaurant } = useServices();
  const [order, setOrder] = useState(null);
  useEffect(() => {
    let active = true;
    const load = () => restaurant.orders.get(orderId).then((found) => active && setOrder(found));
    load();
    const unsubscribe = restaurant.orders.subscribe(load);
    return () => {
      active = false;
      unsubscribe();
    };
  }, [restaurant, orderId]);
  return order;
}
