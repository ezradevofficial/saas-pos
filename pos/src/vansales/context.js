import { createContext, useContext } from 'react';

/**
 * The rep's day as the screens see it (VanSalesProvider fills it): `enabled`
 * is false on tills without a pulled van row, so retail screens can ask
 * without a provider above them.
 */
export const VanSalesContext = createContext({ enabled: false });

export const useVanSales = () => useContext(VanSalesContext);

/** VAN-26: what the signed-in person may do on the route (the server decides again on upload). */
export const VAN = {
  runStart: 'van_sales.run.start',
  loadConfirm: 'van_sales.load.confirm',
  visitRecord: 'van_sales.visit.record',
  collectionRecord: 'van_sales.collection.record',
  countSubmit: 'van_sales.reconciliation.submit',
};

export const canVan = (user, permission) => Boolean(user?.owner || user?.permissions?.includes(permission));
