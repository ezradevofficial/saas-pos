import NetInfo from '@react-native-community/netinfo';
import { createContext, useContext } from 'react';
import { Platform } from 'react-native';
import { createPinGate } from '../auth/pinGate';
import { createClockGate } from '../hr/clockGate';
import { createClockStore } from '../hr/clockStore';
import { apiUrl } from '../config';
import { createDatabase } from '../db';
import { createCredentials, createRiderCredentials } from '../device/credentials';
import i18n from '../i18n';
import { createApiClient } from '../sync/api';
import { createSyncEngine } from '../sync/engine';
import { createSyncScheduler } from '../sync/scheduler';
import { createSyncStore } from '../sync/store';
import { syncMedia } from '../theme/media';
import { createPosStore } from '../pos/posStore';
import { createSelling } from '../pos/selling';
import { createRider } from '../rider/rider';
import '../restaurant/register';
import { createOrderStore } from '../restaurant/orderStore';
import { createOrderSync } from '../restaurant/orderSync';
import { createRestaurant } from '../restaurant/restaurant';
import { registerAfterSale } from '../pos/extensions';
import '../vansales/register';
import { createVanSales } from '../vansales/vansales';
import { createVsStore } from '../vansales/vsStore';
import { createVsSync } from '../vansales/vsSync';

/**
 * Everything the screens use, built once: the database, the keystore
 * credentials, the API client, the sync engine and scheduler, and the PIN
 * gate. Tests pass their own database, fetch and keystore.
 *
 * Selling (POS-01..POS-06): services.selling records shifts, sales,
 * voids, refunds and cash movements locally and in the outbox;
 * services.posStore reads them back.
 *
 * Restaurant (REST-03..REST-17): services.restaurant keeps open orders and tabs on
 * the device; the engine uploads them.
 *
 * Van sales (VAN-27): services.vanSales keeps the rep's runs, visits, collections and counts on the
 * device; the engine uploads them through POST van-sales/device/sync.
 *
 * Rider (ECOM-28): services.rider keeps the rider's deliveries and their
 * queued status updates.
 *
 * Clock-in (HR-22, HR-23): services.clockGate checks clock-in PINs and
 * records punches; services.clockStore reads them back.
 */
export function createServices({ database, fetchImpl, credentialsBackend, netInfo = NetInfo, baseUrl = apiUrl, api: apiOverride, riderApi: riderApiOverride } = {}) {
  const db = database ?? createDatabase();
  const store = createSyncStore(db);
  const credentials = createCredentials(credentialsBackend);
  const api = apiOverride ?? createApiClient({ baseUrl, getToken: () => credentials.token(), getLocale: () => i18n.language, fetchImpl });
  // REST-17: the Restaurant module's open orders are kept on the till and uploaded by the engine
  // (before the sales that pay them); each pull folds the server's orders back in.
  const orders = createOrderStore(db);
  const orderSync = createOrderSync({ orders });
  // VAN-27: the rep's runs, visits, collections and counts, uploaded before the sales they belong to.
  const vsRecords = createVsStore(db);
  const vsSync = createVsSync({ records: vsRecords });
  // BR-02, LAY-05, NFR-04: after each pull, the logos and layout images go into the media cache.
  const engine = createSyncEngine({
    api,
    store,
    credentials,
    afterPull: async (counts) => {
      await syncMedia({ api, store });
      if (counts?.restaurant_orders) await orderSync.reconcile();
    },
    pushers: [
      { name: 'restaurant', pendingCount: orders.dirtyCount, push: orderSync.push },
      { name: 'van_sales', pendingCount: vsSync.pendingCount, push: vsSync.push },
    ],
  });
  const scheduler = createSyncScheduler({ engine, netInfo });
  const pinGate = createPinGate({
    store,
    api,
    credentials,
    // Offline wrong PINs go up with the next sync.
    onAttemptRecorded: () => {
      if (engine.getStatus().network !== 'offline') engine.reportPinAttempts().catch(() => {});
    },
  });
  const posStore = createPosStore(db);
  const selling = createSelling({ engine, posStore, api });
  // REST-16: waiters' orders; a part rung as a sale marks its order paid.
  const restaurant = createRestaurant({ orders, engine, posStore });
  registerAfterSale('restaurant', ({ sale }) => {
    if (sale?.extensions?.restaurant) restaurant.recordPart(sale).catch(() => {});
  });
  // VAN-09, VAN-14: the rep's day; a sale completed in a visit marks the visit sold.
  const vanSales = createVanSales({ records: vsRecords, selling, engine, api });
  registerAfterSale('van_sales', ({ sale }) => {
    if (sale?.extensions?.van_sales) vanSales.recordSale(sale).catch(() => {});
  });
  const clockStore = createClockStore(db);
  const clockGate = createClockGate({ clockStore, engine, credentials });
  // ECOM-28: the rider screen, signed in with the rider's own token (never the device's).
  const riderCredentials = createRiderCredentials(credentialsBackend);
  const riderApi = riderApiOverride ?? createApiClient({ baseUrl, getToken: () => riderCredentials.token(), getLocale: () => i18n.language, fetchImpl });
  const rider = createRider({ api: riderApi, store, credentials: riderCredentials, deleteFile: deletePhotoFile });
  return { database: db, store, credentials, api, engine, scheduler, pinGate, posStore, selling, restaurant, vanSales, clockStore, clockGate, riderCredentials, rider };
}

export const ServicesContext = createContext(null);

export function useServices() {
  const services = useContext(ServicesContext);
  if (!services) throw new Error('useServices needs a ServicesContext provider');
  return services;
}

/**
 * ECOM-28: delete a delivery photo's file once it is uploaded or refused.
 * expo-file-system is not declared in this app's package.json: it is a
 * dependency of `expo` itself (installed and autolinked with it), so it is
 * loaded here on first use rather than added as a new dependency. On the web
 * preview (no camera photo) and if the module cannot be loaded this does
 * nothing; the camera writes to the app's cache, which the OS clears.
 */
async function deletePhotoFile(uri) {
  if (Platform.OS === 'web' || !uri) return;
  let File;
  try {
    ({ File } = require('expo-file-system'));
  } catch {
    return;
  }
  const file = new File(uri);
  if (file.exists) file.delete();
}
