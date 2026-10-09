import { createHmac, pbkdf2Sync } from 'node:crypto';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react-native';
import { themes } from '@app/tokens';
import App from '../../../App';
import { PIN_SCHEME } from '../../auth/pinCrypto';
import { memoryBackend } from '../../device/credentials';
import { resetDisplayChannel } from '../../pos/customerDisplay';
import { createServices } from '../../services/services';
import { fakeServer } from '../../test/fakeServer';
import { testDatabase } from '../../test/testDatabase';
import { clearMediaMemory } from '../../theme/media';
import { CustomerDisplayScreen } from '../CustomerDisplayScreen';

// NativeWind's vars() is opaque on native; the identity version shows which variables reach the root.
jest.mock('nativewind', () => ({ ...jest.requireActual('nativewind'), vars: (variables) => ({ ...variables }) }));

// LAY-05, BR-01, BR-02, NFR-04: the till draws the synced theme and the location's POS layout,
// and feeds the customer display. Jest's window is phone-sized (PosPhone).

const SECRET = Buffer.alloc(32, 9);
const SALT = Buffer.from('0123456789abcdef');
const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const LOGO = id(40);
const LOGO_URI = 'data:image/png;base64,iVBORw0KGgo=';

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

const CASHIER = id(10);
const DRINKS = id(50);
const BAKERY = id(51);
const TUSKER = id(8);
const LOAF = id(9);
const COLA = id(13);

function setup({ theme = null, layout = null } = {}) {
  const server = fakeServer();
  const settings = {
    ...server.state.settings,
    timezone: 'Africa/Nairobi',
    device: { id: 'device-1', name: 'Till 2' },
    company: { id: id(1), name: 'Duka', country: 'KE', base_currency: 'KES', reporting_currencies: [] },
    theme,
  };
  server.define('settings', { mode: 'snapshot', rows: [settings] });
  server.define('staff', {
    mode: 'snapshot',
    rows: [{ id: CASHIER, name: 'Amina Otieno', offline: true, pin: material(CASHIER, '274915'), card: null, pin_version: 1, failed_attempts: 0, locked: false, must_change: false, permissions: ['pos.sale.create'], limits: {} }],
  });
  server.define('currencies', { mode: 'snapshot', rows: [{ id: 'KES', code: 'KES', decimals: 2, cash_rounding_minor: '100' }] });
  server.define('tax_codes', { mode: 'snapshot', rows: [{ id: id(3), code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2020-01-01', effective_to: null, needs_confirmation: false }] }] });
  server.define('price_lists', { mode: 'snapshot', rows: [{ id: id(2), name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }] });
  server.define('payment_methods', { mode: 'snapshot', rows: [{ id: id(4), type: 'cash', name: 'Cash', currency: 'KES', position: 1 }] });
  server.define('item_categories', { rows: [{ id: DRINKS, name: 'Drinks', parent_id: null }, { id: BAKERY, name: 'Bakery', parent_id: null }] });
  const item = (itemId, name, code, categoryId) => ({ id: itemId, code, name, category_id: categoryId, base_uom_id: id(7), tax_code_id: id(3), sellable: true, uoms: [], barcodes: [], images: [] });
  server.define('items', { rows: [item(TUSKER, 'Tusker Lager 500ml', '100', DRINKS), item(LOAF, 'Supaloaf White 400g', '200', BAKERY), item(COLA, 'Coca-Cola 500ml', '300', DRINKS)] });
  server.define('item_prices', {
    rows: [TUSKER, LOAF, COLA].map((itemId, index) => ({ id: id(30 + index), price_list_id: id(2), item_id: itemId, uom_id: id(7), amount_minor: String(10000 * (index + 1)), currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' })),
  });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: [{ id: id(20), document_type: 'pos.receipt', period: 'all', pattern: 'R-WL2-{000001}', from: 1, to: 500, next: 1 }] });
  if (layout) server.define('pos_layout', { mode: 'snapshot', module: 'pos', rows: [{ id: 'layout', layout, scope: { type: 'location', id: 'loc-1' }, version: 1, best_sellers: [] }] });
  server.state.images.set(`sync/brand-assets/${LOGO}`, LOGO_URI);
  server.state.uploadOverride = (path) => (path === 'pos/number-ranges' ? { status: 200, body: { data: [] } } : null);
  const services = createServices({ database: testDatabase(), api: server, credentialsBackend: memoryBackend(), netInfo: null });
  return { server, services };
}

async function pairAndSignIn() {
  await fireEvent.changeText(await screen.findByLabelText('Pairing code'), 'abcd-efgh');
  await fireEvent.changeText(screen.getByLabelText('Till name'), 'Till 2');
  await fireEvent.press(screen.getByRole('button', { name: 'Pair till' }));
  await fireEvent.press(await screen.findByRole('button', { name: 'Continue' }));
}

async function signIn() {
  await fireEvent.press(await screen.findByRole('button', { name: 'Amina Otieno' }));
  for (const digit of '274915') await fireEvent.press(screen.getByRole('button', { name: digit }));
  await fireEvent.press(screen.getByRole('button', { name: 'Sign in' }));
  await fireEvent.changeText(await screen.findByLabelText('Opening cash in KES'), '1,000');
  await fireEvent.press(screen.getByRole('button', { name: 'Open shift' }));
}

jest.setTimeout(30000);

beforeEach(() => {
  clearMediaMemory();
  resetDisplayChannel();
});

const THEME = {
  payload: { preset: 'warm', colors: { primary: '#7c2d5b' }, logo_light: LOGO },
  tokens: { light: { primary: '#7c2d5b', 'on-primary': '#ffffff' }, dark: { primary: '#d8a3c2' } },
  scope: { type: 'tenant', id: null },
  version: 2,
};

const LAYOUT = {
  grid: { tablet_columns: 4, phone_columns: 3, tile_size: 'compact' },
  products: { pinned: [COLA], order: 'category' },
  categories: [{ id: BAKERY, hidden: false, color: 'warning-tint', image: null }, { id: DRINKS, hidden: false, color: 'primary-tint', image: null }],
  quick_buttons: [{ type: 'action', action: 'hold' }, { type: 'item', id: LOAF }],
  keypad: 'left',
  customer_display: { welcome: 'Karibu Duka', show_lines: true, show_second_currency: false, show_logo: true },
};

describe('the till theme (BR-01, BR-02)', () => {
  it('starts in the default light theme, then applies the synced theme with vars() and shows the logo at sign-in', async () => {
    const { services } = setup({ theme: THEME });
    await render(<App services={services} />);

    // Not paired: nothing synced, the default Light theme.
    expect(screen.getByTestId('theme-root')).toHaveStyle({ '--surface-100': themes.light['--surface-100'] });

    await pairAndSignIn();
    await waitFor(() => expect(screen.getByTestId('theme-root')).toHaveStyle({ '--primary': '#7c2d5b', '--surface-100': themes.warm['--surface-100'] }));
    // Only overridable tokens change; status colours stay the preset's.
    expect(screen.getByTestId('theme-root')).toHaveStyle({ '--success': themes.warm['--success'] });
    expect((await screen.findByTestId('brand-logo')).props.source).toEqual({ uri: LOGO_URI });
  });

  it('follows the device dark-mode choice with the dark token values', async () => {
    const { services } = setup({ theme: THEME });
    await services.store.setMeta({ appearance: 'dark' });
    await render(<App services={services} />);
    await pairAndSignIn();

    await waitFor(() => expect(screen.getByTestId('theme-root')).toHaveStyle({ '--primary': '#d8a3c2', '--surface-100': themes.dark['--surface-100'] }));
  });

  it('keeps the logo for offline use once fetched', async () => {
    const { services, server } = setup({ theme: THEME });
    await render(<App services={services} />);
    await pairAndSignIn();
    await screen.findByTestId('brand-logo');
    expect(await services.store.cachedMedia(`brand:${LOGO}`)).toBe(LOGO_URI);
    expect(server.requestsTo(`sync/brand-assets/${LOGO}`)).toHaveLength(1);
  });
});

describe('the sell screen with a POS layout (LAY-05)', () => {
  it('draws the grid, order, coloured chips and quick buttons, and feeds the customer display', async () => {
    const { services } = setup({ layout: LAYOUT, theme: THEME });
    // The display tab's side of the channel: what the till publishes.
    const channel = resetDisplayChannel();
    const published = [];
    channel.listen((message) => message.type === 'state' && published.push(message.state));
    const till = await render(<App services={services} />);
    await pairAndSignIn();
    await signIn();

    // Pinned first, then the layout's category order (Bakery before Drinks); 3 columns on a phone.
    const cola = await screen.findByRole('button', { name: 'Coca-Cola 500ml, KES 300.00' });
    const names = screen.getAllByRole('button').map((button) => button.props.accessibilityLabel).filter((label) => /, KES /.test(label ?? ''));
    expect(names).toEqual(['Coca-Cola 500ml, KES 300.00', 'Supaloaf White 400g, KES 200.00', 'Tusker Lager 500ml, KES 100.00']);
    expect(cola.props.className).toContain('bg-primary-tint');
    expect(cola.props.className).toContain('p-2');

    // Category chips in the layout's order with their token colour.
    const bakery = screen.getByRole('button', { name: 'Bakery' });
    expect(bakery.props.className).toContain('bg-warning-tint');
    expect(screen.getByRole('button', { name: 'Drinks' }).props.className).toContain('bg-primary-tint');

    // Quick buttons: an item adds it; Hold parks the sale.
    await fireEvent.press(screen.getByRole('button', { name: 'Supaloaf White 400g' }));
    expect(await screen.findByText('1 item')).toBeOnTheScreen();

    // The customer display gets the sale in words, the welcome text and logo, never the second currency here.
    await waitFor(() => expect(published.at(-1)?.lines.map((line) => [line.name, line.total])).toEqual([['Supaloaf White 400g', 'KES 200.00']]));
    const shown = published.at(-1);
    expect([shown.welcome, shown.logo, shown.second, shown.totals.total, shown.theme.overrides.primary]).toEqual(['Karibu Duka', LOGO_URI, null, 'KES 200.00', '#7c2d5b']);

    await fireEvent.press(till.getByRole('button', { name: 'Hold sale' }));
    expect(await till.findByText('0 items')).toBeOnTheScreen();
  });
  it('puts the sale panel (the keypad side) on the left on a tablet', async () => {
    const dimensions = jest.spyOn(require('react-native'), 'useWindowDimensions').mockReturnValue({ width: 1280, height: 800, scale: 1, fontScale: 1 });
    try {
      const { services } = setup({ layout: LAYOUT });
      await render(<App services={services} />);
      await pairAndSignIn();
      await signIn();

      const row = await screen.findByTestId('sell-tablet');
      expect(row.props.className).toContain('flex-row-reverse');
      expect(within(row).getByTestId('sale-panel')).toBeOnTheScreen();
    } finally {
      dimensions.mockRestore();
    }
  });
});

describe('CustomerDisplayScreen (LAY-05)', () => {
  const state = (overrides = {}) => ({
    v: 1,
    company: 'Duka',
    welcome: 'Karibu',
    logo: null,
    theme: { theme: 'light', overrides: null },
    showLines: true,
    customer: null,
    count: 2,
    lines: [{ id: 'l1', name: 'Tusker Lager 500ml', qty: '2', total: 'KES 500.00' }],
    totals: { subtotal: 'KES 500.00', discount: null, tax: 'KES 68.97', total: 'KES 500.00' },
    second: 'USD 3.85',
    ...overrides,
  });

  it('shows the welcome text until the till sends a sale, then the lines and totals, read-only', async () => {
    const channel = resetDisplayChannel();
    const hellos = [];
    channel.listen((message) => message.type === 'hello' && hellos.push(message));
    await render(<CustomerDisplayScreen channel={channel} />);

    expect(hellos).toHaveLength(1);
    expect(screen.getByText('Welcome')).toBeOnTheScreen();
    await act(() => channel.post({ type: 'state', state: state({ lines: [], totals: null, count: 0 }) }));
    expect(screen.getByText('Karibu')).toBeOnTheScreen();

    await act(() => channel.post({ type: 'state', state: state() }));
    expect(screen.getByText('Tusker Lager 500ml')).toBeOnTheScreen();
    expect(screen.getByText('USD 3.85')).toBeOnTheScreen();
    expect(within(screen.getByLabelText('Customer display')).queryByRole('button')).toBeNull();

    // Lines hidden by the layout: totals only.
    await act(() => channel.post({ type: 'state', state: state({ showLines: false, second: null }) }));
    expect(screen.queryByText('Tusker Lager 500ml')).toBeNull();
    expect(screen.getAllByText('KES 500.00').length).toBeGreaterThan(0);
    expect(screen.queryByText('USD 3.85')).toBeNull();
  });

  it('draws in the till theme it is sent', async () => {
    const channel = resetDisplayChannel();
    await render(<CustomerDisplayScreen channel={channel} />);
    await act(() => channel.post({ type: 'state', state: state({ theme: { theme: 'dark', overrides: { primary: '#d8a3c2' } } }) }));
    expect(screen.getByTestId('theme-root')).toHaveStyle({ '--surface-100': themes.dark['--surface-100'], '--primary': '#d8a3c2' });
  });
});
