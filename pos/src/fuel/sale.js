import { cartReducer, emptyCart } from '../pos/cart';
import { FuelError, priceAt, quote } from './meters';

/**
 * FUEL-18, FUEL-19, FUEL-20: a fuel sale on the till is a normal POS sale.
 * The line is the nozzle's item with quantity = litres (3 decimals) and unit
 * price = the price per litre in force now, the list price the same, so no
 * price override is involved; tax comes from the item as for any line. The
 * sale carries `extensions.fuel = { shift_id, lines: [{ line_id, nozzle_id }],
 * vehicle?: { registration, odometer? } }` (api Sales/FuelSales.php), built
 * from `cart.ext.fuel` by the cart adjuster in register.js. The server
 * compares the price with its own and flags a difference (server wins prices,
 * the sale is kept).
 */

/**
 * The cart of a nozzle quick sale: one line, the fuel extension, and the
 * customer when it is a fleet sale. `entry` = { mode: 'litres' | 'amount', litres?, amountMinor? }.
 * Throws FuelError: no_shift, nozzle_not_on_shift, price_missing, item_unknown, currency_mismatch, and the
 * quote's (litres_invalid, amount_invalid, litres_zero).
 */
export function buildFuelCart({ catalogue, fuel, shift, nozzle, entry, customer = null, vehicle = null, now = Date.now() }) {
  if (!shift || shift.status !== 'open') throw new FuelError('no_shift');
  if (!shift.nozzles.some((row) => row.nozzle_id === nozzle.id)) throw new FuelError('nozzle_not_on_shift');
  const item = catalogue.itemById.get(nozzle.item_id);
  if (!item) throw new FuelError('item_unknown');
  const row = priceAt(fuel.prices, nozzle.product_id, now);
  if (!row) throw new FuelError('price_missing');
  if (row.currency !== catalogue.saleCurrency) throw new FuelError('currency_mismatch', { currency: row.currency });
  const price = String(row.price_minor);
  const priced = quote({ mode: entry.mode, litres: entry.litres, amountMinor: entry.amountMinor, priceMinor: price });
  const list = catalogue.defaultList;
  if (!list) throw new FuelError('price_missing');
  const uomId = catalogue.salesUom(item);

  let cart = cartReducer(emptyCart(now), {
    type: 'add',
    item,
    uomId,
    uomCode: catalogue.uoms.get(uomId)?.code ?? null,
    qty: priced.litres,
    price,
    listPriceMinor: price,
    priceListId: list.id,
    taxInclusive: Boolean(list.tax_inclusive),
    now,
  });
  const line = cart.lines[0];
  // The price is the pump's: a customer's price list never re-prices it.
  cart = cartReducer(cart, { type: 'edit', id: line.id, patch: { priceSet: true } });
  const ext = { shift_id: shift.id, lines: [{ line_id: line.id, nozzle_id: nozzle.id }] };
  if (vehicle) ext.vehicle = vehicleExtension(vehicle);
  return {
    ...cart,
    customer: customer ? { id: customer.id, name: customer.name, phone: customer.phones?.[0]?.number ?? null, price_list_id: null } : null,
    ext: { fuel: ext },
  };
}

/** FUEL-20: the vehicle as the sale carries it: { registration, odometer? } (odometer: whole kilometres, digits). */
export function vehicleExtension(vehicle) {
  const odometer = String(vehicle.odometer ?? '').replace(/\D+/g, '');
  return { registration: String(vehicle.registration).trim(), ...(odometer ? { odometer } : {}) };
}

/**
 * The sale's `extensions.fuel` from the cart, or null: only the fuel lines still in the cart count, and a
 * sale with none carries no extension (a shop item added later does not break it).
 */
export function fuelExtension(cart) {
  const fuel = cart?.ext?.fuel;
  if (!fuel) return null;
  const present = new Set((cart.lines ?? []).map((line) => line.id));
  const lines = (fuel.lines ?? []).filter((entry) => present.has(entry.line_id));
  if (!lines.length) return null;
  return { shift_id: fuel.shift_id, lines, ...(fuel.vehicle ? { vehicle: fuel.vehicle } : {}) };
}
