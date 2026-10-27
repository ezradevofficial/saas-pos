import { uncountedSales } from '../loyalty/tenders';
import { add } from '../pos/exact';
import { plain } from './engine';

/**
 * PHA-25, NFR-04: the Pharmacy data on the till, from its three synced
 * tables (`pharmacy_settings`, `pharmacy_items`, `pharmacy_lots`), read
 * locally. Null while the module sent nothing (not subscribed, or never
 * pulled): nothing then applies. `used` is the stock this till sold since
 * the last pull (lot id to base quantity), so a lot is not sold past what it
 * held while offline.
 */
export async function loadPharmacyData(database) {
  const rows = async (table) => (await database.get(table).query().fetch()).map((record) => record.data).filter(Boolean);
  const [settingRows, profileRows, lotRows] = await Promise.all([rows('pharmacy_settings'), rows('pharmacy_items'), rows('pharmacy_lots')]);
  if (!settingRows.length && !profileRows.length && !lotRows.length) return null;
  return buildPharmacyData({ settingRows, profileRows, lotRows, sales: await uncountedSales(database, 'pharmacy_lots') });
}

/** Base quantity per lot of the lots chosen in `sales` (their `extensions.pharmacy.lines`). */
export function usedLots(sales) {
  const used = new Map();
  for (const sale of sales) {
    for (const line of sale.extensions?.pharmacy?.lines ?? []) {
      for (const pick of line.lots ?? []) used.set(pick.lot_id, plain(add(used.get(pick.lot_id) ?? '0', pick.qty)));
    }
  }
  return used;
}

export function buildPharmacyData({ settingRows = [], profileRows = [], lotRows = [], sales = [] }) {
  const lots = new Map();
  for (const lot of lotRows) lots.set(lot.item_id, [...(lots.get(lot.item_id) ?? []), lot]);
  return {
    active: settingRows.some((row) => row.id === 'module'),
    settings: settingRows.find((row) => row.id === 'settings') ?? null,
    profiles: new Map(profileRows.map((profile) => [profile.item_id, profile])),
    lots,
    used: usedLots(sales),
  };
}

/** The after-sale hook (extensions.js): lots a sale took count against the till's stock at once, until the next pull. */
export function countLotsUsed({ sale, catalogue }) {
  const data = catalogue?.ext?.pharmacy;
  if (!data) return;
  for (const [lotId, qty] of usedLots([sale])) data.used.set(lotId, plain(add(data.used.get(lotId) ?? '0', qty)));
}
