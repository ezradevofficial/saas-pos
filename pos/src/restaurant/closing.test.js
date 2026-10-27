import { computeCart } from '../pos/cart';
import { salePayload } from '../pos/payloads';
import { IDS, menuCatalogue, settingsRow, waiter } from '../test/restaurantFixtures';
import { billOf, buildPlan, closingProblem, partCart, settingsOf, unpaidParts } from './closing';
import { addLine, createOrder } from './orderModel';
import '../restaurant/register';

// REST-13, REST-14, REST-16: each part of a bill is a normal POS sale built through the existing
// cart and payload path, carrying extensions.restaurant; the parts add up to the order.

const catalogue = menuCatalogue();
const settings = settingsOf([settingsRow], IDS.company);
const now = { now: '2026-11-02T10:00:00Z' };
const user = waiter();

function orderWith() {
  let order = createOrder({ id: 'order-1', kind: 'table', tableId: IDS.table1, waiterId: user.id, currency: 'KES', covers: 3, userId: user.id, ...now });
  const base = { taxCodeId: IDS.vat, taxRate: '16.0000', taxInclusive: false };
  order = addLine(order, { id: 'l1', itemId: IDS.burger, name: 'Burger', unitPriceMinor: '50000', qty: '2', seat: 1, modifiers: [{ id: 'm1', name: 'Cheese', price_minor: '5000' }], ...base }, now);
  order = addLine(order, { id: 'l2', itemId: IDS.beer, name: 'Tusker', unitPriceMinor: '30000', qty: '3', seat: 2, ...base }, now);
  order = addLine(order, { id: 'l3', itemId: IDS.salad, name: 'Salad', unitPriceMinor: '12000', qty: '1', seat: null, ...base }, now);
  order = addLine(order, { id: 'l4', itemId: IDS.salad, name: 'Salad', unitPriceMinor: '12000', qty: '1', seat: 1, ...base }, now);
  // One line voided after send: it counts for nothing.
  order = { ...order, lines: order.lines.map((line) => (line.id === 'l4' ? { ...line, sent_at: now.now, round: 1, removed: { at: now.now, by: user.id, reason: 'x' } } : line)) };
  return order;
}

let counter = 0;
const ids = () => `0190b000-0000-7000-8000-${String(++counter).padStart(12, '0')}`;
const totalOf = (cart) => computeCart(cart, { taxCodes: catalogue.taxCodes, day: catalogue.day, catalogue });

function parts(order, mode, options, tipMinor = 0) {
  const plan = buildPlan(order, { settings, catalogue, mode, options, tipMinor, ids });
  // Parts are paid in order here: part k is built when parts 1 to k-1 are paid, so the last one is final.
  const carts = plan.parts.map((_, index) =>
    partCart({ ...order, local: { ...order.local, plan, paid: plan.parts.slice(0, index).map((part) => ({ sale_id: part.saleId })) } }, plan, index, { settings, catalogue }),
  );
  return { plan, carts, computed: carts.map(totalOf) };
}

describe('restaurant closing into sales (REST-13, REST-14, REST-16)', () => {
  const order = orderWith();
  const bill = billOf(order, settings, catalogue, '2000');

  it('whole bill: one sale of every live line, the service charge on the net and the tip untaxed, final', () => {
    const { carts, computed } = parts(order, 'full', {}, '2000');
    expect(carts).toHaveLength(1);
    const lines = carts[0].lines;
    // Unit price plus the modifier effect.
    expect(lines.find((line) => line.itemId === IDS.burger)).toMatchObject({ qty: '2', unitPriceMinor: '55000', taxInclusive: false, priceSet: true });
    expect(lines.find((line) => line.itemId === IDS.service)).toMatchObject({ qty: '1', unitPriceMinor: bill.service_charge_minor, taxInclusive: false });
    expect(lines.find((line) => line.itemId === IDS.tip)).toMatchObject({ unitPriceMinor: '2000' });
    expect(lines.some((line) => line.name === 'Salad' && line.qty === '1')).toBe(true);
    expect(computed[0].blocked).toEqual([]);
    // The sale's total is the bill's: lines, charge, tax on the charge, tip.
    expect(computed[0].totals.total_minor).toBe(bill.total_minor);
    expect(computed[0].extensions.restaurant).toEqual({ order_id: 'order-1', mode: 'full', part: 1, parts: 1, final: true, line_ids: ['l1', 'l2', 'l3'], covers: 3 });
  });

  it('by item: each group is a sale with its lines in full, the parts add up to the bill exactly', () => {
    const { plan, computed } = parts(order, 'item', { groups: [['l1'], ['l2', 'l3']] }, '2000');
    expect(computed.map((cart) => cart.extensions.restaurant)).toMatchObject([
      { mode: 'item', part: 1, parts: 2, final: false, line_ids: ['l1'] },
      { mode: 'item', part: 2, parts: 2, final: true, line_ids: ['l2', 'l3'] },
    ]);
    const sum = computed.reduce((total, cart) => total + BigInt(cart.totals.total_minor), 0n);
    expect(sum).toBe(BigInt(bill.total_minor));
    plan.parts.forEach((part, index) => expect(BigInt(computed[index].totals.total_minor) - BigInt(part.total_minor)).toBe(0n));
  });

  it('by seat: one sale per seat; the unseated line is shared equally by quantity', () => {
    const { carts, computed } = parts(order, 'seat', {}, '2000');
    expect(carts).toHaveLength(2);
    const shared = carts.map((cart) => cart.lines.find((line) => line.id && line.name === 'Salad'));
    expect(shared.map((line) => line.qty)).toEqual(['0.500000', '0.500000']);
    expect(computed.map((cart) => cart.extensions.restaurant.mode)).toEqual(['seat', 'seat']);
    expect(computed.map((cart) => cart.extensions.restaurant.final)).toEqual([false, true]);
    // The shared half-lines may round a unit apart from the bill; never more than one per line.
    const sum = computed.reduce((total, cart) => total + BigInt(cart.totals.total_minor), 0n);
    expect(sum - BigInt(bill.total_minor) <= 2n && BigInt(bill.total_minor) - sum <= 2n).toBe(true);
  });

  it('equal in three: every line at equalQty, the last part takes the remainder, quantities add up', () => {
    const { carts, computed } = parts(order, 'equal', { parts: 3 }, '2000');
    const qtyOf = (cart, itemId) => cart.lines.find((line) => line.itemId === itemId).qty;
    expect(carts.map((cart) => qtyOf(cart, IDS.salad))).toEqual(['0.333333', '0.333333', '0.333334']);
    expect(carts.map((cart) => qtyOf(cart, IDS.beer))).toEqual(['1.000000', '1.000000', '1.000000']);
    expect(carts.map((cart) => qtyOf(cart, IDS.burger))).toEqual(['0.666666', '0.666666', '0.666668']);
    expect(computed.map((cart) => cart.extensions.restaurant)).toMatchObject([{ part: 1, parts: 3, final: false }, { part: 2, parts: 3, final: false }, { part: 3, parts: 3, final: true }]);
    const sum = computed.reduce((total, cart) => total + BigInt(cart.totals.total_minor), 0n);
    expect(sum - BigInt(bill.total_minor) <= 3n && BigInt(bill.total_minor) - sum <= 3n).toBe(true);
    // The service charge and tip are shared by the bill maths, summing to the bill's.
    const charge = carts.reduce((total, cart) => total + BigInt(cart.lines.find((line) => line.itemId === IDS.service).unitPriceMinor), 0n);
    expect(charge).toBe(BigInt(bill.service_charge_minor));
    const tip = carts.reduce((total, cart) => total + BigInt(cart.lines.find((line) => line.itemId === IDS.tip).unitPriceMinor), 0n);
    expect(tip).toBe(2000n);
  });

  it('an equal split of a total that divides is exact to the minor unit', () => {
    let even = createOrder({ id: 'order-2', kind: 'table', tableId: IDS.table1, waiterId: user.id, currency: 'KES', userId: user.id, ...now });
    even = addLine(even, { id: 'e1', itemId: IDS.beer, name: 'Tusker', unitPriceMinor: '30000', qty: '4', taxCodeId: IDS.vat, taxRate: '16.0000' }, now);
    const { computed } = parts(even, 'equal', { parts: 4 }, 0);
    const evenBill = billOf(even, settings, catalogue, 0);
    expect(computed.reduce((total, cart) => total + BigInt(cart.totals.total_minor), 0n)).toBe(BigInt(evenBill.total_minor));
  });

  it('the sale payload carries extensions.restaurant, with ids that are the same every time a part is built', () => {
    const { plan, carts } = parts(order, 'item', { groups: [['l1'], ['l2', 'l3']] });
    const computed = totalOf(carts[1]);
    const payload = salePayload({
      id: carts[1].id,
      shiftId: 'shift-1',
      cashierId: user.id,
      number: { seq: 1, number: 'R-1', rangeId: 'range-1' },
      soldAt: '2026-11-02T10:00:00.000Z',
      offline: true,
      currency: 'KES',
      lines: computed.lines,
      totals: computed.totals,
      payments: [],
      extensions: computed.extensions,
    });
    expect(payload.id).toBe(plan.parts[1].saleId);
    expect(payload.extensions.restaurant).toMatchObject({ order_id: 'order-1', mode: 'item', part: 2, parts: 2, final: true, covers: 3 });
    const again = partCart({ ...order, local: { ...order.local, plan, paid: [{ sale_id: plan.parts[0].saleId }] } }, plan, 1, { settings, catalogue });
    expect(again.lines.map((line) => line.id)).toEqual(carts[1].lines.map((line) => line.id));
  });

  it('the last part still to pay is final, whichever it is', () => {
    const { plan } = parts(order, 'item', { groups: [['l1'], ['l2', 'l3']] });
    const paid = { ...order, local: { ...order.local, plan, paid: [{ sale_id: plan.parts[1].saleId }] } };
    expect(unpaidParts(paid).map((entry) => entry.index)).toEqual([0]);
    expect(partCart(paid, plan, 0, { settings, catalogue }).ext.restaurant).toMatchObject({ part: 1, parts: 2, final: true });
  });

  it('refuses what the server refuses and what cannot be rung', () => {
    expect(() => buildPlan(order, { settings, catalogue, mode: 'item', options: { groups: [['l1']] } })).toThrow();
    expect(() => buildPlan(order, { settings, catalogue, mode: 'equal', options: { parts: 1 } })).toThrow();
    expect(closingProblem(order, { ...settings, serviceItemId: null }, catalogue)).toBe('service_item_missing');
    expect(closingProblem(order, { ...settings, tipItemId: null }, catalogue, { tipMinor: 1n })).toBe('tip_item_missing');
    expect(closingProblem({ ...order, lines: [] }, settings, catalogue)).toBe('order_empty');
    expect(closingProblem(order, settings, catalogue, { tipMinor: 0n })).toBeNull();
  });
});
