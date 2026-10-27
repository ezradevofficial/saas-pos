/**
 * SAL-29: the records the salon till makes, in the shapes of `POST salon/device/sync` (docs/modules/salon.md).
 * Ids are UUID v7 made on the device; optional fields are left out rather than sent empty, so a resend is the
 * same record (idempotent uploads). Every record carries the person (`user_id`) and the sign-in proof
 * (`actor_proof`, AUTH-07): the server checks them again.
 *
 * A stored record keeps its wire fields at the top and the device's own notes under `local` (rev, seq, the
 * names shown, the server's answer). Kinds: `appointment`, `event` (check_in, start, finish, no_show, cancel)
 * and `queue` (join, seat, leave) go up; `mark` records a local fact only (a sale that paid an appointment).
 */
const clean = (object) => Object.fromEntries(Object.entries(object).filter(([, value]) => value !== undefined && value !== null && value !== ''));

/** The request body key of each kind that is uploaded. */
export const BODY_KEYS = { appointment: 'appointments', event: 'events', queue: 'queue' };

export const wireAppointment = (record) =>
  clean({
    id: record.id,
    user_id: record.user_id,
    actor_proof: record.actor_proof,
    salon_client_id: record.client_id,
    guest_name: record.guest_name,
    guest_phone: record.guest_phone,
    notes: record.notes,
    source: record.source,
    starts_at: record.starts_at,
    lines: record.lines.map((line) => clean({ service_id: line.service_id, staff_id: line.staff_id, resource_id: line.resource_id })),
  });

export const wireEvent = (record) =>
  clean({ id: record.id, appointment_id: record.appointment_id, user_id: record.user_id, actor_proof: record.actor_proof, type: record.type, at: record.at, reason: record.reason });

export const wireQueue = (record) =>
  clean({
    id: record.id,
    user_id: record.user_id,
    actor_proof: record.actor_proof,
    action: record.action,
    guest_name: record.guest_name,
    guest_phone: record.guest_phone,
    salon_client_id: record.client_id,
    service_ids: record.service_ids?.length ? record.service_ids : undefined,
    staff_id: record.staff_id,
    resource_id: record.resource_id,
    entry_id: record.entry_id,
    appointment_id: record.appointment_id,
    at: record.at,
  });

const WIRE = { appointment: wireAppointment, event: wireEvent, queue: wireQueue };

/** The record as the server takes it. */
export const wireOf = (record) => WIRE[record.kind](record);

/**
 * Folds the server's per-record result into the record: stored → kept with the answer; rejected → parked with
 * its code and field, kept for review, never sent again, never deleted.
 */
export function applyResult(record, result) {
  const local = { ...record.local, result };
  if (result.status === 'stored') return { ...record, local: { ...local, error: null } };
  return { ...record, local: { ...local, error: { code: result.code ?? 'rejected', field: result.field ?? null } } };
}

/** Whether the server settled a result for good (stored or rejected). */
export const isFinal = (result) => result?.status === 'stored' || result?.status === 'rejected';
