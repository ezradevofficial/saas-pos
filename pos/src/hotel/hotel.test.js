import { signActorProof } from '../auth/pinCrypto';
import { uuidv7 } from '../lib/random';
import { buildCatalogue } from '../pos/catalogue';
import { cartReducer, emptyCart } from '../pos/cart';
import { resetExtensions, tenderProvider } from '../pos/extensions';
import { paymentOptions } from '../pos/payments/options';
import { createPosStore } from '../pos/posStore';
import { createSelling } from '../pos/selling';
import { entityDefinition } from '../sync/entities';
import { NetworkError } from '../sync/api';
import { createSyncEngine } from '../sync/engine';
import { createSyncStore } from '../sync/store';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { loadHotelData } from './inHouse';
import { registerHotel } from './register';
import { roomChargeTender } from './tender';

// HOT-22, HOT-35, NFR-04: the room-charge tender on the till. The in-house list syncs down as
// a snapshot; offline the till takes a charge to a room that is in house, open, in the sale's
// currency and within its limit (counting what this till already charged), and the payment
// goes up with { room, folio_id }.

const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const IDS = { company: id(1), list: id(2), vat: id(3), cash: id(4), room: id(5), uom: id(8), bread: id(10), cashier: id(11), range: id(13) };
const NOW = Date.parse('2026-10-13T14:30:00Z');
const SECRET = { secret: Buffer.alloc(32, 9).toString('base64url'), kid: 'k1' };

const settings = { id: 'device', timezone: 'Africa/Nairobi', branch: { id: id(30) }, company: { id: IDS.company, base_currency: 'KES', reporting_currencies: [] }, location: { name: 'Hotel bar' } };
const currencies = [{ code: 'KES', decimals: 2, cash_rounding_minor: '100' }];
const taxCodes = [{ id: IDS.vat, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] }];
const priceLists = [{ id: IDS.list, name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }];
const paymentMethods = [
  { id: IDS.cash, type: 'cash', name: 'Cash', currency: 'KES', position: 1 },
  { id: IDS.room, type: 'room_charge', name: 'Charge to room', currency: null, position: 2 },
];
const bread = { id: IDS.bread, code: 'BRE', name: 'Bread 400g', base_uom_id: IDS.uom, tax_code_id: IDS.vat, category_id: null, sellable: true, uoms: [], barcodes: [] };
const prices = [{ id: id(40), price_list_id: IDS.list, item_id: IDS.bread, uom_id: IDS.uom, amount_minor: '6000', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' }];
const cashier = { id: IDS.cashier, name: 'Grace', permissions: ['pos.sale.create'], limits: {} };

// Row shape of api/modules/Hotel/Sync/InHouseSource.php.
const stay = (n, room, guest, extra = {}) => ({ id: id(100 + n), room_number: room, guest_name: guest, folio_id: id(200 + n), currency: 'KES', allowed: true, limit_minor: null, balance_minor: '0', ...extra });
const ROWS = [
  stay(1, '204', 'Amina Wanjiru'),
  stay(2, '305', 'Peter Otieno', { limit_minor: '100000', balance_minor: '70000' }),
  stay(3, '101', 'Closed Folio', { allowed: false }),
  stay(4, '102', 'Dollar Guest', { currency: 'USD' }),
];

async function setup({ withModule = true, rows = ROWS } = {}) {
  const now = () => NOW;
  const server = fakeServer({ now });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: [{ id: IDS.range, document_type: 'pos.receipt', period: 'all', pattern: 'R-HB1-{000001}', from: 1, to: 500, next: 1 }] });
  if (withModule) server.define('hotel_in_house', { mode: 'snapshot', module: 'hotel', rows });
  const database = testDatabase();
  const engine = createSyncEngine({ api: server, store: createSyncStore(database), now, random: () => 0.5 });
  const posStore = createPosStore(database);
  const selling = createSelling({ engine, posStore, api: server, now });
  await engine.pull();
  const ext = { hotel: await loadHotelData(database) };
  const catalogue = buildCatalogue({ settings, currencies, taxCodes, priceLists, paymentMethods, items: [bread], prices, staff: [cashier], ext, now: NOW });
  const proof = signActorProof({ deviceSecret: SECRET, deviceId: 'device-1', sessionId: uuidv7(NOW), userId: cashier.id, signedInAt: new Date(NOW).toISOString() });
  return { server, database, engine, posStore, selling, catalogue, proof };
}

function cartWith(catalogue, qty, extra = {}) {
  const found = catalogue.itemById.get(IDS.bread);
  const price = catalogue.priceFor(found, IDS.uom, IDS.list, '1');
  let cart = cartReducer(emptyCart(NOW), { type: 'add', item: found, uomId: IDS.uom, price: price.amountMinor, listPriceMinor: price.amountMinor, priceListId: IDS.list, taxInclusive: true, now: NOW });
  if (qty !== 1) cart = cartReducer(cart, { type: 'edit', id: cart.lines[0].id, patch: { qty: String(qty) } });
  return { ...cart, ...extra };
}

const roomMethod = paymentMethods[1];
const base = (ctx, extra = {}) => ({ cart: cartWith(ctx.catalogue, 1), catalogue: ctx.catalogue, database: ctx.database, currency: 'KES', online: false, ...extra });

beforeAll(() => registerHotel());

describe('the in-house snapshot (HOT-35)', () => {
  it('is a registered snapshot entity stored in its own table', () => {
    expect(entityDefinition('hotel_in_house')).toMatchObject({ key: 'hotel_in_house', table: 'hotel_in_house' });
    expect(entityDefinition('hotel_in_house').columns({ room_number: '204' })).toEqual({ room_number: '204' });
  });

  it('syncs down and is read locally', async () => {
    const { database } = await setup();
    const data = await loadHotelData(database);
    expect(data.rooms.map((room) => room.room_number).sort()).toEqual(['101', '102', '204', '305']);
  });

  it('H3: a till that was never offered the entity loads none, and the tender is off', async () => {
    const { database, catalogue } = await setup({ withModule: false });
    await expect(loadHotelData(database)).resolves.toBeNull();
    expect(tenderProvider('room_charge', catalogue)).toBeNull();
    expect(paymentOptions(catalogue, NOW).map((option) => option.method.type)).toEqual(['cash']);
  });

  it('an empty list is the module on with nobody in house', async () => {
    const { catalogue } = await setup({ rows: [] });
    expect(tenderProvider('room_charge', catalogue)).toBe(roomChargeTender);
    await expect(roomChargeTender.prepare({ ...base({ catalogue, database: null }), input: '204', amountMinor: '1000' })).rejects.toMatchObject({ code: 'not_in_house' });
  });

  it('offers the tender while the module is on', async () => {
    const { catalogue } = await setup();
    expect(tenderProvider('room_charge', catalogue)).toBe(roomChargeTender);
    expect(paymentOptions(catalogue, NOW).map((option) => `${option.method.type}:${option.currency}`)).toEqual(['cash:KES', 'room_charge:KES']);
    expect(roomChargeTender.input).toBe('room');
  });
});

describe('the room-charge tender offline (HOT-22, HOT-35)', () => {
  it('takes an in-house room, shows the guest’s name and gives { room, folio_id }', async () => {
    const ctx = await setup();
    const prepared = await roomChargeTender.prepare({ ...base(ctx), input: ' 204 ', amountMinor: '6000' });
    expect(prepared).toEqual({ amountMinor: '6000', tender: { room: '204', folio_id: id(201) }, label: '204 · Amina Wanjiru' });
  });

  it('refuses a room that is not in house, with no network at all', async () => {
    const ctx = await setup();
    const api = { post: jest.fn(async () => { throw new NetworkError(new Error('offline')); }) };
    await expect(roomChargeTender.prepare({ ...base(ctx, { online: true, api }), input: '999', amountMinor: '1000' })).rejects.toMatchObject({ code: 'not_in_house' });
    await expect(roomChargeTender.prepare({ ...base(ctx), input: '', amountMinor: '1000' })).rejects.toMatchObject({ code: 'room_required' });
  });

  it('refuses a room whose folio is closed to charges', async () => {
    const ctx = await setup();
    await expect(roomChargeTender.prepare({ ...base(ctx), input: '101', amountMinor: '1000' })).rejects.toMatchObject({ code: 'folio_settled' });
  });

  it('refuses a room billed in another currency than the sale', async () => {
    const ctx = await setup();
    await expect(roomChargeTender.prepare({ ...base(ctx), input: '102', amountMinor: '1000' })).rejects.toMatchObject({ code: 'currency_mismatch', currency: 'USD' });
  });

  it('refuses an amount over the limit (balance KES 700.00 of 1,000.00) and says what is left', async () => {
    const ctx = await setup();
    await expect(roomChargeTender.prepare({ ...base(ctx), input: '305', amountMinor: '30001' })).rejects.toMatchObject({ code: 'limit_exceeded', available: '30000' });
    await expect(roomChargeTender.prepare({ ...base(ctx), input: '305', amountMinor: '30000' })).resolves.toMatchObject({ tender: { room: '305' } });
  });

  it('counts what this till already charged since the last pull, and what the cart already holds', async () => {
    const ctx = await setup();
    const { selling, catalogue, proof } = ctx;
    ctx.server.goOffline();
    ctx.engine.setNetwork('offline');
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    const first = await roomChargeTender.prepare({ ...base(ctx), input: '305', amountMinor: '18000' });
    const sale = cartWith(catalogue, 3);
    await selling.completeSale({ shift, user: cashier, actorProof: proof, cart: sale, catalogue, tenders: [{ id: uuidv7(NOW), method: roomMethod, currency: 'KES', amountMinor: first.amountMinor, tender: first.tender, label: first.label }] });

    // 70,000 synced + 18,000 charged here: 12,000 left.
    await expect(roomChargeTender.prepare({ ...base(ctx), input: '305', amountMinor: '12001' })).rejects.toMatchObject({ code: 'limit_exceeded', available: '12000' });
    // The cart already holds 5,000 on this room: 7,000 left.
    const held = cartWith(catalogue, 1, { tenders: [{ method: roomMethod, currency: 'KES', amountMinor: '5000', tender: { room: '305' } }] });
    await expect(roomChargeTender.prepare({ ...base(ctx, { cart: held }), input: '305', amountMinor: '7001' })).rejects.toMatchObject({ code: 'limit_exceeded', available: '7000' });
    await expect(roomChargeTender.prepare({ ...base(ctx, { cart: held }), input: '305', amountMinor: '7000' })).resolves.toMatchObject({ amountMinor: '7000' });
    // Another room is not affected.
    await expect(roomChargeTender.prepare({ ...base(ctx), input: '204', amountMinor: '99999' })).resolves.toMatchObject({ tender: { room: '204' } });
  });

  it('the sale keeps the tender with its room, for the “already charged” rule, and uploads it', async () => {
    const ctx = await setup();
    const { selling, catalogue, proof, posStore } = ctx;
    ctx.server.goOffline();
    ctx.engine.setNetwork('offline');
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    const prepared = await roomChargeTender.prepare({ ...base(ctx), input: '204', amountMinor: '6000' });
    const sale = await selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, 1), catalogue, tenders: [{ id: uuidv7(NOW), method: roomMethod, currency: 'KES', amountMinor: prepared.amountMinor, tender: prepared.tender, label: prepared.label }] });
    const stored = await posStore.sale(sale.id);
    expect(stored.local.spent).toEqual([{ type: 'room_charge', tender: { room: '204', folio_id: id(201) }, amountMinor: '6000', currency: 'KES' }]);
    expect(sale.payments[0]).toMatchObject({ payment_method_id: IDS.room, amount_minor: '6000', tender: { room: '204', folio_id: id(201) } });
  });
});

describe('the room-charge tender online (HOT-22, HOT-23)', () => {
  it('asks the server for a fresh answer and takes the guest and folio it names', async () => {
    const ctx = await setup();
    const api = { post: jest.fn(async () => ({ status: 200, body: { data: { allowed: true, reason: null, room_number: '204', guest_name: 'Amina W.', folio_id: id(999), currency: 'KES' } } })) };
    const prepared = await roomChargeTender.prepare({ ...base(ctx, { online: true, api }), input: '204', amountMinor: '6000' });
    expect(api.post).toHaveBeenCalledWith('hotel/device/room-lookup', { room: '204', currency: 'KES', amount_minor: 6000 });
    expect(prepared).toEqual({ amountMinor: '6000', tender: { room: '204', folio_id: id(999) }, label: '204 · Amina W.' });
  });

  it('refuses with the server’s reason', async () => {
    const ctx = await setup();
    const api = { post: jest.fn(async () => ({ status: 200, body: { data: { allowed: false, reason: 'limit_exceeded', room_number: '204', guest_name: 'Amina W.', folio_id: id(999), currency: 'KES' } } })) };
    await expect(roomChargeTender.prepare({ ...base(ctx, { online: true, api }), input: '204', amountMinor: '6000' })).rejects.toMatchObject({ code: 'limit_exceeded' });
  });

  it('adds what the cart holds to the amount asked about', async () => {
    const ctx = await setup();
    const api = { post: jest.fn(async () => ({ status: 200, body: { data: { allowed: true, room_number: '204', guest_name: 'A', folio_id: id(999), currency: 'KES' } } })) };
    const held = cartWith(ctx.catalogue, 1, { tenders: [{ method: roomMethod, currency: 'KES', amountMinor: '5000', tender: { room: '204' } }] });
    await roomChargeTender.prepare({ ...base(ctx, { online: true, api, cart: held }), input: '204', amountMinor: '1000' });
    expect(api.post.mock.calls[0][1].amount_minor).toBe(6000);
  });

  it('falls back to the synced list when the server cannot be reached', async () => {
    const ctx = await setup();
    const api = { post: jest.fn(async () => { throw new NetworkError(new Error('offline')); }) };
    await expect(roomChargeTender.prepare({ ...base(ctx, { online: true, api }), input: '204', amountMinor: '6000' })).resolves.toMatchObject({ tender: { room: '204', folio_id: id(201) } });
  });
});

afterAll(() => resetExtensions());
