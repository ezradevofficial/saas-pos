import { createContext, useContext } from 'react';

/**
 * The salon day as the screens see it (SalonProvider fills it): `enabled` is false on tills without a pulled
 * `salon_settings` row, so the sell screens can ask without a provider above them.
 */
export const SalonContext = createContext({ enabled: false });

export const useSalon = () => useContext(SalonContext);
