/**
 * VAN-27: the records the rep's device makes, in the shapes of
 * `POST van-sales/device/sync` (docs/modules/van-sales.md). Amounts are
 * minor units as digit strings, quantities decimal strings, ids UUID v7 made
 * on the device. Optional fields are left out rather than sent empty, so a
 * resend is the same record (idempotent uploads).
 *
 * A stored record keeps its wire fields at the top and the device's own
 * notes under `local` (rev, seq, display names, the server's answer).
 */
const clean = (object) => Object.fromEntries(Object.entries(object).filter(([, value]) => value !== undefined && value !== null && value !== ''));

export const KINDS = ['run', 'visit', 'collection', 'count'];
/** The request body key of each kind. */
export const BODY_KEYS = { run: 'runs', visit: 'visits', collection: 'collections', count: 'counts' };

export function wireRun(run) {
  return clean({ id: run.id, route_id: run.route_id, user_id: run.user_id, started_at: run.started_at });
}

export function wireVisit(visit) {
  return clean({
    id: visit.id,
    run_id: visit.run_id,
    stop_id: visit.stop_id,
    customer_id: visit.customer_id,
    user_id: visit.user_id,
    status: visit.status,
    no_sale_reason: visit.status === 'no_sale' ? visit.no_sale_reason : undefined,
    note: visit.note,
    arrived_at: visit.arrived_at,
    left_at: visit.left_at,
    check_in: visit.check_in ? { lat: visit.check_in.lat, lng: visit.check_in.lng, accuracy_m: visit.check_in.accuracy_m, at: visit.check_in.at } : undefined,
    updated_at: visit.updated_at,
  });
}

export function wireCollection(collection) {
  return clean({
    id: collection.id,
    run_id: collection.run_id,
    visit_id: collection.visit_id,
    customer_id: collection.customer_id,
    user_id: collection.user_id,
    payment_method_id: collection.payment_method_id,
    currency: collection.currency,
    amount_minor: String(collection.amount_minor),
    provider_reference: collection.provider_reference,
    receipt_seq: collection.receipt_seq,
    receipt_number: collection.receipt_number,
    number_range_id: collection.number_range_id,
    collected_at: collection.collected_at,
  });
}

export function wireCount(count) {
  return clean({
    id: count.id,
    run_id: count.run_id,
    user_id: count.user_id,
    submitted_at: count.submitted_at,
    note: count.note,
    lines: count.lines.map((line) => ({ item_id: line.item_id, returned_qty: String(line.returned_qty), damaged_qty: String(line.damaged_qty) })),
    tenders: count.tenders.map((tender) => ({ kind: tender.kind, currency: tender.currency, counted_minor: String(tender.counted_minor) })),
  });
}

const WIRE = { run: wireRun, visit: wireVisit, collection: wireCollection, count: wireCount };

/** The record as the server takes it. */
export const wireOf = (record) => WIRE[record.kind](record);

/**
 * Folds the server's per-record result into the record: stored → the state
 * `stored` (the count keeps the reconciliation it was answered with);
 * rejected → parked with its code and field; kept for review, never sent
 * again, never deleted. `result` is `{ id, status: 'stored', ... }` or
 * `{ id, status: 'rejected', code, field? }`.
 */
export function applyResult(record, result) {
  const local = { ...record.local, result };
  if (result.status === 'stored') {
    return { ...record, local: { ...local, error: null, reconciliation: result.reconciliation ?? record.local?.reconciliation ?? null } };
  }
  return { ...record, local: { ...local, error: { code: result.code ?? 'rejected', field: result.field ?? null } } };
}

/** Whether the server settled a result for good (stored or rejected). */
export const isFinal = (result) => result?.status === 'stored' || result?.status === 'rejected';
