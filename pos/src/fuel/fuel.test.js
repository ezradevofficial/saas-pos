import { signActorProof } from '../auth/pinCrypto';
import { uuidv7 } from '../lib/random';
import { buildCatalogue } from '../pos/catalogue';
import { computeCart } from '../pos/cart';
import { resetExtensions, runCartAdjusters, tenderProvider } from '../pos/extensions';
import { paymentOptions } from '../pos/payments/options';
import { createPosStore } from '../pos/posStore';
import { createSelling } from '../pos/selling';
import { entityDefinition } from '../sync/entities';
import { createSyncEngine } from '../sync/engine';
import { pushKind } from '../sync/pushKinds';
import { createSyncStore } from '../sync/store';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { loadFuelData, searchVehicles } from './data';
import { registerFuel } from './register';
import { createFuelResets } from './resets';
import { buildFuelCart } from './sale';
import { createFuelShifts, previewVariance } from './shifts';
import { fuelVoucherTender } from './tender';
import { summarise } from './tenders';

// FUEL-09, FUEL-10, FUEL-17 to FUEL-22, NFR-04: the forecourt on the till. The station's nozzles, prices,
// tanks, vehicles and vouchers sync down; the till sells by nozzle, opens and closes shifts, takes dips and
// redeems vouchers with no connection, and everything goes up idempotently by uuid.

const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const IDS = { company: id(1), list: id(2), vat: id(3), cash: id(4), voucher: id(5), mpesa: id(6), uom: id(8), diesel: id(10), petrol: id(11), range: id(13), attendant: id(21), supervisor: id(22), station: id(50) };
const NOW = Date.parse('2026-10-13T14:30:00Z');
const SECRET = { secret: Buffer.alloc(32, 9).toString('base64url'), kid: 'k1' };

const settings = { id: 'device', timezone: 'Africa/Nairobi', branch: { id: id(30) }, company: { id: IDS.company, base_currency: 'KES', reporting_currencies: [] }, location: { name: 'Forecourt' } };
const currencies = [{ code: 'KES', decimals: 2, cash_rounding_minor: '1' }];
const taxCodes = [{ id: IDS.vat, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] }];
const priceLists = [{ id: IDS.list, name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }];
const paymentMethods = [
  { id: IDS.cash, type: 'cash', name: 'Cash', currency: 'KES', position: 1 },
  { id: IDS.voucher, type: 'fuel_voucher', name: 'Fuel voucher', currency: null, position: 2 },
];
const uoms = [{ id: IDS.uom, code: 'L', name: 'Litre' }];
const diesel = { id: IDS.diesel, code: 'DSL', name: 'Diesel', base_uom_id: IDS.uom, tax_code_id: IDS.vat, category_id: null, sellable: true, uoms: [], barcodes: [] };
const petrol = { id: IDS.petrol, code: 'PET', name: 'Super petrol', base_uom_id: IDS.uom, tax_code_id: IDS.vat, category_id: null, sellable: true, uoms: [], barcodes: [] };
const prices = [IDS.diesel, IDS.petrol].map((item, i) => ({ id: id(40 + i), price_list_id: IDS.list, item_id: item, uom_id: IDS.uom, amount_minor: '1', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' }));
const attendant = { id: IDS.attendant, name: 'Wanjiru', permissions: ['fuel.sale.create', 'fuel.shift.open', 'fuel.shift.close', 'fuel.dip.record', 'fuel.voucher.redeem'], limits: {} };
const supervisor = { id: IDS.supervisor, name: 'Otieno', permissions: [...attendant.permissions, 'fuel.meter.request_reset'], limits: {} };

// Row shapes of api/modules/FuelStation/Sync/*.php.
const nozzle = (n, extra = {}) => ({
  id: id(60 + n), code: String(n), pump_id: id(70 + Math.ceil(n / 2)), pump_code: `P${Math.ceil(n / 2)}`, pump_name: `Pump ${Math.ceil(n / 2)}`,
  tank_id: id(80 + (n % 2)), tank_code: `T${n % 2}`, product_id: id(90 + (n % 2)), item_id: n % 2 ? IDS.diesel : IDS.petrol, fuel_type: n % 2 ? 'diesel' : 'petrol', grade: null,
  last_reading_litres: '1000.000', last_reading_at: '2026-10-12T20:00:00Z', ...extra,
});
const N1 = nozzle(1);
const N2 = nozzle(2);
const station = { id: IDS.station, currency: 'KES', litre_tolerance: '0.000', cash_variance_tolerance_minor: '0' };
const fuelPrices = [
  { id: id(100), product_id: id(91), price_minor: '17500', currency: 'KES', effective_from: '2026-10-01T00:00:00Z' },
  { id: id(101), product_id: id(91), price_minor: '18050', currency: 'KES', effective_from: '2026-10-10T00:00:00Z' },
  { id: id(102), product_id: id(90), price_minor: '19000', currency: 'KES', effective_from: '2026-10-01T00:00:00Z' },
  // Scheduled: not in force yet.
  { id: id(103), product_id: id(91), price_minor: '19999', currency: 'KES', effective_from: '2026-10-20T00:00:00Z' },
];
const tanks = [
  { id: id(81), code: 'T1', name: 'Diesel tank', product_id: id(91), capacity_litres: '5000.000', strapping: [{ id: id(110), effective_from: '2026-01-01T00:00:00Z', points: [['0', '0.000'], ['50', '1000.000'], ['100', '3000.000']] }] },
];
const vehicles = [
  { id: id(120), party_id: id(130), registration: 'KDA123A', label: 'Truck', fuel_type: 'diesel', daily_litre_limit: null, status: 'active' },
  { id: id(121), party_id: id(130), registration: 'KDB456B', label: 'Pickup', fuel_type: null, daily_litre_limit: '100.000', status: 'blocked' },
  { id: id(122), party_id: id(131), registration: 'KCC789C', label: null, fuel_type: null, daily_litre_limit: null, status: 'active' },
];
const vouchers = [
  { id: id(140), code: 'FV-ABCD-1234', currency: 'KES', balance_minor: '500000', fuel_type: null, expires_on: '2026-12-31' },
  { id: id(141), code: 'OLD-0001', currency: 'KES', balance_minor: '100000', fuel_type: null, expires_on: '2026-10-01' },
  { id: id(142), code: 'USD-0001', currency: 'USD', balance_minor: '5000', fuel_type: null, expires_on: null },
  { id: id(143), code: 'SMALL-1', currency: 'KES', balance_minor: '20000', fuel_type: null, expires_on: null },
];

async function setup({ withModule = true } = {}) {
  let time = NOW;
  const now = () => time;
  const server = fakeServer({ now });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: [{ id: IDS.range, document_type: 'pos.receipt', period: 'all', pattern: 'R-FS1-{000001}', from: 1, to: 500, next: 1 }] });
  if (withModule) {
    server.define('fuel_station', { mode: 'snapshot', module: 'fuel', rows: [station] });
    server.define('fuel_nozzles', { mode: 'snapshot', module: 'fuel', rows: [N1, N2] });
    server.define('fuel_prices', { mode: 'snapshot', module: 'fuel', rows: fuelPrices });
    server.define('fuel_tanks', { mode: 'snapshot', module: 'fuel', rows: tanks });
    server.define('fuel_vehicles', { mode: 'snapshot', module: 'fuel', rows: vehicles });
    server.define('fuel_vouchers', { mode: 'snapshot', module: 'fuel', rows: vouchers });
  }
  // The device endpoints of the module: idempotent by uuid, one result per record, a rejection by id.
  const uploaded = { shifts: new Map(), resets: new Map(), rejects: new Map() };
  server.state.handler = (method, path, body) => {
    const kind = path === 'fuel/device/shifts' ? 'shifts' : path === 'fuel/device/meter-resets' ? 'resets' : null;
    if (method !== 'POST' || !kind) return null;
    const records = body[kind];
    const results = records.map((record) => {
      const rejection = uploaded.rejects.get(`${kind}:${record.id}:${record.closing ? 'close' : 'open'}`);
      if (rejection) return { id: record.id, status: 'rejected', error: rejection };
      uploaded[kind].set(`${record.id}:${record.closing ? 'close' : 'open'}`, record);
      return { id: record.id, status: 'stored' };
    });
    return results.some((result) => result.status === 'stored') ? { status: 200, body: { results } } : { status: 422, body: { code: 'upload_rejected', message: 'Nothing stored', results } };
  };
  const database = testDatabase();
  const store = createSyncStore(database);
  const engine = createSyncEngine({ api: server, store, now, random: () => 0.5 });
  const posStore = createPosStore(database);
  const selling = createSelling({ engine, posStore, api: server, now });
  await engine.pull();
  const ext = { fuel: await loadFuelData(database) };
  const catalogue = buildCatalogue({ settings, currencies, taxCodes, priceLists, paymentMethods, uoms, categories: [], items: [diesel, petrol], prices, staff: [attendant, supervisor], ext, now: NOW });
  const proof = signActorProof({ deviceSecret: SECRET, deviceId: 'device-1', sessionId: uuidv7(NOW), userId: attendant.id, signedInAt: new Date(NOW).toISOString() });
  const shifts = createFuelShifts({ engine, store, database, now });
  const resets = createFuelResets({ engine, now });
  return { server, uploaded, database, store, engine, posStore, selling, catalogue, proof, shifts, resets, fuel: ext.fuel, advance: (ms) => (time += ms) };
}

async function openShift(ctx, readings = { 1: '1000.000', 2: '1000.000' }) {
  return ctx.shifts.open({
    user: attendant,
    actorProof: ctx.proof,
    station: ctx.fuel.station,
    rows: [N1, N2].map((n, i) => ({ nozzle: n, attendantId: IDS.attendant, attendantName: 'Wanjiru', reading: readings[i + 1] })),
  });
}

const cashTender = (ctx, amountMinor) => ({ id: uuidv7(NOW), method: ctx.catalogue.paymentMethods[0], currency: 'KES', amountMinor: String(amountMinor) });

async function ringSale(ctx, posShift, shift, entry, { nozzle: which = N1, customer = null, vehicle = null, tenders = null } = {}) {
  const cart = buildFuelCart({ catalogue: ctx.catalogue, fuel: ctx.fuel, shift, nozzle: which, entry, customer, vehicle, now: NOW });
  const computed = computeCart(cart, { taxCodes: ctx.catalogue.taxCodes, day: ctx.catalogue.day, catalogue: ctx.catalogue, user: attendant });
  return ctx.selling.completeSale({ shift: posShift, user: attendant, actorProof: ctx.proof, cart, catalogue: ctx.catalogue, tenders: tenders ?? [cashTender(ctx, computed.totals.total_minor)] });
}

beforeAll(() => registerFuel());
afterAll(() => resetExtensions());

describe('sync wiring (FUEL-22)', () => {
  it('registers the six entities, each in its own table', () => {
    for (const key of ['fuel_station', 'fuel_nozzles', 'fuel_prices', 'fuel_tanks', 'fuel_vehicles', 'fuel_vouchers']) {
      expect(entityDefinition(key)).toMatchObject({ key, table: key });
    }
    expect(entityDefinition('fuel_vehicles').columns({ registration: 'kda 123-a', party_id: 'p' })).toEqual({ registration: 'KDA123A', party_id: 'p' });
    expect(entityDefinition('fuel_vouchers').columns({ code: 'fv-abcd-1234' })).toEqual({ code: 'FVABCD1234' });
  });

  it('registers the two outbox kinds with the API’s paths, body keys and batch size', () => {
    expect(pushKind('fuel.shifts')).toMatchObject({ path: 'fuel/device/shifts', bodyKey: 'shifts', batchSize: 10, module: 'fuel' });
    expect(pushKind('fuel.meter_resets')).toMatchObject({ path: 'fuel/device/meter-resets', bodyKey: 'resets', module: 'fuel' });
  });

  it('syncs the station’s data down and reads it locally; vehicles are found by registration', async () => {
    const { database, fuel } = await setup();
    expect(fuel.nozzles.map((row) => row.code).sort()).toEqual(['1', '2']);
    expect(fuel.prices).toHaveLength(4);
    expect(fuel.tanks[0].strapping[0].points).toHaveLength(3);
    expect((await searchVehicles(database, 'kda 123')).map((row) => row.registration)).toEqual(['KDA123A']);
    expect((await searchVehicles(database, '', { partyId: id(130) })).map((row) => row.registration)).toEqual(['KDA123A', 'KDB456B']);
  });

  it('H3: a till that was never offered the module loads none, and the voucher tender is off', async () => {
    const { database, catalogue } = await setup({ withModule: false });
    await expect(loadFuelData(database)).resolves.toBeNull();
    expect(tenderProvider('fuel_voucher', catalogue)).toBeNull();
    expect(paymentOptions(catalogue, NOW).map((option) => option.method.type)).toEqual(['cash']);
  });
});

describe('nozzle quick sale offline (FUEL-18, FUEL-19)', () => {
  it('rings the nozzle’s item at the price in force; the uploaded sale carries extensions.fuel with the shift and the line’s nozzle', async () => {
    const ctx = await setup();
    ctx.server.goOffline();
    ctx.engine.setNetwork('offline');
    const posShift = await ctx.selling.openShift({ user: attendant, actorProof: ctx.proof, openingFloat: [] });
    const shift = await openShift(ctx);
    const sale = await ringSale(ctx, posShift, shift, { mode: 'litres', litres: '20' });

    expect(sale.lines).toHaveLength(1);
    expect(sale.lines[0]).toMatchObject({ item_id: IDS.diesel, qty: '20.000', unit_price_minor: '18050', list_price_minor: '18050', total_minor: '361000' });
    expect(sale.lines[0].price_override).toBeUndefined();
    expect(sale.extensions.fuel).toEqual({ shift_id: shift.id, lines: [{ line_id: sale.lines[0].id, nozzle_id: N1.id }] });
    expect(sale.offline).toBe(true);
    // Tax comes from the item as for any line: inclusive 16 % of KES 3,610.00.
    expect(sale.lines[0]).toMatchObject({ tax_code_id: IDS.vat, tax_minor: '49793' });
  });

  it('by amount: KES 1,000.00 at 180.50 rings 5.540 L for KES 999.97 (litres rounded down, total half up)', async () => {
    const ctx = await setup();
    const posShift = await ctx.selling.openShift({ user: attendant, actorProof: ctx.proof, openingFloat: [] });
    const shift = await openShift(ctx);
    const sale = await ringSale(ctx, posShift, shift, { mode: 'amount', amountMinor: '100000' });
    expect(sale.lines[0]).toMatchObject({ qty: '5.540', unit_price_minor: '18050', total_minor: '99997' });
    expect(sale.totals.total_minor).toBe('99997');
  });

  it('prices from the synced rows by the time: a scheduled price applies once it starts, never before', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    const at = (iso) => buildFuelCart({ catalogue: ctx.catalogue, fuel: ctx.fuel, shift, nozzle: N1, entry: { mode: 'litres', litres: '1' }, now: Date.parse(iso) }).lines[0].unitPriceMinor;
    expect(at('2026-10-19T23:59:00Z')).toBe('18050');
    expect(at('2026-10-20T00:00:00Z')).toBe('19999');
  });

  it('a customer’s price list never re-prices the pump price', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    const cart = buildFuelCart({ catalogue: ctx.catalogue, fuel: ctx.fuel, shift, nozzle: N1, entry: { mode: 'litres', litres: '1' }, customer: { id: id(130), name: 'Acme Haulage' } });
    expect(cart.lines[0].priceSet).toBe(true);
    expect(cart.customer).toMatchObject({ id: id(130), name: 'Acme Haulage' });
  });

  it('refuses a nozzle that is not on the shift, no shift, and a product without a price', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    const other = nozzle(3);
    const base = { catalogue: ctx.catalogue, fuel: ctx.fuel, entry: { mode: 'litres', litres: '1' } };
    expect(() => buildFuelCart({ ...base, shift: null, nozzle: N1 })).toThrow(expect.objectContaining({ code: 'no_shift' }));
    expect(() => buildFuelCart({ ...base, shift, nozzle: other })).toThrow(expect.objectContaining({ code: 'nozzle_not_on_shift' }));
    expect(() => buildFuelCart({ ...base, fuel: { ...ctx.fuel, prices: [] }, shift, nozzle: N1 })).toThrow(expect.objectContaining({ code: 'price_missing' }));
  });

  it('the adjuster hands only the fuel lines still in the cart to the sale', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    const cart = buildFuelCart({ catalogue: ctx.catalogue, fuel: ctx.fuel, shift, nozzle: N1, entry: { mode: 'litres', litres: '1' } });
    expect(runCartAdjusters({ lines: cart.lines, cart, catalogue: ctx.catalogue, at: NOW, user: attendant }).extensions.fuel.shift_id).toBe(shift.id);
    const emptied = { ...cart, lines: [] };
    expect(runCartAdjusters({ lines: [], cart: emptied, catalogue: ctx.catalogue, at: NOW, user: attendant }).extensions.fuel).toBeUndefined();
  });

  it('a sale queued offline survives and uploads once, even when the same uuid is sent twice (idempotent)', async () => {
    const ctx = await setup();
    ctx.server.goOffline();
    ctx.engine.setNetwork('offline');
    const posShift = await ctx.selling.openShift({ user: attendant, actorProof: ctx.proof, openingFloat: [] });
    const shift = await openShift(ctx);
    const sale = await ringSale(ctx, posShift, shift, { mode: 'litres', litres: '10' });
    expect((await ctx.store.counts()).pending).toBe(3);

    ctx.server.goOnline();
    ctx.engine.setNetwork('online');
    await ctx.engine.push({ force: true });
    expect((await ctx.store.counts()).pending).toBe(0);
    const stored = ctx.server.state.stored.get(sale.id).record;
    expect(stored.extensions.fuel.shift_id).toBe(shift.id);
    // The shift reached the server before the sale that names it.
    expect([...ctx.uploaded.shifts.keys()]).toEqual([`${shift.id}:open`]);

    // The same uuid again (a retry after a lost answer) is the same record.
    await ctx.engine.enqueue('pos.sales', sale.id, sale, { group: posShift.id });
    await ctx.engine.push({ force: true });
    expect([...ctx.server.state.stored.keys()].filter((key) => key === sale.id)).toHaveLength(1);
  });
});

describe('fleet sale (FUEL-20)', () => {
  it('carries the customer and the vehicle (registration, odometer) in extensions.fuel', async () => {
    const ctx = await setup();
    const posShift = await ctx.selling.openShift({ user: attendant, actorProof: ctx.proof, openingFloat: [] });
    const shift = await openShift(ctx);
    const sale = await ringSale(ctx, posShift, shift, { mode: 'litres', litres: '30' }, { customer: { id: id(130), name: 'Acme Haulage' }, vehicle: { registration: 'KDA123A', odometer: '123 456 km' } });
    expect(sale.customer_id).toBe(id(130));
    expect(sale.extensions.fuel.vehicle).toEqual({ registration: 'KDA123A', odometer: '123456' });
  });

  it('a blocked vehicle does not stop the sale (the server flags it; the device wins)', async () => {
    const ctx = await setup();
    const blocked = (await searchVehicles(ctx.database, 'KDB456B'))[0];
    expect(blocked.status).toBe('blocked');
    const posShift = await ctx.selling.openShift({ user: attendant, actorProof: ctx.proof, openingFloat: [] });
    const shift = await openShift(ctx);
    const sale = await ringSale(ctx, posShift, shift, { mode: 'litres', litres: '5' }, { customer: { id: id(130), name: 'Acme Haulage' }, vehicle: { registration: blocked.registration } });
    expect(sale.extensions.fuel.vehicle).toEqual({ registration: 'KDB456B' });
  });
});

describe('forecourt shifts (FUEL-09, FUEL-10)', () => {
  it('opens offline with a reading and an attendant per nozzle, queues the upload with the actor proof, and resumes after a restart', async () => {
    const ctx = await setup();
    ctx.server.goOffline();
    ctx.engine.setNetwork('offline');
    const shift = await openShift(ctx, { 1: '1000.000', 2: '1012.5' });

    const queued = (await ctx.store.entries('pending')).filter((entry) => entry.kind === 'fuel.shifts');
    expect(queued).toHaveLength(1);
    expect(queued[0].payload).toEqual({
      id: shift.id,
      opening: {
        opened_by_id: IDS.attendant,
        opened_at: shift.opened_at,
        actor_proof: ctx.proof,
        nozzles: [
          { nozzle_id: N1.id, attendant_id: IDS.attendant, reading: '1000.000' },
          { nozzle_id: N2.id, attendant_id: IDS.attendant, reading: '1012.500' },
        ],
      },
    });
    expect(queued[0].group).toBe(shift.id);

    // A restart: a new service on the same database finds the open shift.
    const again = createFuelShifts({ engine: ctx.engine, store: ctx.store, database: ctx.database });
    await expect(again.current()).resolves.toMatchObject({ id: shift.id, status: 'open' });
    await expect(again.open({ user: attendant, actorProof: ctx.proof, station: ctx.fuel.station, rows: [{ nozzle: N1, attendantId: IDS.attendant, reading: '1000' }] })).rejects.toMatchObject({ code: 'shift_open' });
  });

  it('refuses an opening reading below the nozzle’s last one and says which nozzle (monotonic meters)', async () => {
    const ctx = await setup();
    await expect(openShift(ctx, { 1: '999.999', 2: '1000.000' })).rejects.toMatchObject({ code: 'meter_decreased', nozzleId: N1.id, last: '1000.000' });
    await expect(ctx.shifts.current()).resolves.toBeNull();
    expect(await ctx.store.entries('pending')).toHaveLength(0);
  });

  it('knows a nozzle’s last reading from a shift closed here before the next sync', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    await ctx.shifts.close({ user: attendant, actorProof: ctx.proof, shift, nozzles: ctx.fuel.nozzles, readings: { [N1.id]: { reading: '1100' }, [N2.id]: { reading: '1050' } }, declared: { [IDS.attendant]: { cash: '0' } } });
    await expect(openShift(ctx, { 1: '1099.999', 2: '1050.000' })).rejects.toMatchObject({ code: 'meter_decreased', last: '1100.000' });
    await expect(openShift(ctx, { 1: '1100.000', 2: '1050.000' })).resolves.toMatchObject({ status: 'open' });
  });

  it('needs the right to open and to close', async () => {
    const ctx = await setup();
    const nobody = { id: id(23), name: 'Cashier', permissions: ['pos.sale.create'] };
    await expect(ctx.shifts.open({ user: nobody, station: ctx.fuel.station, rows: [{ nozzle: N1, attendantId: IDS.attendant, reading: '1000' }] })).rejects.toMatchObject({ code: 'not_permitted' });
    const shift = await openShift(ctx);
    await expect(ctx.shifts.close({ user: nobody, shift, readings: {}, declared: {} })).rejects.toMatchObject({ code: 'not_permitted' });
  });

  it('blocks closing until every nozzle has a reading and every attendant has declared cash, naming what is missing', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    const close = (readings, declared) => ctx.shifts.close({ user: attendant, actorProof: ctx.proof, shift, nozzles: ctx.fuel.nozzles, readings, declared });

    await expect(close({ [N1.id]: { reading: '1100' } }, { [IDS.attendant]: { cash: '5000' } })).rejects.toMatchObject({ code: 'close_incomplete', missing: 'P1 / 2' });
    await expect(close({ [N1.id]: { reading: '1100' }, [N2.id]: { reading: '1050' } }, {})).rejects.toMatchObject({ code: 'close_incomplete', missing: 'Wanjiru' });
    // Nothing was queued for the refused attempts, and the shift is still open.
    expect((await ctx.store.entries('pending')).filter((entry) => entry.kind === 'fuel.shifts')).toHaveLength(1);
    await expect(ctx.shifts.current()).resolves.toMatchObject({ id: shift.id });
  });

  it('refuses a closing reading below the opening one, and pump tests above the litres moved', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    const declared = { [IDS.attendant]: { cash: '0' } };
    await expect(ctx.shifts.close({ user: attendant, shift, nozzles: ctx.fuel.nozzles, readings: { [N1.id]: { reading: '999' }, [N2.id]: { reading: '1050' } }, declared })).rejects.toMatchObject({ code: 'meter_decreased', nozzleId: N1.id });
    await expect(ctx.shifts.close({ user: attendant, shift, nozzles: ctx.fuel.nozzles, readings: { [N1.id]: { reading: '1010', tests: '11' }, [N2.id]: { reading: '1050' } }, declared })).rejects.toMatchObject({ code: 'test_exceeds_litres', nozzleId: N1.id });
  });

  it('closes with the readings, pump tests, tenders per attendant and dips in the API’s shape, repeating the opening unchanged', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    const { dip } = await ctx.shifts.addDip({ user: attendant, shift, tank: tanks[0], dipCm: '75' });
    const closed = await ctx.shifts.close({
      user: attendant,
      actorProof: ctx.proof,
      shift,
      nozzles: ctx.fuel.nozzles,
      readings: { [N1.id]: { reading: '1250,5', tests: '5' }, [N2.id]: { reading: '1100' } },
      declared: { [IDS.attendant]: { cash: '4500000', mobile_money: '120000', voucher: '' } },
      note: 'Quiet night',
    });
    expect(closed.status).toBe('closed');

    const [open, close] = (await ctx.store.entries('pending')).filter((entry) => entry.kind === 'fuel.shifts');
    expect(close.payload.opening).toEqual(open.payload.opening);
    expect(close.payload.closing).toEqual({
      closed_by_id: IDS.attendant,
      closed_at: closed.closing.closed_at,
      actor_proof: ctx.proof,
      note: 'Quiet night',
      nozzles: [
        { nozzle_id: N1.id, reading: '1250.500', test_litres: '5.000' },
        { nozzle_id: N2.id, reading: '1100.000' },
      ],
      tenders: [{ attendant_id: IDS.attendant, tenders: [{ kind: 'cash', amount_minor: '4500000' }, { kind: 'mobile_money', amount_minor: '120000' }] }],
      dips: [{ id: dip.id, tank_id: tanks[0].id, dip_cm: '75', taken_at: dip.taken_at }],
    });
    expect(close.group).toBe(shift.id);
    await expect(ctx.shifts.current()).resolves.toBeNull();
  });

  it('uploads open then close in order, idempotently, and keeps a refused close with the server’s message', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    ctx.uploaded.rejects.set(`shifts:${shift.id}:close`, { code: 'close_incomplete', message: 'The shift can’t close yet. Missing: Wanjiru. Enter these and close again.', field: 'closing', retryable: false });
    await ctx.shifts.close({ user: attendant, shift, nozzles: ctx.fuel.nozzles, readings: { [N1.id]: { reading: '1100' }, [N2.id]: { reading: '1050' } }, declared: { [IDS.attendant]: { cash: '0' } } });
    await ctx.engine.push({ force: true });
    expect([...ctx.uploaded.shifts.keys()]).toEqual([`${shift.id}:open`]);
    await expect(ctx.shifts.problems()).resolves.toEqual([{ kind: 'fuel.shifts', recordId: shift.id, code: 'close_incomplete', message: expect.stringContaining('Missing: Wanjiru') }]);
  });

  it('retries a retryable refusal (nozzle_in_use) instead of dropping the shift', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    ctx.uploaded.rejects.set(`shifts:${shift.id}:open`, { code: 'nozzle_in_use', message: 'This nozzle is on an open shift.', field: 'opening.nozzles', retryable: true });
    await ctx.engine.push({ force: true });
    expect((await ctx.store.counts()).pending).toBe(1);
    await expect(ctx.shifts.problems()).resolves.toEqual([]);
    ctx.uploaded.rejects.clear();
    await ctx.engine.push({ force: true });
    expect((await ctx.store.counts()).pending).toBe(0);
  });

  it('shows the running litres and value of a shift from the sales rung here', async () => {
    const ctx = await setup();
    const posShift = await ctx.selling.openShift({ user: attendant, actorProof: ctx.proof, openingFloat: [] });
    const shift = await openShift(ctx);
    await ringSale(ctx, posShift, shift, { mode: 'litres', litres: '20' });
    await ringSale(ctx, posShift, shift, { mode: 'litres', litres: '10.5' });
    await ringSale(ctx, posShift, shift, { mode: 'litres', litres: '4' }, { nozzle: N2 });
    const running = await ctx.shifts.running(shift);
    expect(running.nozzles[N1.id]).toEqual({ litres: '30.500', valueMinor: 361000n + 189525n });
    expect(running.nozzles[N2.id]).toEqual({ litres: '4.000', valueMinor: 76000n });
    expect(running.attendants[IDS.attendant].valueMinor).toBe(361000n + 189525n + 76000n);
    expect(running.attendants[IDS.attendant].tenders.cash).toBe(361000n + 189525n + 76000n);
  });

  it('previews the variance: expected is the sales plus the unrecorded litres at the price now; the server decides', async () => {
    const ctx = await setup();
    const posShift = await ctx.selling.openShift({ user: attendant, actorProof: ctx.proof, openingFloat: [] });
    const shift = await openShift(ctx);
    await ringSale(ctx, posShift, shift, { mode: 'litres', litres: '20' });
    const summary = await ctx.shifts.running(shift);
    // The meter moved 25 L on nozzle 1 (20 L rung up) and 0 on nozzle 2: 5 L x 180.50 unrecorded.
    const [row] = previewVariance({ shift, summary, readings: { [N1.id]: { reading: '1025' }, [N2.id]: { reading: '1000' } }, declared: { [IDS.attendant]: { cash: '361000' } }, fuel: ctx.fuel, at: NOW });
    expect(row.expectedMinor).toBe(361000n + 90250n);
    expect(row.collectedMinor).toBe(361000n);
    expect(row.varianceMinor).toBe(-90250n);
    expect(previewVariance({ shift, summary, readings: {}, declared: {}, fuel: ctx.fuel, at: NOW })[0].varianceMinor).toBeNull();
  });
});

describe('the fuel share of mixed sales (FUEL-12)', () => {
  it('scales the payments by fuel / sale total and puts the difference on the largest kind', () => {
    const shift = { id: 's1', nozzles: [{ nozzle_id: 'n1', attendant_id: 'a1' }] };
    const sale = {
      id: 'sale', currency: 'KES', totals: { total_minor: '300000' },
      extensions: { fuel: { shift_id: 's1', lines: [{ line_id: 'l1', nozzle_id: 'n1' }] } },
      lines: [{ id: 'l1', qty: '10.000', total_minor: '100000' }, { id: 'l2', qty: '1', total_minor: '200000' }],
      payments: [{ id: 'p1', amount_in_sale_minor: '100000' }, { id: 'p2', amount_in_sale_minor: '200000' }],
      local: { methods: { p1: { type: 'cash' }, p2: { type: 'mobile_money' } } },
    };
    const { attendants } = summarise(shift, [sale]);
    expect(attendants.a1.tenders).toEqual({ cash: 33333n, mobile_money: 66667n });
    expect(attendants.a1.valueMinor).toBe(100000n);
  });
});

describe('tank dips (FUEL-23, FUEL-06)', () => {
  it('converts a dip by the synced chart offline and keeps it for the close', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    const result = await ctx.shifts.addDip({ user: attendant, shift, tank: tanks[0], dipCm: '75.5', waterCm: '1.25' });
    expect(result.litres).toBe('2020.000');
    expect(result.dip).toMatchObject({ tank_id: tanks[0].id, dip_cm: '75.5', water_cm: '1.25' });
    expect((await ctx.shifts.current()).dips).toHaveLength(1);
  });

  it('refuses a dip outside the chart, an invalid one, and a person without the right', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    await expect(ctx.shifts.addDip({ user: attendant, shift, tank: tanks[0], dipCm: '100.01' })).rejects.toMatchObject({ code: 'dip_out_of_range' });
    await expect(ctx.shifts.addDip({ user: attendant, shift, tank: tanks[0], dipCm: 'abc' })).rejects.toMatchObject({ code: 'dip_invalid' });
    await expect(ctx.shifts.addDip({ user: { id: 'x', permissions: [] }, shift, tank: tanks[0], dipCm: '10' })).rejects.toMatchObject({ code: 'not_permitted' });
    expect((await ctx.shifts.current()).dips).toEqual([]);
  });
});

describe('meter reset requests (FUEL-17)', () => {
  it('queues the request in the API’s shape; only a supervisor can ask', async () => {
    const ctx = await setup();
    const shift = await openShift(ctx);
    await expect(ctx.resets.request({ user: attendant, nozzle: N1, before: '9999', after: '0', reason: 'Meter replaced' })).rejects.toMatchObject({ code: 'not_permitted' });
    const payload = await ctx.resets.request({ user: supervisor, actorProof: ctx.proof, nozzle: N1, shift, before: '9999,9', after: '0', reason: ' Meter replaced ' });
    expect(payload).toEqual({
      id: payload.id, nozzle_id: N1.id, shift_id: shift.id, reading_before: '9999.900', reading_after: '0.000', reason: 'Meter replaced',
      requested_by_id: IDS.supervisor, requested_at: expect.any(String), actor_proof: ctx.proof,
    });
    const queued = (await ctx.store.entries('pending')).filter((entry) => entry.kind === 'fuel.meter_resets');
    expect(queued).toHaveLength(1);
    await ctx.engine.push({ force: true });
    expect(ctx.uploaded.resets.has(`${payload.id}:open`)).toBe(true);
  });

  it('refuses a reading after above the one before, bad readings and no reason', async () => {
    const ctx = await setup();
    const ask = (extra) => ctx.resets.request({ user: supervisor, nozzle: N1, before: '100', after: '0', reason: 'Rolled over', ...extra });
    await expect(ask({ after: '100.001' })).rejects.toMatchObject({ code: 'reset_not_lower' });
    await expect(ask({ before: 'x' })).rejects.toMatchObject({ code: 'reading_invalid' });
    await expect(ask({ reason: ' ' })).rejects.toMatchObject({ code: 'reason_required' });
    await expect(ask({ nozzle: null })).rejects.toMatchObject({ code: 'nozzle_required' });
  });
});

describe('the fuel voucher tender offline (FUEL-21)', () => {
  const method = paymentMethods[1];
  const base = (ctx, extra = {}) => ({ cart: { id: 'cart', lines: [], tenders: [] }, catalogue: ctx.catalogue, database: ctx.database, currency: 'KES', user: attendant, ...extra });

  it('is offered while the module’s data is on the till', async () => {
    const { catalogue } = await setup();
    expect(tenderProvider('fuel_voucher', catalogue)).toBe(fuelVoucherTender);
    expect(paymentOptions(catalogue, NOW).map((option) => option.method.type)).toEqual(['cash', 'fuel_voucher']);
  });

  it('takes the code as typed or scanned and applies min(balance, amount due), sending { code }', async () => {
    const ctx = await setup();
    await expect(fuelVoucherTender.prepare({ ...base(ctx), input: ' fv-abcd 1234 ', amountMinor: '361000' })).resolves.toEqual({ amountMinor: '361000', tender: { code: 'FVABCD1234' }, label: 'FVABCD1234' });
    // More due than the voucher holds: the voucher pays what it has.
    await expect(fuelVoucherTender.prepare({ ...base(ctx), input: 'SMALL-1', amountMinor: '361000' })).resolves.toMatchObject({ amountMinor: '20000' });
  });

  it('refuses an empty or unknown code, an expired voucher, another currency and a missing right', async () => {
    const ctx = await setup();
    const ask = (input, extra) => fuelVoucherTender.prepare({ ...base(ctx, extra), input, amountMinor: '1000' });
    await expect(ask('')).rejects.toMatchObject({ code: 'fuel_voucher_code_required' });
    await expect(ask('NOPE-1')).rejects.toMatchObject({ code: 'fuel_voucher_unknown' });
    await expect(ask('OLD-0001')).rejects.toMatchObject({ code: 'fuel_voucher_expired' });
    await expect(ask('USD-0001')).rejects.toMatchObject({ code: 'fuel_voucher_currency', currency: 'USD' });
    await expect(ask('SMALL-1', { user: { id: 'x', permissions: [] } })).rejects.toMatchObject({ code: 'fuel_voucher_not_permitted' });
  });

  it('counts what the cart already holds of the voucher', async () => {
    const ctx = await setup();
    const held = { id: 'cart', lines: [], tenders: [{ method, currency: 'KES', amountMinor: '15000', tender: { code: 'SMALL1' } }] };
    await expect(fuelVoucherTender.prepare({ ...base(ctx, { cart: held }), input: 'SMALL-1', amountMinor: '50000' })).resolves.toMatchObject({ amountMinor: '5000' });
    const spent = { id: 'cart', lines: [], tenders: [{ method, currency: 'KES', amountMinor: '20000', tender: { code: 'SMALL1' } }] };
    await expect(fuelVoucherTender.prepare({ ...base(ctx, { cart: spent }), input: 'SMALL-1', amountMinor: '1000' })).rejects.toMatchObject({ code: 'fuel_voucher_empty' });
  });

  it('reduces the balance for the next sale on this device, and the sale uploads { code } on its payment', async () => {
    const ctx = await setup();
    ctx.server.goOffline();
    ctx.engine.setNetwork('offline');
    const posShift = await ctx.selling.openShift({ user: attendant, actorProof: ctx.proof, openingFloat: [] });
    const shift = await openShift(ctx);
    // KES 180.50 of a KES 200.00 voucher pays 1 L of diesel.
    const first = await fuelVoucherTender.prepare({ ...base(ctx), input: 'SMALL-1', amountMinor: '18050' });
    expect(first.amountMinor).toBe('18050');
    const sale = await ringSale(ctx, posShift, shift, { mode: 'litres', litres: '1' }, { tenders: [{ id: uuidv7(NOW), method, currency: 'KES', amountMinor: first.amountMinor, tender: first.tender }] });
    expect(sale.payments[0]).toMatchObject({ payment_method_id: IDS.voucher, amount_minor: '18050', tender: { code: 'SMALL1' } });
    expect((await ctx.posStore.sale(sale.id)).local.spent).toEqual([{ type: 'fuel_voucher', tender: { code: 'SMALL1' }, amountMinor: '18050', currency: 'KES' }]);

    // The synced balance is still 20,000 until the next pull; this device has spent 18,050 of it.
    await expect(fuelVoucherTender.prepare({ ...base(ctx), input: 'SMALL-1', amountMinor: '5000' })).resolves.toMatchObject({ amountMinor: '1950' });
    // Another voucher is not affected.
    await expect(fuelVoucherTender.prepare({ ...base(ctx), input: 'FV-ABCD-1234', amountMinor: '1000' })).resolves.toMatchObject({ amountMinor: '1000' });
  });
});
