import { NetworkError } from '../sync/api';

/**
 * REST-17: a stand-in for the Restaurant device endpoints on top of fakeServer, written on its own
 * (not with the till's merge code) from docs/modules/restaurant.md: orders are stored by id, lines
 * unioned by id, the header of the later `updated_at` wins, sent marks and voids only move forward,
 * a closed order refuses with `order_closed`. A ticket is made per new round. The open orders are
 * offered through the `restaurant_orders` sync entity with their tickets.
 *
 * Tests can lose an answer after the server stored (`loseNextAnswer`), close an order elsewhere
 * (`close`) or add a line from another till (`otherTillLine`).
 */
const time = (value) => Date.parse(value ?? '') || 0;

export function restaurantServer(server) {
  const state = { orders: new Map(), closed: new Set(), requests: [], batches: [], marks: [], loseAnswers: 0, tickets: new Map(), ticketNumber: 0 };

  function publish(order) {
    const tickets = [...state.tickets.values()].filter((ticket) => ticket.order_id === order.id).map(({ order_id: _order, ...ticket }) => ticket);
    const lines = order.lines.map((line) => {
      const ticket = tickets.find((candidate) => candidate.round === line.round);
      return { ...line, ticket_status: ticket?.status ?? null };
    });
    server.upsert('restaurant_orders', { ...order, lines, tickets, flags: order.flags ?? [], table_name: order.table_name, waiter_name: order.waiter_name });
  }

  function ticketsFor(order) {
    const rounds = new Set(order.lines.filter((line) => line.sent_at && line.round).map((line) => line.round));
    for (const round of rounds) {
      const id = `tk-${order.id}-${round}`;
      if (!state.tickets.has(id)) state.tickets.set(id, { id, order_id: order.id, station_id: 's-kitchen', status: 'new', kind: 'order', number: ++state.ticketNumber, round });
    }
  }

  function apply(incoming) {
    if (state.closed.has(incoming.id)) return { id: incoming.id, status: 'rejected', error: { code: 'order_closed', message: 'Closed', retryable: false } };
    if (incoming.table_id === 'table-gone') return { id: incoming.id, status: 'rejected', error: { code: 'table_unknown', message: 'Unknown table', retryable: false } };
    const held = state.orders.get(incoming.id);
    const flags = held?.flags ?? [];
    let next;
    if (!held) {
      next = { ...incoming, lines: incoming.lines.map((line) => ({ ...line })), flags };
    } else {
      const header = time(incoming.updated_at) > time(held.updated_at) ? incoming : held;
      const byId = new Map(held.lines.map((line) => [line.id, line]));
      for (const line of incoming.lines) {
        const before = byId.get(line.id);
        byId.set(line.id, before ? { ...line, ...before, sent_at: before.sent_at ?? line.sent_at, sent_by: before.sent_by ?? line.sent_by, round: Math.max(before.round ?? 0, line.round ?? 0) || undefined, removed: before.removed ?? line.removed } : { ...line });
      }
      next = { ...header, id: held.id, opened_at: held.opened_at, lines: [...byId.values()], resends: [...new Map([...(held.resends ?? []), ...(incoming.resends ?? [])].map((entry) => [entry.id, entry])).values()], flags };
    }
    if (next.table_id && [...state.orders.values()].some((other) => other.id !== next.id && other.table_id === next.table_id && !state.closed.has(other.id))) {
      if (!next.flags.includes('table_shared')) next.flags = [...next.flags, 'table_shared'];
    }
    state.orders.set(next.id, next);
    ticketsFor(next);
    publish(next);
    return { id: next.id, status: 'stored', rev: state.orders.size, flags: next.flags, lines: incoming.lines.map((line) => ({ id: line.id, status: 'stored' })) };
  }

  server.state.handler = (method, path, body) => {
    if (method === 'POST' && path === 'restaurant/orders/sync') {
      state.requests.push(path);
      state.batches.push(body.orders.map((order) => order.id));
      const results = body.orders.map(apply);
      if (state.loseAnswers > 0) {
        state.loseAnswers -= 1;
        throw new NetworkError(new Error('answer lost'));
      }
      return { status: 200, body: { results } };
    }
    const status = /^restaurant\/device\/tickets\/(.+)\/status$/.exec(path);
    if (method === 'POST' && status) {
      const ticket = state.tickets.get(status[1]);
      if (!ticket) return { status: 422, body: { code: 'ticket_status_invalid' } };
      ticket.status = body.status;
      state.marks.push({ id: status[1], status: body.status, user_id: body.user_id });
      publish(state.orders.get(ticket.order_id));
      return { status: 200, body: { data: { id: ticket.id, status: ticket.status } } };
    }
    return null;
  };

  return {
    state,
    define() {
      for (const key of ['restaurant_tables', 'restaurant_stations', 'restaurant_modifiers', 'restaurant_settings', 'restaurant_orders']) {
        if (!server.state.entities[key]) server.define(key, { mode: key === 'restaurant_orders' ? 'incremental' : 'snapshot', module: 'restaurant', rows: [] });
      }
    },
    /** The order as the server holds it. */
    order: (id) => state.orders.get(id),
    /** Closed at another till: refused from now on and no longer offered. */
    close(id) {
      state.closed.add(id);
      server.remove('restaurant_orders', id);
    },
    /** Another till adds a line (and may change the header). */
    otherTill(id, { line = null, header = {} }) {
      const held = state.orders.get(id);
      const next = { ...held, ...header, lines: line ? [...held.lines, line] : held.lines };
      state.orders.set(id, next);
      publish(next);
    },
    /** An order another till opened. */
    seed(order) {
      state.orders.set(order.id, order);
      ticketsFor(order);
      publish(order);
    },
    loseNextAnswer: () => void (state.loseAnswers += 1),
  };
}
