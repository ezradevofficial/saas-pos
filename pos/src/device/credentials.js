import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * TEN-05, AUTH-06, AUTH-08: the device token and device secret live in the
 * platform keystore (Android Keystore / iOS Keychain through
 * expo-secure-store), never in the database or AsyncStorage. A secret and
 * its key id are one JSON value, so they are written together or not at
 * all.
 *
 * The Expo web preview has no keystore: it keeps them in this tab's memory
 * and sessionStorage so Playwright checks can pair. The web build is a
 * preview, never a till.
 */
const KEYS = {
  token: 'device.token',
  // {"secret": base64url, "kid": string}
  secret: 'device.secret',
  // A rotated secret the server issued and may not have activated yet (DeviceSecrets::rotate).
  pending: 'device.pending_secret',
};
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

function readSecret(text) {
  try {
    const value = text ? JSON.parse(text) : null;
    return value?.secret ? { secret: value.secret, kid: value.kid ?? null } : null;
  } catch {
    return null;
  }
}

const writeSecret = ({ secret, kid }) => JSON.stringify({ secret, kid: kid ?? null });

/** The credential store for this platform (or one given, for tests). */
export function createCredentials(backend = Platform.OS === 'web' ? webStore : nativeStore) {
  let cache = null;

  async function load() {
    if (!cache) {
      const [token, secret] = await Promise.all([backend.get(KEYS.token), backend.get(KEYS.secret)]);
      cache = { token, secret: readSecret(secret) };
    }
    return cache;
  }

  return {
    async token() {
      return (await load()).token;
    },
    /** { secret: base64url, kid: string | null } or null. */
    async secret() {
      return (await load()).secret;
    },
    async save({ token, secret, kid }) {
      if (token !== undefined) await backend.set(KEYS.token, token);
      if (secret) await backend.set(KEYS.secret, writeSecret({ secret, kid }));
      cache = null;
    },
    async pending() {
      return readSecret(await backend.get(KEYS.pending));
    },
    async savePending({ secret, kid }) {
      await backend.set(KEYS.pending, writeSecret({ secret, kid }));
    },
    async clearPending() {
      await backend.remove(KEYS.pending);
    },
    /** The pending secret becomes the current one (the server activated it). */
    async promotePending() {
      const pending = await this.pending();
      if (!pending) return false;
      await backend.set(KEYS.secret, writeSecret(pending));
      await backend.remove(KEYS.pending);
      cache = null;
      return true;
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

/** The keystore entry for a secret, for tests seeding a backend. */
export const secretEntry = (secret, kid) => ({ [KEYS.secret]: writeSecret({ secret, kid }) });
