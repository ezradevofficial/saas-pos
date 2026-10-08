import { activationProof, rotationProof } from '../auth/pinCrypto';
import { NetworkError } from '../sync/api';

/**
 * TEN-05: pairing a till with the one-time code an admin issued
 * (POST devices/pair, public, rate-limited). The code has 8 characters
 * from an alphabet without 0/O, 1/I or L (DevicePairing::ALPHABET).
 */
export const PAIRING_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
export const PAIRING_LENGTH = 8;

/** What the person typed, as the server reads it: upper case, no spaces or dashes. */
export function normalisePairingCode(text) {
  return String(text ?? '').toUpperCase().replace(/[\s-]+/g, '');
}

/** Keep only characters that can be in a code (for the input as it is typed). */
export function filterPairingInput(text) {
  return [...normalisePairingCode(text)].filter((char) => PAIRING_ALPHABET.includes(char)).join('').slice(0, PAIRING_LENGTH);
}

export function isPairingCode(text) {
  const code = normalisePairingCode(text);
  return code.length === PAIRING_LENGTH && [...code].every((char) => PAIRING_ALPHABET.includes(char));
}

/**
 * Pair: resolves { ok: true, device } or { ok: false, error } with error
 * 'invalid_code' | 'name_required' | 'too_many_attempts' | 'offline' | 'failed'.
 * The token and device secret go to the keystore before anything else.
 */
export async function pairDevice({ api, credentials, store, code, deviceName }) {
  if (!isPairingCode(code)) return { ok: false, error: 'invalid_code' };
  const name = String(deviceName ?? '').trim();
  if (!name) return { ok: false, error: 'name_required' };

  let response;
  try {
    response = await api.post('devices/pair', { code: normalisePairingCode(code), device_name: name }, { auth: false });
  } catch (error) {
    if (error instanceof NetworkError) return { ok: false, error: 'offline' };
    throw error;
  }

  if (response.status === 429) return { ok: false, error: 'too_many_attempts' };
  if (response.status === 422) {
    return { ok: false, error: response.body?.errors?.device_name ? 'name_required' : 'invalid_code' };
  }
  if (response.status !== 200 || !response.body?.token) return { ok: false, error: 'failed' };

  const { token, device_secret: secret, device_secret_kid: kid, device } = response.body;
  const previous = await store.device();
  await credentials.clear();
  await credentials.save({ token, secret, kid: kid ?? null });

  // Re-paired as the same device (an admin unpaired it and issued a code for it again):
  // everything stays, the outbox uploads with the new token. As another device: the
  // synced data (another place, maybe another company) is dropped, but unsent records
  // are kept; the server will refuse those of the old device and they stay for review.
  let unsent = 0;
  if (previous && previous.id !== device.id) {
    const counts = await store.counts();
    unsent = counts.pending + counts.failed;
    await store.resetSyncedData();
  }
  await store.saveDevice(device);
  await store.setMeta({ auth: 'ok', authCode: null });
  return { ok: true, device, sameDevice: Boolean(previous && previous.id === device.id), unsent };
}

/**
 * AUTH-06, AUTH-08: replace the device secret in two steps
 * (DeviceSecrets): prove the current secret against a one-time nonce, get
 * a pending secret, keep it in the keystore, then prove it to activate it.
 * A lost answer is harmless: the current secret stays current until
 * activation, and a pending secret is activated on the next attempt.
 * Upload pending sales first: overrides signed under the old secret still
 * verify (retired secrets are kept), but PIN material changes.
 */
export async function rotateDeviceSecret({ api, credentials, deviceId }) {
  let pending = await credentials.pending();
  if (!pending) {
    const current = await credentials.secret();
    if (!current?.secret || !current.kid) return { ok: false, error: 'no_secret' };
    const challenge = await api.get('sync/device-secret/challenge');
    if (challenge.status !== 200) return { ok: false, error: challenge.body?.code ?? 'failed' };
    const nonce = challenge.body?.nonce ?? challenge.body?.data?.nonce;
    const rotated = await api.post('sync/device-secret/rotate', { kid: current.kid, nonce, proof: rotationProof(current.secret, deviceId, nonce) });
    if (rotated.status !== 200) return { ok: false, error: rotated.body?.code ?? 'failed' };
    const body = rotated.body?.data ?? rotated.body;
    pending = { secret: body.device_secret ?? body.secret, kid: body.kid ?? body.device_secret_kid };
    await credentials.savePending(pending);
  }
  const activated = await api.post('sync/device-secret/activate', { kid: pending.kid, proof: activationProof(pending.secret, deviceId, pending.kid) });
  if (activated.status === 422) {
    // Activated before (its answer was lost) or replaced: the server's current kid says which.
    const bootstrap = await api.get('sync/bootstrap');
    if (bootstrap.status === 200 && bootstrap.body?.device_secret_kid === pending.kid) {
      await credentials.promotePending();
      return { ok: true, kid: pending.kid };
    }
    await credentials.clearPending();
    return { ok: false, error: activated.body?.code ?? 'failed' };
  }
  if (activated.status !== 200) return { ok: false, error: activated.body?.code ?? 'failed' };
  await credentials.promotePending();
  return { ok: true, kid: pending.kid };
}
