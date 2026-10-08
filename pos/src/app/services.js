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

/**
 * Everything the screens use, built once: the database, the keystore
 * credentials, the API client, the sync engine and scheduler, and the PIN
 * gate. Tests pass their own database, fetch and keystore.
 *
 * For Task 5: services.engine.enqueue('pos.sales', sale.id, payload)
 * persists a sale for upload; services.store / services.database read the
 * synced tables (src/db/schema.js).
 */
export function createServices({ database, fetchImpl, credentialsBackend, netInfo = NetInfo, baseUrl = apiUrl, api: apiOverride } = {}) {
  const db = database ?? createDatabase();
  const store = createSyncStore(db);
  const credentials = createCredentials(credentialsBackend);
  const api = apiOverride ?? createApiClient({ baseUrl, getToken: () => credentials.token(), getLocale: () => i18n.language, fetchImpl });
  const engine = createSyncEngine({ api, store });
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
  return { database: db, store, credentials, api, engine, scheduler, pinGate };
}

export const ServicesContext = createContext(null);

export function useServices() {
  const services = useContext(ServicesContext);
  if (!services) throw new Error('useServices needs a ServicesContext provider');
  return services;
}
