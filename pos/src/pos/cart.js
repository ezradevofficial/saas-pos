import { uuidv7 } from '../lib/random';
import { lineAmounts, saleTotals } from './tax';

/**
 * POS-01, POS-02, POS-07, POS-08: the current sale on the till, as a pure
 * reducer (no React), so it survives user switching (it lives above the
 * sign-in screen) and can be parked as a held sale.
 *
 * A line keeps what the till will upload: item, unit, quantity (decimal
 * string), unit price and list price (minor units, strings), the price
 * list, tax inclusion and code, a discount, and the overrides (AUTH-08)
 * or the sign-in proof (AUTH-07) that allowed a discount or price change.
 * The sale id (UUID v7) is made when the cart starts, so a held and
 * resumed sale keeps it.
 */
export function emptyCart(now = Date.now()) {
  return { id: uuidv7(now), lines: [], customer: null, priceListId: null, startedAt: now };
}

const addQty = (qty, delta) => {
  const [whole, fraction = ''] = String(qty).split('.');
  if (fraction) return String(Math.max(0, Number(qty) + delta)); // fractional units are typed, not stepped
  const next = BigInt(whole) + BigInt(delta);
  return String(next < 0n ? 0n : next);
};

const isPositive = (qty) => /[1-9]/.test(String(qty)) && !String(qty).startsWith('-');

export function cartReducer(state, action) {
  switch (action.type) {
    case 'add': {
      const { item, uomId, uomCode, price, listPriceMinor, priceListId, taxInclusive, now } = action;
      const same = state.lines.find((line) => line.itemId === item.id && line.uomId === uomId && !line.priceOverride && line.discountMinor === '0');
      if (same) {
        return { ...state, lines: state.lines.map((line) => (line === same ? { ...line, qty: addQty(line.qty, 1), unitPriceMinor: action.repriced ?? line.unitPriceMinor, listPriceMinor: action.repriced ?? line.listPriceMinor } : line)) };
      }
      const line = {
        id: uuidv7(now ?? Date.now()),
        itemId: item.id,
        name: item.name,
        code: item.code,
        uomId,
        uomCode: uomCode ?? null,
        qty: '1',
        unitPriceMinor: String(price),
        listPriceMinor: listPriceMinor == null ? null : String(listPriceMinor),
        priceListId: priceListId ?? null,
        taxInclusive: Boolean(taxInclusive),
        taxCodeId: item.tax_code_id ?? null,
        discountMinor: '0',
        override: null,
        priceOverride: null,
        actorProof: null,
      };
      return { ...state, lines: [...state.lines, line] };
    }
    case 'setQty': {
      if (!isPositive(action.qty)) return { ...state, lines: state.lines.filter((line) => line.id !== action.id) };
      return {
        ...state,
        lines: state.lines.map((line) => {
          if (line.id !== action.id) return line;
          const repriced = action.listPriceMinor != null && !line.priceOverride;
          return {
            ...line,
            qty: String(action.qty),
            listPriceMinor: action.listPriceMinor != null ? String(action.listPriceMinor) : line.listPriceMinor,
            unitPriceMinor: repriced ? String(action.listPriceMinor) : line.unitPriceMinor,
          };
        }),
      };
    }
    case 'remove':
      return { ...state, lines: state.lines.filter((line) => line.id !== action.id) };
    case 'discount':
      return {
        ...state,
        lines: state.lines.map((line) =>
          line.id === action.id ? { ...line, discountMinor: String(action.discountMinor), override: action.override ?? null, actorProof: action.actorProof ?? line.actorProof } : line,
        ),
      };
    case 'price':
      return {
        ...state,
        lines: state.lines.map((line) =>
          line.id === action.id
            ? { ...line, unitPriceMinor: String(action.unitPriceMinor), priceOverride: action.override ?? null, actorProof: action.actorProof ?? line.actorProof, discountMinor: '0', override: null }
            : line,
        ),
      };
    case 'customer':
      return { ...state, customer: action.customer ?? null, priceListId: action.priceListId ?? null, lines: action.lines ?? state.lines };
    case 'reprice':
      return { ...state, lines: action.lines };
    case 'load':
      return action.cart;
    case 'clear':
      return emptyCart(action.now);
    default:
      return state;
  }
}

/**
 * The cart with each line's amounts (tax per line), totals and what blocks
 * charging: { lines: [{...line, amounts}], totals, itemCount, blocked:
 * [{lineId, reason}] }. `ctx` = { taxCodes (Map), day }.
 */
export function computeCart(cart, ctx) {
  const blocked = [];
  const lines = cart.lines.map((line) => {
    const amounts = lineAmounts(line, ctx);
    if (amounts.blocked) blocked.push({ lineId: line.id, reason: amounts.blocked, name: line.name });
    return { ...line, amounts };
  });
  const totals = saleTotals(lines.filter((line) => !line.amounts.blocked).map((line) => line.amounts));
  const itemCount = lines.reduce((count, line) => count + (Number.isInteger(Number(line.qty)) ? Number(line.qty) : 1), 0);
  return { lines, totals, itemCount, blocked };
}
