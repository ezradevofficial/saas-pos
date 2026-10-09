import { cartReducer, emptyCart, hasMobilePayment } from './cart';

// POS-03, POS-06, NFR-04: tenders of the current sale, changed on the cart as it is now.

const NOW = Date.parse('2026-10-09T05:00:00Z');
const item = { id: 'item-1', name: 'Tusker Lager 500ml', code: 'TUS', tax_code_id: null };
const cash = { id: 'cash', type: 'cash', name: 'Cash' };
const card = { id: 'card', type: 'card', name: 'Card' };
const mpesa = { id: 'mpesa', type: 'mobile_money', name: 'M-Pesa' };
const tender = (id, method, extra = {}) => ({ id, method, currency: 'KES', amountMinor: '25000', ...extra });

function withLine() {
  return cartReducer(emptyCart(NOW), { type: 'add', item, uomId: 'uom', price: '25000', listPriceMinor: '25000', priceListId: 'list', taxInclusive: true, now: NOW });
}

const run = (state, actions) => actions.reduce(cartReducer, state);

describe('tenders change on the current cart (POS-03)', () => {
  it('keeps a tender added while another payment was in progress', () => {
    const cart = withLine();
    // A cash tender is added while an STK push is pending; the push then completes.
    const after = run(cart, [
      { type: 'addTender', saleId: cart.id, tender: tender('t-cash', cash) },
      { type: 'addTender', saleId: cart.id, tender: tender('t-push', mpesa, { status: 'confirmed' }) },
    ]);
    expect(after.tenders.map((entry) => entry.id)).toEqual(['t-cash', 't-push']);
  });

  it('never brings back tenders a cart edit cleared', () => {
    const cart = run(withLine(), []);
    const paidCash = cartReducer(cart, { type: 'addTender', saleId: cart.id, tender: tender('t-cash', cash) });
    const edited = cartReducer(paidCash, { type: 'edit', id: paidCash.lines[0].id, patch: { qty: '2' } });
    expect(edited.tenders).toEqual([]);
    const after = cartReducer(edited, { type: 'addTender', saleId: cart.id, tender: tender('t-code', mpesa, { status: 'pending', reference: 'SJK8H2L9QX' }) });
    expect(after.tenders.map((entry) => entry.id)).toEqual(['t-code']);
  });

  it('refuses a tender for a sale that is no longer on the till', () => {
    const first = withLine();
    const next = cartReducer(first, { type: 'clear', now: NOW + 1 });
    const after = cartReducer(next, { type: 'addTender', saleId: first.id, tender: tender('t-push', mpesa, { status: 'confirmed' }) });
    expect(after).toBe(next);
    expect(after.tenders).toEqual([]);
  });

  it('adds a tender once, and removes only on its own sale', () => {
    const cart = withLine();
    const once = run(cart, [
      { type: 'addTender', saleId: cart.id, tender: tender('t-cash', cash) },
      { type: 'addTender', saleId: cart.id, tender: tender('t-cash', cash) },
    ]);
    expect(once.tenders).toHaveLength(1);
    expect(cartReducer(once, { type: 'removeTender', saleId: 'another-sale', id: 't-cash' })).toBe(once);
    expect(cartReducer(once, { type: 'removeTender', saleId: cart.id, id: 't-cash' }).tenders).toEqual([]);
  });
});

describe('a sale with mobile money sent cannot change (POS-03, POS-06)', () => {
  const changes = (cart) => [
    { type: 'add', item, uomId: 'uom', price: '100', listPriceMinor: '100', priceListId: 'list', taxInclusive: true, now: NOW },
    { type: 'edit', id: cart.lines[0].id, patch: { qty: '3' } },
    { type: 'remove', id: cart.lines[0].id },
    { type: 'customer', customer: { id: 'c-1', name: 'Amina' }, priceListId: 'list' },
  ];

  it.each(['confirmed', 'pending'])('blocks line and customer changes while a %s mobile-money tender is on the sale', (status) => {
    const base = withLine();
    const paid = cartReducer(base, { type: 'addTender', saleId: base.id, tender: tender('t-mm', mpesa, { status }) });
    expect(hasMobilePayment(paid)).toBe(true);
    for (const action of changes(paid)) expect(cartReducer(paid, action)).toBe(paid);
  });

  it('lets a cash or card sale change, dropping its tenders as before', () => {
    const base = withLine();
    const paid = run(base, [
      { type: 'addTender', saleId: base.id, tender: tender('t-cash', cash) },
      { type: 'addTender', saleId: base.id, tender: tender('t-card', card, { status: 'confirmed' }) },
    ]);
    expect(hasMobilePayment(paid)).toBe(false);
    for (const action of changes(paid)) {
      const after = cartReducer(paid, action);
      expect(after).not.toBe(paid);
      expect(after.tenders).toEqual([]);
    }
  });

  it('frees the sale once the mobile-money tender is removed', () => {
    const base = withLine();
    const paid = cartReducer(base, { type: 'addTender', saleId: base.id, tender: tender('t-mm', mpesa, { status: 'pending' }) });
    const freed = cartReducer(paid, { type: 'removeTender', saleId: base.id, id: 't-mm' });
    expect(cartReducer(freed, { type: 'edit', id: base.lines[0].id, patch: { qty: '2' } }).lines[0].qty).toBe('2');
  });
});
