/**
 * LOY-10: the promotions engine on the till, a port of the server's
 * PromotionEngine (api/modules/Loyalty/Promotions/PromotionEngine.php).
 * Pure and deterministic, BigInt only (no amount ever passes through a
 * float); both pass the shared cases in
 * api/tests/Fixtures/loyalty/promotion-cases.json (engine.test.js).
 *
 * Rules (docs/modules/loyalty.md, Decisions): promotions by priority
 * (higher first), then id; a line gets at most one promotion, and lines a
 * promotion applies to are not offered to later ones; every rounding is
 * half up, once; shares go by largest remainder, earlier line first.
 */
export const PROMOTION_TYPES = ['percent', 'amount_off', 'buy_x_get_y', 'bundle'];

const PERCENT_SCALE = 10000n;
const QTY_SCALE = 1000000n;

/**
 * lines: [{id, item_id, category_ids, qty, unit_price_minor}] in cart order;
 * context: {currency, branch_id, customer_tags, coupons, local: {date, weekday, time}};
 * promotions: as synced (`loyalty_promotions`). Resolves
 * {lines: [{line_id, promotion_id, version, discount_minor}], promotions:
 * [{promotion_id, version, discount_minor, coupon}], total_discount_minor}.
 */
export function evaluatePromotions(lines, context, promotions) {
  const prepared = lines.map((line, index) => {
    const unit = BigInt(String(line.unit_price_minor));
    const qty = scaled(String(line.qty), 6);
    return {
      index,
      id: String(line.id),
      itemId: String(line.item_id),
      categoryIds: (line.category_ids ?? []).map(String),
      unit,
      units: qty / QTY_SCALE,
      gross: roundHalfUp(unit * qty, QTY_SCALE),
    };
  });

  const ordered = promotions.map(normalise).sort((a, b) => b.priority - a.priority || compareStrings(a.id, b.id));
  const taken = new Set();
  const discounts = new Map();
  const applied = [];

  for (const promotion of ordered) {
    const coupon = applicable(promotion, context);
    if (coupon === false) continue;
    const free = prepared.filter((line) => !taken.has(line.index));
    const [shares, used] = apply(promotion, free);
    let total = 0n;
    for (const share of shares.values()) total += share;
    if (total === 0n) continue;
    for (const index of used) taken.add(index);
    for (const [index, share] of shares) if (share > 0n) discounts.set(index, { promotion, discount: share });
    applied.push({ promotion_id: promotion.id, version: promotion.version, discount_minor: String(total), coupon });
  }

  let sum = 0n;
  const out = [...discounts.entries()]
    .sort(([a], [b]) => a - b)
    .map(([index, entry]) => {
      sum += entry.discount;
      return { line_id: prepared[index].id, promotion_id: entry.promotion.id, version: entry.promotion.version, discount_minor: String(entry.discount) };
    });
  return { lines: out, promotions: applied, total_discount_minor: String(sum) };
}

/** LOY-03: points for an eligible amount, rounded down. */
export function pointsEarned(eligibleMinor, earnPoints, earnSpendMinor) {
  const eligible = BigInt(String(eligibleMinor));
  const spend = BigInt(String(earnSpendMinor));
  const per = BigInt(earnPoints);
  if (eligible <= 0n || spend <= 0n || per <= 0n) return '0';
  return String((eligible * per) / spend);
}

/** LOY-04: points to reverse for a refund (the earned share of refunds so far, less what was reversed). */
export function pointsToReverse(earned, saleTotalMinor, refundedMinor, alreadyReversed) {
  const points = BigInt(String(earned));
  const total = BigInt(String(saleTotalMinor));
  const already = BigInt(String(alreadyReversed));
  if (points <= 0n || total <= 0n) return '0';
  const refunded = min(BigInt(String(refundedMinor)), total);
  const due = (points * refunded) / total - already;
  const left = points - already;
  return String(max(0n, min(due, left)));
}

/** LOY-05: the value of redeemed points, exact. */
export function redeemValue(points, pointValueMinor) {
  return String(BigInt(String(points)) * BigInt(String(pointValueMinor)));
}

/** Coupon codes compare trimmed, upper case, without spaces. */
export function normaliseCode(code) {
  return String(code ?? '').trim().replace(/ /g, '').toUpperCase();
}

function normalise(promotion) {
  const targets = (t) => ({ items: (t?.items ?? []).map(String), categories: (t?.categories ?? []).map(String) });
  const schedule = promotion.schedule ?? {};
  return {
    id: String(promotion.id),
    version: Number(promotion.version ?? 1),
    type: String(promotion.type),
    priority: Number(promotion.priority ?? 0),
    percent: promotion.percent == null ? null : String(promotion.percent),
    amountMinor: promotion.amount_minor == null ? null : String(promotion.amount_minor),
    currency: promotion.currency ?? null,
    buyQty: Number(promotion.buy_qty ?? 0),
    getQty: Number(promotion.get_qty ?? 0),
    targets: targets(promotion.targets),
    getTargets: promotion.get_targets == null ? null : targets(promotion.get_targets),
    components: (promotion.components ?? []).map((c) => ({ itemId: String(c.item_id), qty: Number(c.qty) })),
    minSpendMinor: promotion.min_spend_minor == null ? null : String(promotion.min_spend_minor),
    branchIds: (promotion.branch_ids ?? []).map(String),
    customerTags: (promotion.customer_tags ?? []).map(String),
    schedule: {
      startsOn: schedule.starts_on ?? null,
      endsOn: schedule.ends_on ?? null,
      days: (schedule.days ?? []).map(Number),
      timeFrom: schedule.time_from ?? null,
      timeTo: schedule.time_to ?? null,
    },
    couponCodes: promotion.coupon_codes == null ? null : promotion.coupon_codes.map(normaliseCode),
  };
}

/** false when the promotion may not apply; else the coupon it applies with (null without one). */
function applicable(promotion, context) {
  if (!PROMOTION_TYPES.includes(promotion.type)) return false;
  const hasAmount = promotion.type === 'amount_off' || promotion.type === 'bundle' || promotion.minSpendMinor !== null;
  if (hasAmount && promotion.currency !== (context.currency ?? null)) return false;
  if (promotion.branchIds.length && !promotion.branchIds.includes(String(context.branch_id ?? ''))) return false;
  if (promotion.customerTags.length) {
    const tags = (context.customer_tags ?? []).map(String);
    if (!promotion.customerTags.some((tag) => tags.includes(tag))) return false;
  }
  const local = context.local ?? {};
  const date = String(local.date ?? '');
  const time = String(local.time ?? '');
  const { startsOn, endsOn, days, timeFrom, timeTo } = promotion.schedule;
  if ((startsOn !== null && date < startsOn) || (endsOn !== null && date > endsOn)) return false;
  let weekday = Number(local.weekday ?? 0);
  if (timeFrom !== null && timeTo !== null) {
    const inside = timeFrom < timeTo ? time >= timeFrom && time < timeTo : time >= timeFrom || time < timeTo;
    if (!inside) return false;
    // A window past midnight belongs to the day it started: its after-midnight part checks the day before.
    if (timeFrom > timeTo && time < timeTo) weekday = weekday === 1 ? 7 : weekday - 1;
  }
  if (days.length && !days.includes(weekday)) return false;
  if (promotion.couponCodes === null) return null;
  for (const code of context.coupons ?? []) {
    const normalised = normaliseCode(code);
    if (promotion.couponCodes.includes(normalised)) return normalised;
  }
  return false;
}

function apply(promotion, lines) {
  const eligible = lines.filter((line) => matches(promotion.targets, line));
  switch (promotion.type) {
    case 'percent':
      return percent(promotion, eligible);
    case 'amount_off':
      return amountOff(promotion, eligible);
    case 'buy_x_get_y':
      return buyGet(promotion, lines, eligible);
    default:
      return bundle(promotion, lines);
  }
}

function matches(targets, line) {
  if (!targets.items.length && !targets.categories.length) return true;
  return targets.items.includes(line.itemId) || line.categoryIds.some((id) => targets.categories.includes(id));
}

function minSpendMet(promotion, eligible) {
  if (promotion.minSpendMinor === null) return true;
  return sum(eligible.map((line) => line.gross)) >= BigInt(promotion.minSpendMinor);
}

function percent(promotion, eligible) {
  if (!eligible.length || !minSpendMet(promotion, eligible)) return [new Map(), []];
  const pct = scaled(promotion.percent, 4);
  return [new Map(eligible.map((line) => [line.index, percentOf(line.gross, pct)])), eligible.map((line) => line.index)];
}

function amountOff(promotion, eligible) {
  if (!eligible.length || !minSpendMet(promotion, eligible)) return [new Map(), []];
  const subtotal = sum(eligible.map((line) => line.gross));
  const amount = min(BigInt(promotion.amountMinor), subtotal);
  return [allocate(amount, new Map(eligible.map((line) => [line.index, line.gross]))), eligible.map((line) => line.index)];
}

function buyGet(promotion, lines, buySet) {
  const x = promotion.buyQty;
  const y = promotion.getQty;
  if (x < 1 || y < 1 || !buySet.length) return [new Map(), []];
  let units;
  let count;
  let used;
  if (promotion.getTargets === null) {
    units = unitsOf(buySet);
    count = Math.floor(units.length / (x + y)) * y;
    used = buySet.map((line) => line.index);
  } else {
    const buyIndexes = buySet.map((line) => line.index);
    const getLines = lines.filter((line) => !buyIndexes.includes(line.index) && matches(promotion.getTargets, line));
    units = unitsOf(getLines);
    const buyUnits = buySet.reduce((total, line) => total + Number(line.units), 0);
    count = Math.min(Math.floor(buyUnits / x) * y, units.length);
    used = [...buyIndexes, ...getLines.map((line) => line.index)];
  }
  if (count === 0) return [new Map(), []];
  // The cheapest units; on an equal price, the later line's first.
  units.sort((a, b) => (a.price < b.price ? -1 : a.price > b.price ? 1 : b.index - a.index));
  const pct = scaled(promotion.percent, 4);
  const shares = new Map();
  for (const unit of units.slice(0, count)) shares.set(unit.index, (shares.get(unit.index) ?? 0n) + percentOf(unit.price, pct));
  return [shares, used.sort((a, b) => a - b)];
}

function bundle(promotion, lines) {
  const { components } = promotion;
  if (!components.length) return [new Map(), []];
  let bundles = null;
  for (const component of components) {
    if (component.qty < 1) return [new Map(), []];
    const available = lines.reduce((total, line) => total + (line.itemId === component.itemId ? Number(line.units) : 0), 0);
    const possible = Math.floor(available / component.qty);
    bundles = bundles === null ? possible : Math.min(bundles, possible);
  }
  if (bundles < 1) return [new Map(), []];
  const contributions = new Map();
  for (const component of components) {
    let needed = bundles * component.qty;
    for (const line of lines) {
      if (needed === 0 || line.itemId !== component.itemId) continue;
      const take = Math.min(needed, Number(line.units));
      if (take > 0) {
        contributions.set(line.index, (contributions.get(line.index) ?? 0n) + line.unit * BigInt(take));
        needed -= take;
      }
    }
  }
  const sorted = new Map([...contributions.entries()].sort(([a], [b]) => a - b));
  const gross = sum([...sorted.values()]);
  const discount = gross - BigInt(promotion.amountMinor) * BigInt(bundles);
  if (discount <= 0n) return [new Map(), []];
  return [allocate(discount, sorted), [...sorted.keys()]];
}

/** Share `amount` over `parts` (index → weight) by largest remainder, the earlier index first on a tie. */
function allocate(amount, parts) {
  const total = sum([...parts.values()]);
  const shares = new Map();
  if (total === 0n) return shares;
  const remainders = [];
  let given = 0n;
  for (const [index, weight] of parts) {
    const product = amount * weight;
    shares.set(index, product / total);
    remainders.push({ index, remainder: product % total });
    given += product / total;
  }
  remainders.sort((a, b) => (b.remainder > a.remainder ? 1 : b.remainder < a.remainder ? -1 : a.index - b.index));
  const left = Number(amount - given);
  for (let i = 0; i < left; i += 1) {
    const { index } = remainders[i];
    shares.set(index, shares.get(index) + 1n);
  }
  return shares;
}

function unitsOf(lines) {
  const units = [];
  for (const line of lines) for (let i = 0n; i < line.units; i += 1n) units.push({ index: line.index, price: line.unit });
  return units;
}

const percentOf = (amount, scaledPercent) => roundHalfUp(amount * scaledPercent, 100n * PERCENT_SCALE);

/** Non-negative numerator over a positive denominator, rounded half up. */
function roundHalfUp(numerator, denominator) {
  return (numerator * 2n + denominator) / (denominator * 2n);
}

/** A non-negative decimal string as a BigInt scaled by 10^places (extra places are cut). */
function scaled(value, places) {
  const [whole, fraction = ''] = String(value).trim().split('.');
  return BigInt(`${whole || '0'}${fraction.padEnd(places, '0').slice(0, places)}`);
}

const sum = (values) => values.reduce((total, value) => total + value, 0n);
const min = (a, b) => (a < b ? a : b);
const max = (a, b) => (a > b ? a : b);
const compareStrings = (a, b) => (a < b ? -1 : a > b ? 1 : 0);
