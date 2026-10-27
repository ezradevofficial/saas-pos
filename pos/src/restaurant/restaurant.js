import { addLine, createOrder, editLine as editOrderLine, fireNextCourse, mergeInto, removeUnsentLine, requestBill as requestOrderBill, resend as resendLines, ringAsNew as ringOrderAsNew, sendLines, setCovers as setOrderCovers, transfer as transferOrder, voidSentLine } from './orderModel';
import { priceItem } from './menu';
import { printTickets } from './tickets';

/**
 * REST-03..REST-17: what the waiter does with orders, offline first. Every
 * action saves the order on the device before it returns (orderStore.js);
 * the sync engine uploads it later (orderSync.js). Actions take the person
 * (`user`, with their sign-in proof) and the catalogue as arguments so they
 * run in Jest without React.
 *
 * Resolves of the actions: the saved order, or { order, ... } with what the
 * screen needs (the tickets printed, the round).
 */
export class RestaurantError extends Error {
  constructor(code, extra = {}) {
    super(code);
    this.name = 'RestaurantError';
    this.code = code;
    Object.assign(this, extra);
  }
}

export function createRestaurant({ orders, engine = null, posStore = null, now = () => Date.now() }) {
  const iso = () => new Date(engine?.serverNow ? engine.serverNow() : now()).toISOString();
  const ctx = (user) => ({ now: iso(), userId: user.id });

  // The engine counts dirty orders in `pending`; tell it after every write so the scheduler uploads soon.
  if (engine?.refreshCounts) orders.subscribe(() => engine.refreshCounts().catch(() => {}));

  async function mustGet(id) {
    const order = await orders.get(id);
    if (!order) throw new RestaurantError('order_unknown');
    return order;
  }

  async function printContext(catalogue) {
    return { stations: await orders.stations(), catalogue };
  }

  const service = {
    orders,

    // -- Opening -----------------------------------------------------------

    /** REST-03: the open order on a table (resumed), or a new one with the covers. */
    async openTable({ table, covers = 1, user, actorProof = null, catalogue }) {
      if (table.status === 'blocked') throw new RestaurantError('table_blocked');
      const open = (await orders.open()).filter((order) => order.table_id === table.id);
      if (open.length) return { order: open[0], resumed: true };
      const order = createOrder({
        kind: 'table',
        tableId: table.id,
        tableName: table.name,
        waiterId: user.id,
        waiterName: user.name,
        covers,
        currency: catalogue.saleCurrency,
        locationId: catalogue.settings?.location?.id ?? null,
        actorProof,
        ...ctx(user),
      });
      await orders.put(order);
      return { order, resumed: false };
    },

    /** REST-04: a bar tab with a name or a customer. */
    async openTab({ name = null, customer = null, user, actorProof = null, catalogue }) {
      const label = String(name ?? customer?.name ?? '').trim();
      if (!label) throw new RestaurantError('tab_name_required');
      const order = createOrder({
        kind: 'tab',
        tabName: label,
        customerId: customer?.id ?? null,
        customerName: customer?.name ?? null,
        waiterId: user.id,
        waiterName: user.name,
        covers: 1,
        currency: catalogue.saleCurrency,
        locationId: catalogue.settings?.location?.id ?? null,
        actorProof,
        ...ctx(user),
      });
      await orders.put(order);
      return { order, resumed: false };
    },

    // -- Lines -------------------------------------------------------------

    /** REST-06, REST-07: one line, priced now; resolves { order, line }. */
    async addItem({ orderId, itemId, catalogue, user, qty = '1', course = 1, seat = null, note = null, modifiers = [], lineId }) {
      const priced = priceItem(catalogue, itemId);
      let line = null;
      const order = await orders.update(orderId, (current) => {
        const next = addLine(current, { id: lineId, ...priced, qty, course, seat, note, modifiers }, ctx(user));
        line = next.lines[next.lines.length - 1];
        return next;
      });
      if (!order) throw new RestaurantError('order_unknown');
      return { order, line };
    },

    editLine: ({ orderId, lineId, patch }) => orders.update(orderId, (current) => editOrderLine(current, lineId, patch)),

    removeLine: ({ orderId, lineId, user }) => orders.update(orderId, (current) => removeUnsentLine(current, lineId, ctx(user))),

    setCovers: ({ orderId, covers, user }) => orders.update(orderId, (current) => setOrderCovers(current, covers, ctx(user))),

    // -- Kitchen -----------------------------------------------------------

    /**
     * REST-09: send the held lines of `courses` (default: the courses
     * already fired) as the next round, and print one ticket per station.
     * Resolves { order, lines, round, tickets }.
     */
    async send({ orderId, courses = null, lineIds = null, user, catalogue }) {
      let result = null;
      const order = await orders.update(orderId, (current) => {
        result = sendLines(current, { courses, lineIds }, ctx(user));
        return result.order === current ? current : result.order;
      });
      if (!order) throw new RestaurantError('order_unknown');
      if (!result.lines.length) return { order, lines: [], round: null, tickets: [] };
      const tickets = await printTickets({ order, lines: result.lines, kind: 'order', round: result.round, context: await printContext(catalogue) });
      return { order, lines: result.lines, round: result.round, tickets };
    },

    /** REST-06: fire the next held course (sends its lines). */
    async fireNext({ orderId, user, catalogue }) {
      let result = null;
      const order = await orders.update(orderId, (current) => {
        result = fireNextCourse(current, ctx(user));
        return result.order === current ? current : result.order;
      });
      if (!order) throw new RestaurantError('order_unknown');
      if (!result.lines.length) return { order, lines: [], round: null, course: null, tickets: [] };
      const tickets = await printTickets({ order, lines: result.lines, kind: 'order', round: result.round, context: await printContext(catalogue) });
      return { order, lines: result.lines, round: result.round, course: result.course, tickets };
    },

    /** REST-10: send sent lines again; a new ticket marked resend. The same `id` twice does nothing the second time. */
    async resend({ orderId, lineIds, id, user, catalogue }) {
      let result = null;
      const order = await orders.update(orderId, (current) => {
        result = resendLines(current, { id, lineIds }, ctx(user));
        return result.order === current ? current : result.order;
      });
      if (!order) throw new RestaurantError('order_unknown');
      if (!result.resend) return { order, resend: null, tickets: [] };
      const lines = order.lines.filter((line) => result.resend.line_ids.includes(line.id));
      const tickets = await printTickets({ order, lines, kind: 'resend', round: Math.max(...lines.map((line) => line.round ?? 0)), context: await printContext(catalogue) });
      return { order, resend: result.resend, tickets };
    },

    /**
     * REST-11: a sent line voided with a reason. `approval` is what
     * authorize() resolved ({ override, proof }); the line stays on the
     * order marked removed and the station gets a void ticket.
     */
    async voidLine({ orderId, lineId, reason, approval = null, user, actorProof = null, catalogue }) {
      if (!String(reason ?? '').trim()) throw new RestaurantError('reason_required');
      const before = await mustGet(orderId);
      const line = before.lines.find((candidate) => candidate.id === lineId);
      if (!line?.sent_at) throw new RestaurantError('line_not_sent');
      const order = await orders.update(orderId, (current) => voidSentLine(current, lineId, { reason, override: approval?.override ?? null, actorProof: approval?.override ? null : (approval?.proof ?? actorProof) }, ctx(user)));
      const tickets = await printTickets({ order, lines: [line], kind: 'void', round: line.round, context: await printContext(catalogue) });
      return { order, tickets };
    },

    // -- The table ---------------------------------------------------------

    /** REST-05: another waiter takes the table or tab. */
    transfer: ({ orderId, waiter, user }) => orders.update(orderId, (current) => transferOrder(current, { waiterId: waiter.id, waiterName: waiter.name }, ctx(user))),

    /** REST-15: every live line of the order moves to another open order, which is then the one to pay. */
    async merge({ orderId, intoId, user }) {
      if (orderId === intoId) throw new RestaurantError('merge_same');
      const [source, target] = [await mustGet(orderId), await mustGet(intoId)];
      if (source.local.plan?.parts?.length && (source.local.paid ?? []).length) throw new RestaurantError('merge_part_paid');
      const merged = mergeInto(source, target, ctx(user));
      await orders.updateBoth(orderId, () => merged.source, intoId, () => merged.target);
      return orders.get(intoId);
    },

    requestBill: ({ orderId, user }) => orders.update(orderId, (current) => requestOrderBill(current, ctx(user))),

    /** A table or tab opened by mistake: cancelled when nothing was sent. */
    async cancel({ orderId, user }) {
      const order = await mustGet(orderId);
      if (order.lines.some((line) => line.sent_at) || (order.local.paid ?? []).length) throw new RestaurantError('order_has_sent');
      return orders.update(orderId, (current) => ({ ...current, status: 'cancelled', updated_at: iso(), updated_by: user.id, local: { ...current.local, closed: 'cancelled' } }));
    },

    // -- Tickets -----------------------------------------------------------

    /** REST-09: the waiter marks a ticket served; kept on the order and sent to the server with the next upload. */
    markServed({ orderId, ticketId, user }) {
      return orders.update(orderId, (current) => ({
        ...current,
        local: {
          ...current.local,
          tickets: (current.local.tickets ?? []).map((ticket) => (ticket.id === ticketId ? { ...ticket, status: 'served' } : ticket)),
          marks: [...(current.local.marks ?? []).filter((mark) => mark.ticket_id !== ticketId), { ticket_id: ticketId, status: 'served', user_id: user.id, at: iso() }],
        },
      }));
    },

    // -- Closing -----------------------------------------------------------

    /** The split being paid (closing.js buildPlan); its sale ids stay fixed. */
    savePlan: ({ orderId, plan }) =>
      orders.update(orderId, (current) => {
        // A plan with a part already paid is final.
        if ((current.local.paid ?? []).length) return current;
        return { ...current, local: { ...current.local, plan } };
      }),

    /** REST-16: a part's sale was saved on the till; the order shows it paid, and closes with the final part. */
    async recordPart(sale) {
      const ext = sale?.extensions?.restaurant;
      if (!ext?.order_id) return null;
      return orders.update(ext.order_id, (current) => {
        if ((current.local.paid ?? []).some((entry) => entry.sale_id === sale.id)) return current;
        const paid = [...(current.local.paid ?? []), { sale_id: sale.id, part: ext.part, parts: ext.parts, mode: ext.mode, total_minor: sale.totals?.total_minor ?? '0', at: sale.sold_at }];
        return { ...current, local: { ...current.local, paid, closed: ext.final ? 'paid' : current.local.closed } };
      });
    },

    /** After a restart: parts whose sale was saved but whose order was not marked (a crash between the two). */
    async recoverParts() {
      if (!posStore) return 0;
      let recovered = 0;
      for (const order of await orders.open()) {
        const plan = order.local.plan;
        if (!plan) continue;
        for (const part of plan.parts) {
          if ((order.local.paid ?? []).some((entry) => entry.sale_id === part.saleId)) continue;
          const sale = await posStore.sale(part.saleId);
          if (sale) {
            await service.recordPart(sale);
            recovered += 1;
          }
        }
      }
      return recovered;
    },

    /** REST-17: lines refused because the order was closed meanwhile are rung as a new order. Resolves the new order. */
    async ringAsNew({ orderId, user }) {
      const order = await mustGet(orderId);
      const { old, order: fresh } = ringOrderAsNew(order, ctx(user));
      // The old order is settled: nothing of it uploads again.
      await orders.update(orderId, () => old, { settle: () => false });
      await orders.put(fresh);
      return fresh;
    },
  };

  return service;
}
