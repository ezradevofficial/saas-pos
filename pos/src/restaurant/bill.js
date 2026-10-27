/**
 * REST-13, REST-14: the maths of an order's bill, exact and pure. A port of
 * api/modules/Restaurant/Billing/Bill.php; both replay
 * api/tests/Fixtures/restaurant/bill.json, so the till and the server agree
 * to the minor unit. BigInt throughout (ADR 003), half-up rounding.
 *
 * A line is `{id, qty, unit_price_minor, modifiers: [{price_minor}],
 * tax_rate?, tax_inclusive, void?, seat?}`. Amounts are minor units: digit
 * strings out, numbers or strings in.
 *
 * - gross = round(qty x (unit price + modifier effects));
 * - net = gross, or gross / (1 + rate) when the price includes tax;
 * - payable = gross when tax-inclusive, else gross + tax on the net;
 * - service charge = round(sum of live nets x percent), rung as a line of
 *   the service item taxed at that item's rate, exclusive of tax;
 * - tip = a tax-free amount.
 * Void lines count for nothing.
 */
const SCALE = 1000000n;

/** A decimal text as an integer scaled by 10^scale, rounded half away from zero (brick's toScale(HalfUp)). */
export function scaled(value, scale) {
  const text = String(value ?? '').trim();
  const match = /^(-?)(\d+)(?:\.(\d*))?$/.exec(text);
  if (!match) throw new Error(`Not a decimal number [${text}]`);
  const fraction = match[3] ?? '';
  const kept = fraction.slice(0, scale).padEnd(scale, '0');
  let units = BigInt(`${match[2]}${kept}`);
  if (fraction.length > scale && fraction.charCodeAt(scale) >= 53) units += 1n;
  return match[1] ? -units : units;
}

/** An integer scaled by 10^scale as a decimal string with exactly `scale` decimals. */
function unscale(units, scale) {
  const negative = units < 0n;
  const digits = String(negative ? -units : units).padStart(scale + 1, '0');
  return `${negative ? '-' : ''}${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}

/** A percentage as parts per million of a unit: 16 % is 160000 (four decimals kept). */
const rate = (percent) => scaled(percent, 4);

/** a / b rounded half up, both positive or a zero. */
const roundDiv = (a, b) => (a * 2n + b) / (b * 2n);

const big = (value) => BigInt(String(value ?? 0));

/** One line's amounts as BigInt: { gross, net, tax, payable }. */
export function lineAmounts(line) {
  const effects = (line.modifiers ?? []).reduce((sum, modifier) => sum + big(modifier.price_minor), 0n);
  const qty = scaled(line.qty, 6);
  const gross = roundDiv(qty * (big(line.unit_price_minor) + effects), SCALE);
  const lineRate = line.tax_rate !== undefined && line.tax_rate !== null ? rate(line.tax_rate) : 0n;
  if (line.tax_inclusive) {
    const net = roundDiv(gross * SCALE, SCALE + lineRate);
    return { gross, net, tax: gross - net, payable: gross };
  }
  const tax = roundDiv(gross * lineRate, SCALE);
  return { gross, net: gross, tax, payable: gross + tax };
}

function serviceCharge(net, service = {}) {
  const percent = service.percent;
  if (percent === undefined || percent === null || Number(percent) <= 0) return [0n, 0n];
  const charge = roundDiv(net * rate(percent), SCALE);
  const tax = service.tax_rate !== undefined && service.tax_rate !== null ? roundDiv(charge * rate(service.tax_rate), SCALE) : 0n;
  return [charge, tax];
}

/**
 * The bill of the live lines: { lines, net_minor, tax_minor, lines_minor,
 * service_charge_minor, service_tax_minor, tip_minor, total_minor }, all
 * digit strings.
 */
export function totals(lines, service = {}, tip = 0) {
  const rows = [];
  let net = 0n;
  let payable = 0n;
  let tax = 0n;
  for (const line of lines) {
    if (line.void) continue;
    const amounts = lineAmounts(line);
    rows.push({ id: String(line.id ?? ''), gross_minor: String(amounts.gross), net_minor: String(amounts.net), tax_minor: String(amounts.tax), payable_minor: String(amounts.payable) });
    net += amounts.net;
    payable += amounts.payable;
    tax += amounts.tax;
  }
  const [charge, chargeTax] = serviceCharge(net, service);
  const tipAmount = big(tip);
  if (tipAmount < 0n) throw new Error('A tip cannot be negative.');
  return {
    lines: rows,
    net_minor: String(net),
    tax_minor: String(tax),
    lines_minor: String(payable),
    service_charge_minor: String(charge),
    service_tax_minor: String(chargeTax),
    tip_minor: String(tipAmount),
    total_minor: String(payable + charge + chargeTax + tipAmount),
  };
}

/**
 * $total shared by $weights (largest remainder; ties to the first), the
 * parts adding up to $total exactly. Zero weights share equally.
 */
export function allocate(total, weights) {
  let list = weights;
  let sum = list.reduce((carry, weight) => carry + weight, 0n);
  if (sum === 0n) {
    list = list.map(() => 1n);
    sum = BigInt(list.length);
  }
  const base = [];
  const remainders = [];
  let given = 0n;
  list.forEach((weight, index) => {
    const product = total * weight;
    base[index] = product / sum;
    remainders[index] = product % sum;
    given += base[index];
  });
  const left = Number(total - given);
  const order = list.map((_, index) => index).sort((a, b) => (remainders[b] > remainders[a] ? 1 : remainders[b] < remainders[a] ? -1 : a - b));
  for (const index of order.slice(0, left)) base[index] += 1n;
  return base;
}

/** What each of `count` parts takes of the shared lines' `key`, the leftover unit of each going to the parts in turn. */
function allocateShared(shared, amounts, key, count) {
  const out = Array.from({ length: count }, () => 0n);
  let turn = 0;
  for (const id of shared) {
    const value = amounts.get(id)[key];
    const each = value / BigInt(count);
    const left = Number(value % BigInt(count));
    for (let i = 0; i < count; i += 1) {
      const extra = left > 0 && (((i - turn) % count) + count) % count < left ? 1n : 0n;
      out[i] += each + extra;
    }
    turn = (turn + left) % count;
  }
  return out;
}

/** Each group's weight (its lines' payable amounts plus its share of the shared lines) and net. */
function byLines(groups, amounts, shared) {
  const count = groups.length;
  const sharedPayable = allocateShared(shared, amounts, 'payable', count);
  const sharedNet = allocateShared(shared, amounts, 'net', count);
  const weights = [];
  const nets = [];
  groups.forEach((group, index) => {
    weights[index] = sharedPayable[index];
    nets[index] = sharedNet[index];
    for (const id of group) {
      weights[index] += amounts.get(id).payable;
      nets[index] += amounts.get(id).net;
    }
  });
  return [weights, nets];
}

const part = (amounts, lineIds, equal) => ({
  line_ids: lineIds,
  lines_minor: amounts.lines_minor,
  service_charge_minor: amounts.service_charge_minor,
  service_tax_minor: amounts.service_tax_minor,
  tip_minor: amounts.tip_minor,
  total_minor: amounts.total_minor,
  equal: equal === null ? null : { index: equal[0], count: equal[1] },
});

/**
 * REST-14: the parts of a split. `mode` is `full`, `item` (`options.groups`:
 * lists of line ids, every live line in exactly one group), `seat` (one
 * part per distinct seat in order; lines without a seat are shared
 * equally) or `equal` (`options.parts` of them, 2 to 20). The parts add up
 * exactly to the order's total, by largest remainder.
 */
export function split(lines, mode, service = {}, tip = 0, options = {}) {
  const whole = totals(lines, service, tip);
  const live = lines.filter((line) => !line.void);
  const amounts = new Map(live.map((line) => [String(line.id), lineAmounts(line)]));
  let weights;
  let nets;
  let ids = null;

  switch (mode) {
    case 'full':
      return [part(whole, null, null)];
    case 'equal': {
      const count = Number.parseInt(options.parts ?? 0, 10) || 0;
      if (count < 2 || count > 20) throw new Error('Equal split needs 2 to 20 parts.');
      weights = Array.from({ length: count }, () => 1n);
      nets = weights;
      break;
    }
    case 'item': {
      const groups = options.groups ?? [];
      const seen = new Set();
      for (const group of groups) {
        for (const id of group) {
          if (!amounts.has(id) || seen.has(id)) throw new Error(`Line [${id}] is not a live line or is in two groups.`);
          seen.add(id);
        }
      }
      if (groups.length < 2 || seen.size !== amounts.size) throw new Error('Split by item needs 2 or more groups covering every live line.');
      [weights, nets] = byLines(groups, amounts, []);
      ids = groups;
      break;
    }
    case 'seat': {
      const seatNumbers = [...new Set(live.filter((line) => line.seat !== undefined && line.seat !== null).map((line) => Number(line.seat)))].sort((a, b) => a - b);
      if (seatNumbers.length < 2) throw new Error('Split by seat needs 2 or more seats with lines.');
      const groups = seatNumbers.map((seat) => live.filter((line) => line.seat !== undefined && line.seat !== null && Number(line.seat) === seat).map((line) => String(line.id)));
      const shared = live.filter((line) => line.seat === undefined || line.seat === null).map((line) => String(line.id));
      [weights, nets] = byLines(groups, amounts, shared);
      ids = groups;
      break;
    }
    default:
      throw new Error(`Unknown split mode [${mode}]`);
  }

  const count = weights.length;
  const lineShares = mode === 'equal' ? allocate(big(whole.lines_minor), weights) : weights;
  const chargeShares = allocate(big(whole.service_charge_minor), mode === 'equal' ? weights : nets);
  const chargeTaxShares = allocate(big(whole.service_tax_minor), mode === 'equal' ? weights : nets);
  const tipShares = allocate(big(whole.tip_minor), lineShares);
  return lineShares.map((lines, index) =>
    part(
      {
        lines_minor: String(lines),
        service_charge_minor: String(chargeShares[index]),
        service_tax_minor: String(chargeTaxShares[index]),
        tip_minor: String(tipShares[index]),
        total_minor: String(lines + chargeShares[index] + chargeTaxShares[index] + tipShares[index]),
      },
      ids?.[index] ?? null,
      mode === 'equal' ? [index, count] : null,
    ),
  );
}

/**
 * REST-14: the quantity of `qty` that part `index` of `count` takes in an
 * equal split: 1/count rounded down to 6 places, the last part taking what
 * is left, so the parts add up to the quantity exactly.
 */
export function equalQty(qty, index, count) {
  const total = scaled(qty, 6);
  const each = total / BigInt(count);
  if (index < count - 1) return unscale(each, 6);
  return unscale(total - each * BigInt(count - 1), 6);
}

export const bill = { totals, split, equalQty, allocate, lineAmounts };
