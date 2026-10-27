import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { SessionProvider } from '../auth/session';
import { memoryBackend } from '../device/credentials';
import { PosProvider } from '../pos/PosProvider';
import { RestaurantProvider } from '../restaurant/RestaurantProvider';
import { PosScreen } from '../screens/pos/PosScreen';
import { createServices, ServicesContext } from '../services/services';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';

// FUEL-09, FUEL-10, FUEL-18, FUEL-19, FUEL-31: the attendant's screens on the phone layout. A fuel station
// till lands on the forecourt; the attendant opens a shift, sells a nozzle through the normal payment
// screens, sees the running litres and closes with the cash counted.

jest.setTimeout(60000);

const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const SECRET = Buffer.alloc(32, 9);
const IDS = { company: id(1), list: id(2), vat: id(3), cash: id(4), uom: id(8), diesel: id(10), user: id(21) };
const PERMISSIONS = ['pos.sale.create', 'fuel.sale.create', 'fuel.shift.open', 'fuel.shift.close', 'fuel.dip.record', 'fuel.voucher.redeem'];
const USER = { id: IDS.user, name: 'Wanjiru Njeri', offline: true, pin: null, card: null, pin_version: 1, failed_attempts: 0, locked: false, must_change: false, permissions: PERMISSIONS, limits: {} };

const nozzle = { id: id(61), code: '1', pump_id: id(71), pump_code: 'P1', pump_name: 'Pump 1', tank_id: id(81), tank_code: 'T1', product_id: id(91), item_id: IDS.diesel, fuel_type: 'diesel', grade: null, last_reading_litres: '1000.000', last_reading_at: '2026-10-12T20:00:00Z' };

function setup({ fuel = true } = {}) {
  const server = fakeServer();
  server.define('settings', {
    mode: 'snapshot',
    rows: [{ id: 'device', timezone: 'Africa/Nairobi', pos_mode: 'fuel_station', device: { id: 'device-1', name: 'Till 1' }, location: { id: id(5), name: 'Westlands forecourt' }, company: { id: IDS.company, name: 'Duka', country: 'KE', base_currency: 'KES', reporting_currencies: [] } }],
  });
  server.define('staff', { mode: 'snapshot', rows: [USER] });
  server.define('currencies', { mode: 'snapshot', rows: [{ id: 'KES', code: 'KES', decimals: 2, cash_rounding_minor: '1' }] });
  server.define('tax_codes', { mode: 'snapshot', rows: [{ id: IDS.vat, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2020-01-01', effective_to: null, needs_confirmation: false }] }] });
  server.define('price_lists', { mode: 'snapshot', rows: [{ id: IDS.list, name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }] });
  server.define('payment_methods', { mode: 'snapshot', rows: [{ id: IDS.cash, type: 'cash', name: 'Cash', currency: 'KES', position: 1 }] });
  server.define('uoms', { rows: [{ id: IDS.uom, code: 'L', name: 'Litre' }] });
  server.define('item_categories', { rows: [] });
  server.define('items', { rows: [{ id: IDS.diesel, code: 'DSL', name: 'Diesel', category_id: null, base_uom_id: IDS.uom, tax_code_id: IDS.vat, sellable: true, uoms: [], barcodes: [], images: [] }] });
  server.define('item_prices', { rows: [{ id: id(40), price_list_id: IDS.list, item_id: IDS.diesel, uom_id: IDS.uom, amount_minor: '1', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' }] });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: [{ id: id(20), document_type: 'pos.receipt', period: 'all', pattern: 'R-FS1-{000001}', from: 1, to: 500, next: 1 }] });
  if (fuel) {
    server.define('fuel_station', { mode: 'snapshot', module: 'fuel', rows: [{ id: id(50), currency: 'KES', litre_tolerance: '0.000', cash_variance_tolerance_minor: '0' }] });
    server.define('fuel_nozzles', { mode: 'snapshot', module: 'fuel', rows: [nozzle] });
    server.define('fuel_prices', { mode: 'snapshot', module: 'fuel', rows: [{ id: id(100), product_id: id(91), price_minor: '18050', currency: 'KES', effective_from: '2026-01-01T00:00:00Z' }] });
    server.define('fuel_tanks', { mode: 'snapshot', module: 'fuel', rows: [{ id: id(81), code: 'T1', name: 'Diesel tank', product_id: id(91), capacity_litres: '5000.000', strapping: [{ id: id(110), effective_from: '2026-01-01T00:00:00Z', points: [['0', '0.000'], ['100', '3000.000']] }] }] });
    server.define('fuel_vehicles', { mode: 'snapshot', module: 'fuel', rows: [] });
    server.define('fuel_vouchers', { mode: 'snapshot', module: 'fuel', rows: [] });
  }
  server.state.uploadOverride = (path) => (path === 'pos/number-ranges' ? { status: 200, body: { data: [] } } : null);
  const services = createServices({ database: testDatabase(), api: server, credentialsBackend: memoryBackend(), netInfo: null });
  return { server, services };
}

async function mount({ services }) {
  await services.credentials.save({ token: '1|device-token', secret: SECRET.toString('base64url'), kid: 'k1' });
  await services.engine.sync();
  await services.selling.openShift({ user: USER, actorProof: null, openingFloat: [{ currency: 'KES', amountMinor: '0' }] });
  return render(
    <ServicesContext.Provider value={services}>
      <SessionProvider initialUser={USER}>
        <PosProvider>
          <RestaurantProvider>
            <PosScreen />
          </RestaurantProvider>
        </PosProvider>
      </SessionProvider>
    </ServicesContext.Provider>,
  );
}

const press = (name) => fireEvent.press(screen.getByRole('button', { name }));
const find = (name) => screen.findByRole('button', { name });

describe('a fuel station till', () => {
  it('lands on the forecourt, opens a shift offline, sells a nozzle through the payment screens and closes with the cash counted', async () => {
    const { services, server } = setup();
    await mount({ services });

    // Home is the forecourt, not the sell screen; no shift yet.
    expect(await screen.findByText('No shift is open. Open a shift to start selling fuel.')).toBeOnTheScreen();

    // Open the shift: the nozzle, its opening reading (a lower one is refused), the attendant is the person signed in.
    server.goOffline();
    services.engine.setNetwork('offline');
    await fireEvent.press(await find('Open shift'));
    await fireEvent.press(await find('P1 / 1'));
    expect(await screen.findByText('Last reading: 1000.000 L')).toBeOnTheScreen();
    await fireEvent.changeText(screen.getByLabelText('Opening meter reading'), '999');
    await press('Open shift');
    expect(await screen.findByText(/This reading is lower than the last one \(1000.000 L\)/)).toBeOnTheScreen();
    await fireEvent.changeText(screen.getByLabelText('Opening meter reading'), '1000');
    await press('Open shift');
    expect(await screen.findByText('Your nozzles')).toBeOnTheScreen();

    // Sell 10 litres: Diesel at KES 180.50 a litre.
    await press('Sell fuel');
    await fireEvent.press(await find(/^P1 \/ 1, Diesel/));
    await fireEvent.changeText(screen.getByLabelText('Litres'), '10');
    await fireEvent.press(await find('Charge KES 1,805.00'));
    await fireEvent.press(await find(/^Exact KES/));
    await press('Complete sale');
    expect(await screen.findByText('Sale complete')).toBeOnTheScreen();

    const [sale] = await services.posStore.recentSales();
    const shift = await services.fuel.shifts.current();
    expect(sale.lines[0]).toMatchObject({ item_id: IDS.diesel, qty: '10.000', unit_price_minor: '18050', total_minor: '180500' });
    expect(sale.extensions.fuel).toEqual({ shift_id: shift.id, lines: [{ line_id: sale.lines[0].id, nozzle_id: nozzle.id }] });

    // Back on the forecourt after the receipt: the running litres and value.
    await press('New sale');
    expect(await screen.findByText('Your nozzles')).toBeOnTheScreen();
    await waitFor(() => expect(screen.getAllByText('10.000 L').length).toBeGreaterThan(0));

    // Close: blocked until the reading and the cash are in, then queued.
    await press('Close shift');
    await press('Close shift');
    expect(await screen.findByText(/The shift can’t close yet\. Missing: P1 \/ 1, Wanjiru Njeri\./)).toBeOnTheScreen();
    await fireEvent.changeText(screen.getByLabelText('Closing meter reading'), '1010');
    await fireEvent.changeText(screen.getByLabelText('Cash counted'), '1805');
    await press('Close shift');
    expect(await screen.findByText('Shift closed')).toBeOnTheScreen();

    const queued = (await services.store.entries('pending')).filter((entry) => entry.kind === 'fuel.shifts');
    expect(queued).toHaveLength(2);
    expect(queued[1].payload.closing).toMatchObject({
      nozzles: [{ nozzle_id: nozzle.id, reading: '1010.000' }],
      tenders: [{ attendant_id: IDS.user, tenders: [{ kind: 'cash', amount_minor: '180500' }] }],
    });
    await press('Back to forecourt');
    expect(await screen.findByText('No shift is open. Open a shift to start selling fuel.')).toBeOnTheScreen();
  });

  it('a till at a location that is not a station says so and keeps the shop open', async () => {
    const { services } = setup({ fuel: false });
    await mount({ services });
    expect(await screen.findByText('This till is not at a fuel station')).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Sell shop items' })).toBeOnTheScreen();
  });
});
