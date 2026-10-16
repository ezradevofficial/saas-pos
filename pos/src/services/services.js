import NetInfo from '@react-native-community/netinfo';
import { createContext, useContext } from 'react';
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

/**
 * Everything the screens use, built once: the database, the keystore
 * credentials, the API client, the sync engine and scheduler, and the PIN
 * gate. Tests pass their own database, fetch and keystore.
 *
 * Selling (POS-01..POS-06): services.selling records shifts, sales,
 * voids, refunds and cash movements locally and in the outbox;
 * services.posStore reads them back.
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
  // BR-02, LAY-05, NFR-04: after each pull, the logos and layout images go into the media cache.
  const engine = createSyncEngine({ api, store, credentials, afterPull: () => syncMedia({ api, store }) });
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
  const clockStore = createClockStore(db);
  const clockGate = createClockGate({ clockStore, engine, credentials });
  // ECOM-28: the rider screen, signed in with the rider's own token (never the device's).
  const riderCredentials = createRiderCredentials(credentialsBackend);
  const riderApi = riderApiOverride ?? createApiClient({ baseUrl, getToken: () => riderCredentials.token(), getLocale: () => i18n.language, fetchImpl });
  const rider = createRider({ api: riderApi, store, credentials: riderCredentials });
  return { database: db, store, credentials, api, engine, scheduler, pinGate, posStore, selling, clockStore, clockGate, riderCredentials, rider };
}

export const ServicesContext = createContext(null);

export function useServices() {
  const services = useContext(ServicesContext);
  if (!services) throw new Error('useServices needs a ServicesContext provider');
  return services;
}
