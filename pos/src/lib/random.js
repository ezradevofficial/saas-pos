import * as ExpoCrypto from 'expo-crypto';

/**
 * Cryptographically random bytes. Web and Node have crypto.getRandomValues;
 * Hermes does not, so native builds use expo-crypto's.
 */
export function randomBytes(length) {
  const bytes = new Uint8Array(length);
  if (typeof globalThis.crypto?.getRandomValues === 'function') {
    globalThis.crypto.getRandomValues(bytes);
  } else {
    ExpoCrypto.getRandomValues(bytes);
  }
  return bytes;
}

/**
 * A UUID v7 (RFC 9562): 48-bit Unix milliseconds, then random bits. Offline
 * records get their id on the device (CLAUDE.md, Data conventions).
 */
export function uuidv7(now = Date.now()) {
  const bytes = randomBytes(16);
  let ms = BigInt(Math.floor(now));
  for (let i = 5; i >= 0; i--) {
    bytes[i] = Number(ms & 0xffn);
    ms >>= 8n;
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x70;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
