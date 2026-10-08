import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * TEN-05, AUTH-06, AUTH-08: the device token and device secret live in the
 * platform keystore (Android Keystore / iOS Keychain through
 * expo-secure-store), never in the database or AsyncStorage.
 *
 * The Expo web preview has no keystore: it keeps them in this tab's memory
 * and sessionStorage so Playwright checks can pair. The web build is a
 * preview, never a till.
 */
const KEYS = { token: 'device.token', secret: 'device.secret', secretKid: 'device.secret_kid' };
const OPTIONS = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };

const memory = new Map();

const webStore = {
  async get(key) {
    try {
      return globalThis.sessionStorage?.getItem(key) ?? memory.get(key) ?? null;
    } catch {
      return memory.get(key) ?? null;
    }
  },
  async set(key, value) {
    memory.set(key, value);
    try {
      globalThis.sessionStorage?.setItem(key, value);
    } catch {
      // memory only
    }
  },
  async remove(key) {
    memory.delete(key);
    try {
      globalThis.sessionStorage?.removeItem(key);
    } catch {
      // memory only
    }
  },
};

const nativeStore = {
  get: (key) => SecureStore.getItemAsync(key, OPTIONS),
  set: (key, value) => SecureStore.setItemAsync(key, value, OPTIONS),
  remove: (key) => SecureStore.deleteItemAsync(key, OPTIONS),
};

/** The credential store for this platform (or one given, for tests). */
export function createCredentials(backend = Platform.OS === 'web' ? webStore : nativeStore) {
  let cache = null;

  async function load() {
    if (!cache) {
      const [token, secret, secretKid] = await Promise.all([backend.get(KEYS.token), backend.get(KEYS.secret), backend.get(KEYS.secretKid)]);
      cache = { token, secret, secretKid };
    }
    return cache;
  }

  return {
    async token() {
      return (await load()).token;
    },
    /** { secret: base64url, kid: string | null } or null. */
    async secret() {
      const { secret, secretKid } = await load();
      return secret ? { secret, kid: secretKid ?? null } : null;
    },
    async save({ token, secret, kid }) {
      if (token !== undefined) await backend.set(KEYS.token, token);
      if (secret !== undefined) await backend.set(KEYS.secret, secret);
      if (kid !== undefined && kid !== null) await backend.set(KEYS.secretKid, String(kid));
      cache = null;
    },
    async clear() {
      await Promise.all(Object.values(KEYS).map((key) => backend.remove(key)));
      cache = null;
    },
  };
}

/** An in-memory backend, for tests. */
export function memoryBackend(initial = {}) {
  const values = new Map(Object.entries(initial));
  return {
    values,
    get: async (key) => values.get(key) ?? null,
    set: async (key, value) => void values.set(key, value),
    remove: async (key) => void values.delete(key),
  };
}
