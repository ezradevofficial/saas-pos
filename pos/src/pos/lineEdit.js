import { discountPercent, extend, notAbove } from './tax';

/**
 * POS-07, RBAC-06, AUTH-07, AUTH-08: one change to a cart line (quantity,
 * unit price and/or discount), decided from the NEW values:
 *
 * - quantity: the list price is re-resolved for the new quantity
 *   (quantity breaks) unless the price was set by hand (`priceSet`);
 * - price: back to the list price clears any price approval; any other
 *   price needs pos.price.override (own right) or a manager's override,
 *   and marks the line `priceSet`;
 * - discount: checked as a percentage of the NEW gross (unit price × new
 *   quantity). An existing approval is kept when the discount amount is
 *   unchanged and its percentage did not grow (the approver allowed at
 *   least that much); otherwise it is authorised again. The discount can
 *   never exceed the gross.
 *
 * `authorize(action, value, reference)` resolves { override, approvedBy,
 * proof } (own right: override null, the person's proof) or null when
 * nobody allowed it. In quiet mode (the +/− buttons) the caller passes an
 * authorize that never asks a manager; a discount it cannot keep is then
 * cleared and the result says so (`notice: 'discount_cleared'`).
 *
 * Resolves { ok: true, patch, notice? } or { ok: false, reason:
 * 'qty' | 'discount_above_price' | 'not_approved' }.
 */
export async function planLineEdit({ line, changes, listPriceFor, authorize, quiet = false }) {
  const qty = changes.qty ?? line.qty;
  if (!/^\d{1,12}(\.\d{1,6})?$/.test(String(qty)) || !/[1-9]/.test(String(qty))) return { ok: false, reason: 'qty' };

  const listPrice = (qty === line.qty ? null : listPriceFor(qty)) ?? line.listPriceMinor;
  const patch = { qty: String(qty), listPriceMinor: listPrice };

  const priceGiven = changes.unitPriceMinor != null && String(changes.unitPriceMinor) !== line.unitPriceMinor;
  if (priceGiven) {
    const unit = String(changes.unitPriceMinor);
    if (listPrice != null && unit === String(listPrice)) {
      Object.assign(patch, { unitPriceMinor: unit, priceOverride: null, priceSet: false });
    } else {
      const approval = await authorize('price', null, line.id);
      if (!approval) return { ok: false, reason: 'not_approved' };
      Object.assign(patch, { unitPriceMinor: unit, priceOverride: approval.override ?? null, priceSet: true });
      if (!approval.override) patch.actorProof = approval.proof ?? line.actorProof;
    }
  } else {
    patch.unitPriceMinor = line.priceSet || line.priceOverride || listPrice == null ? line.unitPriceMinor : String(listPrice);
  }

  const discount = String(changes.discountMinor ?? line.discountMinor ?? '0');
  const gross = extend(patch.unitPriceMinor, patch.qty);
  if (BigInt(discount) > gross) {
    if (changes.discountMinor != null) return { ok: false, reason: 'discount_above_price' };
    Object.assign(patch, { discountMinor: '0', override: null, discountBy: null });
    return { ok: true, patch, notice: 'discount_cleared' };
  }
  if (discount === '0') {
    Object.assign(patch, { discountMinor: '0', override: null, discountBy: null });
    return { ok: true, patch };
  }

  const percent = discountPercent(discount, String(gross));
  const oldGross = extend(line.unitPriceMinor, line.qty);
  const hadApproval = line.discountMinor !== '0' && (line.override || line.discountBy);
  const keep = hadApproval && discount === line.discountMinor && notAbove(percent, discountPercent(line.discountMinor, String(oldGross)));
  if (keep) {
    patch.discountMinor = discount;
    return { ok: true, patch };
  }
  const approval = await authorize('discount', percent, line.id);
  if (!approval) {
    if (!quiet) return { ok: false, reason: 'not_approved' };
    Object.assign(patch, { discountMinor: '0', override: null, discountBy: null });
    return { ok: true, patch, notice: 'discount_cleared' };
  }
  Object.assign(patch, { discountMinor: discount, override: approval.override ?? null, discountBy: approval.approvedBy ?? null });
  if (!approval.override) patch.actorProof = approval.proof ?? line.actorProof;
  return { ok: true, patch };
}
