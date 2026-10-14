import { signActorProof } from '../auth/pinCrypto';
import { uuidv7 } from '../lib/random';
import { buildCatalogue } from '../pos/catalogue';
import { cartReducer, computeCart, emptyCart } from '../pos/cart';
import { paymentOptions } from '../pos/payments/options';
import { createPosStore } from '../pos/posStore';
import { createSelling } from '../pos/selling';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { createSyncEngine } from '../sync/engine';
import { createSyncStore } from '../sync/store';
import { loadLoyaltyData, localTime, PROMOTION_SOURCE } from './promotions';
import { resetExtensions, tenderProvider } from '../pos/extensions';
import { registerLoyalty } from './register';
import { codeHash, codeProof, creditTender, giftCardTender, pointsTender } from './tenders';

// LOY-05, LOY-11, LOY-15, LOY-22, NFR-04: loyalty on the till, offline. Promotions apply
// on the cart with their provenance; points, gift cards and credit follow the offline
// rule (last synced balance less this till's sales since); the sale goes up with them.

const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const IDS = { company: id(1), list: id(2), vat: id(3), cash: id(4), points: id(5), voucher: id(6), credit: id(7), uom: id(8), soda: id(9), bread: id(10), cashier: id(11), manager: id(12), range: id(13), customer: id(14), promo: id(15), drinks: id(16), softDrinks: id(17) };
const NOW = Date.parse('2026-10-13T14:30:00Z'); // 17:30 in Nairobi, a Tuesday
const CARD = 'ABCD-EFGH-JKMN-PQRS';
const SECRET = { secret: Buffer.alloc(32, 9).toString('base64url'), kid: 'k1' };

const settings = { id: 'device', timezone: 'Africa/Nairobi', branch: { id: id(30) }, company: { id: IDS.company, base_currency: 'KES', reporting_currencies: [] }, location: { name: 'Westlands' } };
const currencies = [{ code: 'KES', decimals: 2, cash_rounding_minor: '100' }];
const taxCodes = [{ id: IDS.vat, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] }];
const priceLists = [{ id: IDS.list, name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }];
const paymentMethods = [
  { id: IDS.cash, type: 'cash', name: 'Cash', currency: 'KES', position: 1 },
  { id: IDS.points, type: 'points', name: 'Points', currency: 'KES', position: 2 },
  { id: IDS.voucher, type: 'voucher', name: 'Gift card', currency: null, position: 3 },
  { id: IDS.credit, type: 'credit', name: 'On account', currency: null, position: 4 },
];
const categories = [
  { id: IDS.drinks, name: 'Drinks', parent_id: null },
  { id: IDS.softDrinks, name: 'Soft drinks', parent_id: IDS.drinks },
];
const item = (itemId, name, categoryId) => ({ id: itemId, code: name.slice(0, 3).toUpperCase(), name, base_uom_id: IDS.uom, tax_code_id: IDS.vat, category_id: categoryId, sellable: true, uoms: [], barcodes: [] });
const items = [item(IDS.soda, 'Soda 500ml', IDS.softDrinks), item(IDS.bread, 'Bread 400g', null)];
const prices = [
  { id: id(40), price_list_id: IDS.list, item_id: IDS.soda, uom_id: IDS.uom, amount_minor: '10000', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' },
  { id: id(41), price_list_id: IDS.list, item_id: IDS.bread, uom_id: IDS.uom, amount_minor: '6000', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' },
];
const cashier = { id: IDS.cashier, name: 'Grace', permissions: ['pos.sale.create'], limits: {} };
const manager = { id: IDS.manager, name: 'Peter', permissions: ['pos.sale.create', 'loyalty.credit.sell'], limits: {} };
const customer = { id: IDS.customer, name: 'Amina', phones: [], credit_limit: { amount_minor: '100000', currency: 'KES' } };

const promotion = {
  id: IDS.promo, kind: 'promotion', name: 'Happy hour drinks', version: 2, type: 'percent', priority: 1, percent: '20',
  targets: { items: [], categories: [IDS.drinks] }, customer_tags: ['vip'], schedule: { days: [2], time_from: '17:00', time_to: '19:00' },
  coupon_codes: null, coupons: [{ code: 'SAVE20', remaining: 3 }, { code: 'USED', remaining: 0 }],
};

async function setup() {
  let time = NOW;
  const now = () => time;
  const server = fakeServer({ now });
  server.define('customers', { rows: [customer] });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: [{ id: IDS.range, document_type: 'pos.receipt', period: 'all', pattern: 'R-WL2-{000001}', from: 1, to: 500, next: 1 }] });
  server.define('loyalty_settings', { mode: 'snapshot', module: 'loyalty', rows: [{ id: 'programme', active: true, currency: 'KES', earn_points: 1, earn_spend_minor: '10000', point_value_minor: '100', min_redeem_points: '10', gift_card_item_id: null }] });
  server.define('loyalty_promotions', { mode: 'snapshot', module: 'loyalty', rows: [promotion, { id: 'group:vip', kind: 'group', tag: 'vip', customer_ids: [IDS.customer] }] });
  server.define('loyalty_members', {
    module: 'loyalty',
    rows: [
      { id: id(50), customer_id: IDS.customer, kind: 'points', currency: null, balance: '500', status: 'open' },
      { id: id(51), customer_id: IDS.customer, kind: 'credit', currency: 'KES', balance: '30000', status: 'open' },
    ],
  });
  server.define('loyalty_gift_cards', {
    module: 'loyalty',
    rows: [
      { id: id(60), kind: 'gift_card', code_hash: codeHash(CARD), last4: 'PQRS', currency: 'KES', initial_minor: '20000', balance_minor: '15000', status: 'active', expires_on: null },
      { id: id(61), kind: 'gift_card', code_hash: codeHash('ZZZZ-ZZZZ-ZZZZ-ZZZZ'), last4: 'ZZZZ', currency: 'KES', initial_minor: '20000', balance_minor: '0', status: 'inactive', expires_on: null },
    ],
  });
  const database = testDatabase();
  const engine = createSyncEngine({ api: server, store: createSyncStore(database), now, random: () => 0.5 });
  const posStore = createPosStore(database);
  const selling = createSelling({ engine, posStore, api: server, now });
  await engine.pull();
  const ext = { loyalty: await loadLoyaltyData(database, { categories }) };
  const catalogue = buildCatalogue({ settings, currencies, taxCodes, priceLists, paymentMethods, categories, items, prices, staff: [cashier, manager], ext, now: NOW });
  const proof = signActorProof({ deviceSecret: SECRET, deviceId: 'device-1', sessionId: uuidv7(time), userId: manager.id, signedInAt: new Date(time).toISOString() });
  return { server, database, engine, posStore, selling, catalogue, proof, now, advance: (ms) => (time += ms) };
}

function cartWith(catalogue, entries, extra = {}) {
  let cart = emptyCart(NOW);
  for (const [itemId, qty] of entries) {
    const found = catalogue.itemById.get(itemId);
    const price = catalogue.priceFor(found, IDS.uom, IDS.list, '1');
    cart = cartReducer(cart, { type: 'add', item: found, uomId: IDS.uom, price: price.amountMinor, listPriceMinor: price.amountMinor, priceListId: IDS.list, taxInclusive: true, now: NOW });
    if (qty !== 1) cart = cartReducer(cart, { type: 'edit', id: cart.lines[cart.lines.length - 1].id, patch: { qty: String(qty) } });
  }
  return { ...cart, ...extra };
}

const method = (methodId) => paymentMethods.find((entry) => entry.id === methodId);
const tender = (methodId, amountMinor, extra = {}) => ({ id: uuidv7(NOW), method: method(methodId), currency: 'KES', amountMinor: String(amountMinor), ...extra });
const withCustomer = { customer: { id: IDS.customer, name: 'Amina' } };

beforeAll(() => registerLoyalty());

describe('promotions on the cart (LOY-11)', () => {
  it('applies a happy-hour promotion for a VIP on drinks (category with its parent), with its provenance', async () => {
    const { catalogue } = await setup();
    const computed = computeCart(cartWith(catalogue, [[IDS.soda, 2], [IDS.bread, 1]], withCustomer), { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue, at: NOW });

    const soda = computed.lines.find((line) => line.itemId === IDS.soda);
    expect(soda.amounts.adjustments).toEqual([{ source: PROMOTION_SOURCE, reference: IDS.promo, amount_minor: '4000', label: 'Happy hour drinks' }]);
    expect(soda.amounts.totalMinor).toBe('16000');
    expect(computed.lines.find((line) => line.itemId === IDS.bread).amounts.adjustments).toBeUndefined();
    expect(computed.totals.discount_minor).toBe('4000');
    expect(computed.extensions).toEqual({ loyalty: { promotions: [{ id: IDS.promo, version: 2 }] } });
  });

  it('applies nothing to someone not in the group, or outside the window', async () => {
    const { catalogue } = await setup();
    const ctx = { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue };
    expect(computeCart(cartWith(catalogue, [[IDS.soda, 2]]), { ...ctx, at: NOW }).totals.discount_minor).toBe('0');
    expect(computeCart(cartWith(catalogue, [[IDS.soda, 2]], withCustomer), { ...ctx, at: NOW + 2 * 3600 * 1000 }).totals.discount_minor).toBe('0');
    expect(localTime(NOW, 'Africa/Nairobi')).toEqual({ date: '2026-10-13', weekday: 2, time: '17:30' });
  });

  it('a coupon promotion takes a code with uses left (as synced), and the sale carries it', async () => {
    const { catalogue } = await setup();
    catalogue.ext.loyalty.promotions = [{ ...promotion, customer_tags: [], coupon_codes: ['SAVE20', 'USED'] }];
    const ctx = { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue, at: NOW };

    expect(computeCart(cartWith(catalogue, [[IDS.soda, 1]], { ext: { loyalty: { coupons: ['USED'] } } }), ctx).totals.discount_minor).toBe('0');
    const computed = computeCart(cartWith(catalogue, [[IDS.soda, 1]], { ext: { loyalty: { coupons: ['save20'] } } }), ctx);
    expect(computed.totals.discount_minor).toBe('2000');
    expect(computed.extensions.loyalty.coupons).toEqual(['SAVE20']);
  });

  it('never discounts a line below zero with a manual discount', async () => {
    const { catalogue } = await setup();
    let cart = cartWith(catalogue, [[IDS.soda, 1]], withCustomer);
    cart = cartReducer(cart, { type: 'edit', id: cart.lines[0].id, patch: { discountMinor: '9000' } });
    const line = computeCart(cart, { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue, at: NOW }).lines[0];
    expect(line.amounts.adjustments[0].amount_minor).toBe('1000');
    expect(line.amounts.totalMinor).toBe('0');
  });

  it('offers points in the programme currency', async () => {
    const { catalogue } = await setup();
    expect(paymentOptions(catalogue, NOW).map((option) => `${option.method.type}:${option.currency}`)).toEqual(['cash:KES', 'points:KES', 'voucher:KES', 'credit:KES']);
  });
});

describe('points as a tender, offline rule (LOY-05)', () => {
  it('redeems up to the synced balance less this till’s sales since the pull', async () => {
    const { selling, catalogue, database, engine, proof, server } = await setup();
    server.goOffline();
    engine.setNetwork('offline');
    const base = { cart: cartWith(catalogue, [[IDS.bread, 5]], withCustomer), catalogue, database, currency: 'KES' };

    await expect(pointsTender.prepare({ ...base, input: '5' })).rejects.toMatchObject({ code: 'points_below_minimum' });
    await expect(pointsTender.prepare({ ...base, input: '501' })).rejects.toMatchObject({ code: 'points_insufficient', available: '500' });
    const prepared = await pointsTender.prepare({ ...base, input: '300' });
    expect(prepared).toEqual({ amountMinor: '30000', tender: { points: '300' }, label: '300' });

    const shift = await selling.openShift({ user: manager, actorProof: proof, openingFloat: [] });
    const sale = await selling.completeSale({ shift, user: manager, actorProof: proof, cart: base.cart, catalogue, tenders: [tender(IDS.points, '30000', { tender: prepared.tender })] });
    expect(sale.payments[0].tender).toEqual({ points: '300' });

    // The next sale offline sees 200 left.
    await expect(pointsTender.prepare({ ...base, cart: cartWith(catalogue, [[IDS.bread, 5]], withCustomer), input: '300' })).rejects.toMatchObject({ code: 'points_insufficient', available: '200' });
  });

  it('needs a customer', async () => {
    const { catalogue, database } = await setup();
    await expect(pointsTender.prepare({ cart: cartWith(catalogue, [[IDS.bread, 1]]), catalogue, database, currency: 'KES', input: '50' })).rejects.toMatchObject({ code: 'customer_required' });
  });
});

describe('gift cards as a tender (LOY-22, LOY-23)', () => {
  it('hashes the code, caps the amount at what is left and refuses unsold or unknown cards, offline', async () => {
    const { catalogue, database } = await setup();
    const base = { cart: cartWith(catalogue, [[IDS.bread, 5]]), catalogue, database, currency: 'KES', online: false };

    expect(codeHash('abcd efgh-jkmn pqrs')).toBe(codeHash(CARD));
    // M8: the till looks the card up by H(H(code)) and pays with H(code), the proof.
    await expect(giftCardTender.prepare({ ...base, input: CARD.toLowerCase(), amountMinor: '30000' })).resolves.toEqual({ amountMinor: '15000', tender: { code_hash: codeProof(CARD), last4: 'PQRS' }, label: '•••• PQRS' });
    expect(codeHash(CARD)).not.toBe(codeProof(CARD));
    await expect(giftCardTender.prepare({ ...base, input: 'ZZZZ-ZZZZ-ZZZZ-ZZZZ', amountMinor: '100' })).rejects.toMatchObject({ code: 'gift_card_inactive' });
    await expect(giftCardTender.prepare({ ...base, input: 'NOPE', amountMinor: '100' })).rejects.toMatchObject({ code: 'gift_card_unknown' });
  });

  it('a card spent offline on one sale has only the rest for the next', async () => {
    const { selling, catalogue, database, proof, engine, server } = await setup();
    server.goOffline();
    engine.setNetwork('offline');
    const shift = await selling.openShift({ user: manager, actorProof: proof, openingFloat: [] });
    const first = await giftCardTender.prepare({ cart: {}, catalogue, database, currency: 'KES', input: CARD, amountMinor: '12000', online: false });
    await selling.completeSale({ shift, user: manager, actorProof: proof, cart: cartWith(catalogue, [[IDS.bread, 2]]), catalogue, tenders: [tender(IDS.voucher, first.amountMinor, { tender: first.tender })] });

    const second = await giftCardTender.prepare({ cart: {}, catalogue, database, currency: 'KES', input: CARD, amountMinor: '12000', online: false });
    expect(second.amountMinor).toBe('3000');
  });
});

describe('selling on credit (LOY-15)', () => {
  it('needs the permission, an open account, and stays within the limit with this till’s sales counted', async () => {
    const { selling, catalogue, database, proof, engine, server } = await setup();
    server.goOffline();
    engine.setNetwork('offline');
    const cart = cartWith(catalogue, [[IDS.bread, 5]], withCustomer);
    const base = { cart, catalogue, database, currency: 'KES' };

    await expect(creditTender.prepare({ ...base, user: cashier, amountMinor: '30000' })).rejects.toMatchObject({ code: 'credit_not_permitted' });
    // Owes KES 300.00 of a KES 1,000.00 limit: KES 700.00 left.
    await expect(creditTender.prepare({ ...base, user: manager, amountMinor: '70001' })).rejects.toMatchObject({ code: 'credit_limit_exceeded', available: '70000' });
    const prepared = await creditTender.prepare({ ...base, user: manager, amountMinor: '30000' });

    const shift = await selling.openShift({ user: manager, actorProof: proof, openingFloat: [] });
    await selling.completeSale({ shift, user: manager, actorProof: proof, cart, catalogue, tenders: [tender(IDS.credit, prepared.amountMinor)] });
    await expect(creditTender.prepare({ ...base, cart: cartWith(catalogue, [[IDS.bread, 7]], withCustomer), user: manager, amountMinor: '42000' })).rejects.toMatchObject({ code: 'credit_limit_exceeded', available: '40000' });
  });
});

describe('the sale goes up with its loyalty data after a week offline (NFR-04)', () => {
  it('uploads the promotion provenance, the coupon and the tender once, idempotently', async () => {
    const { selling, catalogue, database, proof, engine, server, advance } = await setup();
    catalogue.ext.loyalty.promotions = [{ ...promotion, coupon_codes: ['SAVE20'] }];
    server.goOffline();
    engine.setNetwork('offline');
    const shift = await selling.openShift({ user: manager, actorProof: proof, openingFloat: [] });
    const cart = cartWith(catalogue, [[IDS.soda, 2]], { ...withCustomer, ext: { loyalty: { coupons: ['SAVE20'] } } });
    const points = await pointsTender.prepare({ cart, catalogue, database, currency: 'KES', input: '160' });
    const sale = await selling.completeSale({ shift, user: manager, actorProof: proof, cart, catalogue, tenders: [tender(IDS.points, points.amountMinor, { tender: points.tender })] });

    expect(sale.lines[0].adjustments).toEqual([{ source: PROMOTION_SOURCE, reference: IDS.promo, amount_minor: '4000' }]);
    expect(sale.extensions).toEqual({ loyalty: { coupons: ['SAVE20'], promotions: [{ id: IDS.promo, version: 2 }] } });
    expect(sale.totals.total_minor).toBe('16000');

    advance(7 * 24 * 3600 * 1000);
    server.goOnline();
    engine.setNetwork('online');
    await engine.push();
    await engine.push();

    const uploaded = [...server.state.stored.values()].filter((entry) => entry.path === 'pos/sales').map((entry) => entry.record);
    expect(uploaded).toHaveLength(1);
    expect(uploaded[0].payments[0].tender).toEqual({ points: '160' });
    expect(uploaded[0].lines[0].adjustments[0].reference).toBe(IDS.promo);
  });
});

describe('review fixes on the till (H3, M3, M4, M5, M8)', () => {
  it('H3: without the Loyalty module its tenders and payment options are off', async () => {
    const { catalogue } = await setup();
    const off = { ...catalogue, ext: { loyalty: null } };
    expect(tenderProvider('points', off)).toBeNull();
    expect(tenderProvider('voucher', off)).toBeNull();
    expect(tenderProvider('credit', off)).toBeNull();
    expect(paymentOptions(off, NOW).map((option) => option.method.type)).toEqual(['cash']);
    expect(tenderProvider('points', catalogue)).toBe(pointsTender);
  });

  it('H3: a till that never pulled loyalty data loads none', async () => {
    const database = testDatabase();
    await expect(loadLoyaltyData(database, { categories })).resolves.toBeNull();
  });

  it('M3: tenders already on the cart count against the balance', async () => {
    const { catalogue, database } = await setup();
    const onCart = (methodId, amountMinor, extra) => ({ method: method(methodId), currency: 'KES', amountMinor, ...extra });
    const cart = cartWith(catalogue, [[IDS.bread, 20]], { ...withCustomer, tenders: [onCart(IDS.points, '40000', { tender: { points: '400' } }), onCart(IDS.voucher, '10000', { tender: { code_hash: codeProof(CARD) } }), onCart(IDS.credit, '50000')] });
    const base = { cart, catalogue, database, currency: 'KES', online: false };

    await expect(pointsTender.prepare({ ...base, input: '101' })).rejects.toMatchObject({ code: 'points_insufficient', available: '100' });
    await expect(giftCardTender.prepare({ ...base, input: CARD, amountMinor: '9000' })).resolves.toMatchObject({ amountMinor: '5000' });
    await expect(creditTender.prepare({ ...base, user: manager, amountMinor: '20001' })).rejects.toMatchObject({ code: 'credit_limit_exceeded', available: '20000' });
  });

  it('M4: a sale acknowledged before the next pull still counts; after the pull the server balance rules', async () => {
    const { selling, catalogue, database, proof, engine, server, advance } = await setup();
    const shift = await selling.openShift({ user: manager, actorProof: proof, openingFloat: [] });
    const cart = cartWith(catalogue, [[IDS.bread, 5]], withCustomer);
    const prepared = await pointsTender.prepare({ cart, catalogue, database, currency: 'KES', input: '300' });
    advance(1000);
    await selling.completeSale({ shift, user: manager, actorProof: proof, cart, catalogue, tenders: [tender(IDS.points, '30000', { tender: prepared.tender })] });
    await engine.push();
    const acknowledged = (await database.get('outbox').query().fetch()).filter((row) => row._raw.kind === 'pos.sales');
    expect(acknowledged.map((row) => row._raw.status)).toEqual(['acknowledged']);

    // Acknowledged, but the members row was pulled before the sale: still counted.
    await expect(pointsTender.available({ database, customerId: IDS.customer })).resolves.toBe(200n);

    // The server counts it; the next pull brings 200 and the sale is no longer subtracted.
    server.upsert('loyalty_members', { id: id(50), customer_id: IDS.customer, kind: 'points', currency: null, balance: '200', status: 'open' });
    advance(1000);
    await engine.pull();
    await expect(pointsTender.available({ database, customerId: IDS.customer })).resolves.toBe(200n);
  });

  it('M5: a coupon with one use left is not offered again after this till used it, offline', async () => {
    const { selling, catalogue, proof, engine, server } = await setup();
    catalogue.ext.loyalty.promotions = [{ ...promotion, customer_tags: [], coupon_codes: ['ONCE'], coupons: [{ code: 'ONCE', remaining: 1 }] }];
    server.goOffline();
    engine.setNetwork('offline');
    const ctx = { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue, at: NOW };
    const cart = cartWith(catalogue, [[IDS.soda, 1]], { ext: { loyalty: { coupons: ['ONCE'] } } });
    expect(computeCart(cart, ctx).totals.discount_minor).toBe('2000');

    const shift = await selling.openShift({ user: manager, actorProof: proof, openingFloat: [] });
    await selling.completeSale({ shift, user: manager, actorProof: proof, cart, catalogue, tenders: [tender(IDS.cash, '8000')] });

    expect(computeCart(cartWith(catalogue, [[IDS.soda, 1]], { ext: { loyalty: { coupons: ['ONCE'] } } }), ctx).totals.discount_minor).toBe('0');
  });

  it('M5: the own uses survive a restart (reloaded from sales the pull may not count)', async () => {
    const { selling, catalogue, database, proof, engine, server } = await setup();
    catalogue.ext.loyalty.promotions = [{ ...promotion, customer_tags: [], coupon_codes: ['SAVE20'] }];
    server.goOffline();
    engine.setNetwork('offline');
    const shift = await selling.openShift({ user: manager, actorProof: proof, openingFloat: [] });
    const cart = cartWith(catalogue, [[IDS.soda, 1]], { ext: { loyalty: { coupons: ['SAVE20'] } } });
    await selling.completeSale({ shift, user: manager, actorProof: proof, cart, catalogue, tenders: [tender(IDS.cash, '8000')] });

    const reloaded = await loadLoyaltyData(database, { categories });
    expect(reloaded.couponUses.get('SAVE20')).toBe(1);
  });

  it('M8: online, the lookup sends the proof, never the stored hash', async () => {
    const { catalogue, database } = await setup();
    const calls = [];
    const api = { post: async (path, body) => (calls.push({ path, body }), { status: 200, body: { data: { last4: 'PQRS', currency: 'KES', balance_minor: '8000', status: 'active', expires_on: null } } }) };
    const prepared = await giftCardTender.prepare({ cart: {}, catalogue, database, currency: 'KES', input: CARD, amountMinor: '9000', online: true, api });
    expect(calls).toEqual([{ path: 'loyalty/device/gift-cards/lookup', body: { code_hash: codeProof(CARD) } }]);
    expect(prepared.amountMinor).toBe('8000');
  });
});

afterAll(() => resetExtensions());
