import NetInfo from '@react-native-community/netinfo';
import { createContext, useContext } from 'react';
import { createPinGate } from '../auth/pinGate';
import { apiUrl } from '../config';
import { createDatabase } from '../db';
import { createCredentials } from '../device/credentials';
import i18n from '../i18n';
import { createApiClient } from '../sync/api';
import { createSyncEngine } from '../sync/engine';
import { createSyncScheduler } from '../sync/scheduler';
import { createSyncStore } from '../sync/store';
import { createPosStore } from '../pos/posStore';
import { createSelling } from '../pos/selling';

/**
 * Everything the screens use, built once: the database, the keystore
 * credentials, the API client, the sync engine and scheduler, and the PIN
 * gate. Tests pass their own database, fetch and keystore.
 *
 * Selling (POS-01..POS-06): services.selling records shifts, sales,
 * voids, refunds and cash movements locally and in the outbox;
 * services.posStore reads them back.
 */
export function createServices({ database, fetchImpl, credentialsBackend, netInfo = NetInfo, baseUrl = apiUrl, api: apiOverride } = {}) {
  const db = database ?? createDatabase();
  const store = createSyncStore(db);
  const credentials = createCredentials(credentialsBackend);
  const api = apiOverride ?? createApiClient({ baseUrl, getToken: () => credentials.token(), getLocale: () => i18n.language, fetchImpl });
  const engine = createSyncEngine({ api, store, credentials });
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
  return { database: db, store, credentials, api, engine, scheduler, pinGate, posStore, selling };
}

export const ServicesContext = createContext(null);

export function useServices() {
  const services = useContext(ServicesContext);
  if (!services) throw new Error('useServices needs a ServicesContext provider');
  return services;
}
