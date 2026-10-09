import { createHmac, pbkdf2Sync } from 'node:crypto';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react-native';
import App from '../../../App';
import { PIN_SCHEME } from '../../auth/pinCrypto';
import { memoryBackend } from '../../device/credentials';
import { createServices } from '../../services/services';
import { fakeServer } from '../../test/fakeServer';
import { testDatabase } from '../../test/testDatabase';

// POS-01..POS-06, AUTH-07, AUTH-08, NFR-04: the till end to end on the phone layout (Jest's
// window is phone-sized) against the in-memory server: open a shift, sell offline, switch
// user without losing the cart, pay, see the receipt, then upload in order.

const SECRET = Buffer.alloc(32, 9);
const SALT = Buffer.from('0123456789abcdef');
const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;

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

const staffRow = (userId, name, pin, extra = {}) => ({ id: userId, name, offline: true, pin: material(userId, pin), card: null, pin_version: 1, failed_attempts: 0, locked: false, must_change: false, permissions: ['pos.sale.create'], limits: {}, ...extra });
const CASHIER = id(10);
const SECOND = id(11);
const MANAGER = id(12);

function setup() {
  const server = fakeServer();
  const settings = { ...server.state.settings, timezone: 'Africa/Nairobi', device: { id: 'device-1', name: 'Till 2' }, company: { id: id(1), name: 'Duka', country: 'KE', base_currency: 'KES', reporting_currencies: [] } };
  server.define('settings', { mode: 'snapshot', rows: [settings] });
  server.define('staff', {
    mode: 'snapshot',
    rows: [
      staffRow(CASHIER, 'Amina Otieno', '274915'),
      staffRow(SECOND, 'Baraka Mwangi', '508163'),
      staffRow(MANAGER, 'Peter Kamau', '615243', { permissions: ['pos.sale.create', 'pos.discount.give', 'pos.sale.void'], limits: { max_discount_percent: '20.0000' } }),
    ],
  });
  server.define('currencies', { mode: 'snapshot', rows: [{ id: 'KES', code: 'KES', decimals: 2, cash_rounding_minor: '100' }] });
  server.define('tax_codes', { mode: 'snapshot', rows: [{ id: id(3), code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2020-01-01', effective_to: null, needs_confirmation: false }] }] });
  server.define('price_lists', { mode: 'snapshot', rows: [{ id: id(2), name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }] });
  server.define('payment_methods', { mode: 'snapshot', rows: [{ id: id(4), type: 'cash', name: 'Cash', currency: 'KES', position: 1 }, { id: id(6), type: 'mobile_money', name: 'M-Pesa', currency: 'KES', position: 2 }] });
  const item = (itemId, name, code) => ({ id: itemId, code, name, base_uom_id: id(7), tax_code_id: id(3), sellable: true, uoms: [], barcodes: [{ barcode: `600${code}`, uom_id: null }], images: [] });
  server.define('items', { rows: [item(id(8), 'Tusker Lager 500ml', '100'), item(id(9), 'Supaloaf White 400g', '200'), { ...item(id(13), 'Excise Gin 750ml', '300'), sellable: false, reason: 'tax_rate_needed' }] });
  server.define('item_prices', {
    rows: [
      { id: id(30), price_list_id: id(2), item_id: id(8), uom_id: id(7), amount_minor: '25000', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' },
      { id: id(31), price_list_id: id(2), item_id: id(9), uom_id: id(7), amount_minor: '6500', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' },
    ],
  });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: [{ id: id(20), document_type: 'pos.receipt', period: 'all', pattern: 'R-WL2-{000001}', from: 1, to: 500, next: 1 }] });
  server.state.uploadOverride = (path) => (path === 'pos/number-ranges' ? { status: 200, body: { data: [] } } : null);
  const services = createServices({ database: testDatabase(), api: server, credentialsBackend: memoryBackend(), netInfo: null });
  return { server, services };
}

async function typePin(pin) {
  for (const digit of pin) await fireEvent.press(screen.getByRole('button', { name: digit }));
}

async function signInAs(name, pin) {
  await fireEvent.press(await screen.findByRole('button', { name }));
  await typePin(pin);
  await fireEvent.press(screen.getByRole('button', { name: 'Sign in' }));
}

describe('PosScreen', () => {
  it('opens a shift, sells offline, keeps the cart across a user switch, and uploads in order', async () => {
    const { services, server } = setup();
    const view = await render(<App services={services} />);

    await fireEvent.changeText(await screen.findByLabelText('Pairing code'), 'abcd-efgh');
    await fireEvent.changeText(screen.getByLabelText('Till name'), 'Till 2');
    await fireEvent.press(screen.getByRole('button', { name: 'Pair till' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Continue' }));
    await signInAs('Amina Otieno', '274915');

    // POS-04: opening float per drawer currency.
    await fireEvent.changeText(await screen.findByLabelText('Opening cash in KES'), '1,000');
    await fireEvent.press(screen.getByRole('button', { name: 'Open shift' }));

    // POS-01: tiles with list prices; an item whose rate is needed is shown disabled with the reason.
    const tusker = await screen.findByRole('button', { name: 'Tusker Lager 500ml, KES 250.00' });
    expect(screen.getByRole('button', { name: 'Excise Gin 750ml, Rate needed' })).toBeDisabled();
    server.goOffline();
    services.engine.setNetwork('offline');
    await fireEvent.press(tusker);
    await fireEvent.press(tusker);
    // A hardware scanner types the barcode and presses Enter.
    await fireEvent.changeText(screen.getByLabelText('Search products'), '600200');
    await fireEvent(screen.getByLabelText('Search products'), 'submitEditing');
    expect(await screen.findByText('3 items')).toBeOnTheScreen();
    expect(screen.getByText('Offline: cash and M-Pesa till payments are recorded and sent to KRA eTIMS when you reconnect.')).toBeOnTheScreen();

    // AUTH-07: fast user switching keeps the cart and the shift.
    await fireEvent.press(screen.getByRole('button', { name: 'Open the till menu' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Switch user' }));
    await signInAs('Baraka Mwangi', '508163');
    expect(await screen.findByText('3 items')).toBeOnTheScreen();

    // Payment: exact cash, then complete.
    await fireEvent.press(screen.getByRole('button', { name: 'Charge KES 565.00' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Exact KES 565.00' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Complete sale' }));

    // POS-06: the receipt, with the fiscal section pending.
    expect(await screen.findByText('Sale complete')).toBeOnTheScreen();
    const receipt = screen.getByTestId('receipt');
    expect(within(receipt).getByText('R-WL2-000001')).toBeOnTheScreen();
    expect(within(receipt).getByText('Baraka Mwangi')).toBeOnTheScreen();
    expect(within(screen.getByTestId('fiscal')).getByText('Pending')).toBeOnTheScreen();

    // NFR-04: the shift and the sale wait offline, then go up in order.
    expect(services.engine.getStatus().pending).toBe(2);
    server.goOnline();
    services.engine.setNetwork('online');
    await act(() => services.engine.push());
    const uploads = server.state.requests.filter((request) => request.method === 'POST' && request.path.startsWith('pos/') && request.path !== 'pos/number-ranges' && request.path !== 'pos/pin/attempts');
    expect(uploads.map((request) => request.path)).toEqual(['pos/shifts', 'pos/sales']);
    const sale = uploads[1].body.sales[0];
    expect(sale).toMatchObject({ cashier_id: SECOND, offline: true, receipt_number: 'R-WL2-000001', totals: { total_minor: '56500' }, actor_proof: { user_id: SECOND, kid: 'k1' } });
    expect(uploads[0].body.shifts[0]).toMatchObject({ opened_by_id: CASHIER, opening_float: [{ currency: 'KES', amount_minor: '100000' }], actor_proof: { user_id: CASHIER } });

    await fireEvent.press(screen.getByRole('button', { name: 'New sale' }));
    expect(await screen.findByText('0 items')).toBeOnTheScreen();
    await view.unmount();
  });

  it('asks a manager to approve a discount above the cashier’s limit (AUTH-08)', async () => {
    const { services } = setup();
    const view = await render(<App services={services} />);
    await fireEvent.changeText(await screen.findByLabelText('Pairing code'), 'abcd-efgh');
    await fireEvent.changeText(screen.getByLabelText('Till name'), 'Till 2');
    await fireEvent.press(screen.getByRole('button', { name: 'Pair till' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Continue' }));
    await signInAs('Amina Otieno', '274915');
    await fireEvent.press(await screen.findByRole('button', { name: 'Open shift' }));

    await fireEvent.press(await screen.findByRole('button', { name: 'Tusker Lager 500ml, KES 250.00' }));
    await fireEvent.press(await screen.findByText('Tap to see the sale'));
    await fireEvent.press(await screen.findByRole('button', { name: 'Change Tusker Lager 500ml' }));
    await fireEvent.changeText(await screen.findByLabelText('Line discount'), '25');
    // Saving waits for the approval, so the press resolves only once the manager approves.
    const saving = fireEvent.press(screen.getByRole('button', { name: 'Save line' }));

    // Only staff who hold pos.discount.give within the limit are offered.
    expect(await screen.findByText('This discount is above your limit. A manager approves it with their PIN.')).toBeOnTheScreen();
    expect(screen.queryByRole('button', { name: 'Baraka Mwangi' })).toBeNull();
    await fireEvent.press(screen.getByRole('button', { name: 'Peter Kamau' }));
    await typePin('615243');
    await fireEvent.press(screen.getByRole('button', { name: 'Approve' }));
    await saving;

    await waitFor(() => expect(screen.getByText('Discount KES 25.00')).toBeOnTheScreen());
    expect(screen.getByRole('button', { name: 'Charge KES 225.00' })).toBeOnTheScreen();
    await view.unmount();
  });
});
