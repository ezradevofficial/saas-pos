import { hmac } from '@noble/hashes/hmac.js';
import { pbkdf2Async } from '@noble/hashes/pbkdf2.js';
import { sha256 } from '@noble/hashes/sha2.js';
import { concatBytes, constantTimeEqual, fromBase64Url, toBase64Url, utf8 } from '../lib/bytes';

/**
 * AUTH-06..AUTH-08, ADR 004: the till's half of the offline PIN scheme
 * `pbkdf2-sha256+hmac-sha256/v1` (server: App\Core\Identity\Pin\Pins).
 *
 *   key      = PBKDF2-HMAC-SHA256(PIN as UTF-8, salt, iterations, 32 bytes)
 *   verifier = HMAC-SHA256(device secret, "pin:v1:{user_id}:" || key)
 *
 * (card: "card:v1:", the card code in upper case). The device secret is
 * the one named by the material's `kid`.
 *
 * PBKDF2 runs on WebCrypto where it exists (the web preview, Jest) and on
 * @noble/hashes elsewhere (Hermes has no WebCrypto). noble's async form
 * yields to the UI thread while it works.
 */
export const PIN_SCHEME = 'pbkdf2-sha256+hmac-sha256/v1';
export const MIN_ITERATIONS = 100000;
const MAX_ITERATIONS = 5000000;

async function webCryptoPbkdf2(password, salt, iterations, length) {
  const subtle = globalThis.crypto?.subtle;
  const key = await subtle.importKey('raw', password, 'PBKDF2', false, ['deriveBits']);
  const bits = await subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, key, length * 8);
  return new Uint8Array(bits);
}

function noblePbkdf2(password, salt, iterations, length) {
  return pbkdf2Async(sha256, password, salt, { c: iterations, dkLen: length, asyncTick: 16 });
}

/** PBKDF2-HMAC-SHA256. `engine`: 'auto' (WebCrypto when present), 'noble' or 'webcrypto'. */
export async function pbkdf2Sha256(password, salt, iterations, length = 32, { engine = 'auto' } = {}) {
  const useWeb = engine === 'webcrypto' || (engine === 'auto' && typeof globalThis.crypto?.subtle?.deriveBits === 'function');
  if (useWeb) {
    try {
      return await webCryptoPbkdf2(password, salt, iterations, length);
    } catch (error) {
      if (engine === 'webcrypto') throw error;
    }
  }
  return noblePbkdf2(password, salt, iterations, length);
}

export function hmacSha256(key, message) {
  return hmac(sha256, key, message);
}

/** The secret as typed: a card code compares in upper case. */
export function normaliseSecret(kind, input) {
  return kind === 'card' ? String(input).toUpperCase() : String(input);
}

/** The verifier for a typed PIN or card (base64url), as the server computes it. */
export async function computeVerifier({ kind = 'pin', userId, input, salt, iterations, deviceSecret, engine }) {
  const saltBytes = typeof salt === 'string' ? fromBase64Url(salt) : salt;
  const secretBytes = typeof deviceSecret === 'string' ? fromBase64Url(deviceSecret) : deviceSecret;
  const key = await pbkdf2Sha256(utf8(normaliseSecret(kind, input)), saltBytes, iterations, 32, { engine });
  return hmacSha256(secretBytes, concatBytes(utf8(`${kind}:v1:${userId}:`), key));
}

/**
 * Check a PIN or card offline against the staff row's material.
 * Resolves { ok } or { ok: false, reason }: 'not_set' (no material: sign in
 * online), 'unsupported' (unknown scheme or iterations out of range),
 * 'no_secret' (no device secret, or not the one the material names),
 * 'incorrect'.
 */
export async function verifyOffline({ material, kind = 'pin', userId, input, deviceSecret, engine }) {
  if (!material?.salt || !material?.verifier) return { ok: false, reason: 'not_set' };
  const iterations = Number(material.iterations);
  if (material.scheme !== PIN_SCHEME || !Number.isInteger(iterations) || iterations < MIN_ITERATIONS || iterations > MAX_ITERATIONS) {
    return { ok: false, reason: 'unsupported' };
  }
  if (!deviceSecret?.secret || (material.kid && deviceSecret.kid && material.kid !== deviceSecret.kid)) {
    return { ok: false, reason: 'no_secret' };
  }
  const salt = fromBase64Url(material.salt);
  const expected = fromBase64Url(material.verifier);
  const secret = fromBase64Url(deviceSecret.secret);
  if (!salt || !expected || !secret) return { ok: false, reason: 'unsupported' };

  const actual = await computeVerifier({ kind, userId, input, salt, iterations, deviceSecret: secret, engine });
  return constantTimeEqual(actual, expected) ? { ok: true } : { ok: false, reason: 'incorrect' };
}

/**
 * The message an offline manager override signs (AUTH-08,
 * OverrideVerifier::offlineMessage). v2 names the secret's kid after the
 * device id; v1 (before key ids) does not.
 */
export function overrideMessage({ version = 2, deviceId, kid, id, managerUserId, cashierUserId, permission, reference, authorisedAt }) {
  const head = version >= 2 ? ['override:v2', deviceId, kid ?? ''] : ['override:v1', deviceId];
  return [...head, id, managerUserId, cashierUserId ?? '', permission, reference ?? '', authorisedAt].join('\n');
}

/** base64url(HMAC-SHA256(device secret, overrideMessage(...))). */
export function signOverride({ deviceSecret, ...fields }) {
  const secret = fromBase64Url(deviceSecret.secret);
  const version = fields.version ?? (deviceSecret.kid ? 2 : 1);
  return toBase64Url(hmacSha256(secret, utf8(overrideMessage({ ...fields, version, kid: fields.kid ?? deviceSecret.kid }))));
}

/** Proofs for the two-step secret rotation (DeviceSecrets::rotate / activate). */
export function rotationProof(secret, deviceId, nonce) {
  return toBase64Url(hmacSha256(fromBase64Url(secret), utf8(`rotate:v1\n${deviceId}\n${nonce}`)));
}

export function activationProof(secret, deviceId, kid) {
  return toBase64Url(hmacSha256(fromBase64Url(secret), utf8(`activate:v1\n${deviceId}\n${kid}`)));
}
