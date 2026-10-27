import { compare, div, exact, mul, quantize, ROUND, round, sub, add, toFixed, divideScaled } from '../pos/exact';

/**
 * FUEL-06, FUEL-11, FUEL-16, FUEL-19, FUEL-28: the forecourt maths on the
 * till, exact (bigint fractions from pos/exact.js, never floats), reproducing
 * api/modules/FuelStation (Shifts/Meters.php, Support/Litres.php,
 * Tanks/Strapping.php) to the millilitre and the minor unit. The shared
 * fixture meters.fixture.json holds figures produced by the PHP classes.
 *
 * Rules, as the server applies them:
 * - litres and meter readings have 3 decimals, rounded half up;
 * - litres sold = closing - opening - pump tests + approved reset adjustments;
 * - by amount, litres = amount / price per litre rounded DOWN to 3 decimals;
 * - a line total = price per litre (minor units) x litres, rounded half up to
 *   the minor unit (the POS Amounts rule);
 * - a dip in cm converts to litres by linear interpolation between the two
 *   nearest chart points (the division at 9 decimals half up, the result at
 *   3 decimals half up); outside the chart it is refused `dip_out_of_range`;
 * - the chart in force is the latest one with effective_from not after the dip
 *   time (none: `chart_missing`);
 * - the price in force is the latest row with effective_from not after the
 *   time (none: `price_missing`).
 */
export class FuelError extends Error {
  constructor(code, extra = {}) {
    super(code);
    this.name = 'FuelError';
    this.code = code;
    Object.assign(this, extra);
  }
}

export const LITRES_SCALE = 3;
const LITRES_PATTERN = /^\d{1,13}(\.\d{1,3})?$/;
const CM_PATTERN = /^\d{1,5}(\.\d{1,2})?$/;

const fixed3 = (value) => toFixed(exact(value), LITRES_SCALE);

/** A typed litres or meter reading ("1 234,5" is not accepted): the text as the API takes it, or null. */
export function parseLitres(text) {
  const value = String(text ?? '').trim().replace(',', '.');
  return LITRES_PATTERN.test(value) ? value : null;
}

/** A typed dip in cm (up to 2 decimals), or null. */
export function parseCm(text) {
  const value = String(text ?? '').trim().replace(',', '.');
  return CM_PATTERN.test(value) ? value : null;
}

/** Litres with exactly 3 decimals: "12.500". */
export const showLitres = (value) => (value == null || value === '' ? '' : fixed3(value));

/** FUEL-11: litres sold = closing - opening - tests + reset adjustments (3 decimals, half up). */
export function meterLitres({ opening, closing, tests = '0', resets = '0' }) {
  return fixed3(add(sub(sub(closing, opening), tests), resets));
}

/** FUEL-11: the unrecorded litres, never negative. */
export function unrecordedLitres(meter, recorded) {
  const gap = sub(meter, recorded);
  return compare(gap, 0) < 0 ? fixed3(0) : fixed3(gap);
}

/** FUEL-17: a reset's adjustment to the litres of a shift nozzle: reading before - reading after. */
export const resetAdjustment = (before, after) => fixed3(sub(before, after));

/**
 * FUEL-16: the lowest closing reading a shift nozzle accepts: its opening
 * reading, or after an approved reset the latest reading after it.
 */
export function closingFloor(opening, latestResetAfter = null) {
  return latestResetAfter ?? fixed3(opening);
}

/**
 * FUEL-16: an opening reading against the nozzle's last accepted reading.
 * Below it is refused `meter_decreased` (the attendant asks a supervisor for a
 * reset); above it is allowed and the gap is dispensing nobody recorded.
 * Returns { gap } (litres, "0.000" when equal or no last reading).
 */
export function checkOpening(reading, last) {
  const value = parseLitres(reading);
  if (value === null) throw new FuelError('reading_invalid');
  if (last == null || last === '') return { gap: fixed3(0) };
  if (compare(value, last) < 0) throw new FuelError('meter_decreased', { last: fixed3(last) });
  return { gap: fixed3(sub(value, last)) };
}

/**
 * FUEL-16, FUEL-11: a closing reading and the pump tests of a shift nozzle.
 * Below the floor is refused `meter_decreased`; tests above the litres the
 * meter moved are refused `test_exceeds_litres`. Returns { litres }.
 */
export function checkClosing({ opening, reading, tests = '0', resets = '0', latestResetAfter = null }) {
  const value = parseLitres(reading);
  if (value === null) throw new FuelError('reading_invalid');
  const testsValue = parseLitres(tests === '' || tests == null ? '0' : tests);
  if (testsValue === null) throw new FuelError('tests_invalid');
  if (compare(value, closingFloor(opening, latestResetAfter)) < 0) throw new FuelError('meter_decreased', { last: closingFloor(opening, latestResetAfter) });
  // After an approved reset the adjustment is the server's to apply: the till only keeps the floor.
  if (latestResetAfter != null) return { litres: null };
  const litres = meterLitres({ opening, closing: value, tests: testsValue, resets });
  if (compare(litres, 0) < 0) throw new FuelError('test_exceeds_litres');
  return { litres };
}

/** FUEL-19: by amount, litres = amount / price per litre rounded DOWN to 3 decimals (both minor units). */
export function litresFromAmount(amountMinor, pricePerLitreMinor) {
  if (compare(pricePerLitreMinor, 0) <= 0) throw new FuelError('price_missing');
  return toFixed(quantize(div(amountMinor, pricePerLitreMinor), LITRES_SCALE, ROUND.DOWN), LITRES_SCALE);
}

/** FUEL-19: the line total: price per litre x litres, rounded half up to the minor unit (a digit string). */
export const lineTotal = (pricePerLitreMinor, litres) => String(round(mul(exact(pricePerLitreMinor), exact(litres)), ROUND.HALF_UP));

/**
 * FUEL-19: what a quick sale rings. mode 'litres': the typed litres (3 decimals max);
 * mode 'amount': the typed amount in minor units. Resolves { litres, totalMinor, priceMinor }.
 */
export function quote({ mode, litres, amountMinor, priceMinor }) {
  if (priceMinor == null || compare(priceMinor, 0) <= 0) throw new FuelError('price_missing');
  let quantity;
  if (mode === 'amount') {
    if (amountMinor == null || compare(amountMinor, 0) <= 0) throw new FuelError('amount_invalid');
    quantity = litresFromAmount(amountMinor, priceMinor);
  } else {
    quantity = parseLitres(litres);
    if (quantity === null) throw new FuelError('litres_invalid');
    quantity = fixed3(quantity);
  }
  if (compare(quantity, 0) <= 0) throw new FuelError('litres_zero');
  return { litres: quantity, totalMinor: lineTotal(priceMinor, quantity), priceMinor: String(priceMinor) };
}

/** FUEL-08: the price row in force for a product at `at` (ms or ISO), or null. Rows: { id, product_id, price_minor, effective_from }. */
export function priceAt(prices, productId, at) {
  const time = typeof at === 'number' ? at : Date.parse(at);
  let chosen = null;
  for (const row of prices ?? []) {
    if (row.product_id !== productId) continue;
    const from = Date.parse(row.effective_from);
    if (!(from <= time)) continue;
    const chosenFrom = chosen ? Date.parse(chosen.effective_from) : -Infinity;
    if (from > chosenFrom || (from === chosenFrom && String(row.id) > String(chosen.id))) chosen = row;
  }
  return chosen;
}

/** FUEL-06: the chart of a tank in force at `at`: the latest with effective_from not after it, or null. Charts: { id, effective_from, points: [[cm, litres]] }. */
export function chartAt(charts, at) {
  const time = typeof at === 'number' ? at : Date.parse(at);
  let chosen = null;
  for (const chart of charts ?? []) {
    const from = Date.parse(chart.effective_from);
    if (!(from <= time)) continue;
    const chosenFrom = chosen ? Date.parse(chosen.effective_from) : -Infinity;
    if (from > chosenFrom || (from === chosenFrom && String(chart.id) > String(chosen.id))) chosen = chart;
  }
  return chosen;
}

/** FUEL-06: Strapping::interpolate. points = [[cm, litres]] sorted by cm, strictly rising. Throws `dip_out_of_range`. */
export function interpolate(points, dipCm) {
  const dip = exact(dipCm);
  if (!points?.length || compare(dip, points[0][0]) < 0 || compare(dip, points[points.length - 1][0]) > 0) throw new FuelError('dip_out_of_range');
  for (let i = 0; i < points.length; i += 1) {
    const [cm, litres] = points[i];
    if (compare(dip, cm) === 0) return fixed3(litres);
    if (compare(dip, cm) < 0) {
      const [cm0, litres0] = points[i - 1];
      const step = divideScaled(mul(sub(dip, cm0), sub(litres, litres0)), sub(cm, cm0), 9);
      return fixed3(add(litres0, step));
    }
  }
  throw new FuelError('dip_out_of_range');
}

/** FUEL-06, FUEL-23: litres for a dip in cm in a tank (a synced `fuel_tanks` row) at `at`. Resolves { litres, chartId }. */
export function dipToLitres(tank, dipCm, at) {
  const value = parseCm(dipCm);
  if (value === null) throw new FuelError('dip_invalid');
  const chart = chartAt(tank?.strapping, at);
  if (!chart) throw new FuelError('chart_missing');
  const points = [...chart.points].map(([cm, litres]) => [String(cm), String(litres)]).sort((a, b) => compare(a[0], b[0]));
  return { litres: interpolate(points, value), chartId: chart.id };
}
