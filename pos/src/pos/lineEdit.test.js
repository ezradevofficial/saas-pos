import { cartReducer, computeCart, emptyCart } from './cart';
import { planLineEdit } from './lineEdit';
import { refundBaseMajor, refundedQuantities, quantityLeft, refundAmounts, toBaseMinor } from './payloads';

// POS-07, RBAC-06, AUTH-07, AUTH-08: line edits are decided from the NEW values.

const base = {
  id: 'l1',
  qty: '2',
  unitPriceMinor: '25000',
  listPriceMinor: '25000',
  discountMinor: '0',
  override: null,
  priceOverride: null,
  priceSet: false,
  discountBy: null,
  actorProof: null,
};
const me = { override: null, approvedBy: 'u-cashier', proof: { user_id: 'u-cashier' } };
const manager = { override: { id: 'ov', manager_user_id: 'u-m' }, approvedBy: 'u-m' };

/** An authorize that allows a discount up to `limit` percent on own right, else answers `fallback`. */
const limitTo = (limit, fallback = null) => jest.fn(async (action, value) => (action !== 'discount' || Number(value) <= limit ? me : fallback));

describe('planLineEdit', () => {
  it('checks the discount against the new quantity, not the old one', async () => {
    // KES 100 off: 20 % of 2 × 250 is fine at a 20 % limit; 40 % of 1 × 250 is not.
    const authorize = limitTo(20);
    const together = await planLineEdit({ line: base, changes: { qty: '1', discountMinor: '10000' }, listPriceFor: () => '25000', authorize });
    expect(together).toEqual({ ok: false, reason: 'not_approved' });
    expect(authorize).toHaveBeenCalledWith('discount', '40.0000', 'l1');

    const fine = await planLineEdit({ line: base, changes: { qty: '2', discountMinor: '10000' }, listPriceFor: () => '25000', authorize });
    expect(fine).toMatchObject({ ok: true, patch: { qty: '2', discountMinor: '10000', discountBy: 'u-cashier', actorProof: { user_id: 'u-cashier' } } });
  });

  it('keeps an approval when the discount’s share did not grow, re-asks when it did, and clears quietly', async () => {
    const discounted = { ...base, discountMinor: '10000', override: manager.override, discountBy: 'u-m' };
    const never = jest.fn(async () => null);
    // + one: 100 of 750 (13 %) is less than the approved 20 %: kept without asking.
    const more = await planLineEdit({ line: discounted, changes: { qty: '3' }, listPriceFor: () => '25000', authorize: never, quiet: true });
    expect(more).toMatchObject({ ok: true, patch: { qty: '3', discountMinor: '10000' } });
    expect(more.patch).not.toHaveProperty('override'); // the manager's override stays on the line
    expect(never).not.toHaveBeenCalled();
    // − one: 40 % needs approval again; quietly (the − button) it is cleared and said so.
    const less = await planLineEdit({ line: discounted, changes: { qty: '1' }, listPriceFor: () => '25000', authorize: never, quiet: true });
    expect(less).toMatchObject({ ok: true, notice: 'discount_cleared', patch: { qty: '1', discountMinor: '0', override: null } });
  });

  it('re-prices on quantity breaks unless the price was set by hand, and re-checks a discount on a new price', async () => {
    const breaks = (qty) => (Number(qty) >= 6 ? '22000' : '25000');
    const repriced = await planLineEdit({ line: base, changes: { qty: '6' }, listPriceFor: breaks, authorize: limitTo(0) });
    expect(repriced.patch).toMatchObject({ unitPriceMinor: '22000', listPriceMinor: '22000' });

    const handPriced = { ...base, unitPriceMinor: '20000', priceSet: true };
    const kept = await planLineEdit({ line: handPriced, changes: { qty: '6' }, listPriceFor: breaks, authorize: limitTo(0) });
    expect(kept.patch).toMatchObject({ unitPriceMinor: '20000', listPriceMinor: '22000' });

    // A lower price makes the same discount a larger share: asked again (and refused here).
    const discounted = { ...base, discountMinor: '5000', discountBy: 'u-cashier', actorProof: me.proof };
    const cheaper = await planLineEdit({ line: discounted, changes: { unitPriceMinor: '12500' }, listPriceFor: breaks, authorize: limitTo(15) });
    expect(cheaper).toEqual({ ok: false, reason: 'not_approved' });
    const priced = await planLineEdit({ line: discounted, changes: { unitPriceMinor: '12500' }, listPriceFor: breaks, authorize: limitTo(25) });
    expect(priced.patch).toMatchObject({ unitPriceMinor: '12500', priceSet: true, discountMinor: '5000' });
    // Back to the list price clears the price approval.
    const back = await planLineEdit({ line: { ...handPriced, priceOverride: manager.override }, changes: { unitPriceMinor: '25000' }, listPriceFor: breaks, authorize: limitTo(0) });
    expect(back.patch).toMatchObject({ unitPriceMinor: '25000', priceOverride: null, priceSet: false });
  });

  it('refuses a discount above the line and fractional quantities stay exact', async () => {
    expect(await planLineEdit({ line: base, changes: { discountMinor: '50001' }, listPriceFor: () => null, authorize: limitTo(100) })).toEqual({ ok: false, reason: 'discount_above_price' });
    const weighed = await planLineEdit({ line: base, changes: { qty: '0.375' }, listPriceFor: () => null, authorize: limitTo(0) });
    expect(weighed.patch.qty).toBe('0.375');
    let cart = cartReducer(emptyCart(), { type: 'add', item: { id: 'i', name: 'Tomatoes' }, uomId: 'kg', price: '12000', listPriceMinor: '12000', qty: '1.25' });
    cart = cartReducer(cart, { type: 'add', item: { id: 'j', name: 'Soap' }, uomId: 'pc', price: '9500', listPriceMinor: '9500' });
    expect(computeCart(cart, { taxCodes: new Map(), day: '2026-10-09' }).itemCount).toBe(2);
  });
});

describe('refunds, the server’s way (RBAC-06, POS-05)', () => {
  const sale = {
    currency: 'CDF',
    lines: [{ id: 's1', qty: '2.5', total_minor: '28500', tax_minor: '3931' }],
    local: { base: { currency: 'USD', rate: { base: 'USD', quote: 'CDF', mid: '2850.00000000' } } },
  };
  const decimals = (code) => ({ CDF: 0, USD: 2 })[code] ?? 2;

  it('values a refund in the base currency per line, rounded, at the sale’s base rate', () => {
    expect(toBaseMinor('28500', 'CDF', sale.local.base, decimals)).toBe(1000n);
    const amounts = refundAmounts(sale, [{ saleLineId: 's1', qty: '1.25' }]);
    expect(amounts.totalMinor).toBe('14250');
    expect(refundBaseMajor(sale, amounts.lines, decimals)).toBe('5.0000');
    expect(refundBaseMajor({ ...sale, local: {} }, amounts.lines, decimals)).toBeNull();
  });

  it('adds fractional quantities exactly', () => {
    const refunded = refundedQuantities([{ lines: [{ sale_line_id: 's1', qty: '0.1' }] }, { lines: [{ sale_line_id: 's1', qty: '0.2' }] }]);
    expect(refunded).toEqual({ s1: '0.3' });
    expect(quantityLeft('2.5', refunded.s1)).toBe('2.2');
    expect(() => refundAmounts(sale, [{ saleLineId: 's1', qty: '2.3' }], refunded)).toThrow('refund_qty_exceeded');
  });
});
