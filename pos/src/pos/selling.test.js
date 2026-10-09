import { signActorProof, signOverride } from '../auth/pinCrypto';
import { uuidv7 } from '../lib/random';
import { shapeProblems } from '../test/apiShapes';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { createSyncEngine } from '../sync/engine';
import { createSyncStore, OUTBOX } from '../sync/store';
import { buildCatalogue } from './catalogue';
import { cartReducer, emptyCart } from './cart';
import { createPosStore } from './posStore';
import { createSelling, expectedCash, SellingError } from './selling';

// POS-01..POS-06, POS-09, NFR-04, AUTH-07, AUTH-08: selling on the till against the
// in-memory server; every payload is checked against the POS module's request rules.

const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const IDS = { company: id(1), list: id(2), vat: id(3), cash: id(4), cashUsd: id(5), mpesa: id(6), uom: id(7), tusker: id(8), bread: id(9), cashier: id(10), manager: id(11), range: id(12), refundRange: id(13), customer: id(14) };
const SECRET = { secret: Buffer.alloc(32, 9).toString('base64url'), kid: 'k1' };
const NOW = Date.parse('2026-10-09T05:00:00Z');

const settings = { id: 'device', timezone: 'Africa/Nairobi', company: { id: IDS.company, base_currency: 'KES', reporting_currencies: ['USD'] }, location: { name: 'Westlands' } };
const currencies = [
  { code: 'KES', decimals: 2, cash_rounding_minor: '100' },
  { code: 'USD', decimals: 2, cash_rounding_minor: '1' },
];
const rates = [{ id: id(20), base: 'USD', quote: 'KES', kind: 'shop', mid: '129.50000000', effective_at: '2026-10-01T00:00:00Z' }];
const taxCodes = [{ id: IDS.vat, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] }];
const priceLists = [{ id: IDS.list, name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }];
const paymentMethods = [
  { id: IDS.cash, type: 'cash', name: 'Cash', currency: 'KES', position: 1 },
  { id: IDS.cashUsd, type: 'cash', name: 'Cash USD', currency: 'USD', position: 2 },
  { id: IDS.mpesa, type: 'mobile_money', name: 'M-Pesa', currency: 'KES', position: 3 },
];
const item = (itemId, name) => ({ id: itemId, code: name.slice(0, 3).toUpperCase(), name, base_uom_id: IDS.uom, tax_code_id: IDS.vat, sellable: true, uoms: [], barcodes: [] });
const items = [item(IDS.tusker, 'Tusker Lager 500ml'), item(IDS.bread, 'Supaloaf White 400g')];
const prices = [
  { id: id(30), price_list_id: IDS.list, item_id: IDS.tusker, uom_id: IDS.uom, amount_minor: '25000', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' },
  { id: id(31), price_list_id: IDS.list, item_id: IDS.bread, uom_id: IDS.uom, amount_minor: '6500', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' },
];
const cashier = { id: IDS.cashier, name: 'Grace Wanjiru', permissions: ['pos.sale.create'], limits: {} };
const manager = { id: IDS.manager, name: 'Peter Kamau', permissions: ['pos.sale.void', 'pos.sale.refund', 'pos.discount.give'], limits: { max_discount_percent: '20.0000', max_refund_amount: '1000.0000' } };
const ranges = (receiptNext = 1) => [
  { id: IDS.range, document_type: 'pos.receipt', period: 'all', pattern: 'R-WL2-{000001}', from: 1, to: 105, next: receiptNext },
  { id: IDS.refundRange, document_type: 'pos.refund', period: 'all', pattern: 'F-WL2-{0001}', from: 1, to: 500, next: 1 },
];

async function setup({ receiptNext = 1 } = {}) {
  let time = NOW;
  const now = () => time;
  const server = fakeServer({ now });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: ranges(receiptNext) });
  const database = testDatabase();
  const engine = createSyncEngine({ api: server, store: createSyncStore(database), now, random: () => 0.5 });
  const posStore = createPosStore(database);
  const selling = createSelling({ engine, posStore, api: server, now });
  await engine.pull();
  const catalogue = buildCatalogue({ settings, currencies, rates, taxCodes, priceLists, paymentMethods, items, prices, staff: [cashier, manager], now: NOW });
  const proofFor = (user) => signActorProof({ deviceSecret: SECRET, deviceId: 'device-1', sessionId: uuidv7(time), userId: user.id, signedInAt: new Date(time).toISOString() });
  return { server, database, engine, posStore, selling, catalogue, proofFor, advance: (ms) => (time += ms), now };
}

function cartWith(catalogue, entries) {
  let cart = emptyCart(NOW);
  for (const [itemId, qty] of entries) {
    const found = catalogue.itemById.get(itemId);
    const price = catalogue.priceFor(found, IDS.uom, IDS.list, '1');
    cart = cartReducer(cart, { type: 'add', item: found, uomId: IDS.uom, price: price.amountMinor, listPriceMinor: price.amountMinor, priceListId: IDS.list, taxInclusive: true, now: NOW });
    const line = cart.lines[cart.lines.length - 1];
    if (qty !== 1) cart = cartReducer(cart, { type: 'edit', id: line.id, patch: { qty: String(qty) } });
  }
  return cart;
}

const tender = (methodId, currency, amountMinor, extra = {}) => ({ id: uuidv7(NOW), method: paymentMethods.find((method) => method.id === methodId), currency, amountMinor: String(amountMinor), ...extra });

describe('selling offline, then uploading in order (POS-09, NFR-04)', () => {
  it('sells three sales offline, keeps them in the outbox, then uploads shift and sales in order', async () => {
    const { server, engine, selling, catalogue, proofFor, posStore } = await setup();
    server.goOffline();
    engine.setNetwork('offline');
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [{ currency: 'KES', amountMinor: '500000' }] });

    const sales = [];
    for (const entries of [[[IDS.tusker, 2]], [[IDS.bread, 1]], [[IDS.tusker, 1], [IDS.bread, 3]]]) {
      const cart = cartWith(catalogue, entries);
      const total = entries.reduce((sum, [itemId, qty]) => sum + (itemId === IDS.tusker ? 25000 : 6500) * qty, 0);
      sales.push(await selling.completeSale({ shift, user: cashier, actorProof: proof, cart, catalogue, tenders: [tender(IDS.cash, 'KES', total)] }));
    }

    expect(sales.map((sale) => sale.receipt_number)).toEqual(['R-WL2-000001', 'R-WL2-000002', 'R-WL2-000003']);
    expect(sales.every((sale) => sale.offline === true)).toBe(true);
    for (const sale of sales) expect(shapeProblems('sale', sale.payload ?? stripLocal(sale))).toEqual([]);
    expect(engine.getStatus().pending).toBe(4);
    expect((await posStore.saleByReceipt('R-WL2-000002')).id).toBe(sales[1].id);

    server.goOnline();
    engine.setNetwork('online');
    const summary = await engine.push();

    expect(summary.acknowledged).toBe(4);
    const uploads = server.state.requests.filter((request) => request.method === 'POST').map((request) => [request.path, Object.values(request.body)[0].map((record) => record.id)]);
    expect(uploads).toEqual([
      ['pos/shifts', [shift.id]],
      ['pos/sales', sales.map((sale) => sale.id)],
    ]);
    // Idempotent: a resend changes nothing (the server answers stored again).
    expect(server.state.stored.size).toBe(4);
  });

  it('stores a sale and its outbox row together, and draws numbers in order across calls', async () => {
    const { selling, catalogue, proofFor, database } = await setup();
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    const [a, b] = await Promise.all([
      selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, tenders: [tender(IDS.cash, 'KES', 6500)] }),
      selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, tenders: [tender(IDS.cash, 'KES', 6500)] }),
    ]);
    expect([a.receipt_seq, b.receipt_seq]).toEqual([1, 2]);
    const outbox = await database.get('outbox').query().fetch();
    expect(outbox.map((row) => row._raw.kind)).toEqual(['pos.shifts', 'pos.sales', 'pos.sales']);
    expect(outbox.every((row) => row._raw.group_key === shift.id)).toBe(true);
  });
});

const stripLocal = ({ local: _local, ...payload }) => payload;

describe('sale payload (POS-01, POS-03, CUR-04, CUR-06, CUR-09)', () => {
  it('matches the API rules for a split, two-currency sale with change in KES', async () => {
    const { selling, catalogue, proofFor, engine } = await setup();
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [{ currency: 'KES', amountMinor: '0' }, { currency: 'USD', amountMinor: '0' }] });
    // Total KES 565.00 paid by USD 2.00 (= KES 259.00 at 129.50), M-Pesa KES 200.00 and cash KES 500.00.
    const cart = cartWith(catalogue, [[IDS.tusker, 2], [IDS.bread, 1]]);
    const sale = await selling.completeSale({
      shift,
      user: cashier,
      actorProof: proof,
      cart: { ...cart, customer: { id: IDS.customer, name: 'Achieng Otieno' } },
      catalogue,
      tenders: [tender(IDS.cashUsd, 'USD', 200), tender(IDS.mpesa, 'KES', 20000, { reference: 'QJK4XYZ12', status: 'confirmed' }), tender(IDS.cash, 'KES', 50000)],
      changeCurrency: 'KES',
    });
    const payload = stripLocal(sale);
    expect(shapeProblems('sale', payload)).toEqual([]);
    expect(payload.totals).toEqual({ subtotal_minor: '56500', discount_minor: '0', tax_minor: '7794', total_minor: '56500' });
    expect(payload.payments[0]).toEqual({
      id: expect.any(String),
      payment_method_id: IDS.cashUsd,
      currency: 'USD',
      amount_minor: '200',
      amount_in_sale_minor: '25900',
      rate: { rate: '129.50000000', base: 'USD', quote: 'KES', kind: 'shop', effective_at: '2026-10-01T00:00:00Z' },
    });
    expect(payload.payments[1]).toMatchObject({ provider_reference: 'QJK4XYZ12', status: 'confirmed', amount_in_sale_minor: '20000' });
    expect(payload.payments[1].rate).toBeUndefined();
    // KES 959.00 paid for KES 565.00: KES 394.00 back, rounded down to whole shillings.
    expect(payload.change).toEqual({ currency: 'KES', amount_minor: '39400' });
    expect(payload.customer_id).toBe(IDS.customer);
    expect(payload.lines[0]).toMatchObject({ qty: '2', unit_price_minor: '25000', list_price_minor: '25000', tax_inclusive: true, tax_code_id: IDS.vat, tax_rate: '16.0000', tax_minor: '6897', total_minor: '50000' });
    expect(payload.lines[0].actor_proof).toBeUndefined();
    expect(sale.local.rate_ids).toEqual([rates[0].id]);
    expect(engine.getStatus().pending).toBe(2);
  });

  it('gives change in USD at the shop rate, rounded down, with the rate on the change', async () => {
    const { selling, catalogue, proofFor } = await setup();
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    const sale = await selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, tenders: [tender(IDS.cashUsd, 'USD', 1000)], changeCurrency: 'USD' });
    // USD 10 = KES 1,295.00; KES 65.00 due; KES 1,230.00 over = USD 9.4980... → USD 9.49.
    expect(sale.change).toEqual({ currency: 'USD', amount_minor: '949', rate: { rate: '129.50000000', base: 'USD', quote: 'KES', kind: 'shop', effective_at: '2026-10-01T00:00:00Z' } });
    expect(shapeProblems('sale', stripLocal(sale))).toEqual([]);
  });

  it('refuses to complete an underpaid sale or a line whose tax rate is needed', async () => {
    const { selling, catalogue, proofFor } = await setup();
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    await expect(selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, tenders: [tender(IDS.cash, 'KES', 6400)] })).rejects.toMatchObject({ code: 'sale_underpaid' });
    const needed = buildCatalogue({ settings, currencies, rates, priceLists, paymentMethods, items, prices, taxCodes: [{ ...taxCodes[0], rates: [{ rate: null, effective_from: '2026-01-01', needs_confirmation: true }] }], now: NOW });
    expect(needed.tiles.every((tile) => tile.sellable === false && tile.reason === 'tax_rate_needed')).toBe(true);
    await expect(selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue: needed, tenders: [tender(IDS.cash, 'KES', 6500)] })).rejects.toBeInstanceOf(SellingError);
  });

  it('sells from the default price list in the company currency when other currencies have defaults too (MD-03)', () => {
    const usdList = { id: 'list-usd', name: 'Retail Kinshasa', currency: 'USD', tax_inclusive: true, is_default: true };
    const both = buildCatalogue({ settings, currencies, rates, taxCodes, priceLists: [usdList, ...priceLists], paymentMethods, items, prices, now: NOW });
    expect(both.defaultList.id).toBe(IDS.list);
    expect(both.saleCurrency).toBe('KES');
    const usdTill = buildCatalogue({ settings: { ...settings, company: { ...settings.company, base_currency: 'USD' } }, currencies, rates, taxCodes, priceLists: [...priceLists, usdList], paymentMethods, items, prices, now: NOW });
    expect(usdTill.defaultList.id).toBe('list-usd');
    expect(usdTill.saleCurrency).toBe('USD');
  });

  it('carries a manager override (AUTH-08) on a discounted line, signed for that line', async () => {
    const { selling, catalogue, proofFor } = await setup();
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    let cart = cartWith(catalogue, [[IDS.tusker, 2]]);
    const line = cart.lines[0];
    const overrideId = uuidv7(NOW);
    const { signature, authorisedAt } = signOverride({ deviceSecret: SECRET, serverNow: () => NOW, deviceId: 'device-1', id: overrideId, managerUserId: IDS.manager, cashierUserId: IDS.cashier, permission: 'pos.discount.give', reference: line.id });
    const override = { id: overrideId, kid: 'k1', manager_user_id: IDS.manager, cashier_user_id: IDS.cashier, permission: 'pos.discount.give', reference: line.id, authorised_at: authorisedAt, signature };
    cart = cartReducer(cart, { type: 'edit', id: line.id, patch: { discountMinor: '5000', override, discountBy: IDS.manager } });
    const sale = await selling.completeSale({ shift, user: cashier, actorProof: proof, cart, catalogue, tenders: [tender(IDS.cash, 'KES', 45000)] });
    expect(sale.lines[0]).toMatchObject({ discount_minor: '5000', total_minor: '45000', tax_minor: '6207', override: { manager_user_id: IDS.manager, reference: line.id, permission: 'pos.discount.give' } });
    // Approved by override: the line needs no proof of its own (the sale's applies).
    expect(sale.lines[0].actor_proof).toBeUndefined();
    expect(shapeProblems('sale', stripLocal(sale))).toEqual([]);
  });
});

describe('open cart and stored sale values', () => {
  it('drops the saved open cart in the sale’s transaction and keeps base and second-currency values', async () => {
    const { selling, catalogue, proofFor, posStore } = await setup();
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    const cart = cartWith(catalogue, [[IDS.bread, 2]]);
    await posStore.saveOpenCart(cart);
    expect((await posStore.openCart()).id).toBe(cart.id);
    const sale = await selling.completeSale({ shift, user: cashier, actorProof: proof, cart, catalogue, tenders: [tender(IDS.cash, 'KES', 13000)] });
    expect(await posStore.openCart()).toBeNull();
    // KES is the base: no rate; the second currency (USD) total asked at the shop rate, rounded up.
    expect(sale.local.base).toEqual({ currency: 'KES', rate: null });
    expect(sale.local.base_lines[sale.lines[0].id]).toBe('13000');
    expect(sale.local.dual).toEqual({ currency: 'USD', minor: '101' });
  });
});

describe('day rollover (POS-11)', () => {
  it('taxes a sale completed after the company’s midnight at the new day’s rate', async () => {
    const { selling, proofFor, advance } = await setup();
    const proof = proofFor(cashier);
    const rising = [{ ...taxCodes[0], rates: [{ rate: '16.0000', effective_from: '2026-01-01', effective_to: '2026-10-09', needs_confirmation: false }, { rate: '18.0000', effective_from: '2026-10-10', effective_to: null, needs_confirmation: false }] }];
    // Built at 08:00 in Nairobi on the 9th; the company's zone decides the day.
    const catalogue = buildCatalogue({ settings: { ...settings, company: { ...settings.company, timezone: 'Africa/Nairobi' } }, currencies, rates, taxCodes: rising, priceLists, paymentMethods, items, prices, now: NOW });
    expect(catalogue.day).toBe('2026-10-09');
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    const cart = cartWith(catalogue, [[IDS.bread, 1]]);
    advance(17 * 60 * 60 * 1000); // 01:00 on the 10th in Nairobi
    const sale = await selling.completeSale({ shift, user: cashier, actorProof: proof, cart, catalogue, tenders: [tender(IDS.cash, 'KES', 6500)] });
    expect(sale.lines[0]).toMatchObject({ tax_rate: '18.0000', tax_minor: '992' });
  });
});

describe('a newly paired till gets its ranges (NUM-02)', () => {
  /** A server that gives a 500-number range for each document type asked. */
  function allocating(server) {
    const given = [];
    server.state.handler = (method, path, body) => {
      if (method !== 'POST' || path !== 'pos/number-ranges') return null;
      const rangeId = body.document_type === 'pos.receipt' ? IDS.range : IDS.refundRange;
      const pattern = body.document_type === 'pos.receipt' ? 'R-WL2-{000001}' : 'F-WL2-{0001}';
      given.push(body);
      server.upsert('pos_number_ranges', { id: rangeId, document_type: body.document_type, period: 'all', pattern, from: 1, to: 500, next: 1 });
      return { status: 200, body: { data: [] } };
    };
    return given;
  }

  async function freshTill() {
    const context = await setup();
    // A new pairing: the server holds no range for this till yet.
    context.server.state.entities.pos_number_ranges.rows.clear();
    context.server.bumpVersion('pos_number_ranges');
    await context.engine.pull();
    expect(await context.posStore.numberRanges()).toEqual([]);
    return context;
  }

  it('asks for every document type it lacks, once, then has numbers', async () => {
    const { server, selling, posStore } = await freshTill();
    const given = allocating(server);

    expect(await selling.ensureRanges('Africa/Nairobi')).toEqual(['pos.receipt', 'pos.refund']);
    expect(given).toEqual([{ document_type: 'pos.receipt' }, { document_type: 'pos.refund' }]);
    expect((await posStore.numberRanges()).map((range) => range.document_type).sort()).toEqual(['pos.receipt', 'pos.refund']);

    // Enough numbers now: nothing more is asked.
    expect(await selling.ensureRanges('Africa/Nairobi')).toEqual([]);
    expect(given).toHaveLength(2);
  });

  it('asks nothing offline', async () => {
    const { server, engine, selling } = await freshTill();
    const given = allocating(server);
    engine.setNetwork('offline');
    expect(await selling.ensureRanges('Africa/Nairobi')).toEqual([]);
    expect(given).toEqual([]);
  });

  it('tops up and retries once when a sale finds no number while online', async () => {
    const { server, engine, selling, catalogue, proofFor } = await freshTill();
    const given = allocating(server);
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    const sale = await selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, tenders: [tender(IDS.cash, 'KES', 6500)] });
    expect(sale.receipt_number).toBe('R-WL2-000001');
    expect(given[0]).toEqual({ document_type: 'pos.receipt' });

    // Offline with no numbers left, the sale is refused (and the cart kept by the caller).
    server.state.entities.pos_number_ranges.rows.clear();
    server.bumpVersion('pos_number_ranges');
    await engine.pull();
    engine.setNetwork('offline');
    await expect(selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, tenders: [tender(IDS.cash, 'KES', 6500)] })).rejects.toMatchObject({ code: 'no_receipt_numbers' });
  });
});

describe('receipt ranges top-up (NUM-02)', () => {
  it('asks for more numbers when fewer than 100 remain, reporting the next number', async () => {
    const { server, selling, catalogue, proofFor } = await setup({ receiptNext: 7 });
    server.state.uploadOverride = (path, body) => (path === 'pos/number-ranges' ? { status: 200, body: { data: [] } } : null);
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    await selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, tenders: [tender(IDS.cash, 'KES', 6500)] });
    await new Promise((resolve) => setTimeout(resolve, 0));
    const asked = server.requestsTo('pos/number-ranges');
    expect(asked).toHaveLength(1);
    expect(asked[0].body).toEqual({ document_type: 'pos.receipt', next: 8 });
  });

  it('refuses to sell when the till has no number left', async () => {
    const { selling, catalogue, proofFor, posStore } = await setup();
    const proof = proofFor(cashier);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    await posStore.write([posStore.prepareCounters('pos.receipt', { [IDS.range]: 106 })]);
    await expect(selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, tenders: [tender(IDS.cash, 'KES', 6500)] })).rejects.toMatchObject({ code: 'no_receipt_numbers' });
  });
});

describe('voids, refunds, cash movements and shifts (POS-04, POS-05)', () => {
  it('builds void, refund, movement and close payloads the API accepts, and computes expected cash', async () => {
    const { selling, catalogue, proofFor, posStore, engine, server } = await setup();
    const proof = proofFor(cashier);
    const managerProof = proofFor(manager);
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [{ currency: 'KES', amountMinor: '100000' }, { currency: 'USD', amountMinor: '0' }] });
    const voided = await selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, tenders: [tender(IDS.cash, 'KES', 10000)] });
    const kept = await selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.tusker, 3]]), catalogue, tenders: [tender(IDS.cash, 'KES', 75000)] });

    const voidPayloadSent = await selling.voidSale({ sale: voided, shift, user: manager, actorProof: managerProof, reason: 'Wrong item' });
    expect(shapeProblems('void', voidPayloadSent)).toEqual([]);
    await expect(selling.voidSale({ sale: voided, shift, user: manager, actorProof: managerProof, reason: 'Again' })).rejects.toMatchObject({ code: 'sale_already_voided' });

    const quote = await selling.refundQuote({ sale: kept, requested: [{ saleLineId: kept.lines[0].id, qty: '1' }] });
    expect(quote.totalMinor).toBe('25000');
    const refund = await selling.refund({ sale: kept, shift, user: manager, actorProof: managerProof, requested: [{ saleLineId: kept.lines[0].id, qty: '1' }], method: paymentMethods[0], currency: 'KES', reason: 'Damaged', catalogue });
    expect(shapeProblems('refund', stripLocal(refund))).toEqual([]);
    expect(refund).toMatchObject({ receipt_number: 'F-WL2-0001', total_minor: '25000', payments: [{ currency: 'KES', amount_minor: '25000', amount_in_sale_minor: '25000' }] });
    await expect(selling.refund({ sale: kept, shift, user: manager, actorProof: managerProof, requested: [{ saleLineId: kept.lines[0].id, qty: '3' }], method: paymentMethods[0], currency: 'KES', reason: 'Too many', catalogue })).rejects.toMatchObject({ code: 'refund_qty_exceeded' });
    await expect(selling.voidSale({ sale: kept, shift, user: manager, actorProof: managerProof, reason: 'After refund' })).rejects.toMatchObject({ code: 'sale_has_refunds' });

    const payOut = await selling.cashMovement({ shift, user: manager, actorProof: managerProof, kind: 'pay_out', currency: 'KES', amountMinor: '20000', reason: 'Cleaning supplies' });
    expect(shapeProblems('movement', payOut)).toEqual([]);

    const expected = expectedCash({ shift, sales: await posStore.salesOfShift(shift.id), records: await posStore.recordsOfShift(shift.id), cashMethodIds: new Set([IDS.cash, IDS.cashUsd]) });
    // 1,000 float + 750 sale (the voided sale excluded) − 250 refund − 200 pay-out = KES 1,300.00.
    expect(Object.fromEntries([...expected].map(([currency, minor]) => [currency, String(minor)]))).toEqual({ KES: '130000', USD: '0' });

    const closed = await selling.closeShift({ shift, user: manager, actorProof: managerProof, counted: [{ currency: 'KES', amountMinor: '129500' }, { currency: 'USD', amountMinor: '0' }], note: 'Short 5' });
    expect(shapeProblems('shift', closed.payload)).toEqual([]);
    expect(closed.payload.closing).toMatchObject({ closed_by_id: IDS.manager, counted: [{ currency: 'KES', amount_minor: '129500' }, { currency: 'USD', amount_minor: '0' }], note: 'Short 5' });
    expect((await posStore.openShift())).toBeNull();

    await engine.push();
    const order = server.state.requests.filter((request) => request.method === 'POST' && request.path !== 'pos/number-ranges').map((request) => request.path);
    expect(order).toEqual(['pos/shifts', 'pos/sales', 'pos/voids', 'pos/refunds', 'pos/cash-movements', 'pos/shifts']);
    expect((await engine.store.entries(OUTBOX.PENDING))).toHaveLength(0);
  });
});
