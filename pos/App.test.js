import { createHmac, pbkdf2Sync } from 'node:crypto';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import App from './App';
import { createServices } from './src/app/services';
import { PIN_SCHEME } from './src/auth/pinCrypto';
import { memoryBackend } from './src/device/credentials';
import { fakeServer } from './src/test/fakeServer';
import { testDatabase } from './src/test/testDatabase';

// TEN-05, AUTH-06, AUTH-07: pair the till, sign in with a PIN offline-checked,
// switch user. The server is the in-memory fake (src/test/fakeServer.js).

const SECRET = Buffer.alloc(32, 9);
const SALT = Buffer.from('0123456789abcdef');

function material(userId, pin) {
  const key = pbkdf2Sync(pin, SALT, 100000, 32, 'sha256');
  return {
    scheme: PIN_SCHEME,
    kid: 'k1',
    salt: SALT.toString('base64url'),
    iterations: 100000,
    verifier: createHmac('sha256', SECRET).update(Buffer.concat([Buffer.from(`pin:v1:${userId}:`), key])).digest('base64url'),
  };
}

const staffRow = (id, name, pin, extra = {}) => ({ id, name, offline: true, pin: material(id, pin), card: null, pin_version: 1, failed_attempts: 0, locked: false, must_change: false, permissions: [], ...extra });

function setup() {
  const server = fakeServer();
  server.define('settings', { mode: 'snapshot', rows: [server.state.settings] });
  server.define('staff', { mode: 'snapshot', rows: [staffRow('u1', 'Amina Otieno', '274915'), staffRow('u2', 'Baraka Mwangi', '508163')] });
  const services = createServices({ database: testDatabase(), api: server, credentialsBackend: memoryBackend(), netInfo: null });
  return { server, services };
}

async function typePin(pin) {
  for (const digit of pin) await fireEvent.press(screen.getByRole('button', { name: digit }));
}

describe('App', () => {
  it('shows the app name from EXPO_PUBLIC_APP_NAME', async () => {
    const { services } = setup();
    await render(<App services={services} />);

    expect(await screen.findByRole('header', { name: 'Test app' })).toBeOnTheScreen();
  });

  it('pairs, signs in with a PIN and switches user while keeping the till ready', async () => {
    const { services, server } = setup();
    const view = await render(<App services={services} />);

    // Pairing: wrong code first, translated error.
    await fireEvent.changeText(await screen.findByLabelText('Pairing code'), 'zzzz-zzzz');
    await fireEvent.changeText(screen.getByLabelText('Till name'), 'Front till');
    await fireEvent.press(screen.getByRole('button', { name: 'Pair till' }));
    expect(await screen.findByText('This pairing code is not valid or has expired. Ask an admin for a new code.')).toBeOnTheScreen();

    await fireEvent.changeText(screen.getByLabelText('Pairing code'), 'abcd-efgh');
    await fireEvent.press(screen.getByRole('button', { name: 'Pair till' }));
    expect(await screen.findByText('This till now sells for Westlands shop.')).toBeOnTheScreen();
    expect(server.requestsTo('devices/pair').at(-1).body).toEqual({ code: 'ABCDEFGH', device_name: 'Front till' });

    await fireEvent.press(screen.getByRole('button', { name: 'Continue' }));

    // Staff list after the first sync.
    await fireEvent.press(await screen.findByRole('button', { name: 'Amina Otieno' }));
    await typePin('000000');
    await fireEvent.press(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText('Wrong PIN. 4 tries left before this PIN locks on this till.')).toBeOnTheScreen();

    await typePin('274915');
    await fireEvent.press(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText('Ready to sell')).toBeOnTheScreen();
    expect(screen.getByText('Signed in as Amina Otieno')).toBeOnTheScreen();
    expect(screen.getByText('Westlands shop')).toBeOnTheScreen();

    // Fast user switching.
    await fireEvent.press(screen.getByRole('button', { name: 'Switch user' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Baraka Mwangi' }));
    await typePin('508163');
    await fireEvent.press(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText('Signed in as Baraka Mwangi')).toBeOnTheScreen();

    // The wrong attempt was reported for the server's count (AUTH-06).
    await waitFor(() => expect(server.state.pinReports).toEqual([expect.objectContaining({ user_id: 'u1', failed_attempts: 1, locked: false })]));
    await view.unmount();
  });
});
