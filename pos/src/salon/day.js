import { localDate } from '../sync/prices';

/**
 * SAL-12, SAL-13, SAL-29: the salon's day as the till shows it, computed on the device from the pulled schedule and
 * what the till made since (no connection needed). The server decides again on upload.
 *
 * The pulled schedule is the base. The till's own records are laid over it, forward only (a step never moves an
 * appointment back): appointments made at the till, check-in / start / finish / no-show / cancel steps, and marks that
 * a sale paid an appointment or its fee. A step the server refused (`local.error`) has no effect.
 */
export const STATUSES = ['booked', 'checked_in', 'in_service', 'completed', 'paid', 'cancelled', 'no_show'];
const RANK = { booked: 0, checked_in: 1, in_service: 2, completed: 3, paid: 4 };
const TERMINAL = new Set(['cancelled', 'no_show']);
/** Statuses whose lines hold a staff member's time. */
export const HOLDING = new Set(['booked', 'checked_in', 'in_service', 'completed', 'paid']);

/** A dot tone for each status (status is a dot and a word, never a filled pill). */
export const STATUS_TONE = { booked: 'neutral', checked_in: 'info', in_service: 'info', completed: 'warning', paid: 'success', cancelled: 'neutral', no_show: 'danger' };

const live = (record) => !record.local?.error;

function advance(view, to) {
  if (TERMINAL.has(view.status)) return view.status;
  if (to in RANK) return RANK[to] > RANK[view.status] ? to : view.status;
  return view.status;
}

/** The appointment a record made at the till stands for until the server's own row is pulled. */
function viewOfLocal(record) {
  const lines = record.local.lines ?? [];
  const ends = lines.reduce((max, line) => (line.ends_at > max ? line.ends_at : max), record.starts_at);
  return {
    id: record.id,
    number: null,
    status: record.source === 'walk_in' ? 'checked_in' : 'booked',
    source: record.source ?? 'device',
    client_id: record.client_id ?? null,
    client_name: record.local.client_name ?? record.guest_name ?? '',
    has_alerts: false,
    starts_at: record.starts_at,
    ends_at: ends,
    total_minor: String(lines.reduce((sum, line) => sum + BigInt(line.price_minor ?? 0), 0n)),
    currency: record.local.currency ?? null,
    fee_minor: '0',
    fee_status: 'none',
    deposit_required_minor: '0',
    deposit_balance_minor: '0',
    sale_id: null,
    lines,
    local: { made_here: true },
  };
}

/**
 * Every appointment the till knows: the pulled ones and those made here (not pulled yet), with the steps and marks
 * laid over. `seated` are queue records (seat) whose appointment the server makes on upload.
 */
export function appointmentViews({ schedule = [], records = [] }) {
  const byId = new Map();
  for (const row of schedule) byId.set(row.id, { ...row, local: {} });
  const ordered = [...records].sort((a, b) => (a.local?.seq ?? 0) - (b.local?.seq ?? 0));

  for (const record of ordered) {
    if (!live(record)) continue;
    if (record.kind === 'appointment' && !byId.has(record.id)) byId.set(record.id, viewOfLocal(record));
    // A seated queue entry stands for the walk-in appointment the server makes from it.
    if (record.kind === 'queue' && record.action === 'seat' && record.appointment_id && !byId.has(record.appointment_id)) {
      byId.set(record.appointment_id, viewOfLocal({ ...record, id: record.appointment_id, source: 'walk_in', starts_at: record.at, local: record.local }));
    }
  }

  for (const record of ordered) {
    if (!live(record)) continue;
    const view = byId.get(record.appointment_id);
    if (!view) continue;
    if (record.kind === 'event') {
      const next = { check_in: 'checked_in', start: 'in_service', finish: 'completed', no_show: 'no_show', cancel: 'cancelled' }[record.type];
      const open = ['booked', 'checked_in'].includes(view.status);
      if (['no_show', 'cancel'].includes(record.type)) {
        if (open && (record.type === 'cancel' || view.status === 'booked')) byId.set(view.id, { ...view, status: next, local: { ...view.local, stepped: true } });
      } else if (next) {
        byId.set(view.id, { ...view, status: advance(view, next), local: { ...view.local, stepped: true } });
      }
    } else if (record.kind === 'mark') {
      if (record.fact === 'paid') byId.set(view.id, { ...view, status: advance(view, 'paid'), sale_id: record.sale_id ?? view.sale_id, local: { ...view.local, paid_here: true } });
      if (record.fact === 'fee_paid') byId.set(view.id, { ...view, fee_status: 'paid', local: { ...view.local, fee_paid_here: true } });
    }
  }

  const pendingIds = new Set(ordered.filter((record) => record.local?.rev !== undefined && record.local?.result == null && live(record) && record.kind !== 'mark').map((record) => record.appointment_id ?? record.id));
  return [...byId.values()].map((view) => ({ ...view, local: { ...view.local, pending: pendingIds.has(view.id) } })).sort((a, b) => a.starts_at.localeCompare(b.starts_at) || a.id.localeCompare(b.id));
}

/** The waiting queue: the pulled entries with the till's joins added and its leaves and seats taken out. */
export function queueViews({ queue = [], records = [] }) {
  const entries = new Map(queue.map((entry) => [entry.id, { ...entry, local: {} }]));
  for (const record of [...records].filter((candidate) => candidate.kind === 'queue' && live(candidate)).sort((a, b) => a.local.seq - b.local.seq)) {
    if (record.action === 'join' && !entries.has(record.id)) {
      entries.set(record.id, {
        id: record.id,
        guest_name: record.guest_name ?? record.local.client_name ?? '',
        client_id: record.client_id ?? null,
        service_ids: record.service_ids ?? [],
        staff_id: record.staff_id ?? null,
        joined_at: record.at,
        wait_minutes: null,
        local: { made_here: true },
      });
    } else if (['leave', 'seat'].includes(record.action)) {
      entries.delete(record.entry_id);
    }
  }
  return [...entries.values()].sort((a, b) => String(a.joined_at).localeCompare(String(b.joined_at)) || a.id.localeCompare(b.id)).map((entry, index) => ({ ...entry, position: index + 1 }));
}

/** The local day of an appointment (the branch's time zone), `YYYY-MM-DD`. */
export const dayOf = (view, timeZone) => localDate(Date.parse(view.starts_at), timeZone);

/** The appointments of one local day, optionally of one staff member's lines, with their lines in order. */
export function dayList(views, { date, timeZone, staffId = null }) {
  return views.filter((view) => dayOf(view, timeZone) === date && (!staffId || view.lines.some((line) => line.staff_id === staffId)));
}

/** The seven local days from `from` that the schedule covers: `[{ date }]`. */
export function weekDays(from, count = 8) {
  const [y, m, d] = from.split('-').map(Number);
  return Array.from({ length: count }, (_, i) => new Date(Date.UTC(y, m - 1, d + i)).toISOString().slice(0, 10));
}

const overlaps = (aStart, aEnd, bStart, bEnd) => aStart < bEnd && aEnd > bStart;
const spanOf = (pair) => [Date.parse(pair[0]), Date.parse(pair[1])];

/** Whether a staff row (pulled, with `work` and `off` spans) can take [start, end): on duty, not off, not booked. */
export function staffFree(staff, startMs, endMs, views, { ignore = null } = {}) {
  const onDuty = (staff.work ?? []).some((pair) => {
    const [from, to] = spanOf(pair);
    return startMs >= from && endMs <= to;
  });
  if (!onDuty) return 'outside_hours';
  if ((staff.off ?? []).some((pair) => overlaps(startMs, endMs, ...spanOf(pair)))) return 'staff_off';
  const busy = views.some(
    (view) => view.id !== ignore && HOLDING.has(view.status) && view.lines.some((line) => line.staff_id === staff.id && overlaps(startMs, endMs, Date.parse(line.starts_at), Date.parse(line.ends_at))),
  );
  return busy ? 'slot_taken' : null;
}

/**
 * Plans the lines of a walk-in on the device from the pulled data: one service after another from `startMs`, each with
 * the staff member named or the first qualified one who is free. Returns `{ lines }` or `{ error: code }`. This is the
 * till's check for the screen only; the server checks again under its row locks (`slot_taken` comes back as a result).
 */
export function planWalkIn({ wants, startMs, staff, services, views }) {
  const staffById = new Map(staff.map((row) => [row.id, row]));
  const lines = [];
  let cursor = startMs;
  for (const want of wants) {
    const service = services.find((candidate) => candidate.id === want.service_id);
    if (!service) return { error: 'service_unknown' };
    const end = cursor + Number(service.duration_minutes) * 60000;
    const candidates = want.staff_id
      ? [staffById.get(want.staff_id)].filter(Boolean)
      : staff.filter((row) => row.kind === 'staff' && (!service.staff_ids?.length || service.staff_ids.includes(row.id)));
    if (want.staff_id && service.staff_ids?.length && !service.staff_ids.includes(want.staff_id)) return { error: 'staff_unqualified' };
    let reason = candidates.length ? null : 'no_staff';
    let chosen = null;
    for (const candidate of candidates) {
      const problem = staffFree(candidate, cursor, end, [...views, ...laid(lines)]);
      if (!problem) {
        chosen = candidate;
        break;
      }
      reason ??= problem;
    }
    if (!chosen) return { error: reason ?? 'no_staff' };
    lines.push({
      id: want.id,
      service_id: service.id,
      item_id: service.item_id,
      name: service.name,
      kind: service.kind,
      staff_id: chosen.id,
      staff_name: chosen.name,
      resource_id: want.resource_id ?? null,
      starts_at: new Date(cursor).toISOString(),
      ends_at: new Date(end).toISOString(),
      price_minor: service.price_minor ?? '0',
    });
    // The next service starts when this one ends; the same person first cleans up.
    cursor = end + (candidatesSame(lines) ? Number(service.buffer_minutes ?? 0) * 60000 : 0);
  }
  return { lines };
}

// The lines planned so far, as an appointment that holds time, so two lines never share a person.
const laid = (lines) => (lines.length ? [{ id: '__plan', status: 'booked', lines }] : []);
const candidatesSame = (lines) => lines.length > 0;

/** Counts per status for the day header. */
export function daySummary(list) {
  const counts = Object.fromEntries(STATUSES.map((status) => [status, 0]));
  for (const view of list) counts[view.status] += 1;
  return { total: list.length, ...counts };
}
