import { evaluatePromotions, normaliseCode } from './engine';
import { uncountedSales } from './tenders';

/**
 * LOY-11: promotions on the cart, offline. The synced `loyalty_promotions`
 * rows (the till's branch only), its customer groups and the item category
 * tree feed the shared engine (engine.js, the server's PromotionEngine);
 * each discounted line gets its provenance, so the sale carries it to the
 * server, which re-evaluates and flags what differs (device wins).
 */
export const PROMOTION_SOURCE = 'loyalty.promotion';

/**
 * The catalogue extension `loyalty`: programme, promotions, customer groups,
 * category parents and this till's own coupon uses. Null while none of the
 * module's data is on the till (H3: not subscribed, or never pulled), so its
 * tenders and panel stay off.
 */
export async function loadLoyaltyData(database, { categories = [] } = {}) {
  const rows = async (table) => (await database.get(table).query().fetch()).map((record) => record.data).filter(Boolean);
  const [settings, promotionRows] = await Promise.all([rows('loyalty_settings'), rows('loyalty_promotions')]);
  if (!settings.length && !promotionRows.length) return null;
  const sales = await uncountedSales(database, 'loyalty_promotions');
  return buildLoyaltyData({ settings, promotionRows, categories, sales });
}

/** M5: the coupons this till used in `sales` the last pull may not count, by code. */
export function couponUsesOf(sales) {
  const uses = new Map();
  for (const sale of sales) for (const code of sale.extensions?.loyalty?.coupons ?? []) uses.set(normaliseCode(code), (uses.get(normaliseCode(code)) ?? 0) + 1);
  return uses;
}

export function buildLoyaltyData({ settings = [], promotionRows = [], categories = [], sales = [] }) {
  const groups = new Map();
  for (const row of promotionRows) if (row.kind === 'group') groups.set(row.tag, new Set(row.customer_ids ?? []));
  return {
    programme: settings.find((row) => row.id === 'programme') ?? null,
    promotions: promotionRows.filter((row) => row.kind === 'promotion'),
    groups,
    parents: new Map(categories.map((category) => [category.id, category.parent_id ?? null])),
    couponUses: couponUsesOf(sales),
  };
}

/** The after-sale hook (extensions.js): a coupon used counts at once, until the next pull. */
export function countCouponUses({ sale, catalogue }) {
  const data = catalogue?.ext?.loyalty;
  if (!data) return;
  if (!data.couponUses) data.couponUses = new Map();
  for (const [code, count] of couponUsesOf([sale])) data.couponUses.set(code, (data.couponUses.get(code) ?? 0) + count);
}

/** Uses left of a coupon as last synced, less this till's own uses since (null: unlimited). */
export function couponLeft(coupon, uses) {
  if (coupon.remaining == null) return null;
  return Number(coupon.remaining) - (uses?.get(normaliseCode(coupon.code)) ?? 0);
}

/** The category and its ancestors, as the server's Promotions::engineLines. */
export function categoryChain(parents, categoryId) {
  const ids = [];
  let current = categoryId ?? null;
  while (current && !ids.includes(current)) {
    ids.push(current);
    current = parents.get(current) ?? null;
  }
  return ids;
}

/** The branch's local date, ISO weekday and time at `at` (ms). */
export function localTime(at, timeZone) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-GB', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', weekday: 'short' })
      .formatToParts(new Date(at))
      .map((part) => [part.type, part.value]),
  );
  const weekdays = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };
  return { date: `${parts.year}-${parts.month}-${parts.day}`, weekday: weekdays[parts.weekday], time: `${parts.hour === '24' ? '00' : parts.hour}:${parts.minute}` };
}

/** The customer groups (party tags) of a customer, from the synced groups. */
export function tagsOf(groups, customerId) {
  if (!customerId) return [];
  return [...groups.entries()].filter(([, ids]) => ids.has(customerId)).map(([tag]) => tag);
}

/**
 * The cart adjuster (extensions.js): the promotions' line discounts and,
 * for the sale's `extensions.loyalty`, the coupons that applied and the
 * versions used. Codes whose uses ran out (as last synced) are not offered.
 */
export function promotionAdjuster({ lines, cart, catalogue, at }) {
  const data = catalogue.ext?.loyalty;
  const coupons = (cart.ext?.loyalty?.coupons ?? []).map(normaliseCode);
  if (!data || !data.promotions.length || !lines.length) return {};
  const promotions = data.promotions.map((promotion) => ({
    ...promotion,
    coupon_codes: promotion.coupon_codes == null ? null : (promotion.coupons ?? []).filter((coupon) => { const left = couponLeft(coupon, data.couponUses); return left === null || left > 0; }).map((coupon) => coupon.code),
  }));
  const result = evaluatePromotions(
    lines.map((line) => ({
      id: line.id,
      item_id: line.itemId,
      category_ids: categoryChain(data.parents, catalogue.itemById?.get(line.itemId)?.category_id),
      qty: String(line.qty),
      unit_price_minor: String(line.unitPriceMinor),
    })),
    { currency: catalogue.saleCurrency, branch_id: catalogue.settings?.branch?.id ?? null, customer_tags: tagsOf(data.groups, cart.customer?.id), coupons, local: localTime(at, catalogue.timeZone ?? 'UTC') },
    promotions,
  );
  const names = new Map(data.promotions.map((promotion) => [promotion.id, promotion.name]));
  const adjustments = new Map();
  for (const line of result.lines) {
    adjustments.set(line.line_id, [{ source: PROMOTION_SOURCE, reference: line.promotion_id, amount_minor: line.discount_minor, label: names.get(line.promotion_id) ?? null }]);
  }
  const used = result.promotions.map((promotion) => promotion.coupon).filter(Boolean);
  const extension = {};
  if (used.length) extension.coupons = [...new Set(used)];
  if (result.promotions.length) extension.promotions = result.promotions.map((promotion) => ({ id: promotion.promotion_id, version: promotion.version }));
  return { adjustments, extension };
}
