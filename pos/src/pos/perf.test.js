import { signActorProof } from '../auth/pinCrypto';
import { uuidv7 } from '../lib/random';
import { fakeServer } from '../test/fakeServer';
import { testDatabase } from '../test/testDatabase';
import { createSyncEngine } from '../sync/engine';
import { createSyncStore } from '../sync/store';
import { buildCatalogue, findByCode } from './catalogue';
import { cartReducer, computeCart, emptyCart } from './cart';
import { createPosStore } from './posStore';
import { createSelling } from './selling';

// NFR-03: a regression guard for the till's own logic on a large catalogue
// and a long cart. Budgets are for a CI machine, about ten times faster
// than the low-end Android device NFR-03 is measured on (docs/performance.md,
// "POS on-device test"), so a budget here stays well inside 1 s there.
// It catches a change that makes adding an item or completing a sale grow
// with the catalogue or the cart (an accidental O(n²)), not small drifts.

const BUDGET = {
  buildCatalogueMs: 3000, // 20,000 items with prices, once per sync
  findByCodeMs: 5, // one scan lookup
  addItemP95Ms: 60, // reducer + totals recomputed, on a 200-line cart
  completeSaleMs: 1500, // 150 lines, numbering, payload, outbox write
};

const ITEMS = 20000;
const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const IDS = { company: id(1), list: id(2), vat: id(3), cash: id(4), uom: id(7), cashier: id(10), range: id(12) };
const SECRET = { secret: Buffer.alloc(32, 9).toString('base64url'), kid: 'k1' };
const NOW = Date.parse('2026-10-09T05:00:00Z');

const settings = { id: 'device', timezone: 'Africa/Nairobi', company: { id: IDS.company, base_currency: 'KES', reporting_currencies: [] }, location: { name: 'Westlands' } };
const currencies = [{ code: 'KES', decimals: 2, cash_rounding_minor: '100' }];
const taxCodes = [{ id: IDS.vat, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] }];
const priceLists = [{ id: IDS.list, name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }];
const paymentMethods = [{ id: IDS.cash, type: 'cash', name: 'Cash', currency: 'KES', position: 1 }];
const cashier = { id: IDS.cashier, name: 'Grace Wanjiru', permissions: ['pos.sale.create'], limits: {} };
const itemId = (n) => id(100000 + n);
const items = Array.from({ length: ITEMS }, (_, n) => ({
  id: itemId(n), code: `SKU${n}`, name: `Item ${n}`, base_uom_id: IDS.uom, tax_code_id: IDS.vat, sellable: true, uoms: [], barcodes: [{ barcode: `6161${String(n).padStart(9, '0')}`, uom_id: IDS.uom }],
}));
const prices = items.map((item, n) => ({
  id: id(200000 + n), price_list_id: IDS.list, item_id: item.id, uom_id: IDS.uom, amount_minor: String(1000 + (n % 500) * 100), currency: 'KES', effective_from: '2026-01-01', min_quantity: '1',
}));

const time = (fn) => {
  const started = performance.now();
  const result = fn();
  return [performance.now() - started, result];
};
const p95 = (values) => [...values].sort((a, b) => a - b)[Math.floor(values.length * 0.95)];

let catalogue;
beforeAll(() => {
  let ms;
  [ms, catalogue] = time(() => buildCatalogue({ settings, currencies, rates: [], taxCodes, priceLists, paymentMethods, items, prices, staff: [cashier], now: NOW }));
  catalogue.builtInMs = ms;
});

function add(cart, n) {
  const item = catalogue.itemById.get(itemId(n));
  const price = catalogue.priceFor(item, IDS.uom, IDS.list, '1');
  return cartReducer(cart, { type: 'add', item, uomId: IDS.uom, price: price.amountMinor, listPriceMinor: price.amountMinor, priceListId: IDS.list, taxInclusive: true, now: NOW });
}

describe('till speed on a large catalogue and a long cart (NFR-03)', () => {
  it('builds a 20,000-item catalogue within budget', () => {
    expect(catalogue.itemById.size).toBe(ITEMS);
    expect(catalogue.builtInMs).toBeLessThan(BUDGET.buildCatalogueMs);
  });

  it('finds an item by barcode or code within budget, whatever its place in the catalogue', () => {
    // Best of three per lookup: one pause on a shared CI runner (GC, JIT) is not the till being slow.
    const best = (fn) => [0, 1, 2].map(() => time(fn)).reduce((a, b) => (b[0] < a[0] ? b : a))
    const lookups = [0, ITEMS / 2, ITEMS - 1].flatMap((n) => [
      best(() => findByCode(catalogue, `6161${String(n).padStart(9, '0')}`)),
      // A typed item code falls back to a scan of the tiles: the slowest case.
      best(() => findByCode(catalogue, `sku${n}`)),
    ]);
    for (const [ms, found] of lookups) {
      expect(found).toBeTruthy();
      expect(ms).toBeLessThan(BUDGET.findByCodeMs);
    }
  });

  it('adds an item and recomputes the totals within budget on a 200-line cart', () => {
    let cart = emptyCart(NOW);
    const timings = [];
    for (let n = 0; n < 200; n++) {
      const [ms, next] = time(() => {
        const after = add(cart, (n * 97) % ITEMS);
        computeCart(after, { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue, at: NOW });
        return after;
      });
      cart = next;
      timings.push(ms);
    }
    const computed = computeCart(cart, { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue, at: NOW });
    expect(computed.lines).toHaveLength(200);
    expect(computed.blocked).toEqual([]);
    // The last additions are no slower than budget: cost does not grow out of hand with the cart.
    expect(p95(timings)).toBeLessThan(BUDGET.addItemP95Ms);
    expect(p95(timings.slice(-50))).toBeLessThan(BUDGET.addItemP95Ms);
  });

  it('completes a 150-line cash sale within budget', async () => {
    const server = fakeServer({ now: () => NOW });
    server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: [{ id: IDS.range, document_type: 'pos.receipt', period: 'all', pattern: 'R-{000001}', from: 1, to: 1000, next: 1 }] });
    const database = testDatabase();
    const engine = createSyncEngine({ api: server, store: createSyncStore(database), now: () => NOW, random: () => 0.5 });
    const selling = createSelling({ engine, posStore: createPosStore(database), api: server, now: () => NOW });
    await engine.pull();
    const proof = signActorProof({ deviceSecret: SECRET, deviceId: 'device-1', sessionId: uuidv7(NOW), userId: cashier.id, signedInAt: new Date(NOW).toISOString() });
    const shift = await selling.openShift({ user: cashier, actorProof: proof, openingFloat: [] });

    let cart = emptyCart(NOW);
    for (let n = 0; n < 150; n++) cart = add(cart, n * 13);
    const total = computeCart(cart, { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue, at: NOW }).totals.total_minor ?? null;
    const due = total ?? cart.lines.reduce((sum, line) => sum + BigInt(line.unitPriceMinor), 0n).toString();

    const started = performance.now();
    const sale = await selling.completeSale({
      shift, user: cashier, actorProof: proof, cart, catalogue,
      tenders: [{ id: uuidv7(NOW), method: paymentMethods[0], currency: 'KES', amountMinor: String(BigInt(due) + 100000n) }],
    });
    const ms = performance.now() - started;

    expect(sale.receipt_number).toBe('R-000001');
    expect(sale.lines ?? sale.payload?.lines).toHaveLength(150);
    expect(ms).toBeLessThan(BUDGET.completeSaleMs);
  });
});
