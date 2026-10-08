import { createHmac } from 'node:crypto';
import { NetworkError } from '../sync/api';
import { createSyncStore } from '../sync/store';
import { testDatabase } from '../test/testDatabase';
import { createCredentials, memoryBackend, secretEntry } from './credentials';
import { filterPairingInput, isPairingCode, pairDevice, rotateDeviceSecret } from './pairing';

// TEN-05: pairing; AUTH-06/AUTH-08: device secret kept in the keystore and rotated.

const answer = (status, body) => async () => ({ status, body });

function setup(post) {
  const backend = memoryBackend();
  const credentials = createCredentials(backend);
  const store = createSyncStore(testDatabase());
  const calls = [];
  const api = {
    post: async (path, body, options) => {
      calls.push({ path, body, options });
      return post(path, body);
    },
  };
  return { backend, credentials, store, api, calls };
}

describe('pairing code', () => {
  it('keeps only characters of the pairing alphabet', () => {
    expect(filterPairingInput('ab0c-d1ef ghIL23')).toBe('ABCDEFGH');
    expect(isPairingCode('abcd-efgh')).toBe(true);
    expect(isPairingCode('ABCDEFG0')).toBe(false);
    expect(isPairingCode('ABCDEFG')).toBe(false);
  });
});

describe('pairDevice', () => {
  it('stores the token and secret in the keystore and the device in the database', async () => {
    const { backend, credentials, store, api, calls } = setup(
      answer(200, { token: '1|tok', device_secret: 'c2VjcmV0', device_secret_kid: 'k1', device: { id: 'd1', name: 'Till 1', location_id: 'l1', status: 'active' } }),
    );

    const result = await pairDevice({ api, credentials, store, code: 'abcd-efgh', deviceName: ' Till 1 ' });

    expect(result.ok).toBe(true);
    expect(calls[0]).toEqual({ path: 'devices/pair', body: { code: 'ABCDEFGH', device_name: 'Till 1' }, options: { auth: false } });
    expect(Object.fromEntries(backend.values)).toEqual({ 'device.token': '1|tok', 'device.secret': JSON.stringify({ secret: 'c2VjcmV0', kid: 'k1' }) });
    await expect(store.device()).resolves.toMatchObject({ id: 'd1', locationId: 'l1' });
    // Nothing secret in the database.
    const raw = JSON.stringify((await store.database.get('device').query().fetch()).map((record) => record._raw));
    expect(raw).not.toContain('tok');
    expect(raw).not.toContain('c2VjcmV0');
  });

  it.each([
    [answer(422, { code: 'invalid_pairing_code' }), 'invalid_code'],
    [answer(429, { message: 'Too many' }), 'too_many_attempts'],
    [async () => { throw new NetworkError(new Error('down')); }, 'offline'],
    [answer(500, null), 'failed'],
  ])('translates refusals (%#)', async (post, error) => {
    const { credentials, store, api } = setup(post);

    await expect(pairDevice({ api, credentials, store, code: 'ABCDEFGH', deviceName: 'Till' })).resolves.toEqual({ ok: false, error });
  });

  it('checks the code and name before calling the server', async () => {
    const { credentials, store, api, calls } = setup(answer(200, {}));

    await expect(pairDevice({ api, credentials, store, code: 'ABC', deviceName: 'Till' })).resolves.toEqual({ ok: false, error: 'invalid_code' });
    await expect(pairDevice({ api, credentials, store, code: 'ABCDEFGH', deviceName: ' ' })).resolves.toEqual({ ok: false, error: 'name_required' });
    expect(calls).toHaveLength(0);
  });
});

describe('rotateDeviceSecret', () => {
  const OLD = Buffer.alloc(32, 1).toString('base64url');
  const NEW = Buffer.alloc(32, 2).toString('base64url');
  const hmac = (secret, message) => createHmac('sha256', Buffer.from(secret, 'base64url')).update(message).digest('base64url');

  function server({ loseRotateAnswer = false } = {}) {
    let lost = loseRotateAnswer;
    const calls = [];
    return {
      calls,
      get: async (path) => {
        calls.push(path);
        return { status: 200, body: { nonce: 'n1' } };
      },
      post: async (path, body) => {
        calls.push(path);
        if (path === 'sync/device-secret/rotate') {
          expect(body).toEqual({ kid: 'k1', nonce: 'n1', proof: hmac(OLD, 'rotate:v1\nd1\nn1') });
          return { status: 200, body: { kid: 'k2', device_secret: NEW } };
        }
        if (lost) {
          lost = false;
          throw new NetworkError(new Error('lost'));
        }
        expect(body).toEqual({ kid: 'k2', proof: hmac(NEW, 'activate:v1\nd1\nk2') });
        return { status: 200, body: { kid: 'k2' } };
      },
    };
  }

  it('proves the current secret, keeps the pending one, then activates it', async () => {
    const credentials = createCredentials(memoryBackend(secretEntry(OLD, 'k1')));
    const api = server();

    await expect(rotateDeviceSecret({ api, credentials, deviceId: 'd1' })).resolves.toEqual({ ok: true, kid: 'k2' });
    await expect(credentials.secret()).resolves.toEqual({ secret: NEW, kid: 'k2' });
    await expect(credentials.pending()).resolves.toBeNull();
  });

  it('keeps the current secret when activation is not answered, and finishes next time', async () => {
    const credentials = createCredentials(memoryBackend(secretEntry(OLD, 'k1')));
    const api = server({ loseRotateAnswer: true });

    await expect(rotateDeviceSecret({ api, credentials, deviceId: 'd1' })).rejects.toThrow('network_error');
    await expect(credentials.secret()).resolves.toEqual({ secret: OLD, kid: 'k1' });

    await rotateDeviceSecret({ api, credentials, deviceId: 'd1' });
    await expect(credentials.secret()).resolves.toEqual({ secret: NEW, kid: 'k2' });
    expect(api.calls.filter((path) => path.endsWith('rotate'))).toHaveLength(1);
  });

  it('on a 422 from activate, promotes when the server already names the pending kid, else drops it', async () => {
    const credentials = createCredentials(memoryBackend(secretEntry(OLD, 'k1')));
    await credentials.savePending({ secret: NEW, kid: 'k2' });
    let currentKid = 'k2';
    const api = {
      get: async () => ({ status: 200, body: { device_secret_kid: currentKid } }),
      post: async () => ({ status: 422, body: { code: 'secret_proof_invalid' } }),
    };

    await expect(rotateDeviceSecret({ api, credentials, deviceId: 'd1' })).resolves.toEqual({ ok: true, kid: 'k2' });
    await expect(credentials.secret()).resolves.toEqual({ secret: NEW, kid: 'k2' });

    await credentials.savePending({ secret: OLD, kid: 'k3' });
    currentKid = 'k2';
    await expect(rotateDeviceSecret({ api, credentials, deviceId: 'd1' })).resolves.toEqual({ ok: false, error: 'secret_proof_invalid' });
    await expect(credentials.pending()).resolves.toBeNull();
    await expect(credentials.secret()).resolves.toEqual({ secret: NEW, kid: 'k2' });
  });
});

describe('pairing again (M12)', () => {
  const paired = (id) => answer(200, { token: `tok-${id}`, device_secret: 'c2VjcmV0', device_secret_kid: 'k9', device: { id, name: 'Till', location_id: 'l1', status: 'active' } });

  it('keeps the outbox and synced data when re-paired as the same device', async () => {
    const { credentials, store, api } = setup(paired('d1'));
    await store.saveDevice({ id: 'd1', name: 'Till', location_id: 'l1' });
    await store.enqueue('pos.sales', 's1', { id: 's1' });
    await store.setMeta({ auth: 'lost', authCode: 'unauthenticated', entities: [] });

    const result = await pairDevice({ api, credentials, store, code: 'ABCDEFGH', deviceName: 'Till' });

    expect(result).toMatchObject({ ok: true, sameDevice: true, unsent: 0 });
    expect(await store.counts()).toEqual({ pending: 1, failed: 0 });
    await expect(store.meta()).resolves.toMatchObject({ auth: 'ok', entities: [] });
    await expect(credentials.token()).resolves.toBe('tok-d1');
  });

  it('as another device: drops synced data, keeps unsent records and says how many', async () => {
    const { credentials, store, api } = setup(paired('d2'));
    await store.saveDevice({ id: 'd1', name: 'Till', location_id: 'l1' });
    await store.enqueue('pos.sales', 's1', { id: 's1' });
    await store.applyPage('staff', { replace: true, upserts: [{ id: 'u1', name: 'Amina' }], tombstones: [], cursor: 'c', has_more: false }, 1);

    const result = await pairDevice({ api, credentials, store, code: 'ABCDEFGH', deviceName: 'Till' });

    expect(result).toMatchObject({ ok: true, sameDevice: false, unsent: 1 });
    expect(await store.counts()).toEqual({ pending: 1, failed: 0 });
    await expect(store.staff()).resolves.toEqual([]);
    await expect(store.cursors(['staff'])).resolves.toEqual({});
    await expect(store.device()).resolves.toMatchObject({ id: 'd2' });
  });
});
