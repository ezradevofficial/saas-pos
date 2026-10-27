import { add, div, exact, mul, ROUND, round, toFixed } from '../pos/exact';

/**
 * FUEL-12, FUEL-27: the tender kinds of a shift's collections and the fuel
 * share of a sale's payments, mirroring api Shifts/Tenders.php (integers,
 * minor units), plus what the sales rung on this till came to per nozzle and
 * attendant. The till uses it for the running figures and the variance
 * preview; the server recomputes everything from the sales it accepts and
 * decides (FUEL-28).
 */
export const KINDS = ['cash', 'mobile_money', 'card', 'credit', 'voucher'];

/** A payment method type of the sale -> the shift's tender kind (Tenders::kindOf). */
export function kindOf(methodType) {
  switch (methodType) {
    case 'cash':
      return 'cash';
    case 'mobile_money':
      return 'mobile_money';
    case 'card':
    case 'bank_transfer':
      return 'card';
    case 'credit':
    case 'room_charge':
      return 'credit';
    case 'voucher':
    case 'fuel_voucher':
    case 'points':
      return 'voucher';
    default:
      return 'card';
  }
}

const largest = (amounts) => {
  let key = Object.keys(amounts)[0];
  for (const kind of Object.keys(amounts)) if (amounts[kind] > amounts[key]) key = kind;
  return key;
};

/** What the payments came to per kind, net of the change given (change comes out of cash, else off the largest kind). */
export function net(payments, changeMinor = 0n) {
  const kinds = {};
  for (const payment of payments) {
    const kind = kindOf(payment.method_type);
    kinds[kind] = (kinds[kind] ?? 0n) + BigInt(payment.amount_in_sale_minor);
  }
  const change = BigInt(changeMinor);
  if (change > 0n && Object.keys(kinds).length) {
    const from = kinds.cash !== undefined ? 'cash' : largest(kinds);
    kinds[from] -= change;
  }
  return kinds;
}

/** The fuel share of the net payments: each kind scaled by fuel / sale total, half up, the difference on the largest kind. */
export function fuelShare(netKinds, fuelTotal, saleTotal) {
  const fuel = BigInt(fuelTotal);
  const sale = BigInt(saleTotal);
  if (sale === 0n || fuel >= sale) return netKinds;
  const shares = {};
  let sum = 0n;
  let netSum = 0n;
  for (const [kind, amount] of Object.entries(netKinds)) {
    shares[kind] = round(div(mul(exact(amount), exact(fuel)), exact(sale)), ROUND.HALF_UP);
    sum += shares[kind];
    netSum += amount;
  }
  if (Object.keys(shares).length) {
    const target = round(div(mul(exact(netSum), exact(fuel)), exact(sale)), ROUND.HALF_UP);
    shares[largest(shares)] += target - sum;
  }
  return shares;
}

/**
 * The live fuel sales of `shift` rung on this till (sales and their lines as stored by the till):
 * { nozzles: { [nozzleId]: { litres, valueMinor } }, attendants: { [attendantId]: { valueMinor, tenders: { [kind]: bigint } } } }.
 * A line is attributed to the attendant of its nozzle; the payments of a sale go to the attendant of its
 * first fuel line (FUEL-12, to confirm 9). Voided sales are left out.
 */
export function summarise(shift, sales) {
  const attendantOf = new Map((shift?.nozzles ?? []).map((row) => [row.nozzle_id, row.attendant_id]));
  const nozzles = {};
  const attendants = {};
  const litresSum = {};
  for (const sale of sales ?? []) {
    const fuel = sale.extensions?.fuel;
    if (!fuel || fuel.shift_id !== shift?.id || sale.local?.status === 'voided') continue;
    const byLine = new Map((fuel.lines ?? []).map((entry) => [entry.line_id, entry.nozzle_id]));
    let fuelTotal = 0n;
    let first = null;
    for (const line of sale.lines ?? []) {
      const nozzleId = byLine.get(line.id);
      if (!nozzleId) continue;
      litresSum[nozzleId] = add(litresSum[nozzleId] ?? 0, line.qty);
      nozzles[nozzleId] = { litres: toFixed(litresSum[nozzleId], 3), valueMinor: (nozzles[nozzleId]?.valueMinor ?? 0n) + BigInt(line.total_minor) };
      fuelTotal += BigInt(line.total_minor);
      first ??= attendantOf.get(nozzleId) ?? null;
      const attendant = attendantOf.get(nozzleId);
      if (attendant) {
        attendants[attendant] ??= { valueMinor: 0n, tenders: {} };
        attendants[attendant].valueMinor += BigInt(line.total_minor);
      }
    }
    if (!first) continue;
    const payments = (sale.payments ?? []).map((payment) => ({ method_type: sale.local?.methods?.[payment.id]?.type ?? 'card', amount_in_sale_minor: payment.amount_in_sale_minor }));
    const change = sale.change && sale.change.currency === sale.currency ? BigInt(sale.change.amount_minor) : 0n;
    const share = fuelShare(net(payments, change), fuelTotal, sale.totals?.total_minor ?? fuelTotal);
    attendants[first] ??= { valueMinor: 0n, tenders: {} };
    for (const [kind, amount] of Object.entries(share)) attendants[first].tenders[kind] = (attendants[first].tenders[kind] ?? 0n) + amount;
  }
  return { nozzles, attendants };
}
