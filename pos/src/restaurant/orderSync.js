import { applyResult, wireOrder } from './orderModel';

/**
 * REST-17, NFR-04: uploading the till's open orders, and folding the
 * server's back in.
 *
 * - push(call): every dirty order goes to `POST restaurant/orders/sync` in
 *   batches of at most 25. The server stores by id and answers one result
 *   per order (and per line), the same each time, so a resend after a lost
 *   answer is safe. A result is folded in with the order's revision as it
 *   was sent: an order changed since stays dirty. A rejection that can
 *   never succeed (`order_closed`, `table_unknown`, `waiter_unknown`) is
 *   kept on the order with its lines (the waiter rings them as a new
 *   order); only a retryable answer or a failed request leaves it to try
 *   again. Nothing is deleted from the till.
 * - ticket marks (a waiter's "served") wait on the order and go up through
 *   `POST restaurant/device/tickets/{id}/status`.
 * - reconcile(): after a pull, merges `restaurant_orders` into the till's
 *   orders (orderStore.reconcile).
 *
 * `call(method, path, { body })` is the sync engine's request function
 * (it records the network state and stops on lost access).
 */
export const BATCH = 25;

export function createOrderSync({ orders, log = () => {} }) {
  /** Upload ticket marks of this order; returns the order's marks still to send. */
  async function sendMarks(order, call, userId) {
    const left = [];
    for (const mark of order.local.marks ?? []) {
      const response = await call('POST', `restaurant/device/tickets/${mark.ticket_id}/status`, { body: { status: mark.status, user_id: mark.user_id ?? userId } });
      // A ticket the server will never take (unknown, status not allowed) is dropped; a failed request is retried.
      if (response.status >= 500 || response.status === 429 || response.status === 408 || response.status === 403) left.push(mark);
    }
    return left;
  }

  return {
    pendingCount: () => orders.dirtyCount(),

    async push(call) {
      const summary = { sent: 0, stored: 0, rejected: 0, retried: 0, stopped: null };
      const dirty = await orders.dirty();

      for (let i = 0; i < dirty.length; i += BATCH) {
        const batch = dirty.slice(i, i + BATCH);
        const sent = new Map(batch.map((order) => [order.id, order.local.rev]));
        // Ticket marks first: they do not depend on the order reaching the server.
        for (const order of batch) {
          if (!(order.local.marks ?? []).length) continue;
          const left = await sendMarks(order, call, order.updated_by);
          if (left.length !== order.local.marks.length) {
            // Still dirty: the order itself is uploaded right after.
            await orders.update(order.id, (current) => ({ ...current, local: { ...current.local, marks: (current.local.marks ?? []).filter((mark) => left.some((kept) => kept.ticket_id === mark.ticket_id && kept.status === mark.status)) } }), { settle: () => true });
          }
        }
        const response = await call('POST', 'restaurant/orders/sync', { body: { orders: batch.map(wireOrder) } });
        summary.sent += batch.length;
        const results = Array.isArray(response.body?.results) ? response.body.results : Array.isArray(response.body?.data) ? response.body.data : null;
        if (!((response.status === 200 || response.status === 422) && results)) {
          // 403 module_inactive, 5xx, 429, anything unexpected: nothing proves the orders wrong; try later.
          summary.retried += batch.length;
          summary.stopped = response.body?.code ?? `http_${response.status}`;
          log('restaurant: order upload not accepted', response.status);
          break;
        }
        const byId = new Map(results.map((result) => [String(result.id), result]));
        for (const order of batch) {
          const result = byId.get(order.id);
          if (!result) {
            summary.retried += 1;
            continue;
          }
          let retry = false;
          const saved = await orders.update(
            order.id,
            (current) => {
              const applied = applyResult(current, result);
              retry = applied.retry;
              return applied.order;
            },
            // Clean only when the order is as it was when sent, nothing waits on it, and the server decided.
            { settle: (next) => retry || next.local.rev !== sent.get(order.id) || (next.local.marks ?? []).length > 0 },
          );
          if (!saved) continue;
          if (retry) summary.retried += 1;
          else if (result.status === 'stored') summary.stored += 1;
          else summary.rejected += 1;
        }
      }
      return summary;
    },

    /** Folds the pulled server orders into the till's. */
    async reconcile() {
      return orders.reconcile(await orders.serverOrders());
    },
  };
}
