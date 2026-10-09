import { hmac } from '@noble/hashes/hmac.js';
import { pbkdf2Async } from '@noble/hashes/pbkdf2.js';
import { sha256 } from '@noble/hashes/sha2.js';
import AppCrypto from '../../modules/app-crypto';
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
 * PBKDF2 runs, in order of preference, on the local AppCrypto native module
 * (modules/app-crypto: Android javax.crypto, iOS CommonCrypto; in the
 * development build), on WebCrypto (the web preview, Jest), and on
 * @noble/hashes (pure JS; on Hermes, which has no JIT, it takes seconds at
 * 150,000 iterations, so it is the last resort).
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

/** Standard base64 with padding (what the native modules read). */
function toBase64(bytes) {
  const url = toBase64Url(bytes).replace(/-/g, '+').replace(/_/g, '/');
  return url + '='.repeat((4 - (url.length % 4)) % 4);
}

async function nativePbkdf2(native, password, salt, iterations, length) {
  const key = fromBase64Url(await native.pbkdf2Sha256(new TextDecoder().decode(password), toBase64(salt), iterations, length));
  if (!key || key.length !== length) throw new Error('native PBKDF2 returned a bad key');
  return key;
}

const ENGINES = { native: nativePbkdf2, webcrypto: (_native, ...args) => webCryptoPbkdf2(...args), noble: (_native, ...args) => noblePbkdf2(...args) };

/**
 * The PBKDF2 engines to try, best first: the native module when built in,
 * WebCrypto when present, @noble/hashes always.
 */
export function pbkdf2Engines(native = AppCrypto, crypto = globalThis.crypto) {
  const engines = [];
  if (typeof native?.pbkdf2Sha256 === 'function') engines.push('native');
  if (typeof crypto?.subtle?.deriveBits === 'function') engines.push('webcrypto');
  engines.push('noble');
  return engines;
}

/**
 * PBKDF2-HMAC-SHA256 of `password` (bytes) with `salt` (bytes). `engine`:
 * 'auto' (the best one that works, falling back on an error), or one of
 * 'native' | 'webcrypto' | 'noble' exactly. `native` is for tests.
 */
export async function pbkdf2Sha256(password, salt, iterations, length = 32, { engine = 'auto', native = AppCrypto } = {}) {
  if (engine !== 'auto') return ENGINES[engine](native, password, salt, iterations, length);
  const engines = pbkdf2Engines(native);
  for (const name of engines) {
    try {
      return await ENGINES[name](native, password, salt, iterations, length);
    } catch (error) {
      if (name === 'noble') throw error;
    }
  }
  throw new Error('no PBKDF2 engine');
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
 * OverrideVerifier::offlineMessage, `override:v2`): the lines below joined
 * by "\n". The kid names the device secret that signs; the reference (the
 * sale or line the override is for) is required.
 */
export function overrideMessage({ deviceId, kid, id, managerUserId, cashierUserId, permission, reference, authorisedAt }) {
  if (!kid || !reference) throw new Error('An offline override needs the secret kid and a reference');
  const lines = [deviceId, kid, id, managerUserId, cashierUserId ?? '', permission, reference, authorisedAt];
  // A line break would let one field pose as the next: the server would check another message.
  if (lines.some((line) => typeof line !== 'string' || /[\r\n]/.test(line))) throw new Error('Override fields must be single-line strings');
  return ['override:v2', ...lines].join('\n');
}

/**
 * Sign an offline override: { signature: base64url(HMAC-SHA256(device
 * secret, overrideMessage(...))), authorisedAt }. authorisedAt is the
 * server's clock as the till knows it (engine.serverNow), not the till's.
 */
export function signOverride({ deviceSecret, serverNow, authorisedAt, ...fields }) {
  const at = authorisedAt ?? new Date(serverNow()).toISOString();
  const secret = fromBase64Url(deviceSecret.secret);
  return { signature: toBase64Url(hmacSha256(secret, utf8(overrideMessage({ ...fields, authorisedAt: at, kid: deviceSecret.kid })))), authorisedAt: at };
}

/**
 * AUTH-07: the sign-in attestation (`actor_proof`) the till makes when a
 * staff member signs in (PIN checked offline, or online through
 * pos/pin/verify), and attaches to every record that person makes:
 *
 *   signin:v1\n{device_id}\n{kid}\n{session_id}\n{user_id}\n{signed_in_at}
 *
 * signed with HMAC-SHA256 under the device's current secret (base64url,
 * no padding), as overrides are. The server (ActorProofVerifier) checks
 * the secret's validity window, that the person is active staff here,
 * and that the proof names the record's actor.
 */
export function signInMessage({ deviceId, kid, sessionId, userId, signedInAt }) {
  const lines = [deviceId, kid, sessionId, userId, signedInAt];
  if (lines.some((line) => typeof line !== 'string' || line === '' || /[\r\n]/.test(line))) throw new Error('Sign-in fields must be single-line strings');
  return ['signin:v1', ...lines].join('\n');
}

/** { session_id, user_id, signed_in_at, kid, signature } for the API, or null without a device secret. */
export function signActorProof({ deviceSecret, deviceId, sessionId, userId, signedInAt }) {
  if (!deviceSecret?.secret || !deviceSecret.kid) return null;
  const secret = fromBase64Url(deviceSecret.secret);
  const message = signInMessage({ deviceId, kid: deviceSecret.kid, sessionId, userId, signedInAt });
  return { session_id: sessionId, user_id: userId, signed_in_at: signedInAt, kid: deviceSecret.kid, signature: toBase64Url(hmacSha256(secret, utf8(message))) };
}

/** Proofs for the two-step secret rotation (DeviceSecrets::rotate / activate). */
export function rotationProof(secret, deviceId, nonce) {
  return toBase64Url(hmacSha256(fromBase64Url(secret), utf8(`rotate:v1\n${deviceId}\n${nonce}`)));
}

export function activationProof(secret, deviceId, kid) {
  return toBase64Url(hmacSha256(fromBase64Url(secret), utf8(`activate:v1\n${deviceId}\n${kid}`)));
}
