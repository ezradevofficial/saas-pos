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
    // Not uploaded yet: the fiscal section says so.
    expect(within(screen.getByTestId('fiscal')).getByText('Waiting to upload')).toBeOnTheScreen();

    // NFR-04: the shift and the sale wait offline, then go up in order.
    expect(services.engine.getStatus().pending).toBe(2);
    server.goOnline();
    services.engine.setNetwork('online');
    // POS-10: the server answers the upload with the fiscal state, then the authority accepts it.
    server.state.handler = (method, path, body) => {
      if (method === 'POST' && path === 'pos/sales') return { status: 200, body: { results: body.sales.map((record) => ({ id: record.id, status: 'stored', fiscal: 'pending' })) } };
      if (method === 'GET' && /^pos\/sales\/.+\/fiscal$/.test(path)) return { status: 200, body: { data: { sale: { status: 'accepted', invoice_number: '42', accepted_at: null, authority: {} }, refunds: [], void: null } } };
      return null;
    };
    await act(() => services.engine.push());
    const uploads = server.state.requests.filter((request) => request.method === 'POST' && request.path.startsWith('pos/') && request.path !== 'pos/number-ranges' && request.path !== 'pos/pin/attempts');
    expect(uploads.map((request) => request.path)).toEqual(['pos/shifts', 'pos/sales']);
    const sale = uploads[1].body.sales[0];
    expect(sale).toMatchObject({ cashier_id: SECOND, offline: true, receipt_number: 'R-WL2-000001', totals: { total_minor: '56500' }, actor_proof: { user_id: SECOND, kid: 'k1' } });
    expect(uploads[0].body.shifts[0]).toMatchObject({ opened_by_id: CASHIER, opening_float: [{ currency: 'KES', amount_minor: '100000' }], actor_proof: { user_id: CASHIER } });
    expect(await within(screen.getByTestId('fiscal')).findByText('Accepted · Fiscal invoice 42')).toBeOnTheScreen();

    await fireEvent.press(screen.getByRole('button', { name: 'New sale' }));
    expect(await screen.findByText('0 items')).toBeOnTheScreen();
    await view.unmount();
  });

  it('takes M-Pesa by STK push: requests, keeps checking while unknown, adds the payment once paid', async () => {
    const { services, server } = setup();
    server.define('payment_methods', { mode: 'snapshot', rows: [{ id: id(6), type: 'mobile_money', name: 'M-Pesa', currency: 'KES', provider: 'mpesa_ke', position: 1, capabilities: { stk: true, manual_code: true } }] });
    let polls = 0;
    const pushes = [];
    server.state.handler = (method, path, body) => {
      if (method === 'POST' && path === 'payments/intents') {
        pushes.push(body);
        return { status: 201, body: { data: { id: body.id, status: 'pending', mode: body.mode, amount: { amount_minor: body.amount_minor, currency: body.currency } } } };
      }
      if (method === 'GET' && path.startsWith('payments/intents/')) {
        polls += 1;
        const status = polls === 1 ? 'unknown' : 'succeeded';
        return { status: 200, body: { data: { id: path.split('/').pop(), status, receipt: status === 'succeeded' ? 'QJK9PUSH01' : null, amount: { amount_minor: pushes[0].amount_minor, currency: 'KES' } } } };
      }
      return null;
    };
    const view = await render(<App services={services} />);
    await fireEvent.changeText(await screen.findByLabelText('Pairing code'), 'abcd-efgh');
    await fireEvent.changeText(screen.getByLabelText('Till name'), 'Till 2');
    await fireEvent.press(screen.getByRole('button', { name: 'Pair till' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Continue' }));
    await signInAs('Amina Otieno', '274915');
    await fireEvent.press(await screen.findByRole('button', { name: 'Open shift' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Tusker Lager 500ml, KES 250.00' }));
    await fireEvent.press(screen.getByRole('button', { name: 'Charge KES 250.00' }));

    await fireEvent.changeText(await screen.findByLabelText('Customer’s phone number'), '0722000418');
    // The request waits for the customer, so the press resolves only once the push is final.
    const sending = fireEvent.press(screen.getByRole('button', { name: 'Send payment request for KES 250.00' }));
    // The provider has not answered yet (unknown): still checking.
    expect(await screen.findByText('Request sent to the customer’s phone')).toBeOnTheScreen();
    expect(pushes[0]).toMatchObject({ mode: 'stk', amount_minor: '25000', currency: 'KES', phone: '0722000418', reference_type: 'pos.sale', user_id: CASHIER, payment_method_id: id(6) });
    // The till polls every 3 seconds.
    await act(() => sending);
    expect(await screen.findByText('M-Pesa confirmed the payment (QJK9PUSH01).')).toBeOnTheScreen();
    expect(polls).toBe(2);

    await fireEvent.press(await screen.findByRole('button', { name: 'Complete sale' }));
    expect(await screen.findByText('Sale complete')).toBeOnTheScreen();
    const stored = await services.posStore.recentSales(1);
    // The intent id is the sale payment's id: the server confirms that payment when M-Pesa pays.
    expect(stored[0].payments[0]).toMatchObject({ id: pushes[0].id, provider_reference: 'QJK9PUSH01', status: 'confirmed', amount_minor: '25000' });
    expect(pushes[0].reference).toBe(stored[0].id);
    await view.unmount();
  }, 20000);

  it('gets receipt ranges on a newly paired till before the first sale (NUM-02)', async () => {
    const { services, server } = setup();
    server.state.entities.pos_number_ranges.rows.clear();
    server.state.handler = (method, path, body) => {
      if (method !== 'POST' || path !== 'pos/number-ranges') return null;
      const receipt = body.document_type === 'pos.receipt';
      server.upsert('pos_number_ranges', { id: id(receipt ? 20 : 21), document_type: body.document_type, period: 'all', pattern: receipt ? 'R-WL2-{000001}' : 'F-WL2-{0001}', from: 1, to: 500, next: 1 });
      return { status: 200, body: { data: [] } };
    };
    const view = await render(<App services={services} />);
    await fireEvent.changeText(await screen.findByLabelText('Pairing code'), 'abcd-efgh');
    await fireEvent.changeText(screen.getByLabelText('Till name'), 'Till 2');
    await fireEvent.press(screen.getByRole('button', { name: 'Pair till' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Continue' }));
    await signInAs('Amina Otieno', '274915');
    await fireEvent.press(await screen.findByRole('button', { name: 'Open shift' }));

    // The till asked for both document types it numbers, once each, without anyone selling yet.
    await waitFor(() => expect(server.requestsTo('pos/number-ranges').map((request) => request.body.document_type).sort()).toEqual(['pos.receipt', 'pos.refund']));

    await fireEvent.press(await screen.findByRole('button', { name: 'Tusker Lager 500ml, KES 250.00' }));
    await fireEvent.press(screen.getByRole('button', { name: 'Charge KES 250.00' }));
    expect(await screen.findByText(/Receipt R-WL2-000001/)).toBeOnTheScreen();
    await fireEvent.press(await screen.findByRole('button', { name: 'Exact KES 250.00' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Complete sale' }));
    expect(within(await screen.findByTestId('receipt')).getByText('R-WL2-000001')).toBeOnTheScreen();
    expect(server.requestsTo('pos/number-ranges')).toHaveLength(2);
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

  async function openTill(services) {
    const view = await render(<App services={services} />);
    await fireEvent.changeText(await screen.findByLabelText('Pairing code'), 'abcd-efgh');
    await fireEvent.changeText(screen.getByLabelText('Till name'), 'Till 2');
    await fireEvent.press(screen.getByRole('button', { name: 'Pair till' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Continue' }));
    await signInAs('Amina Otieno', '274915');
    await fireEvent.press(await screen.findByRole('button', { name: 'Open shift' }));
    return view;
  }

  it('keeps a cash payment added while an STK push waits, then locks the sale until it completes (POS-03, NFR-04)', async () => {
    const { services, server } = setup();
    server.define('payment_methods', {
      mode: 'snapshot',
      rows: [
        { id: id(4), type: 'cash', name: 'Cash', currency: 'KES', position: 1 },
        { id: id(6), type: 'mobile_money', name: 'M-Pesa', currency: 'KES', provider: 'mpesa_ke', position: 2, capabilities: { stk: true, manual_code: true } },
      ],
    });
    const pushes = [];
    let polls = 0;
    server.state.handler = (method, path, body) => {
      if (method === 'POST' && path === 'payments/intents') {
        pushes.push(body);
        return { status: 201, body: { data: { id: body.id, status: 'pending', mode: body.mode, amount: { amount_minor: body.amount_minor, currency: body.currency } } } };
      }
      if (method === 'GET' && path.startsWith('payments/intents/')) {
        polls += 1;
        const status = polls === 1 ? 'pending' : 'succeeded';
        return { status: 200, body: { data: { id: path.split('/').pop(), status, receipt: status === 'succeeded' ? 'QJK9PUSH02' : null, amount: { amount_minor: pushes[0].amount_minor, currency: 'KES' } } } };
      }
      return null;
    };
    const view = await openTill(services);
    await fireEvent.press(await screen.findByRole('button', { name: 'Tusker Lager 500ml, KES 250.00' }));
    await fireEvent.press(screen.getByRole('button', { name: 'Charge KES 250.00' }));

    // KES 150 by STK push; while the customer confirms, KES 100 in cash.
    await fireEvent.press(await screen.findByText('M-Pesa'));
    await fireEvent.changeText(screen.getByLabelText('Amount received'), '150');
    await fireEvent.changeText(screen.getByLabelText('Customer’s phone number'), '0722000418');
    const sending = fireEvent.press(screen.getByRole('button', { name: 'Send payment request for KES 150.00' }));
    expect(await screen.findByText('Request sent to the customer’s phone')).toBeOnTheScreen();
    await fireEvent.press(screen.getByText('Cash'));
    await fireEvent.changeText(screen.getByLabelText('Amount received'), '100');
    await fireEvent.press(screen.getByRole('button', { name: 'Add payment' }));
    await act(() => sending);

    // Both payments stand: the push did not overwrite the cash added meanwhile.
    expect(await screen.findByRole('button', { name: 'Remove the Cash payment' })).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Remove the M-Pesa payment' })).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Complete sale' })).toBeEnabled();

    // The customer paid by M-Pesa: the items cannot change (the payment would be dropped).
    await fireEvent.press(screen.getByRole('button', { name: 'Back to the sale' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Supaloaf White 400g, KES 65.00' }));
    expect(await screen.findByText('Mobile money was sent for this sale, so its items and customer cannot change. Complete the sale, or refund the payment first.')).toBeOnTheScreen();
    await fireEvent.press(screen.getByRole('button', { name: 'Charge KES 250.00' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Complete sale' }));
    expect(await screen.findByText('Sale complete')).toBeOnTheScreen();
    const [sale] = await services.posStore.recentSales(1);
    expect(sale.payments.map((payment) => payment.amount_minor).sort()).toEqual(['10000', '15000']);
    await view.unmount();
  }, 20000);

  it('records one sale when Complete sale is pressed twice (POS-01, NFR-04)', async () => {
    const { services } = setup();
    const view = await openTill(services);
    await fireEvent.press(await screen.findByRole('button', { name: 'Tusker Lager 500ml, KES 250.00' }));
    await fireEvent.press(screen.getByRole('button', { name: 'Charge KES 250.00' }));
    await fireEvent.press(await screen.findByRole('button', { name: 'Exact KES 250.00' }));
    const complete = await screen.findByRole('button', { name: 'Complete sale' });
    await act(async () => {
      fireEvent.press(complete);
      fireEvent.press(complete);
    });
    expect(within(await screen.findByTestId('receipt')).getByText('R-WL2-000001')).toBeOnTheScreen();
    expect(await services.posStore.recentSales(5)).toHaveLength(1);
    expect(screen.queryByText('This sale is already recorded. Start a new sale.')).toBeNull();
    await view.unmount();
  });
});
