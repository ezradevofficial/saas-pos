import { signActorProof } from '../auth/pinCrypto';
import { uuidv7 } from '../lib/random';
import { buildCatalogue } from '../pos/catalogue';
import { cartReducer, computeCart, emptyCart } from '../pos/cart';
import { resetExtensions } from '../pos/extensions';
import { createPosStore } from '../pos/posStore';
import { createSelling } from '../pos/selling';
import { createSyncEngine } from '../sync/engine';
import { createSyncStore } from '../sync/store';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { loadPharmacyData } from './pharmacy';
import { registerPharmacy } from './register';

// PHA-07, PHA-08, PHA-16, PHA-17, PHA-25, NFR-04: pharmacy on the till, offline. The lots
// sync down like master data; the till refuses expired stock, picks first-expiry-first-out,
// asks for the prescription and the pharmacist, and the sale goes up with its choices.

const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const IDS = { company: id(1), list: id(2), vat: id(3), cash: id(4), uom: id(8), pcm: id(9), amox: id(10), morphine: id(11), tonic: id(12), cashier: id(13), pharmacist: id(14), range: id(15), customer: id(16) };
const NOW = Date.parse('2026-10-13T14:30:00Z');
const SECRET = { secret: Buffer.alloc(32, 9).toString('base64url'), kid: 'k1' };

const settings = { id: 'device', timezone: 'Africa/Nairobi', branch: { id: id(30) }, company: { id: IDS.company, base_currency: 'KES', reporting_currencies: [] }, location: { name: 'Westlands' } };
const currencies = [{ code: 'KES', decimals: 2, cash_rounding_minor: '100' }];
const taxCodes = [{ id: IDS.vat, code: 'VAT0', kind: 'standard', rates: [{ rate: '0.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] }];
const priceLists = [{ id: IDS.list, name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }];
const paymentMethods = [{ id: IDS.cash, type: 'cash', name: 'Cash', currency: 'KES', position: 1 }];
const item = (itemId, name) => ({ id: itemId, code: name.slice(0, 3).toUpperCase(), name, base_uom_id: IDS.uom, tax_code_id: IDS.vat, sellable: true, uoms: [], barcodes: [] });
const items = [item(IDS.pcm, 'Paracetamol'), item(IDS.amox, 'Amoxicillin'), item(IDS.morphine, 'Morphine'), item(IDS.tonic, 'Cattle tonic')];
const prices = items.map((entry, index) => ({ id: id(40 + index), price_list_id: IDS.list, item_id: entry.id, uom_id: IDS.uom, amount_minor: '10000', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' }));
const cashier = { id: IDS.cashier, name: 'Grace', permissions: ['pos.sale.create'], limits: {} };
const pharmacist = { id: IDS.pharmacist, name: 'Peter', permissions: ['pos.sale.create', 'pharmacy.dispense.authorise'], limits: {} };

const rules = { id: 'settings', near_expiry_days: 90, min_sale_days: 0, prescription_classes: ['prescription_only', 'controlled'], pharmacist_classes: ['controlled'], prescription_valid_days: 30 };
const profile = (n, itemId, klass, extra = {}) => ({ id: id(60 + n), item_id: itemId, class: klass, product_kind: 'human', batch_tracked: true, restricted_sale: false, usage_notes: null, withdrawal_days: null, ...extra });
const balance = (n, itemId, lotId, batch, expires, qty, blocked = null) => ({ id: id(80 + n), item_id: itemId, lot_id: lotId, batch_number: batch, manufactured_on: null, expires_on: expires, qty, blocked });
const LOTS = [
  balance(1, IDS.pcm, id(101), 'P-OLD', '2026-10-01', '10'),
  balance(2, IDS.pcm, id(102), 'P-SOON', '2027-01-10', '2'),
  balance(3, IDS.pcm, id(103), 'P-LATE', '2027-06-01', '20'),
  balance(4, IDS.amox, id(104), 'A-1', '2027-06-01', '30'),
  balance(5, IDS.morphine, id(105), 'M-1', '2027-06-01', '5'),
  balance(6, IDS.tonic, id(106), 'T-OLD', '2026-09-01', '4'),
];
const prescription = { id: id(300), prescriber_name: 'Dr Otieno', prescriber_licence: 'LIC-1', facility: 'Westlands Clinic', patient_name: 'Amina', prescribed_on: '2026-10-05' };

async function setup({ withModule = true } = {}) {
  const now = () => NOW;
  const server = fakeServer({ now });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: [{ id: IDS.range, document_type: 'pos.receipt', period: 'all', pattern: 'R-WL2-{000001}', from: 1, to: 500, next: 1 }] });
  if (withModule) {
    server.define('pharmacy_settings', { mode: 'snapshot', module: 'pharmacy', rows: [{ id: 'module', active: true }, rules] });
    server.define('pharmacy_items', {
      module: 'pharmacy',
      rows: [profile(1, IDS.pcm, 'otc'), profile(2, IDS.amox, 'prescription_only'), profile(3, IDS.morphine, 'controlled'), profile(4, IDS.tonic, 'agrochemical', { usage_notes: 'Keep away from water. Withdrawal 14 days.' })],
    });
    server.define('pharmacy_lots', { module: 'pharmacy', rows: LOTS });
  }
  const database = testDatabase();
  const engine = createSyncEngine({ api: server, store: createSyncStore(database), now, random: () => 0.5 });
  const selling = createSelling({ engine, posStore: createPosStore(database), api: server, now });
  await engine.pull();
  const ext = { pharmacy: await loadPharmacyData(database) };
  const catalogue = buildCatalogue({ settings, currencies, taxCodes, priceLists, paymentMethods, items, prices, staff: [cashier, pharmacist], ext, now: NOW });
  const proof = signActorProof({ deviceSecret: SECRET, deviceId: 'device-1', sessionId: uuidv7(NOW), userId: pharmacist.id, signedInAt: new Date(NOW).toISOString() });
  return { server, database, engine, selling, catalogue, proof };
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

const run = (catalogue, cart, user = cashier) => computeCart(cart, { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue, at: NOW, user });
const reasons = (computed) => computed.blocked.map((entry) => entry.reason);
const pharmacy = (extra) => ({ ext: { pharmacy: extra } });

beforeAll(() => registerPharmacy());

describe('the lots on the till (PHA-25)', () => {
  it('loads the settings, profiles and lots that synced down', async () => {
    const { catalogue } = await setup();
    const data = catalogue.ext.pharmacy;
    expect(data.active).toBe(true);
    expect(data.settings.near_expiry_days).toBe(90);
    expect(data.profiles.get(IDS.amox).class).toBe('prescription_only');
    expect(data.lots.get(IDS.pcm).map((row) => row.batch_number).sort()).toEqual(['P-LATE', 'P-OLD', 'P-SOON']);
  });

  it('is null, and blocks nothing, while the module is not subscribed', async () => {
    const { catalogue } = await setup({ withModule: false });
    expect(catalogue.ext.pharmacy).toBeNull();
    const computed = run(catalogue, cartWith(catalogue, [[IDS.morphine, 1]]));
    expect(computed.blocked).toEqual([]);
    expect(computed.extensions.pharmacy).toBeUndefined();
  });
});

describe('batches offline (PHA-07, PHA-08)', () => {
  it('picks first-expiry-first-out and skips the expired batch, sending the choice with the sale', async () => {
    const { catalogue } = await setup();
    const computed = run(catalogue, cartWith(catalogue, [[IDS.pcm, 3]]));
    expect(computed.blocked).toEqual([]);
    expect(computed.extensions.pharmacy.lines).toEqual([{ line_id: computed.lines[0].id, lots: [{ lot_id: id(102), qty: '2' }, { lot_id: id(103), qty: '1' }] }]);
  });

  it('refuses a line whose only stock is expired', async () => {
    const { catalogue } = await setup();
    expect(reasons(run(catalogue, cartWith(catalogue, [[IDS.tonic, 1]])))).toEqual(['lot_expired']);
  });

  it('refuses a chosen expired batch, and honours a chosen good one', async () => {
    const { catalogue } = await setup();
    const cart = cartWith(catalogue, [[IDS.pcm, 2]]);
    const lineId = cart.lines[0].id;
    expect(reasons(run(catalogue, { ...cart, ...pharmacy({ lots: { [lineId]: [{ lot_id: id(101), qty: '2' }] } }) }))).toEqual(['lot_expired']);
    const chosen = run(catalogue, { ...cart, ...pharmacy({ lots: { [lineId]: [{ lot_id: id(103), qty: '2' }] } }) });
    expect(chosen.blocked).toEqual([]);
    expect(chosen.extensions.pharmacy.lines[0].lots).toEqual([{ lot_id: id(103), qty: '2' }]);
  });

  it('refuses a recalled or quarantined batch pulled after the fact', async () => {
    const { catalogue } = await setup();
    catalogue.ext.pharmacy.lots.set(IDS.pcm, [{ ...LOTS[2], blocked: 'recalled' }, { ...LOTS[1], blocked: 'quarantined' }]);
    expect(reasons(run(catalogue, cartWith(catalogue, [[IDS.pcm, 1]])))).toEqual(['lot_quarantined']);
  });

  it('counts this till’s own sales against a batch while offline, and the next pull resets it', async () => {
    const { catalogue, selling, proof, server, engine } = await setup();
    server.goOffline();
    engine.setNetwork('offline');
    const shift = await selling.openShift({ user: pharmacist, actorProof: proof, openingFloat: [] });
    const sell = (qty) => selling.completeSale({ shift, user: pharmacist, actorProof: proof, cart: cartWith(catalogue, [[IDS.pcm, qty]]), catalogue, tenders: [{ id: uuidv7(NOW), method: paymentMethods[0], currency: 'KES', amountMinor: String(qty * 10000) }] });

    const sale = await sell(21);
    expect(sale.extensions.pharmacy.lines[0].lots).toEqual([{ lot_id: id(102), qty: '2' }, { lot_id: id(103), qty: '19' }]);
    // 22 in date stock, 21 gone: one left, two is a shortage.
    expect(reasons(run(catalogue, cartWith(catalogue, [[IDS.pcm, 2]])))).toEqual(['lot_short']);
    expect(run(catalogue, cartWith(catalogue, [[IDS.pcm, 1]])).blocked).toEqual([]);
    await expect(sell(2)).rejects.toMatchObject({ code: 'lines_blocked' });
  });
});

describe('prescription and pharmacist (PHA-16, PHA-17)', () => {
  it('blocks a prescription item until a prescription is taken down, then sends it', async () => {
    const { catalogue } = await setup();
    expect(reasons(run(catalogue, cartWith(catalogue, [[IDS.amox, 1]])))).toEqual(['prescription_needed']);
    const computed = run(catalogue, cartWith(catalogue, [[IDS.amox, 1]], pharmacy({ prescription })));
    expect(computed.blocked).toEqual([]);
    expect(computed.extensions.pharmacy.prescription).toEqual(prescription);
  });

  it('blocks a prescription that is too old for the tenant’s validity', async () => {
    const { catalogue } = await setup();
    expect(reasons(run(catalogue, cartWith(catalogue, [[IDS.amox, 1]], pharmacy({ prescription: { ...prescription, prescribed_on: '2026-08-01' } }))))).toEqual(['prescription_stale']);
  });

  it('wants a pharmacist for a controlled drug: the cashier asks, a pharmacist sells alone', async () => {
    const { catalogue } = await setup();
    const withRx = pharmacy({ prescription });
    expect(reasons(run(catalogue, cartWith(catalogue, [[IDS.morphine, 1]], withRx)))).toEqual(['pharmacist_needed']);

    const override = { manager_id: IDS.pharmacist, signature: 'proof' };
    const approved = run(catalogue, cartWith(catalogue, [[IDS.morphine, 1]], pharmacy({ prescription, approval: override })));
    expect(approved.blocked).toEqual([]);
    expect(approved.extensions.pharmacy.approval).toEqual(override);

    const alone = run(catalogue, cartWith(catalogue, [[IDS.morphine, 1]], withRx), pharmacist);
    expect(alone.blocked).toEqual([]);
    expect(alone.extensions.pharmacy.approval).toBeUndefined();
  });

  it('wants a named buyer for a restricted item', async () => {
    const { catalogue } = await setup();
    catalogue.ext.pharmacy.profiles.set(IDS.pcm, { ...catalogue.ext.pharmacy.profiles.get(IDS.pcm), restricted_sale: true });
    const cart = cartWith(catalogue, [[IDS.pcm, 1]]);
    expect(reasons(run(catalogue, cart, pharmacist))).toEqual(['buyer_needed']);
    expect(run(catalogue, { ...cart, customer: { id: IDS.customer, name: 'Amina' } }, pharmacist).blocked).toEqual([]);
  });

  it('refuses to complete a blocked sale and completes a cleared one offline', async () => {
    const { catalogue, selling, proof, server, engine } = await setup();
    server.goOffline();
    engine.setNetwork('offline');
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });
    const pay = { id: uuidv7(NOW), method: paymentMethods[0], currency: 'KES', amountMinor: '10000' };
    await expect(selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.amox, 1]]), catalogue, tenders: [pay] })).rejects.toMatchObject({ code: 'lines_blocked' });
    const sale = await selling.completeSale({ shift, user: cashier, actorProof: proof, cart: cartWith(catalogue, [[IDS.amox, 1]], pharmacy({ prescription })), catalogue, tenders: [pay] });
    expect(sale.extensions.pharmacy.prescription.id).toBe(prescription.id);
    expect(sale.extensions.pharmacy.lines[0].lots).toEqual([{ lot_id: id(104), qty: '1' }]);
  });
});

afterAll(() => resetExtensions());
