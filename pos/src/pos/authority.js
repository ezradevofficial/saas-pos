import { notAbove } from './tax';

/**
 * POS-05, POS-07, RBAC-06, AUTH-08: what the signed-in person may do at the
 * till, from their synced staff row (`permissions`, `limits`). The server
 * decides again on upload; the till asks for a manager's override before
 * doing something the person may not.
 *
 * Limits (RBAC-06) are decimal strings; a missing key means "not allowed".
 * Discounts compare a percentage (4 decimals); refunds compare the amount
 * in the company's base currency, major units.
 */
export const PERMISSIONS = {
  discount: 'pos.discount.give',
  price: 'pos.price.override',
  void: 'pos.sale.void',
  refund: 'pos.sale.refund',
  cash: 'pos.cash.move',
  shiftOpen: 'pos.shift.open',
  shiftClose: 'pos.shift.close',
  shiftManage: 'pos.shift.manage',
};

export const LIMITS = { discount: 'max_discount_percent', refund: 'max_refund_amount' };

export function can(staff, permission) {
  return Boolean(staff?.permissions?.includes(permission));
}

export function limitOf(staff, key) {
  const value = staff?.limits?.[key];
  return value == null || value === '' ? null : String(value);
}

/** `value` within the staff member's `key` limit (a missing limit is not allowed). */
export function within(staff, key, value) {
  // RBAC-06: an Owner role has no limits (staff row `owner`, as Authority::within).
  if (staff?.owner) return true;
  const max = limitOf(staff, key);
  return max !== null && notAbove(value, max);
}

/**
 * Whether `staff` may do `action` alone: { allowed, permission, reason }.
 * action: 'discount' (value = percent), 'price', 'void', 'refund'
 * (value = base major amount), 'pay_out', 'pay_in'.
 */
export function check(staff, action, value) {
  switch (action) {
    case 'discount':
      return decide(staff, PERMISSIONS.discount, () => within(staff, LIMITS.discount, value));
    case 'price':
      return decide(staff, PERMISSIONS.price);
    case 'void':
      return decide(staff, PERMISSIONS.void);
    case 'refund':
      return decide(staff, PERMISSIONS.refund, () => within(staff, LIMITS.refund, value));
    case 'pay_out':
    case 'pay_in':
      return decide(staff, PERMISSIONS.cash);
    default:
      throw new Error(`Unknown action [${action}]`);
  }
}

function decide(staff, permission, limit) {
  if (!can(staff, permission)) return { allowed: false, permission, reason: 'permission' };
  if (limit && !limit()) return { allowed: false, permission, reason: 'limit' };
  return { allowed: true, permission, reason: null };
}

/** Staff who could approve `action` for `value` (the override picker), never the requester. */
export function approvers(staffList, action, value, requesterId) {
  return staffList.filter((member) => member.id !== requesterId && !member.locked && check(member, action, value).allowed);
}
