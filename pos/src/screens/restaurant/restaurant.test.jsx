import { createHmac, pbkdf2Sync } from 'node:crypto';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react-native';
import { SessionProvider } from '../../auth/session';
import { PIN_SCHEME } from '../../auth/pinCrypto';
import { memoryBackend } from '../../device/credentials';
import { PosProvider } from '../../pos/PosProvider';
import { RestaurantProvider } from '../../restaurant/RestaurantProvider';
import { stubPrinter } from '../../restaurant/tickets';
import { createServices, ServicesContext } from '../../services/services';
import { fakeServer } from '../../test/fakeServer';
import { IDS, id, menuRows, modifierRows, settingsRow, stationRows, tableRows } from '../../test/restaurantFixtures';
import { restaurantServer } from '../../test/restaurantServer';
import { testDatabase } from '../../test/testDatabase';
import { PosScreen } from '../pos/PosScreen';

// REST-02..REST-17, POS-01, AUTH-08: the waiter's screens on the phone layout (Jest's window is
// phone-sized): the floor, the order with modifiers, send, void after send with a manager's
// override, and paying a split bill through the normal payment screens.

jest.setTimeout(60000);

const SECRET = Buffer.alloc(32, 9);
const SALT = Buffer.from('0123456789abcdef');
const ITERATIONS = 100000;

function material(userId, pin) {
  const key = pbkdf2Sync(pin, SALT, ITERATIONS, 32, 'sha256');
  return {
    scheme: PIN_SCHEME,
    kid: 'k1',
    salt: SALT.toString('base64url'),
    iterations: ITERATIONS,
    verifier: createHmac('sha256', SECRET).update(Buffer.concat([Buffer.from(`pin:v1:${userId}:`), key])).digest('base64url'),
  };
}

const PERMISSIONS = ['pos.sale.create', 'restaurant.order.create', 'restaurant.order.edit', 'restaurant.order.send', 'restaurant.order.close', 'restaurant.order.transfer', 'restaurant.order.merge'];
const staffRow = (userId, name, pin, extra = {}) => ({ id: userId, name, offline: true, pin: material(userId, pin), card: null, pin_version: 1, failed_attempts: 0, locked: false, must_change: false, permissions: PERMISSIONS, limits: {}, ...extra });
const WAITER = staffRow(IDS.waiter, 'Amina Otieno', '274915');
const OTHER = staffRow(IDS.waiter2, 'Baraka Mwangi', '508163');
const MANAGER = staffRow(IDS.manager, 'Peter Kamau', '615243', { permissions: [...PERMISSIONS, 'pos.sale.void'], limits: { max_refund_amount: '100000.0000' } });

function setup({ mode = 'restaurant', user = WAITER, retail = false } = {}) {
  const server = fakeServer();
  const remote = restaurantServer(server);
  if (!retail) remote.define();
  server.define('settings', { mode: 'snapshot', rows: [{ ...menuRows.settings, pos_mode: mode ?? undefined }] });
  server.define('staff', { mode: 'snapshot', rows: [WAITER, OTHER, MANAGER] });
  server.define('currencies', { mode: 'snapshot', rows: menuRows.currencies });
  server.define('tax_codes', { mode: 'snapshot', rows: menuRows.taxCodes });
  server.define('price_lists', { mode: 'snapshot', rows: menuRows.priceLists });
  server.define('payment_methods', { mode: 'snapshot', rows: menuRows.paymentMethods });
  server.define('uoms', { rows: menuRows.uoms });
  server.define('item_categories', { rows: menuRows.categories.map((category) => ({ ...category, parent_id: null })) });
  server.define('items', { rows: menuRows.items });
  server.define('item_prices', { rows: menuRows.prices });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: [{ id: id(20), document_type: 'pos.receipt', period: 'all', pattern: 'R-WL2-{000001}', from: 1, to: 500, next: 1 }] });
  if (!retail) {
    server.define('restaurant_tables', { mode: 'snapshot', module: 'restaurant', rows: tableRows });
    server.define('restaurant_stations', { mode: 'snapshot', module: 'restaurant', rows: stationRows });
    server.define('restaurant_modifiers', { mode: 'snapshot', module: 'restaurant', rows: modifierRows });
    server.define('restaurant_settings', { mode: 'snapshot', module: 'restaurant', rows: [settingsRow] });
  }
  server.state.uploadOverride = (path) => (path === 'pos/number-ranges' ? { status: 200, body: { data: [] } } : null);
  const services = createServices({ database: testDatabase(), api: server, credentialsBackend: memoryBackend(), netInfo: null });
  return { server, remote, services, user };
}

async function mount({ services, user }) {
  // The till is paired (its token and offline key are in the keystore) and has synced once; a shift is open.
  await services.credentials.save({ token: '1|device-token', secret: SECRET.toString('base64url'), kid: 'k1' });
  await services.engine.sync();
  await services.selling.openShift({ user, actorProof: null, openingFloat: [{ currency: 'KES', amountMinor: '0' }] });
  return render(
    <ServicesContext.Provider value={services}>
      <SessionProvider initialUser={user}>
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
const choose = async (name) => fireEvent.press(await screen.findByRole('radio', { name }));

async function openTable(name = 'T1') {
  await fireEvent.press(await find(new RegExp(`^${name}, Free`)));
  await press('Open table');
  await screen.findByText(`Table ${name}`);
}

beforeEach(() => {
  stubPrinter.queued.length = 0;
});

describe('the floor (REST-02, REST-04)', () => {
  it('shows tables by area with status as a dot and a word, and hides other waiters\' tables behind the filter', async () => {
    const { services, remote, user } = setup();
    // Baraka has T2 open (set up by another till).
    remote.seed({ id: '0190c000-0000-7000-8000-000000000001', kind: 'table', table_id: IDS.table2, table_name: 'T2', waiter_id: IDS.waiter2, waiter_name: 'Baraka Mwangi', covers: 2, currency: 'KES', opened_at: '2026-11-02T09:00:00Z', updated_at: '2026-11-02T09:00:00Z', updated_by: IDS.waiter2, status: 'open', lines: [], resends: [] });
    await mount({ services, user });

    expect(await screen.findByText('Main hall')).toBeOnTheScreen();
    expect(screen.getByText('Terrace')).toBeOnTheScreen();
    // T1 is free, T3 is blocked (and cannot be tapped); Baraka's T2 is hidden under "Mine".
    expect(screen.getByRole('button', { name: /^T1, Free/ })).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: /^T3, Blocked/ })).toBeDisabled();
    expect(screen.queryByRole('button', { name: /^T2/ })).toBeNull();
    await choose('All');
    expect(await find(/^T2, Occupied/)).toBeOnTheScreen();
    expect(within(screen.getByRole('button', { name: /^T2, Occupied/ })).getByText('Baraka Mwangi')).toBeOnTheScreen();
  });

  it('lists bar tabs across rounds, finds one by name and opens a new one', async () => {
    const { services, user } = setup({ mode: 'bar' });
    await mount({ services, user });
    // Bar mode opens on the tabs.
    expect(await screen.findByText('No open tabs. Open a tab to start a round.')).toBeOnTheScreen();
    await press('New tab');
    await fireEvent.changeText(await screen.findByLabelText('Tab name'), 'Joe at the bar');
    await press('Open tab');
    await screen.findByText('Tab Joe at the bar');
    // Two rounds on the tab.
    await fireEvent.press(await find('Tusker, KES 300.00'));
    await choose(/^Order \(1 item\)/);
    await press('Send 1 item to the kitchen');
    await screen.findByText(/1 item sent in round 1/);
    await press('Back to tables');
    expect(await screen.findByText('Joe at the bar')).toBeOnTheScreen();
    expect(screen.getByText(/1 round/)).toBeOnTheScreen();
    await fireEvent.changeText(screen.getByLabelText('Search tabs by name'), 'nobody');
    expect(await screen.findByText('No tab matches that name.')).toBeOnTheScreen();
    await fireEvent.changeText(screen.getByLabelText('Search tabs by name'), 'joe');
    expect(await screen.findByText('Joe at the bar')).toBeOnTheScreen();
  });

  it('opens a retail till on the sell screen, exactly as before', async () => {
    const { services, user } = setup({ mode: 'retail', retail: true });
    await mount({ services, user });
    expect(await screen.findByLabelText('Search products')).toBeOnTheScreen();
    expect(screen.queryByText('Main hall')).toBeNull();
    // No Tables entry in the menu.
    await press('Open the till menu');
    expect(await screen.findByRole('button', { name: 'Sales and returns' })).toBeOnTheScreen();
    expect(screen.queryByRole('button', { name: 'Quick sale' })).toBeNull();
  });

  it('is a floor till when the settings row does not name the mode but the module\'s data is there', async () => {
    const { services, user } = setup({ mode: null });
    await mount({ services, user });
    expect(await screen.findByText('Main hall')).toBeOnTheScreen();
  });

  it('keeps the retail sell screen reachable as a quick sale', async () => {
    const { services, user } = setup();
    await mount({ services, user });
    await press('Quick sale');
    expect(await screen.findByLabelText('Search products')).toBeOnTheScreen();
  });
});

describe('the order (REST-06, REST-07, REST-09, REST-11)', () => {
  it('asks for the modifier choice (minimum, price effect) and shows it on the line; send marks the round and queues the ticket', async () => {
    const { services, user } = setup();
    await mount({ services, user });
    await openTable('T1');

    await fireEvent.press(await find('Burger, KES 500.00'));
    // Cooking asks for exactly one; the dialog cannot be confirmed before.
    expect(await screen.findByText('Choose 1')).toBeOnTheScreen();
    expect(screen.getByText('Choose up to 2')).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: /^Add to order/ })).toBeDisabled();
    await choose('Rare');
    await fireEvent.press(screen.getByRole('checkbox', { name: 'Cheese, +KES 50.00' }));
    await fireEvent.changeText(screen.getByLabelText('Note for the kitchen'), 'no onions');
    await fireEvent.press(screen.getByRole('button', { name: 'Add to order · KES 550.00' }));

    await fireEvent.press(await find('Tusker, KES 300.00'));
    await fireEvent.press(screen.getByRole('button', { name: 'Tusker, KES 300.00' }));
    await choose(/^Order \(2 items\)/);
    expect(await screen.findByText('1 × Burger')).toBeOnTheScreen();
    expect(screen.getByText('+ Rare, + Cheese')).toBeOnTheScreen();
    expect(screen.getByText('“no onions”')).toBeOnTheScreen();
    // The same plain item twice is one line of 2.
    expect(screen.getByText('2 × Tusker')).toBeOnTheScreen();

    await press('Send 2 items to the kitchen');
    expect(await screen.findByText(/2 items sent in round 1\./)).toBeOnTheScreen();
    expect(screen.getByText('Round 1')).toBeOnTheScreen();
    expect(screen.getByText(/No kitchen printer is connected/)).toBeOnTheScreen();
    // One ticket per station: the kitchen has the burger with its choices and note, the bar the beers.
    const texts = stubPrinter.queued.map((ticket) => ticket.text);
    expect(texts).toHaveLength(2);
    expect(texts.find((text) => text.startsWith('Kitchen'))).toMatch(/1 x Burger[\s\S]*\+ Rare[\s\S]*\+ Cheese[\s\S]*\* no onions/);
    expect(texts.find((text) => text.startsWith('Bar'))).toContain('2 x Tusker');

    const [order] = await services.restaurant.orders.open();
    expect(order.lines.every((line) => line.sent_at && line.round === 1)).toBe(true);
    expect(order.lines[0].modifiers).toEqual([{ id: IDS.rare, name: 'Rare', price_minor: '0' }, { id: IDS.cheese, name: 'Cheese', price_minor: '5000' }]);
  });

  it('holds a later course until it is fired', async () => {
    const { services, user } = setup();
    await mount({ services, user });
    await openTable('T1');
    await fireEvent.press(await find('Tusker, KES 300.00'));
    await choose('Course 2');
    await fireEvent.press(await find('Tusker, KES 300.00'));
    await choose(/^Order \(2 items\)/);
    // The first course goes; the second is held.
    await press('Send 1 item to the kitchen');
    expect(await screen.findByText('1 item is held for a later course.')).toBeOnTheScreen();
    await press('Fire course 2');
    expect(await screen.findByText(/Course 2 fired: 1 item sent\./)).toBeOnTheScreen();
    expect(screen.getByText('Round 2')).toBeOnTheScreen();
  });

  it('voids a sent line with a reason and a manager\'s override, keeps it on the order and tells the station', async () => {
    const { services, user } = setup();
    await mount({ services, user });
    await openTable('T1');
    await fireEvent.press(await find('Tusker, KES 300.00'));
    await choose(/^Order \(1 item\)/);
    await press('Send 1 item to the kitchen');
    await screen.findByText(/1 item sent in round 1\./);

    await fireEvent.press(screen.getByRole('button', { name: /^1 Tusker/ }));
    // A reason is required.
    await press('Void item');
    expect(await screen.findByText('Enter a reason, then void the item.')).toBeOnTheScreen();
    await fireEvent.changeText(screen.getByLabelText('Reason'), 'Guest changed their mind');
    await press('Void item');

    // The waiter has no void right: a manager approves with their PIN.
    expect(await screen.findByText('Manager approval')).toBeOnTheScreen();
    await fireEvent.press(await find('Peter Kamau'));
    for (const digit of '615243') await fireEvent.press(screen.getByRole('button', { name: digit }));
    await press('Approve');

    expect(await screen.findByText(/Tusker voided\./)).toBeOnTheScreen();
    await waitFor(async () => {
      const [order] = await services.restaurant.orders.open();
      expect(order.lines[0].removed).toMatchObject({ reason: 'Guest changed their mind', by: IDS.waiter, override: { manager_user_id: IDS.manager, permission: 'pos.sale.void', reference: order.lines[0].id } });
    });
    // The station got a void ticket.
    expect(stubPrinter.queued.at(-1).text).toContain('VOID');
    // The line stays, marked void.
    expect(screen.getByText('Voided')).toBeOnTheScreen();
  });

  it('shows each station\'s ticket state and lets the waiter mark it served', async () => {
    const { services, remote, user } = setup();
    await mount({ services, user });
    await openTable('T1');
    await fireEvent.press(await find('Tusker, KES 300.00'));
    await choose(/^Order \(1 item\)/);
    await press('Send 1 item to the kitchen');
    await screen.findByText(/1 item sent in round 1\./);
    // The server made the ticket and the next pull brings its state.
    await act(async () => {
      await services.engine.sync();
    });
    const strip = await screen.findByTestId('ticket-strip');
    expect(within(strip).getByText('New')).toBeOnTheScreen();
    expect(within(strip).getByText(/^#1 Kitchen/)).toBeOnTheScreen();
    await fireEvent.press(within(strip).getByRole('button', { name: 'Mark ticket 1 served' }));
    expect(await within(screen.getByTestId('ticket-strip')).findByText('Served')).toBeOnTheScreen();
    await act(async () => {
      await services.engine.sync();
    });
    expect(remote.state.marks).toEqual([{ id: expect.any(String), status: 'served', user_id: IDS.waiter }]);
  });

  it('voids within the person\'s own limit without asking a manager', async () => {
    const { services } = setup();
    await mount({ services, user: MANAGER });
    await openTable('T1');
    await fireEvent.press(await find('Tusker, KES 300.00'));
    await choose(/^Order \(1 item\)/);
    await press('Send 1 item to the kitchen');
    await screen.findByText(/1 item sent in round 1\./);
    await fireEvent.press(screen.getByRole('button', { name: /^1 Tusker/ }));
    await fireEvent.changeText(await screen.findByLabelText('Reason'), 'Spilt');
    await press('Void item');
    expect(await screen.findByText(/Tusker voided\./)).toBeOnTheScreen();
    expect(screen.queryByText('Manager approval')).toBeNull();
    const [order] = await services.restaurant.orders.open();
    expect(order.lines[0].removed.override).toBeUndefined();
    expect(order.lines[0].removed.actor_proof).toBeUndefined();
  });
});

describe('the bill and split payment (REST-13, REST-14, REST-16)', () => {
  async function orderWithTwoBeers(services, user) {
    await mount({ services, user });
    await openTable('T1');
    await fireEvent.press(await find('Tusker, KES 300.00'));
    await fireEvent.press(screen.getByRole('button', { name: 'Tusker, KES 300.00' }));
    await choose(/^Order \(1 item\)/);
    await press('Send 1 item to the kitchen');
    await screen.findByText(/1 item sent in round 1\./);
  }

  it('splits equally in two: each part is a normal sale with extensions.restaurant, the last is final, the order closes', async () => {
    const { services, remote, server, user } = setup();
    await orderWithTwoBeers(services, user);
    await press('Bill and pay');
    expect(await screen.findByText('Bill for T1')).toBeOnTheScreen();
    await choose('Equally');
    // 2 beers = 60000 + 16% = 69600; service 10% = 6000 + 960 tax = 6960; total 76560; two parts of 38280.
    expect(await screen.findByTestId('part-1')).toBeOnTheScreen();
    expect(within(screen.getByTestId('part-1')).getByText('KES 382.80')).toBeOnTheScreen();
    expect(within(screen.getByTestId('part-2')).getByText('KES 382.80')).toBeOnTheScreen();

    // Part 1 through the normal payment screen.
    await fireEvent.press(within(screen.getByTestId('part-1')).getByRole('button', { name: 'Charge KES 382.80' }));
    await fireEvent.press(await find('Exact KES 383.00'));
    await press('Complete sale');
    expect(await screen.findByText('Sale complete')).toBeOnTheScreen();
    await press('New sale');
    // Back on the bill: part 1 is paid, the split is fixed.
    expect(await screen.findByText('A part is paid, so the split can no longer change.')).toBeOnTheScreen();
    expect(within(screen.getByTestId('part-1')).getByText('Paid')).toBeOnTheScreen();

    await fireEvent.press(within(screen.getByTestId('part-2')).getByRole('button', { name: 'Charge KES 382.80' }));
    await fireEvent.press(await find('Exact KES 383.00'));
    await press('Complete sale');
    await screen.findByText('Sale complete');
    await press('New sale');
    expect(await screen.findByText('Order paid')).toBeOnTheScreen();

    const sales = (await services.posStore.recentSales()).reverse();
    expect(sales.map((sale) => sale.extensions.restaurant)).toMatchObject([
      { mode: 'equal', part: 1, parts: 2, final: false, covers: 2, order_id: expect.any(String) },
      { mode: 'equal', part: 2, parts: 2, final: true, covers: 2 },
    ]);
    expect(sales[0].extensions.restaurant.order_id).toBe(sales[1].extensions.restaurant.order_id);
    expect(sales.map((sale) => sale.totals.total_minor)).toEqual(['38280', '38280']);
    // The service charge is a line of the service item, taxed by it.
    expect(sales[0].lines.map((line) => line.item_name)).toEqual(['Tusker', 'Service charge']);
    const [order] = await services.restaurant.orders.all();
    expect(order.local.closed).toBe('paid');
    expect(order.local.paid).toHaveLength(2);

    // The order reaches the server before the sales that pay it.
    await services.engine.sync({ pull: false });
    const paths = server.state.requests.filter((request) => request.method === 'POST').map((request) => request.path);
    expect(paths.indexOf('restaurant/orders/sync')).toBeLessThan(paths.indexOf('pos/sales'));
    expect(remote.order(order.id)).toBeDefined();
  });

  it('splits by item: lines are put in groups and each group is paid on its own', async () => {
    const { services, user } = setup();
    await mount({ services, user });
    await openTable('T1');
    await fireEvent.press(await find('Tusker, KES 300.00'));
    await fireEvent.press(await find('Salad, KES 120.00'));
    // Mains offer Extras (optional): the choice is skipped.
    await fireEvent.press(await find('Add to order · KES 120.00'));
    await choose(/^Order \(2 items\)/);
    await press('Bill and pay');
    await choose('By item');
    // Both lines start in group 1: not a split yet.
    expect(await screen.findByText(/This split does not work yet/)).toBeOnTheScreen();
    const salad = screen.getByText('1 × Salad').parent.parent;
    await fireEvent.press(within(salad).getByRole('radio', { name: 'Put Salad in group 2' }));
    await waitFor(() => expect(screen.queryByText(/This split does not work yet/)).toBeNull());
    // Salad: 12000 + 16% + 10% service (+ its tax); beer likewise.
    expect(within(screen.getByTestId('part-2')).getByText('KES 153.12')).toBeOnTheScreen();
    await fireEvent.press(within(screen.getByTestId('part-2')).getByRole('button', { name: 'Charge KES 153.12' }));
    await fireEvent.press(await find('Exact KES 154.00'));
    await press('Complete sale');
    await screen.findByText('Sale complete');
    const [sale] = await services.posStore.recentSales();
    expect(sale.extensions.restaurant).toMatchObject({ mode: 'item', part: 2, parts: 2, final: false, line_ids: [expect.any(String)] });
  });

  it('explains what blocks closing instead of ringing a sale without its service item', async () => {
    const { services, server, user } = setup();
    server.define('restaurant_settings', { mode: 'snapshot', module: 'restaurant', rows: [{ ...settingsRow, service_charge_item_id: null }] });
    await mount({ services, user });
    await openTable('T1');
    await fireEvent.press(await find('Tusker, KES 300.00'));
    await choose(/^Order \(1 item\)/);
    await press('Bill and pay');
    expect(await screen.findByText(/The service charge item is not set for this company/)).toBeOnTheScreen();
    await act(async () => {
      await fireEvent.press(screen.getByRole('button', { name: /^Charge / }));
    });
    expect(screen.queryByText('Sale complete')).toBeNull();
  });
});
