import { uuidv7 } from '../lib/random';

/**
 * REST-03..REST-06, REST-10, REST-11, REST-15, REST-17: an open order (a
 * table or a bar tab) on the till, as pure functions over plain records.
 *
 * The record is the one `POST restaurant/orders/sync` takes (docs/modules/
 * restaurant.md "API"); what only the till needs lives under `local` and is
 * never uploaded (see wireOrder):
 *
 * - `rev`: counts local changes; an upload clears "dirty" only when the
 *   order still has the revision that was sent;
 * - `uploaded`: line ids the server has acknowledged (a line the server
 *   holds is voided, never just dropped, or the union would bring it back);
 * - `tickets`, `flags`: what the server last said;
 * - `fired`: the highest course already fired (courses are held until then);
 * - `rejected`: `{order, lines}` error codes from the last upload;
 * - `plan`, `paid`: the split being paid and the parts already rung;
 * - `closed`: null while open, else `paid`, `remote`, `merged` or `cancelled`.
 *
 * Merge rules (REST-17): lines are unioned by id and never lost; header
 * fields (table, waiter, covers, tab name, note, bill request) are
 * last-writer-wins by `updated_at`; sent marks and voids only move forward.
 */
export const HEADER_FIELDS = ['table_id', 'tab_name', 'customer_id', 'waiter_id', 'covers', 'note', 'bill_requested_at', 'status', 'merged_into_id', 'updated_at', 'updated_by'];

const LINE_FIELDS = ['id', 'item_id', 'name', 'qty', 'unit_price_minor', 'tax_code_id', 'tax_rate', 'tax_inclusive', 'course', 'seat', 'note', 'modifiers', 'created_at', 'round', 'sent_at', 'sent_by', 'removed'];

const iso = (now) => (typeof now === 'string' ? now : new Date(now ?? Date.now()).toISOString());
const time = (value) => {
  const at = Date.parse(value ?? '');
  return Number.isFinite(at) ? at : 0;
};
const omitNil = (object) => Object.fromEntries(Object.entries(object).filter(([, value]) => value !== undefined && value !== null));

export function emptyLocal() {
  return { rev: 0, uploaded: [], tickets: [], flags: [], fired: 1, rejected: { order: null, lines: {} }, plan: null, paid: [], closed: null, seen_remote: false, marks: [] };
}

/** A new order, opened on this till (the id is made here, UUID v7). */
export function createOrder({ id = uuidv7(), kind, tableId = null, tableName = null, tabName = null, customerId = null, customerName = null, waiterId, waiterName = null, covers = 1, currency, locationId = null, now, userId, actorProof = null }) {
  const at = iso(now);
  return {
    id,
    kind,
    table_id: kind === 'table' ? tableId : null,
    tab_name: kind === 'tab' ? tabName : null,
    customer_id: customerId,
    waiter_id: waiterId,
    covers: Number(covers) || 1,
    currency,
    opened_at: at,
    updated_at: at,
    updated_by: userId ?? waiterId,
    note: null,
    bill_requested_at: null,
    status: 'open',
    merged_into_id: null,
    lines: [],
    resends: [],
    actor_proof: actorProof,
    local: { ...emptyLocal(), location_id: locationId, table_name: tableName, waiter_name: waiterName, customer_name: customerName },
  };
}

export const isOpen = (order) => order.status === 'open' && !order.local?.closed;
export const isRemoved = (line) => Boolean(line.removed);
export const liveLines = (order) => order.lines.filter((line) => !isRemoved(line));
export const unsentLines = (order) => liveLines(order).filter((line) => !line.sent_at);
export const sentLines = (order) => liveLines(order).filter((line) => line.sent_at);
export const rounds = (order) => [...new Set(order.lines.map((line) => line.round).filter(Boolean))].sort((a, b) => a - b);

const withLocal = (order, patch) => ({ ...order, local: { ...order.local, ...patch } });

/** Changing the header (table, waiter, covers, tab name, bill request) is a last-writer-wins write. */
export function touch(order, patch, { now, userId }) {
  return { ...order, ...patch, updated_at: iso(now), updated_by: userId ?? order.updated_by };
}

/**
 * REST-06, REST-07: one line appended. `item` is what the till priced (unit
 * price and tax at that moment); `modifiers` are chosen as {id, name,
 * price_minor}.
 */
export function addLine(order, { id = uuidv7(), itemId, name, qty = '1', unitPriceMinor, taxCodeId = null, taxRate = null, taxInclusive = false, course = 1, seat = null, note = null, modifiers = [] }, { now }) {
  const line = {
    id,
    item_id: itemId,
    name,
    qty: String(qty),
    unit_price_minor: String(unitPriceMinor),
    tax_code_id: taxCodeId,
    tax_rate: taxRate === null || taxRate === undefined ? null : String(taxRate),
    tax_inclusive: Boolean(taxInclusive),
    course: Math.min(9, Math.max(1, Number(course) || 1)),
    seat: seat === null || seat === undefined || seat === '' ? null : Number(seat),
    note: note ? String(note).trim() || null : null,
    modifiers: modifiers.map((modifier) => ({ id: modifier.id, name: modifier.name, price_minor: String(modifier.price_minor ?? 0) })),
    created_at: iso(now),
  };
  return { ...order, lines: [...order.lines, line] };
}

/** REST-06: a line is edited until it is sent. Returns the order unchanged when the line is sent, void or unknown. */
export function editLine(order, lineId, patch) {
  const line = order.lines.find((candidate) => candidate.id === lineId);
  if (!line || line.sent_at || isRemoved(line)) return order;
  const allowed = {};
  if (patch.qty !== undefined) allowed.qty = String(patch.qty);
  if (patch.course !== undefined) allowed.course = Math.min(9, Math.max(1, Number(patch.course) || 1));
  if (patch.seat !== undefined) allowed.seat = patch.seat === null || patch.seat === '' ? null : Number(patch.seat);
  if (patch.note !== undefined) allowed.note = patch.note ? String(patch.note).trim() || null : null;
  if (patch.modifiers !== undefined) allowed.modifiers = patch.modifiers.map((modifier) => ({ id: modifier.id, name: modifier.name, price_minor: String(modifier.price_minor ?? 0) }));
  return { ...order, lines: order.lines.map((candidate) => (candidate.id === lineId ? { ...candidate, ...allowed } : candidate)) };
}

/**
 * REST-11: an unsent line is removed freely. One the server already holds
 * stays as a removed line (so the union cannot bring it back); one it never
 * saw is simply dropped.
 */
export function removeUnsentLine(order, lineId, { now, userId }) {
  const line = order.lines.find((candidate) => candidate.id === lineId);
  if (!line || line.sent_at || isRemoved(line)) return order;
  if (!order.local.uploaded.includes(lineId)) return { ...order, lines: order.lines.filter((candidate) => candidate.id !== lineId) };
  return { ...order, lines: order.lines.map((candidate) => (candidate.id === lineId ? { ...candidate, removed: { at: iso(now), by: userId, reason: 'removed_before_send' } } : candidate)) };
}

/**
 * REST-09: send lines to the stations: the held lines of `courses`
 * (default: the courses already fired), or the given `lineIds`. They share
 * the next round number. Returns { order, lines, round }.
 */
export function sendLines(order, { courses = null, lineIds = null }, { now, userId }) {
  const fired = order.local.fired ?? 1;
  const chosen = unsentLines(order).filter((line) => (lineIds ? lineIds.includes(line.id) : courses ? courses.includes(line.course) : line.course <= fired));
  if (!chosen.length) return { order, lines: [], round: null };
  const round = Math.max(0, ...order.lines.map((line) => line.round ?? 0)) + 1;
  const at = iso(now);
  const ids = new Set(chosen.map((line) => line.id));
  const highest = Math.max(fired, ...chosen.map((line) => line.course));
  const next = {
    ...order,
    lines: order.lines.map((line) => (ids.has(line.id) ? { ...line, round, sent_at: at, sent_by: userId } : line)),
    local: { ...order.local, fired: highest },
  };
  return { order: next, lines: next.lines.filter((line) => ids.has(line.id)), round };
}

/** The next course that still holds unsent lines, after those already fired, or null. */
export function nextCourse(order) {
  const fired = order.local.fired ?? 1;
  const courses = unsentLines(order).map((line) => line.course);
  const later = courses.filter((course) => course > fired);
  return later.length ? Math.min(...later) : null;
}

/** REST-09: fire the next held course (sends its lines). */
export function fireNextCourse(order, ctx) {
  const course = nextCourse(order);
  if (course === null) return { order, lines: [], round: null, course: null };
  return { ...sendLines(order, { courses: [course] }, ctx), course };
}

/** REST-10: send sent lines again (a lost print). Idempotent by the resend's id. */
export function resend(order, { id = uuidv7(), lineIds }, { now, userId }) {
  const known = new Set(sentLines(order).map((line) => line.id));
  const ids = lineIds.filter((lineId) => known.has(lineId));
  if (!ids.length || order.resends.some((entry) => entry.id === id)) return { order, resend: null };
  const entry = { id, line_ids: ids, at: iso(now), by: userId };
  return { order: { ...order, resends: [...order.resends, entry] }, resend: entry };
}

/**
 * REST-11: a sent line voided, with a reason. The line stays on the order
 * marked removed; `approval` carries the manager's override or the person's
 * own proof (the server flags unproven ones for review).
 */
export function voidSentLine(order, lineId, { reason, override = null, actorProof = null }, { now, userId }) {
  const line = order.lines.find((candidate) => candidate.id === lineId);
  if (!line || !line.sent_at || isRemoved(line) || !String(reason ?? '').trim()) return order;
  const removed = omitNil({ at: iso(now), by: userId, reason: String(reason).trim(), override: override ?? undefined, actor_proof: actorProof ?? undefined });
  return { ...order, lines: order.lines.map((candidate) => (candidate.id === lineId ? { ...candidate, removed } : candidate)) };
}

/** REST-05: another waiter takes the table or tab. */
export function transfer(order, { waiterId, waiterName = null }, ctx) {
  const moved = touch(order, { waiter_id: waiterId }, ctx);
  return withLocal(moved, { waiter_name: waiterName });
}

/** The bill was asked for: the table shows `bill`. */
export function requestBill(order, ctx) {
  return touch(order, { bill_requested_at: iso(ctx.now) }, ctx);
}

export function setCovers(order, covers, ctx) {
  return touch(order, { covers: Math.max(1, Number(covers) || 1) }, ctx);
}

/**
 * REST-15: every live line of `source` moves to `target`; `source` is
 * marked merged into it. The server applies the merge when it sees
 * `merged_into_id` (idempotent); the target carries the lines too so the
 * table shows them at once. Returns { source, target }.
 */
export function mergeInto(source, target, ctx) {
  const known = new Set(target.lines.map((line) => line.id));
  const moving = liveLines(source).filter((line) => !known.has(line.id));
  const nextTarget = { ...target, lines: [...target.lines, ...moving], local: { ...target.local, fired: Math.max(target.local.fired ?? 1, source.local.fired ?? 1) } };
  const nextSource = { ...touch(source, { merged_into_id: target.id }, ctx), local: { ...source.local, closed: 'merged' } };
  return { source: nextSource, target: nextTarget };
}

/** REST-17: lines the server refused (the order was closed meanwhile) and so need ringing again. */
export function rejectedLines(order) {
  const { order: orderError, lines } = order.local.rejected ?? {};
  return order.lines.filter((line) => !isRemoved(line) && (lines?.[line.id] || (orderError && !order.local.uploaded.includes(line.id))));
}

/**
 * REST-17: the lines of an order closed meanwhile are rung as a new order
 * (new ids; the old order keeps its copy). Returns { old, order }.
 */
export function ringAsNew(order, { id = uuidv7(), now, userId }) {
  const lines = rejectedLines(order);
  const fresh = {
    ...createOrder({ id, kind: order.kind, tableId: order.table_id, tableName: order.local.table_name, tabName: order.tab_name, customerId: order.customer_id, customerName: order.local.customer_name, waiterId: order.waiter_id, waiterName: order.local.waiter_name, covers: order.covers, currency: order.currency, locationId: order.local.location_id, now, userId, actorProof: order.actor_proof }),
    lines: lines.map((line) => ({ ...line, id: uuidv7(), created_at: iso(now) })),
  };
  const old = withLocal(order, { rejected: { order: null, lines: {} }, closed: order.local.closed ?? 'remote', rehomed: id });
  return { old, order: fresh };
}

// -- The wire ------------------------------------------------------------

/** The order as `POST restaurant/orders/sync` takes it: no local fields, no tickets. */
export function wireOrder(order) {
  return omitNil({
    id: order.id,
    kind: order.kind,
    table_id: order.table_id,
    tab_name: order.tab_name,
    customer_id: order.customer_id,
    waiter_id: order.waiter_id,
    covers: order.covers,
    currency: order.currency,
    opened_at: order.opened_at,
    updated_at: order.updated_at,
    updated_by: order.updated_by,
    note: order.note,
    bill_requested_at: order.bill_requested_at,
    status: order.status,
    merged_into_id: order.merged_into_id,
    lines: order.lines.map((line) => omitNil(Object.fromEntries(LINE_FIELDS.map((field) => [field, field === 'modifiers' ? (line.modifiers ?? []) : line[field]])))),
    resends: order.resends,
    actor_proof: order.actor_proof,
  });
}

function removedOf(line) {
  if (line.removed) return line.removed;
  // The order resource shows a void line as `void: {at, by, reason, after_send}`.
  if (line.void) return { at: line.void.at, by: line.void.by, reason: line.void.reason };
  return null;
}

/** Union of one line from both sides: sent marks and voids only move forward. */
function mergeLine(local, server) {
  const serverSent = Boolean(server.sent_at) && !local.sent_at;
  const base = serverSent ? { ...local, ...Object.fromEntries(LINE_FIELDS.filter((field) => server[field] !== undefined).map((field) => [field, server[field]])) } : local;
  const removed = local.removed ?? removedOf(server);
  const round = Math.max(local.round ?? 0, server.round ?? 0) || undefined;
  const merged = { ...base, ...(round ? { round } : {}), ...(local.sent_at || server.sent_at ? { sent_at: local.sent_at ?? server.sent_at, sent_by: local.sent_by ?? server.sent_by } : {}), ...(removed ? { removed } : {}) };
  // REST-11: a void the server could not prove waits for review. The line stays as sold and on the bill
  // until the server applies it (the line comes back voided) or rejects it (the pending mark goes).
  if (server.void_pending !== undefined) {
    if (server.void_pending && !removed) merged.void_pending = server.void_pending;
    else delete merged.void_pending;
  }
  if (removed) delete merged.void_pending;
  if (server.ticket_status !== undefined) merged.ticket_status = server.ticket_status;
  else if (local.ticket_status !== undefined) merged.ticket_status = local.ticket_status;
  return merged;
}

function mergeLines(localLines, serverLines) {
  const serverById = new Map((serverLines ?? []).map((line) => [line.id, line]));
  const seen = new Set();
  const merged = localLines.map((line) => {
    seen.add(line.id);
    const other = serverById.get(line.id);
    return other ? mergeLine(line, other) : line;
  });
  for (const line of serverLines ?? []) {
    if (seen.has(line.id)) continue;
    const removed = removedOf(line);
    const { void: _void, ...rest } = line;
    merged.push({ ...rest, ...(removed ? { removed } : {}) });
  }
  return merged;
}

const mergeBy = (a, b) => {
  const seen = new Set(a.map((entry) => entry.id));
  return [...a, ...(b ?? []).filter((entry) => !seen.has(entry.id))];
};

/**
 * REST-17: the server's copy of an order folded into the till's. Lines are
 * unioned by id; the header of the later `updated_at` wins (a tie keeps the
 * till's); tickets and flags are the server's; a cancel or merge the server
 * made stands. Never drops a local line.
 */
export function mergeServer(local, server) {
  const serverWins = time(server.updated_at) > time(local.updated_at);
  const header = serverWins ? Object.fromEntries(HEADER_FIELDS.filter((field) => server[field] !== undefined).map((field) => [field, server[field]])) : {};
  let closed = local.local.closed;
  const status = header.status ?? local.status;
  if (!closed && (server.status === 'cancelled' || server.merged_into_id)) {
    // Cancels and merges are final wherever they were made.
    header.status = server.status ?? status;
    header.merged_into_id = server.merged_into_id ?? header.merged_into_id ?? local.merged_into_id;
  }
  const finalStatus = header.status ?? status;
  if (!closed && finalStatus === 'cancelled') closed = 'cancelled';
  if (!closed && (header.merged_into_id ?? local.merged_into_id)) closed = 'merged';
  const tickets = (server.tickets ?? local.local.tickets).map((ticket) => {
    const mark = (local.local.marks ?? []).find((entry) => entry.ticket_id === ticket.id);
    return mark ? { ...ticket, status: mark.status } : ticket;
  });
  return {
    ...local,
    ...header,
    lines: mergeLines(local.lines, server.lines),
    resends: mergeBy(local.resends ?? [], server.resends),
    local: {
      ...local.local,
      tickets,
      flags: [...new Set([...(local.local.flags ?? []), ...(server.flags ?? [])])],
      seen_remote: true,
      closed,
      location_id: local.local.location_id ?? server.location_id ?? null,
      table_name: server.table_name ?? local.local.table_name ?? null,
      waiter_name: server.waiter_name ?? local.local.waiter_name ?? null,
      customer_name: server.customer_name ?? local.local.customer_name ?? null,
    },
  };
}

/** A server order the till has never seen, as a local record. */
export function fromServer(server) {
  const base = {
    id: server.id,
    kind: server.kind,
    table_id: server.table_id ?? null,
    tab_name: server.tab_name ?? null,
    customer_id: server.customer_id ?? null,
    waiter_id: server.waiter_id,
    covers: server.covers ?? 1,
    currency: server.currency,
    opened_at: server.opened_at,
    updated_at: server.updated_at ?? server.opened_at,
    updated_by: server.updated_by ?? server.waiter_id,
    note: server.note ?? null,
    bill_requested_at: server.bill_requested_at ?? null,
    status: server.status ?? 'open',
    merged_into_id: server.merged_into_id ?? null,
    lines: [],
    resends: [],
    actor_proof: server.actor_proof ?? null,
    local: emptyLocal(),
  };
  return mergeServer(base, server);
}

/** Whether the till's copy holds something the server's copy lacks (so it must be uploaded). */
export function differsFrom(order, server) {
  if (time(order.updated_at) > time(server.updated_at)) return true;
  const serverLines = new Map((server.lines ?? []).map((line) => [line.id, line]));
  return order.lines.some((line) => {
    const other = serverLines.get(line.id);
    if (!other) return true;
    if (line.sent_at && !other.sent_at) return true;
    if (line.removed && !removedOf(other)) return true;
    return false;
  }) || (order.resends ?? []).some((entry) => !(server.resends ?? []).some((other) => other.id === entry.id));
}

/**
 * The result of uploading an order (`{id, status: stored, rev, flags,
 * lines: [{id, status, error?}]}` or `{id, status: rejected, error}`),
 * folded into the till's copy. Returns { order, retry }: `retry` is true
 * when the server could not decide now.
 */
export function applyResult(order, result) {
  const errorCode = (error) => (typeof error === 'string' ? error : (error?.code ?? 'rejected'));
  if (result.status !== 'stored') {
    if (result.error?.retryable) return { order, retry: true };
    return { order: withLocal(order, { rejected: { order: errorCode(result.error), lines: {} } }), retry: false };
  }
  const uploaded = new Set(order.local.uploaded);
  const lines = {};
  const pending = new Set();
  for (const entry of result.lines ?? []) {
    if (entry.status === 'stored') {
      uploaded.add(entry.id);
      if (entry.void === 'pending') pending.add(entry.id);
    } else lines[entry.id] = errorCode(entry.error);
  }
  // A stored order with no line results stored every line it was sent.
  if (!result.lines) for (const line of order.lines) uploaded.add(line.id);
  let next = withLocal(order, {
    uploaded: [...uploaded],
    rejected: { order: null, lines },
    flags: [...new Set([...(order.local.flags ?? []), ...(result.flags ?? [])])],
    server_rev: result.rev ?? order.local.server_rev ?? null,
    seen_remote: true,
  });
  // A void waiting for review: the line is not voided on the till, it shows "pending" and counts in the bill.
  if (pending.size) {
    next = {
      ...next,
      lines: next.lines.map((line) => (pending.has(line.id) && line.removed ? { ...omitKey(line, 'removed'), void_pending: { at: line.removed.at, by: line.removed.by, reason: line.removed.reason } } : line)),
    };
  }
  if (result.order) next = mergeServer(next, result.order);
  return { order: next, retry: false };
}

const omitKey = (object, key) => Object.fromEntries(Object.entries(object).filter(([name]) => name !== key));

/** Whether a sent line's void waits for a reviewer (REST-11). */
export const isVoidPending = (line) => Boolean(line.void_pending) && !line.removed;

/** A line as the Bill takes it: removed lines are void. */
export function billLines(order) {
  return order.lines.map((line) => ({ ...line, void: isRemoved(line) }));
}

/** The status a table shows: derived from its open orders, else its manual status (REST-02). */
export function tableStatus(manual, openOrders) {
  if (openOrders.some((order) => order.bill_requested_at)) return 'bill';
  if (openOrders.length) return 'occupied';
  return manual || 'free';
}
