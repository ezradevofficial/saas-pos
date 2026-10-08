import { createHmac, pbkdf2Sync } from 'node:crypto';
import { fromBase64Url, toBase64Url } from '../lib/bytes';
import { computeVerifier, overrideMessage, pbkdf2Sha256, PIN_SCHEME, signOverride, verifyOffline } from './pinCrypto';

// AUTH-06..AUTH-08: the offline PIN scheme matches the server's.
// Vectors made with PHP's hash_pbkdf2/hash_hmac, the functions Pins.php uses:
//   salt "0123456789abcdef", device secret 32 × 0x07, 150,000 iterations.
const USER = '0192a5a0-0000-7000-8000-000000000001';
const SALT = 'MDEyMzQ1Njc4OWFiY2RlZg';
const SECRET = 'BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcHBwc';
const PHP_PIN_VERIFIER = '5HgUFO7FHAvn-Y3HO7R1bFm8r_ebiOMjpGISlLC0ptA'; // PIN 482913
const PHP_CARD_VERIFIER = 'ZfhF1lwmgXkDMYKU9rYwpPuN5LkS4PadNvbeDel6hhI'; // card AB12CD34
const PHP_OVERRIDE_V1 = 'eZNS4Vyi_6qGJ_azmBZK8-5_xg6x7r7N_T2g9Ptp3Ss';

const material = (verifier, extra = {}) => ({ scheme: PIN_SCHEME, kid: 'k1', salt: SALT, iterations: 150000, verifier, ...extra });
const deviceSecret = { secret: SECRET, kid: 'k1' };

/** The same algorithm with Node's crypto, to cross-check @noble/hashes. */
function nodeVerifier(kind, userId, input, salt, iterations, secret) {
  const key = pbkdf2Sync(Buffer.from(input, 'utf8'), Buffer.from(salt), iterations, 32, 'sha256');
  return createHmac('sha256', Buffer.from(secret)).update(Buffer.concat([Buffer.from(`${kind}:v1:${userId}:`), key])).digest('base64url');
}

describe('PIN verifier', () => {
  it.each(['noble', 'webcrypto'])('matches the server (PHP) vectors with %s', async (engine) => {
    const pin = await computeVerifier({ kind: 'pin', userId: USER, input: '482913', salt: SALT, iterations: 150000, deviceSecret: SECRET, engine });
    const card = await computeVerifier({ kind: 'card', userId: USER, input: 'ab12cd34', salt: SALT, iterations: 150000, deviceSecret: SECRET, engine });

    expect(toBase64Url(pin)).toBe(PHP_PIN_VERIFIER);
    expect(toBase64Url(card)).toBe(PHP_CARD_VERIFIER);
  });

  it('matches Node crypto for random inputs', async () => {
    const salt = Uint8Array.from({ length: 16 }, (_, i) => (i * 37) % 256);
    const secret = Uint8Array.from({ length: 32 }, (_, i) => (i * 11 + 3) % 256);
    for (const [kind, input] of [['pin', '0471'], ['pin', '905318'], ['card', 'Z9Y8X7W6']]) {
      const ours = await computeVerifier({ kind, userId: USER, input, salt, iterations: 100000, deviceSecret: secret, engine: 'noble' });
      expect(toBase64Url(ours)).toBe(nodeVerifier(kind, USER, kind === 'card' ? input.toUpperCase() : input, salt, 100000, secret));
    }
  });

  it('accepts the right PIN and refuses a wrong one', async () => {
    await expect(verifyOffline({ material: material(PHP_PIN_VERIFIER), userId: USER, input: '482913', deviceSecret })).resolves.toEqual({ ok: true });
    await expect(verifyOffline({ material: material(PHP_PIN_VERIFIER), userId: USER, input: '482914', deviceSecret })).resolves.toEqual({ ok: false, reason: 'incorrect' });
    // Bound to the user: another user's id gives another verifier.
    await expect(
      verifyOffline({ material: material(PHP_PIN_VERIFIER), userId: '0192a5a0-0000-7000-8000-000000000009', input: '482913', deviceSecret }),
    ).resolves.toEqual({ ok: false, reason: 'incorrect' });
  });

  it('accepts a card in any case', async () => {
    await expect(verifyOffline({ material: material(PHP_CARD_VERIFIER), kind: 'card', userId: USER, input: 'ab12CD34', deviceSecret })).resolves.toEqual({ ok: true });
  });

  it('refuses material it cannot check', async () => {
    const check = (m, secret = deviceSecret) => verifyOffline({ material: m, userId: USER, input: '482913', deviceSecret: secret });
    await expect(check(null)).resolves.toMatchObject({ reason: 'not_set' });
    await expect(check(material(PHP_PIN_VERIFIER, { scheme: 'other/v9' }))).resolves.toMatchObject({ reason: 'unsupported' });
    await expect(check(material(PHP_PIN_VERIFIER, { iterations: 1000 }))).resolves.toMatchObject({ reason: 'unsupported' });
    await expect(check(material(PHP_PIN_VERIFIER), null)).resolves.toMatchObject({ reason: 'no_secret' });
    // Material made under another secret (rotation): check online instead.
    await expect(check(material(PHP_PIN_VERIFIER, { kid: 'k2' }))).resolves.toMatchObject({ reason: 'no_secret' });
  });

  it('measures PBKDF2-SHA256 at 150,000 iterations', async () => {
    const salt = fromBase64Url(SALT);
    const password = new TextEncoder().encode('482913');
    const time = async (engine) => {
      const started = performance.now();
      await pbkdf2Sha256(password, salt, 150000, 32, { engine });
      return Math.round(performance.now() - started);
    };
    const noble = await time('noble');
    const webcrypto = await time('webcrypto');
    // Reported in the task report; Hermes on a low-end Android is far slower than Node.
    console.log(`PBKDF2-SHA256 150k: @noble/hashes ${noble} ms, WebCrypto ${webcrypto} ms (Node ${process.version})`);
    expect(noble).toBeLessThan(5000);
  });
});

describe('offline override signature', () => {
  const fields = {
    deviceId: 'dev-1',
    id: '0192a5a0-0000-7000-8000-0000000000aa',
    managerUserId: '0192a5a0-0000-7000-8000-000000000002',
    cashierUserId: null,
    permission: 'pos.sale.void',
    reference: 'sale-9',
    authorisedAt: '2026-10-08T10:00:00.000Z',
  };

  it('signs the v1 message as the server checks it', () => {
    expect(signOverride({ deviceSecret: { secret: SECRET }, ...fields })).toBe(PHP_OVERRIDE_V1);
  });

  it('names the key id in v2', () => {
    expect(overrideMessage({ ...fields, version: 2, kid: 'k1' })).toBe(
      ['override:v2', 'dev-1', 'k1', fields.id, fields.managerUserId, '', 'pos.sale.void', 'sale-9', fields.authorisedAt].join('\n'),
    );
    const signature = signOverride({ deviceSecret, ...fields });
    const expected = createHmac('sha256', Buffer.from(fromBase64Url(SECRET))).update(overrideMessage({ ...fields, version: 2, kid: 'k1' })).digest('base64url');
    expect(signature).toBe(expected);
  });
});
