import { createHmac, pbkdf2Sync } from 'node:crypto';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import App from '../../App';
import { PIN_SCHEME } from '../auth/pinCrypto';
import { memoryBackend } from '../device/credentials';
import { createServices } from '../services/services';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';

// HR-22, HR-23, NFR-04: clock in and out from the staff sign-in screen with HR's clock-in PIN,
// checked on the till, then the punches upload. The server is the in-memory fake.

const SECRET = Buffer.alloc(32, 9);
const SALT = Buffer.from('0123456789abcdef');
const AMINA = '0192a5a0-0000-7000-8000-000000000101';
const JOSEPH = '0192a5a0-0000-7000-8000-000000000103';

function material(prefix, id, pin) {
  const key = pbkdf2Sync(pin, SALT, 100000, 32, 'sha256');
  return {
    scheme: PIN_SCHEME,
    kid: 'k1',
    salt: SALT.toString('base64url'),
    iterations: 100000,
    verifier: createHmac('sha256', SECRET).update(Buffer.concat([Buffer.from(`${prefix}:v1:${id}:`), key])).digest('base64url'),
  };
}

function setup({ hr = true } = {}) {
  const server = fakeServer();
  server.define('settings', { mode: 'snapshot', rows: [server.state.settings] });
  server.define('staff', {
    mode: 'snapshot',
    rows: [{ id: 'u1', name: 'Amina Otieno', offline: true, pin: material('pin', 'u1', '274915'), card: null, pin_version: 1, failed_attempts: 0, locked: false, must_change: false, permissions: [] }],
  });
  if (hr) {
    server.define('hr_clock_staff', {
      mode: 'snapshot',
      module: 'hr',
      rows: [
        { id: AMINA, number: 'E-001', name: 'Amina Otieno', version: 1, pin: material('hr-clock', AMINA, '2749') },
        { id: JOSEPH, number: 'E-003', name: 'Joseph Kilonzo', version: 1, pin: material('hr-clock', JOSEPH, '8316') },
      ],
    });
  }
  const services = createServices({ database: testDatabase(), api: server, credentialsBackend: memoryBackend(), netInfo: null });
  return { server, services };
}

async function pair() {
  await fireEvent.changeText(await screen.findByLabelText('Pairing code'), 'abcd-efgh');
  await fireEvent.changeText(screen.getByLabelText('Till name'), 'Front till');
  await fireEvent.press(screen.getByRole('button', { name: 'Pair till' }));
  await fireEvent.press(await screen.findByRole('button', { name: 'Continue' }));
  await screen.findByRole('button', { name: 'Amina Otieno' });
}

async function typePin(pin) {
  for (const digit of pin) await fireEvent.press(screen.getByRole('button', { name: digit }));
}

const TIME = /\d{2}:\d{2}/;

jest.setTimeout(30000);

describe('ClockInScreen', () => {
  it('clocks an employee in, then out, offline-checked, and uploads the punches', async () => {
    const { services, server } = setup();
    const view = await render(<App services={services} />);
    await pair();

    await fireEvent.press(await screen.findByRole('button', { name: 'Clock in or out' }));
    expect(await screen.findByRole('header', { name: 'Clock in or out' })).toBeOnTheScreen();

    // Search narrows the list.
    await fireEvent.changeText(screen.getByLabelText('Search by name or number'), 'e-003');
    await waitFor(() => expect(screen.queryByRole('button', { name: /^Amina Otieno/ })).toBeNull());
    await fireEvent.changeText(screen.getByLabelText('Search by name or number'), 'amina');
    await fireEvent.press(await screen.findByRole('button', { name: /^Amina Otieno, E-001/ }));

    expect(await screen.findByRole('header', { name: 'Clock-in PIN for Amina Otieno' })).toBeOnTheScreen();
    // The till sign-in PIN is not the clock-in PIN.
    await typePin('274915');
    await fireEvent.press(screen.getByRole('button', { name: 'Clock in' }));
    expect(await screen.findByText('Wrong PIN. 4 tries left before clock-in locks for you on this till.')).toBeOnTheScreen();

    await typePin('2749');
    await fireEvent.press(screen.getByRole('button', { name: 'Clock in' }));
    expect(await screen.findByText(new RegExp(`^Clocked in at ${TIME.source}$`))).toBeOnTheScreen();

    await fireEvent.press(screen.getByRole('button', { name: 'Back to the list' }));
    await fireEvent.press(await screen.findByRole('button', { name: new RegExp(`^Amina Otieno, E-001, In since ${TIME.source}$`) }));
    await typePin('2749');
    await fireEvent.press(await screen.findByRole('button', { name: 'Clock out' }));
    expect(await screen.findByText(new RegExp(`^Clocked out at ${TIME.source}$`))).toBeOnTheScreen();

    // Both punches upload with the next push, in order, by their own ids.
    await act(() => services.engine.push({ force: true }));
    const sent = server.requestsTo('hr/till/punches').flatMap((request) => request.body.punches);
    expect(sent.map((punch) => [punch.employee_id, punch.kind])).toEqual([
      [AMINA, 'in'],
      [AMINA, 'out'],
    ]);
    expect(server.state.stored.size).toBe(2);

    await fireEvent.press(screen.getByRole('button', { name: 'Back to the list' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Back to sign-in' }));
    expect(await screen.findByRole('header', { name: 'Who is selling?' })).toBeOnTheScreen();
    await view.unmount();
  });

  it('hides clock-in when the tenant has no HR module', async () => {
    const { services } = setup({ hr: false });
    const view = await render(<App services={services} />);
    await pair();

    expect(screen.queryByRole('button', { name: 'Clock in or out' })).toBeNull();
    await view.unmount();
  });
});
