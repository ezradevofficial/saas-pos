/**
 * NFR-04, POS-09: what the outbox can upload, and where. Each kind is a
 * batch endpoint answering one result per record, in order:
 * `{ results: [{ id, status: 'stored' | 'rejected', error?: { code,
 * message, field, retryable } }] }` (200 when anything was stored, 422
 * `upload_rejected` with the same results when nothing was).
 *
 * Rows go up in the order they were enqueued, a run of one kind per
 * request, so causes precede effects (a shift before its sales, a sale
 * before its void, the shift's close after its sales).
 */
const kinds = new Map();

export function registerPushKind(kind, { path, bodyKey, batchSize = 50 }) {
  kinds.set(kind, { kind, path, bodyKey, batchSize });
}

export function pushKind(kind) {
  return kinds.get(kind) ?? null;
}

// The POS module's device uploads (api/modules/POS/routes/api.php).
registerPushKind('pos.shifts', { path: 'pos/shifts', bodyKey: 'shifts' });
registerPushKind('pos.sales', { path: 'pos/sales', bodyKey: 'sales' });
registerPushKind('pos.cash_movements', { path: 'pos/cash-movements', bodyKey: 'movements' });
registerPushKind('pos.voids', { path: 'pos/voids', bodyKey: 'voids' });
registerPushKind('pos.refunds', { path: 'pos/refunds', bodyKey: 'refunds' });
