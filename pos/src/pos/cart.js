import { uuidv7 } from '../lib/random';
import { lineAmounts, saleTotals } from './tax';

/**
 * POS-01, POS-02, POS-07, POS-08: the current sale on the till, as a pure
 * reducer (no React), so it survives user switching (it lives above the
 * sign-in screen), app restarts (saved locally) and can be parked as a
 * held sale.
 *
 * A line keeps what the till will upload: item, unit, quantity (decimal
 * string, never a float), unit price and list price (minor units,
 * strings), the price list, tax inclusion and code, a discount, and who
 * allowed a discount or a price change:
 * - `override` (discount) and `priceOverride` (price): a manager's
 *   override (AUTH-08);
 * - `actorProof` and `discountBy`: the sign-in proof (AUTH-07) and id of
 *   the person who gave it on their own right; users switch mid-sale, so
 *   this may not be the cashier who completes the sale;
 * - `priceSet`: the price was set by hand; adding more, changing the
 *   quantity or the customer never re-prices the line.
 *
 * Every change of quantity, price or discount is one `edit` action whose
 * values the caller computed from the new values (PosProvider.editLine),
 * so a limit is never checked against a stale line. The sale id (UUID v7)
 * is made when the cart starts, so a held and resumed sale keeps it.
 */
export function emptyCart(now = Date.now()) {
  return { id: uuidv7(now), lines: [], customer: null, priceListId: null, startedAt: now, tenders: [] };
}

export const isWholeQty = (qty) => /^\d+$/.test(String(qty));

export function cartReducer(state, action) {
  switch (action.type) {
    case 'add': {
      const { item, uomId, uomCode, price, listPriceMinor, priceListId, taxInclusive, now } = action;
      const line = {
        id: uuidv7(now ?? Date.now()),
        itemId: item.id,
        name: item.name,
        code: item.code,
        uomId,
        uomCode: uomCode ?? null,
        qty: String(action.qty ?? '1'),
        unitPriceMinor: String(price),
        listPriceMinor: listPriceMinor == null ? null : String(listPriceMinor),
        priceListId: priceListId ?? null,
        taxInclusive: Boolean(taxInclusive),
        taxCodeId: item.tax_code_id ?? null,
        discountMinor: '0',
        override: null,
        priceOverride: null,
        priceSet: false,
        discountBy: null,
        actorProof: null,
      };
      return { ...state, lines: [...state.lines, line], tenders: [] };
    }
    case 'edit':
      return { ...state, lines: state.lines.map((line) => (line.id === action.id ? { ...line, ...action.patch } : line)), tenders: [] };
    case 'remove':
      return { ...state, lines: state.lines.filter((line) => line.id !== action.id), tenders: [] };
    case 'customer':
      return { ...state, customer: action.customer ?? null, priceListId: action.priceListId ?? null, lines: action.lines ?? state.lines, tenders: [] };
    case 'tenders':
      // Payments in progress are kept with the cart (they survive a restart); any change to the sale drops them.
      return { ...state, tenders: action.tenders };
    case 'load':
      return { tenders: [], ...action.cart };
    case 'clear':
      return emptyCart(action.now);
    default:
      return state;
  }
}

/**
 * The cart with each line's amounts (tax per line), totals and what blocks
 * charging: { lines: [{...line, amounts}], totals, itemCount, blocked:
 * [{lineId, reason}] }. `ctx` = { taxCodes (Map), day }. A line with a
 * fractional quantity (weighed, measured) counts as one item.
 */
export function computeCart(cart, ctx) {
  const blocked = [];
  const lines = cart.lines.map((line) => {
    const amounts = lineAmounts(line, ctx);
    if (amounts.blocked) blocked.push({ lineId: line.id, reason: amounts.blocked, name: line.name });
    return { ...line, amounts };
  });
  const totals = saleTotals(lines.filter((line) => !line.amounts.blocked).map((line) => line.amounts));
  const itemCount = Number(lines.reduce((count, line) => count + (isWholeQty(line.qty) ? BigInt(line.qty) : 1n), 0n));
  return { lines, totals, itemCount, blocked };
}
