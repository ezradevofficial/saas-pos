import { memoryBackend } from '../device/credentials';
import { uuidv7 } from '../lib/random';
import { buildCatalogue } from '../pos/catalogue';
import { cartReducer, emptyCart } from '../pos/cart';
import { tenderProvider } from '../pos/extensions';
import { createServices } from '../services/services';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { vanSalesServer } from '../test/vanSalesServer';
import { BATCH } from './vsSync';

// VAN-06..VAN-11, VAN-14, VAN-17, VAN-18, VAN-27, NFR-04: the rep's day on the device, offline first,
// uploaded through POST van-sales/device/sync (idempotent, per-record results).

const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const IDS = { company: id(1), list: id(2), vat: id(3), cash: id(4), mpesa: id(6), credit: id(14), uom: id(7), tusker: id(8), bread: id(9), rep: id(10), range: id(12), refundRange: id(13), van: id(40), route: id(41), c1: id(51), c2: id(52), c3: id(53), stop1: id(61), stop2: id(62), load: id(70), loadLine1: id(71), loadLine2: id(72) };
const NOW = Date.parse('2026-11-02T06:00:00Z');

const settings = { id: 'device', timezone: 'Africa/Nairobi', company: { id: IDS.company, base_currency: 'KES', reporting_currencies: [] }, location: { name: 'Van 1' } };
const currencies = [{ code: 'KES', decimals: 2, cash_rounding_minor: '100' }];
const taxCodes = [{ id: IDS.vat, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] }];
const priceLists = [{ id: IDS.list, name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }];
const methods = {
  cash: { id: IDS.cash, type: 'cash', name: 'Cash', currency: 'KES', position: 1 },
  mpesa: { id: IDS.mpesa, type: 'mobile_money', name: 'M-Pesa', currency: 'KES', position: 2 },
  credit: { id: IDS.credit, type: 'credit', name: 'Credit', currency: 'KES', position: 3 },
};
const item = (itemId, name) => ({ id: itemId, code: name.slice(0, 3).toUpperCase(), name, base_uom_id: IDS.uom, tax_code_id: IDS.vat, sellable: true, uoms: [], barcodes: [] });
const items = [item(IDS.tusker, 'Tusker Lager 500ml'), item(IDS.bread, 'Supaloaf White 400g')];
const prices = [
  { id: id(30), price_list_id: IDS.list, item_id: IDS.tusker, uom_id: IDS.uom, amount_minor: '25000', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' },
  { id: id(31), price_list_id: IDS.list, item_id: IDS.bread, uom_id: IDS.uom, amount_minor: '6500', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' },
];
const rep = { id: IDS.rep, name: 'Juma Mwangi', permissions: ['pos.sale.create', 'van_sales.run.start'], limits: {} };
const van = { id: IDS.van, name: 'Van 1', plate: 'KDA 001A', rep_id: IDS.rep, rep_name: rep.name, location_id: id(300), gps_checkin: 'optional', gps_retention_days: 30 };
const route = {
  id: IDS.route,
  name: 'Route A',
  visit_days: [1, 2, 3, 4, 5, 6, 7],
  stops: [
    { id: IDS.stop1, customer_id: IDS.c1, customer_name: 'Mama Njeri', phone: '0722000111', position: 1, visit_days: [] },
    { id: IDS.stop2, customer_id: IDS.c2, customer_name: 'Duka la Baraka', phone: '0733000222', position: 2, visit_days: [] },
  ],
};
const accounts = [
  { id: IDS.c1, credit_limit_minor: '500000', credit_limit_currency: 'KES', balances: [{ currency: 'KES', amount_minor: '100000' }] },
  { id: IDS.c2, credit_limit_minor: null, credit_limit_currency: null, balances: [] },
];
const loads = [
  { id: IDS.load, run_id: null, number: 'L-1', status: 'confirmed', lines: [{ id: IDS.loadLine1, item_id: IDS.tusker, item_name: 'Tusker Lager 500ml', qty: '20', qty_confirmed: '20' }, { id: IDS.loadLine2, item_id: IDS.bread, item_name: 'Supaloaf White 400g', qty: '10', qty_confirmed: '10' }] },
];
const ranges = [
  { id: IDS.range, document_type: 'pos.receipt', period: 'all', pattern: 'R-VAN-{000001}', from: 1, to: 200, next: 1 },
  { id: IDS.refundRange, document_type: 'pos.refund', period: 'all', pattern: 'F-VAN-{0001}', from: 1, to: 500, next: 1 },
];

async function setup() {
  const server = fakeServer({ now: () => NOW });
  server.define('settings', { mode: 'snapshot', rows: [{ ...server.state.settings }] });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: ranges });
  const remote = vanSalesServer(server);
  remote.define({ van, routes: [route], accounts, loads });
  const services = createServices({ database: testDatabase(), api: server, credentialsBackend: memoryBackend(), netInfo: null });
  await services.engine.sync();
  const catalogue = buildCatalogue({ settings, currencies, taxCodes, priceLists, paymentMethods: Object.values(methods), items, prices, staff: [rep], ext: { vansales: { van } }, now: NOW });
  return { server, remote, services, vanSales: services.vanSales, engine: services.engine, catalogue };
}

function cartFor(catalogue, entries, { customer, ext }) {
  let cart = emptyCart(NOW);
  for (const [itemId, qty] of entries) {
    const found = catalogue.itemById.get(itemId);
    const price = catalogue.priceFor(found, IDS.uom, IDS.list, '1');
    cart = cartReducer(cart, { type: 'add', item: found, uomId: IDS.uom, price: price.amountMinor, listPriceMinor: price.amountMinor, priceListId: IDS.list, taxInclusive: true, now: NOW });
    if (qty !== 1) cart = cartReducer(cart, { type: 'edit', id: cart.lines[cart.lines.length - 1].id, patch: { qty: String(qty) } });
  }
  cart = cartReducer(cart, { type: 'customer', customer, priceListId: IDS.list });
  return cartReducer(cart, { type: 'ext', key: 'van_sales', value: ext });
}

const tender = (method, amountMinor, extra = {}) => ({ id: uuidv7(NOW), method, currency: 'KES', amountMinor: String(amountMinor), ...extra });
const until = async (check) => {
  for (let i = 0; i < 100; i += 1) {
    if (await check()) return;
    await new Promise((resolve) => setTimeout(resolve, 5));
  }
  throw new Error('condition not met');
};

describe('route selling offline (VAN-06..VAN-18, NFR-04)', () => {
  it('starts the day, visits, sells cash, M-Pesa and credit, collects and counts with the network down', async () => {
    const { server, remote, services, vanSales, engine, catalogue } = await setup();
    const { selling } = services;
    server.goOffline();
    engine.setNetwork('offline');

    // The module is on this device only because the van row was pulled.
    expect((await vanSales.van()).id).toBe(IDS.van);

    const shift = await selling.openShift({ user: rep, actorProof: null, openingFloat: [] });
    const run = await vanSales.startDay({ user: rep, routeId: IDS.route, timeZone: 'Africa/Nairobi' });
    expect(run.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7/);
    await expect(vanSales.startDay({ user: rep, routeId: IDS.route })).rejects.toMatchObject({ code: 'run_open' });
    expect((await vanSales.currentRun()).id).toBe(run.id);

    const visit = await vanSales.openVisit({ run, customer: { id: IDS.c1, name: 'Mama Njeri' }, stop: route.stops[0], user: rep });
    expect(visit.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7/);
    // The same stop again is the same visit.
    expect((await vanSales.openVisit({ run, customer: { id: IDS.c1, name: 'Mama Njeri' }, stop: route.stops[0], user: rep })).id).toBe(visit.id);
    const customer = { id: IDS.c1, name: 'Mama Njeri', phone: null, price_list_id: null };
    const ext = { run_id: run.id, visit_id: visit.id };

    // 1. A cash sale.
    const cash = await selling.completeSale({ shift, user: rep, actorProof: null, cart: cartFor(catalogue, [[IDS.tusker, 2]], { customer, ext }), catalogue, tenders: [tender(methods.cash, 50000)] });
    // 2. An M-Pesa sale.
    const mpesa = await selling.completeSale({ shift, user: rep, actorProof: null, cart: cartFor(catalogue, [[IDS.bread, 2]], { customer, ext }), catalogue, tenders: [tender(methods.mpesa, 13000, { reference: 'QJK9PUSH01', status: 'confirmed' })] });

    // 3. A part-credit sale: the credit tender is decided on the device, offline.
    const creditCart = cartFor(catalogue, [[IDS.tusker, 1]], { customer, ext });
    const provider = tenderProvider('credit', catalogue);
    expect(provider).not.toBeNull();
    const prepared = await provider.prepare({ amountMinor: '20000', currency: 'KES', cart: creditCart, catalogue, database: services.database, online: false });
    const creditTender = tender(methods.credit, 20000, { extension: prepared.extension });
    const creditSale = await selling.completeSale({ shift, user: rep, actorProof: null, cart: creditCart, catalogue, tenders: [creditTender, tender(methods.cash, 5000)] });
    expect(creditSale.extensions.van_sales).toEqual({
      run_id: run.id,
      visit_id: visit.id,
      credit: { limit_minor: '500000', balance_minor: '100000', currency: 'KES', decided_at: expect.any(String), offline: true },
    });

    // The credit sale is not in the pulled balance yet: it is counted against the limit (4,000.00 - 200.00 left).
    const again = tenderProvider('credit', catalogue).prepare({ amountMinor: '380001', currency: 'KES', cart: creditCart, catalogue, database: services.database, online: false });
    await expect(again).rejects.toMatchObject({ code: 'over_limit', available: '380000' });
    // A customer with no limit gets none.
    const noLimitCart = cartFor(catalogue, [[IDS.bread, 1]], { customer: { id: IDS.c2, name: 'Duka la Baraka' }, ext });
    await expect(provider.prepare({ amountMinor: '1000', currency: 'KES', cart: noLimitCart, catalogue, database: services.database, online: false })).rejects.toMatchObject({ code: 'no_credit_limit' });

    // The visit becomes sold when a sale of that visit completes.
    await until(async () => (await services.vanSales.records.get(visit.id)).status === 'sold');

    // 4. A collection takes the next number of the receipt range.
    const collection = await vanSales.collect({ run, visit, customer, method: methods.cash, currency: 'KES', amountMinor: '30000', user: rep, timeZone: 'Africa/Nairobi' });
    const numbers = [cash.receipt_number, mpesa.receipt_number, creditSale.receipt_number, collection.receipt_number];
    expect(numbers).toEqual(['R-VAN-000001', 'R-VAN-000002', 'R-VAN-000003', 'R-VAN-000004']);
    expect(new Set(numbers).size).toBe(4);
    expect(collection).toMatchObject({ receipt_seq: 4, number_range_id: IDS.range });
    expect((await services.posStore.counters('pos.receipt'))[IDS.range]).toBe(5);

    // A visit with no sale.
    const second = await vanSales.openVisit({ run, customer: { id: IDS.c2, name: 'Duka la Baraka' }, stop: route.stops[1], user: rep });
    await vanSales.noSale(second.id, { reason: 'closed', note: 'Shutters down' });

    // 5. The count: items from the confirmed load and local sales; money from local tenders.
    const figures = await vanSales.dayFigures(run);
    expect(figures.items).toEqual([
      { item_id: IDS.bread, item_name: 'Supaloaf White 400g', load_qty: '10', sold_qty: '2', customer_returns: '0' },
      { item_id: IDS.tusker, item_name: 'Tusker Lager 500ml', load_qty: '20', sold_qty: '3', customer_returns: '0' },
    ]);
    // Cash 500.00 + 50.00 + collection 300.00; M-Pesa 130.00; the credit part never counts.
    expect(figures.expected).toEqual([
      { kind: 'cash', currency: 'KES', expected_minor: '85000' },
      { kind: 'mobile_money', currency: 'KES', expected_minor: '13000' },
    ]);
    const countId = uuidv7(NOW);
    await vanSales.submitCount({ id: countId, run, user: rep, lines: [{ item_id: IDS.bread, returned_qty: '8', damaged_qty: '0' }, { item_id: IDS.tusker, returned_qty: '15', damaged_qty: '1' }], tenders: [{ kind: 'cash', currency: 'KES', counted_minor: '85000' }, { kind: 'mobile_money', currency: 'KES', counted_minor: '13000' }] });
    await expect(vanSales.submitCount({ id: countId, run, user: rep, lines: [], tenders: [] })).rejects.toMatchObject({ code: 'record_exists' });
    expect((await vanSales.runs())[0]).toMatchObject({ id: run.id, status: 'counting', count: { status: 'queued' } });

    // Everything is stored locally with unique ids; nothing reached the server.
    const kept = [...(await vanSales.records.list('run')), ...(await vanSales.records.list('visit')), ...(await vanSales.records.list('collection')), ...(await vanSales.records.list('count'))];
    expect(new Set(kept.map((record) => record.id)).size).toBe(kept.length);
    expect(kept.map((record) => record.kind).sort()).toEqual(['collection', 'count', 'run', 'visit', 'visit']);
    expect(server.state.requests.filter((request) => request.method === 'POST')).toHaveLength(0);
    expect(engine.getStatus().pending).toBeGreaterThan(5);
    expect(await vanSales.records.pendingCount()).toBe(5);

    // Back online: the route records go up before the sales that belong to them.
    server.goOnline();
    engine.setNetwork('online');
    const run1 = await engine.sync();
    expect(run1.pushed.van_sales).toMatchObject({ sent: 5, stored: 5, rejected: 0 });
    const posts = server.state.requests.filter((request) => request.method === 'POST').map((request) => request.path);
    expect(posts.indexOf('van-sales/device/sync')).toBeGreaterThanOrEqual(0);
    expect(posts.indexOf('van-sales/device/sync')).toBeLessThan(posts.indexOf('pos/sales'));
    expect(remote.count('runs')).toBe(1);
    expect(remote.count('visits')).toBe(2);
    expect(remote.count('collections')).toBe(1);
    expect(remote.count('counts')).toBe(1);
    expect(await vanSales.records.pendingCount()).toBe(0);
    // The server's answer to the count is shown.
    expect((await vanSales.runs())[0]).toMatchObject({ status: 'counting', count: { status: 'pending' } });
  });
});

describe('idempotent upload (VAN-27, NFR-04)', () => {
  async function dayWith(vanSales, services, stops = 2) {
    const run = await vanSales.startDay({ user: rep, routeId: IDS.route, timeZone: 'Africa/Nairobi' });
    const visits = [];
    for (let i = 0; i < stops; i += 1) visits.push(await vanSales.openVisit({ run, customer: { id: id(900 + i), name: `Customer ${i}` }, user: rep }));
    return { run, visits };
  }

  it('a resend after a lost answer duplicates nothing and keeps the records until stored', async () => {
    const { remote, services, vanSales, engine } = await setup();
    const { run, visits } = await dayWith(vanSales, services);
    await vanSales.collect({ run, visit: visits[0], customer: { id: id(900), name: 'Customer 0' }, method: methods.cash, currency: 'KES', amountMinor: '1000', user: rep, timeZone: 'Africa/Nairobi' });

    // The server stores everything, but the answer is lost: the records stay pending.
    remote.loseNextAnswer();
    const lost = await engine.sync({ pull: false });
    expect(lost.error.name).toBe('NetworkError');
    expect(remote.count('visits')).toBe(2);
    expect(await vanSales.records.pendingCount()).toBe(4);

    const again = await engine.sync({ pull: false });
    expect(again.pushed.van_sales).toMatchObject({ sent: 4, stored: 4 });
    expect([remote.count('runs'), remote.count('visits'), remote.count('collections')]).toEqual([1, 2, 1]);
    expect(await vanSales.records.pendingCount()).toBe(0);

    // Nothing is sent when nothing is pending, and a stored visit changed later goes again, once.
    const quiet = remote.state.requests.length;
    await engine.sync({ pull: false });
    expect(remote.state.requests.length).toBe(quiet);
    await vanSales.noSale(visits[0].id, { reason: 'no_need' });
    await engine.sync({ pull: false });
    expect(remote.state.requests.length).toBe(quiet + 1);
    expect(remote.count('visits')).toBe(2);
  });

  it('a batch the server stopped part way through is resent whole and the server holds each record once', async () => {
    const { remote, services, vanSales, engine } = await setup();
    await dayWith(vanSales, services, 4);
    const total = await vanSales.records.pendingCount();
    expect(total).toBe(5);

    remote.storeOnly(3);
    const partial = await engine.sync({ pull: false });
    expect(partial.error.name).toBe('NetworkError');
    expect(remote.count('runs') + remote.count('visits')).toBe(3);
    // Nothing was acknowledged, so nothing is lost on the device.
    expect(await vanSales.records.pendingCount()).toBe(5);

    const done = await engine.sync({ pull: false });
    expect(done.pushed.van_sales).toMatchObject({ sent: 5, stored: 5 });
    expect(remote.count('runs')).toBe(1);
    expect(remote.count('visits')).toBe(4);
    expect(await vanSales.records.pendingCount()).toBe(0);
  });

  it('keeps pending the records the server did not answer, and parks a refused record with its code', async () => {
    const { remote, services, vanSales, engine } = await setup();
    const { visits } = await dayWith(vanSales, services, 3);
    remote.reject(visits[1].id, 'visit_invalid');

    const summary = (await engine.sync({ pull: false })).pushed.van_sales;
    expect(summary).toMatchObject({ sent: 4, stored: 3, rejected: 1 });
    const parked = await vanSales.records.rejected();
    expect(parked.map((record) => [record.id, record.local.error.code])).toEqual([[visits[1].id, 'visit_invalid']]);
    expect(await vanSales.records.pendingCount()).toBe(0);
    const requests = remote.state.requests.length;
    await engine.sync({ pull: false });
    expect(remote.state.requests.length).toBe(requests);

    // A parked record can be put back in the queue once the cause is fixed.
    await vanSales.retry(visits[1].id);
    expect(await vanSales.records.pendingCount()).toBe(1);
  });

  it('uploads at most 100 records in a request, the run first', async () => {
    const { remote, services, vanSales, engine } = await setup();
    await dayWith(vanSales, services, 130);
    expect(BATCH).toBe(100);
    await engine.sync({ pull: false });
    expect(remote.state.batches.map((batch) => batch.total)).toEqual([100, 31]);
    expect(remote.count('runs')).toBe(1);
    expect(remote.count('visits')).toBe(130);
    expect(await vanSales.records.pendingCount()).toBe(0);
  });
});

describe('confirming a load (VAN-08)', () => {
  it('posts the counts when online and says so, keeping the sheet, when offline', async () => {
    const { server, remote, vanSales, engine } = await setup();
    const sheet = { id: IDS.load, lines: [{ id: IDS.loadLine1, item_id: IDS.tusker, qty: '20' }, { id: IDS.loadLine2, item_id: IDS.bread, qty: '10' }] };
    server.goOffline();
    engine.setNetwork('offline');
    await expect(vanSales.confirmLoad({ load: sheet, received: {}, user: rep })).rejects.toMatchObject({ code: 'offline' });
    expect(remote.state.confirms).toHaveLength(0);

    server.goOnline();
    engine.setNetwork('online');
    await vanSales.confirmLoad({ load: { ...sheet, status: 'released' }, received: { [IDS.loadLine1]: '19', [IDS.loadLine2]: '10' }, user: rep });
    expect(remote.state.confirms).toEqual([{ id: IDS.load, body: { user_id: rep.id, lines: [{ id: IDS.loadLine1, qty_received: '19' }, { id: IDS.loadLine2, qty_received: '10' }] } }]);
  });
});
