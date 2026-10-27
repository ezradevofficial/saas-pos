import { Q } from '@nozbe/watermelondb';
import { priceAt } from './meters';

/**
 * FUEL-22, NFR-04: the forecourt data on the till, read from the synced
 * tables. Null while the server does not offer the module's data to this
 * till (the module is off, the location is not a station, or nothing has
 * been pulled yet): the fuel screens and the voucher tender then do not
 * apply. Vehicles and vouchers are looked up on demand (they can be many).
 */
export async function loadFuelData(database) {
  const rows = async (table) => (await database.get(table).query().fetch()).map((record) => record.data).filter(Boolean);
  const [stations, nozzles, prices, tanks] = await Promise.all([rows('fuel_station'), rows('fuel_nozzles'), rows('fuel_prices'), rows('fuel_tanks')]);
  if (!stations.length) return null;
  return { station: stations[0], nozzles, prices, tanks };
}

/** Upper case, letters and digits only: how the server compares registrations and voucher codes (Vouchers::normalise). */
export const normalise = (text) => String(text ?? '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();

/** A nozzle's product for people: the item's name and the grade, "Diesel · V-Power". */
export function productName(catalogue, nozzle) {
  const item = catalogue?.itemById?.get(nozzle.item_id);
  const name = item?.name ?? nozzle.fuel_type ?? '';
  return nozzle.grade ? `${name} · ${nozzle.grade}` : name;
}

/** FUEL-08: the price per litre now (minor units, digit string) of a nozzle's product, or null. */
export function nozzlePrice(fuel, nozzle, at = Date.now()) {
  return priceAt(fuel?.prices, nozzle.product_id, at)?.price_minor ?? null;
}

/** The nozzles grouped by pump, in pump and nozzle code order: [{ pumpId, pumpCode, pumpName, nozzles }]. */
export function nozzlesByPump(fuel) {
  const pumps = new Map();
  for (const nozzle of fuel?.nozzles ?? []) {
    if (!pumps.has(nozzle.pump_id)) pumps.set(nozzle.pump_id, { pumpId: nozzle.pump_id, pumpCode: nozzle.pump_code, pumpName: nozzle.pump_name, nozzles: [] });
    pumps.get(nozzle.pump_id).nozzles.push(nozzle);
  }
  const collator = new Intl.Collator('en', { numeric: true });
  return [...pumps.values()]
    .map((pump) => ({ ...pump, nozzles: pump.nozzles.sort((a, b) => collator.compare(a.code, b.code)) }))
    .sort((a, b) => collator.compare(a.pumpCode, b.pumpCode));
}

/** FUEL-20: vehicles whose registration contains the typed text (case and spacing ignored); all when empty. */
export async function searchVehicles(database, text, { partyId = null, limit = 20 } = {}) {
  const wanted = normalise(text);
  const conditions = [];
  if (wanted) conditions.push(Q.where('registration', Q.like(`%${Q.sanitizeLikeString(wanted)}%`)));
  if (partyId) conditions.push(Q.where('party_id', partyId));
  const found = await database.get('fuel_vehicles').query(...conditions, Q.sortBy('registration', Q.asc), Q.take(limit)).fetch();
  return found.map((record) => record.data).filter(Boolean);
}

/** FUEL-21: the synced voucher with this code (as typed or scanned), or null. */
export async function findVoucher(database, code) {
  const wanted = normalise(code);
  if (!wanted) return null;
  const found = await database.get('fuel_vouchers').query(Q.where('code', wanted)).fetch();
  return found[0]?.data ?? null;
}
