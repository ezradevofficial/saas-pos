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
 *
 * `module` names the module that serves the endpoint (default `pos`): a
 * 403 `module_inactive` from another module's endpoint keeps its rows for
 * later without stopping the run or saying the POS is off. `readResults`
 * (body, payloads sent) turns an endpoint's own answer into the results
 * above, for endpoints that answer in another shape.
 */
const kinds = new Map();

export function registerPushKind(kind, { path, bodyKey, batchSize = 50, module = 'pos', readResults = null }) {
  kinds.set(kind, { kind, path, bodyKey, batchSize, module, readResults });
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

/**
 * HR-22, HR-23: clock-ins made on this till (POST hr/till/punches, up to
 * 500 a request). The endpoint answers `{ data: [{ id, status, code?,
 * message? }] }`; a rejected punch can never succeed (`not_at_device`,
 * `period_closed`, ...), so it is kept with its code and never sent again.
 */
export function readHrPunchResults(body) {
  if (!Array.isArray(body?.data)) return null;
  return body.data.map((result) =>
    result?.status === 'rejected'
      ? { id: result.id, status: 'rejected', error: { code: result.code ?? 'rejected', message: result.message ?? null, retryable: false } }
      : result,
  );
}

registerPushKind('hr.punches', { path: 'hr/till/punches', bodyKey: 'punches', batchSize: 100, module: 'hr', readResults: readHrPunchResults });

/**
 * HR-23: wrong clock-in PINs and lockouts counted on this till (POST
 * hr/till/clock-attempts, up to 100 a request). The endpoint answers
 * `{ data: [{ employee_id, recorded }] }` in the order sent, with no report
 * id: each answer is matched to the report sent at its position. A report
 * the server does not record (the employee is not of this company) can
 * never be, so it counts as done either way.
 */
export function readClockAttemptResults(body, payloads = []) {
  if (!Array.isArray(body?.data)) return null;
  return body.data.slice(0, payloads.length).map((result, index) => ({ id: payloads[index].id, status: 'stored', recorded: Boolean(result?.recorded) }));
}

registerPushKind('hr.clock_attempts', { path: 'hr/till/clock-attempts', bodyKey: 'reports', batchSize: 100, module: 'hr', readResults: readClockAttemptResults });
