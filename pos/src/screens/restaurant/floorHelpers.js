import { billOf } from '../../restaurant/closing';

/** Status is a small dot and a word (design system): the tone of each table and ticket status. */
export const TABLE_TONE = { free: 'success', occupied: 'info', bill: 'accent', reserved: 'warning', cleaning: 'neutral', blocked: 'danger' };
export const TICKET_TONE = { new: 'warning', preparing: 'info', ready: 'success', served: 'neutral' };

/** The bill of an order with the company's service charge, or null when it cannot be computed. */
export function orderBill(order, settings, catalogue, tipMinor = 0) {
  try {
    return billOf(order, settings, catalogue, tipMinor);
  } catch {
    return null;
  }
}
