import { add, compare, isPositive, mul, sub, toFixed } from '../pos/exact';

/**
 * PHA-07, PHA-08, PHA-16, PHA-17, PHA-25: the pharmacy rules on the till,
 * pure so they work offline and a test can drive them. Mirrors the server's
 * Lots::check / Lots::suggest and Dispensing::allocate: expired, recalled,
 * quarantined and too-close lots are refused, near-expiry lots warn, and
 * stock is taken first-expiry-first-out. The server decides again on upload
 * (the device wins for a completed sale; differences are flagged).
 */

const DAY_MS = 86400000;
const dayNumber = (iso) => Math.floor(Date.parse(`${String(iso).slice(0, 10)}T00:00:00Z`) / DAY_MS);

/** Whole days from `today` to `date` (negative when past). */
export const daysUntil = (today, date) => dayNumber(date) - dayNumber(today);

/** The decimal without trailing zeros ("2.5000" becomes "2.5", "3.0" "3"). */
export function plain(value) {
  const text = toFixed(value, 4);
  return text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text;
}

/** Base units of `qty` sold in `uomId` (the item's unit conversion). */
export function toBase(item, uomId, qty) {
  const factor = !uomId || uomId === item?.base_uom_id ? '1' : ((item?.uoms ?? []).find((uom) => uom.uom_id === uomId)?.factor ?? '1');
  return mul(qty, factor);
}

/** { refuse, warn, daysLeft } of a lot row on `today`; refuse is a reason key or null. */
export function lotVerdict(lot, settings, today) {
  const daysLeft = daysUntil(today, lot.expires_on);
  let refuse = null;
  if (daysLeft < 0) refuse = 'lot_expired';
  else if (lot.blocked === 'recalled') refuse = 'lot_recalled';
  else if (lot.blocked === 'quarantined') refuse = 'lot_quarantined';
  else if (daysLeft < Number(settings?.min_sale_days ?? 0)) refuse = 'lot_too_close';
  return { refuse, warn: refuse === null && daysLeft <= Number(settings?.near_expiry_days ?? 0) ? 'near_expiry' : null, daysLeft };
}

export const byExpiry = (a, b) => String(a.expires_on).localeCompare(String(b.expires_on)) || String(a.batch_number).localeCompare(String(b.batch_number));

/** Stock of a lot on this till: as last synced, less what this till sold since (`used`: lot id to base quantity). */
export const available = (lot, used) => {
  const left = sub(lot.qty, used?.get(lot.lot_id) ?? '0');
  return isPositive(left) ? left : mul(0, 1);
};

/**
 * PHA-07: the lots a line of `qty` base units takes. `picks` are the
 * person's choices ([{lot_id, qty}], in order); what they leave is taken
 * first-expiry-first-out from sellable lots. Returns { taken: [{lot_id,
 * batch_number, expires_on, qty, warn}], short, reason }: `short` is what no
 * sellable lot covers; `reason` is why the line cannot be sold (a chosen lot
 * that is refused, else the earliest refused lot when nothing sellable
 * exists, else `lot_short`), null when it can.
 */
export function allocate({ lots, used, settings, today, qty, picks = [] }) {
  const rows = [...lots].sort(byExpiry);
  const taken = [];
  let left = qty;
  let reason = null;

  const take = (lot, want, verdict) => {
    const cap = available(lot, used);
    const alreadyTaken = taken.find((row) => row.lot_id === lot.lot_id);
    const room = alreadyTaken ? sub(cap, alreadyTaken.qty) : cap;
    const amount = compare(want, room) < 0 ? want : room;
    if (!isPositive(amount)) return;
    if (alreadyTaken) alreadyTaken.qty = plain(add(alreadyTaken.qty, amount));
    else taken.push({ lot_id: lot.lot_id, batch_number: lot.batch_number, expires_on: lot.expires_on, qty: plain(amount), warn: verdict.warn });
    left = sub(left, amount);
  };

  for (const pick of picks) {
    const lot = rows.find((row) => row.lot_id === pick.lot_id);
    if (!lot || !isPositive(left)) continue;
    const verdict = lotVerdict(lot, settings, today);
    if (verdict.refuse) {
      reason ??= verdict.refuse;
      continue;
    }
    take(lot, compare(pick.qty, left) < 0 ? pick.qty : left, verdict);
  }
  for (const lot of rows) {
    if (!isPositive(left)) break;
    const verdict = lotVerdict(lot, settings, today);
    if (!verdict.refuse) take(lot, left, verdict);
  }
  if (isPositive(left) && reason === null) {
    const refused = rows.map((lot) => lotVerdict(lot, settings, today).refuse).find(Boolean);
    reason = taken.length === 0 && refused ? refused : 'lot_short';
  }
  return { taken, short: isPositive(left) ? plain(left) : '0', reason };
}

/** Which of an item's profile rules apply under `settings` (PHA-16, PHA-17, PHA-20). */
export function rulesOf(profile, settings) {
  if (!profile) return { rx: false, pharmacist: false, lots: false, buyer: false, notes: null };
  return {
    rx: (settings?.prescription_classes ?? []).includes(profile.class),
    pharmacist: Boolean(profile.restricted_sale) || (settings?.pharmacist_classes ?? []).includes(profile.class),
    lots: Boolean(profile.batch_tracked),
    buyer: Boolean(profile.restricted_sale),
    notes: profile.usage_notes ?? null,
  };
}

const REQUIRED = ['prescriber_name', 'prescriber_licence', 'facility', 'patient_name', 'prescribed_on'];

/** null when the prescription can be used on `today`, else `prescription_needed` or `prescription_stale`. */
export function prescriptionProblem(prescription, settings, today) {
  if (!prescription || REQUIRED.some((key) => !String(prescription[key] ?? '').trim()) || !/^\d{4}-\d{2}-\d{2}$/.test(prescription.prescribed_on)) return 'prescription_needed';
  const age = daysUntil(prescription.prescribed_on, today);
  if (age < 0) return 'prescription_needed';
  const valid = settings?.prescription_valid_days;
  return valid != null && age > Number(valid) ? 'prescription_stale' : null;
}
