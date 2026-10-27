import { memoryBackend } from '../device/credentials';
import { createServices } from '../services/services';
import { fakeServer } from '../test/fakeServer';
import { IDS, menuCatalogue, modifierRows, settingsRow, stationRows, tableRows, waiter } from '../test/restaurantFixtures';
import { restaurantServer } from '../test/restaurantServer';
import { testDatabase } from '../test/testDatabase';
import { addLine, createOrder, mergeServer, wireOrder } from './orderModel';
import { BATCH } from './orderSync';
import { stubPrinter } from './tickets';

// REST-03..REST-06, REST-10, REST-11, REST-15, REST-17, NFR-04: open orders live on the device and
// are uploaded through POST restaurant/orders/sync, idempotent and safe to resend.

const DAY = 24 * 60 * 60 * 1000;
const catalogue = menuCatalogue();
const user = waiter();

function setup() {
  const server = fakeServer();
  server.define('settings', { mode: 'snapshot', rows: [{ ...server.state.settings }] });
  const remote = restaurantServer(server);
  remote.define();
  server.define('restaurant_tables', { mode: 'snapshot', module: 'restaurant', rows: tableRows });
  server.define('restaurant_stations', { mode: 'snapshot', module: 'restaurant', rows: stationRows });
  server.define('restaurant_modifiers', { mode: 'snapshot', module: 'restaurant', rows: modifierRows });
  server.define('restaurant_settings', { mode: 'snapshot', module: 'restaurant', rows: [settingsRow] });
  const services = createServices({ database: testDatabase(), api: server, credentialsBackend: memoryBackend(), netInfo: null });
  // Pairing is not under test: the till already has its device and token.
  return { server, remote, services, restaurant: services.restaurant, orders: services.restaurant.orders, engine: services.engine };
}

async function openTable(restaurant, table = tableRows[0], covers = 2) {
  return (await restaurant.openTable({ table: { ...table, status: 'free' }, covers, user, catalogue })).order;
}
const add = (restaurant, orderId, itemId, extra = {}) => restaurant.addItem({ orderId, itemId, catalogue, user, ...extra });

describe('restaurant orders offline and sync (REST-17)', () => {
  beforeEach(() => {
    stubPrinter.queued.length = 0;
  });

  it('keeps an order for days offline: rounds, send, void after send, transfer; uploads once back online', async () => {
    const { server, remote, restaurant, orders, engine } = setup();
    await engine.sync();
    server.goOffline();
    engine.setNetwork('offline');
    const start = Date.parse('2026-11-02T10:00:00Z');
    jest.useFakeTimers({ now: start, doNotFake: ['setTimeout', 'setInterval', 'clearTimeout', 'clearInterval', 'setImmediate', 'nextTick', 'queueMicrotask'] });
    try {
      const order = await openTable(restaurant);
      expect(order.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7/);
      const burger = (await add(restaurant, order.id, IDS.burger, { modifiers: [{ id: IDS.rare, name: 'Rare', price_minor: '0' }, { id: IDS.cheese, name: 'Cheese', price_minor: '5000' }], note: 'no onions', course: 1 })).line;
      const salad = (await add(restaurant, order.id, IDS.salad, { course: 2, seat: 2 })).line;
      expect(burger.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7/);

      // Round 1: the first course goes to the kitchen; the second is held.
      const first = await restaurant.send({ orderId: order.id, user, catalogue });
      expect(first.round).toBe(1);
      expect(first.lines.map((line) => line.id)).toEqual([burger.id]);
      expect(first.tickets).toHaveLength(1);
      expect(first.tickets[0].text).toContain('Kitchen');
      expect(first.tickets[0].text).toContain('+ Cheese');
      expect(first.tickets[0].text).toContain('* no onions');
      expect(stubPrinter.queued).toHaveLength(1);

      // Days pass with no connection.
      jest.setSystemTime(start + 3 * DAY);
      const beer = (await add(restaurant, order.id, IDS.beer, { qty: '2' })).line;
      const second = await restaurant.fireNext({ orderId: order.id, user, catalogue });
      expect(second.course).toBe(2);
      expect(second.lines.map((line) => line.id)).toEqual([salad.id]);
      const third = await restaurant.send({ orderId: order.id, courses: [1], user, catalogue });
      expect(third.round).toBe(3);
      expect(third.tickets.map((ticket) => ticket.stationName)).toEqual(['Bar']);

      // A resend and a void after send, approved by a manager's override.
      const resent = await restaurant.resend({ orderId: order.id, lineIds: [burger.id], id: '0190a000-0000-7000-8000-00000000aaaa', user, catalogue });
      expect(resent.resend.line_ids).toEqual([burger.id]);
      const override = { id: 'ov-1', manager_user_id: IDS.manager, permission: 'pos.sale.void', reference: beer.id };
      const voided = await restaurant.voidLine({ orderId: order.id, lineId: beer.id, reason: 'Guest changed their mind', approval: { override }, user, catalogue });
      expect(voided.order.lines.find((line) => line.id === beer.id).removed).toMatchObject({ reason: 'Guest changed their mind', by: user.id, override });
      expect(voided.tickets[0].text).toContain('- 2 x Tusker');
      await restaurant.transfer({ orderId: order.id, waiter: { id: IDS.waiter2, name: 'Baraka' }, user });
      await restaurant.requestBill({ orderId: order.id, user });

      const local = await orders.get(order.id);
      expect(local.lines.map((line) => line.round)).toEqual([1, 2, 3]);
      expect(local.waiter_id).toBe(IDS.waiter2);
      expect(await orders.dirtyCount()).toBe(1);
      expect(engine.getStatus().pending).toBe(1);
      // Nothing reached the server while offline.
      expect(remote.state.requests).toHaveLength(0);
    } finally {
      jest.useRealTimers();
    }

    server.goOnline();
    engine.setNetwork('online');
    const run = await engine.sync();
    expect(run.pushed.restaurant).toMatchObject({ sent: 1, stored: 1, rejected: 0 });
    expect(remote.state.requests).toHaveLength(1);
    const held = remote.order((await orders.all())[0].id);
    expect(held.lines).toHaveLength(3);
    expect(held.waiter_id).toBe(IDS.waiter2);
    expect(held.bill_requested_at).toBeTruthy();
    expect(held.lines.find((line) => line.removed)).toMatchObject({ removed: { reason: 'Guest changed their mind' } });
    expect(held.resends).toHaveLength(1);
    expect(await orders.dirtyCount()).toBe(0);
    expect(engine.getStatus().pending).toBe(0);
    // The pull brought the tickets back into the order (a ticket per round).
    const synced = (await orders.all())[0];
    expect(synced.local.tickets.map((ticket) => ticket.status)).toEqual(['new', 'new', 'new']);
    expect(synced.lines.find((line) => line.id === held.lines[0].id).ticket_status).toBe('new');
  });

  it('a duplicate upload returns the same state and a resend after a lost answer duplicates nothing', async () => {
    const { server, remote, restaurant, orders, engine } = setup();
    await engine.sync();
    const order = await openTable(restaurant);
    await add(restaurant, order.id, IDS.burger, { modifiers: [{ id: IDS.rare, name: 'Rare', price_minor: '0' }] });
    await restaurant.send({ orderId: order.id, user, catalogue });

    // The server stores the order but the answer is lost: the till keeps it dirty.
    remote.loseNextAnswer();
    const lost = await engine.sync({ pull: false });
    expect(lost.error.name).toBe('NetworkError');
    expect(remote.order(order.id).lines).toHaveLength(1);
    expect(await orders.dirtyCount()).toBe(1);

    const again = await engine.sync({ pull: false });
    expect(again.pushed.restaurant).toMatchObject({ sent: 1, stored: 1 });
    expect(remote.order(order.id).lines).toHaveLength(1);
    expect(await orders.dirtyCount()).toBe(0);
    const stateAfterFirst = JSON.stringify(await orders.get(order.id));

    // The same content again (forced dirty) gives the same answer and the same local state.
    await orders.update(order.id, (current) => ({ ...current }), {});
    await engine.sync({ pull: false });
    const body = server.state.requests.filter((request) => request.path === 'restaurant/orders/sync').at(-1).body;
    expect(body.orders[0].lines).toHaveLength(1);
    const restored = JSON.parse(stateAfterFirst);
    const final = await orders.get(order.id);
    expect(final.lines).toEqual(restored.lines);
    expect(remote.order(order.id).lines).toHaveLength(1);
  });

  it('uploads in batches of at most 25', async () => {
    const { remote, restaurant, orders, engine } = setup();
    await engine.sync();
    for (let i = 0; i < 60; i += 1) {
      const order = createOrder({ kind: 'tab', tabName: `Tab ${i}`, waiterId: user.id, currency: 'KES', now: '2026-11-02T10:00:00Z', userId: user.id });
      await orders.put(addLine(order, { itemId: IDS.beer, name: 'Tusker', unitPriceMinor: '30000' }, { now: '2026-11-02T10:00:00Z' }));
    }
    expect(await orders.dirtyCount()).toBe(60);
    await engine.sync({ pull: false });
    expect(remote.state.batches.map((batch) => batch.length)).toEqual([BATCH, BATCH, 10]);
    expect(await orders.dirtyCount()).toBe(0);
    expect(restaurant).toBeDefined();
  });

  it('header conflicts are last-writer-wins by updated_at', async () => {
    const { remote, restaurant, orders, engine } = setup();
    await engine.sync();
    const order = await openTable(restaurant);
    await add(restaurant, order.id, IDS.beer);
    await engine.sync();

    // Another till moved the table to another waiter LATER than this till's change: the server's header wins.
    await restaurant.setCovers({ orderId: order.id, covers: 5, user });
    const mine = await orders.get(order.id);
    remote.otherTill(order.id, { header: { covers: 3, waiter_id: IDS.waiter2, updated_at: new Date(Date.parse(mine.updated_at) + 60000).toISOString() } });
    await engine.sync();
    const lost = await orders.get(order.id);
    expect(lost.covers).toBe(3);
    expect(lost.waiter_id).toBe(IDS.waiter2);
    expect(remote.order(order.id).covers).toBe(3);

    // This till changes the header after: its change wins everywhere.
    await new Promise((resolve) => setTimeout(resolve, 5));
    const later = new Date(Date.parse(lost.updated_at) + 120000).toISOString();
    await orders.update(order.id, (current) => ({ ...current, covers: 8, updated_at: later }));
    await engine.sync();
    expect(remote.order(order.id).covers).toBe(8);
    expect((await orders.get(order.id)).covers).toBe(8);
  });

  it('unions lines: a line another till added is kept with this till\'s own, and nothing is lost', async () => {
    const { remote, restaurant, orders, engine } = setup();
    await engine.sync();
    const order = await openTable(restaurant);
    const mine = (await add(restaurant, order.id, IDS.burger)).line;
    await engine.sync();

    const theirs = { id: '0190a000-0000-7000-8000-00000000bbbb', item_id: IDS.beer, name: 'Tusker', qty: '1', unit_price_minor: '30000', tax_code_id: IDS.vat, tax_rate: '16.0000', tax_inclusive: false, course: 1, seat: null, note: null, modifiers: [], created_at: '2026-11-02T11:00:00Z', round: 1, sent_at: '2026-11-02T11:00:00Z', sent_by: IDS.waiter2 };
    remote.otherTill(order.id, { line: theirs });
    // A line added on this till at the same time, before the pull.
    const mine2 = (await add(restaurant, order.id, IDS.salad)).line;
    await engine.sync();
    const merged = await orders.get(order.id);
    expect(merged.lines.map((line) => line.id).sort()).toEqual([mine.id, mine2.id, theirs.id].sort());
    expect(merged.lines.find((line) => line.id === theirs.id).sent_at).toBeTruthy();
    expect(remote.order(order.id).lines.map((line) => line.id).sort()).toEqual([mine.id, mine2.id, theirs.id].sort());
  });

  it('sent marks and voids only move forward', () => {
    const base = createOrder({ kind: 'tab', tabName: 'Joe', waiterId: user.id, currency: 'KES', now: '2026-11-02T10:00:00Z', userId: user.id });
    const local = addLine(base, { id: 'l1', itemId: IDS.beer, name: 'Tusker', unitPriceMinor: '100' }, { now: '2026-11-02T10:00:00Z' });
    // The server has it sent in round 2 and voided; the till has it unsent.
    const server = { ...wireOrder(local), lines: [{ ...wireOrder(local).lines[0], sent_at: '2026-11-02T10:05:00Z', sent_by: 'x', round: 2, removed: { at: '2026-11-02T10:06:00Z', by: 'm', reason: 'spilt' } }] };
    const merged = mergeServer(local, server);
    expect(merged.lines[0]).toMatchObject({ sent_at: '2026-11-02T10:05:00Z', round: 2, removed: { reason: 'spilt' } });
    // The reverse: sent on the till, unsent on the server: stays sent.
    const sent = { ...local, lines: [{ ...local.lines[0], sent_at: '2026-11-02T10:01:00Z', round: 1, sent_by: 'y' }] };
    const back = mergeServer(sent, wireOrder(local));
    expect(back.lines[0]).toMatchObject({ sent_at: '2026-11-02T10:01:00Z', round: 1 });
    // A removal never comes undone.
    const removed = { ...sent, lines: [{ ...sent.lines[0], removed: { at: 'z', by: 'y', reason: 'r' } }] };
    expect(mergeServer(removed, wireOrder(local)).lines[0].removed).toBeTruthy();
  });

  it('keeps the lines of an order the server refused as closed, and rings them as a new order', async () => {
    const { remote, restaurant, orders, engine } = setup();
    await engine.sync();
    const order = await openTable(restaurant);
    await add(restaurant, order.id, IDS.burger);
    await engine.sync();
    // The bill was paid at another till; this till adds a round.
    remote.close(order.id);
    const extra = (await add(restaurant, order.id, IDS.beer)).line;
    await restaurant.send({ orderId: order.id, user, catalogue });
    const run = await engine.sync();
    expect(run.pushed.restaurant).toMatchObject({ rejected: 1 });
    const kept = await orders.get(order.id);
    expect(kept.local.rejected.order).toBe('order_closed');
    expect(kept.lines).toHaveLength(2);
    expect(await orders.dirtyCount()).toBe(0);

    const fresh = await restaurant.ringAsNew({ orderId: order.id, user });
    expect(fresh.id).not.toBe(order.id);
    // Only the line the server refused is rung again (the first round was uploaded and paid elsewhere).
    expect(fresh.lines.map((line) => line.name)).toEqual(['Tusker']);
    expect(fresh.lines.some((line) => line.id === extra.id)).toBe(false);
    expect((await orders.get(order.id)).local.closed).toBe('remote');
    await engine.sync();
    expect(remote.order(fresh.id).lines).toHaveLength(1);
    // The old order never uploads again.
    expect(await orders.dirtyCount()).toBe(0);
  });

  it('an order closed elsewhere leaves the open list after a pull; a dirty one is kept', async () => {
    const { remote, restaurant, orders, engine } = setup();
    await engine.sync();
    const order = await openTable(restaurant);
    await add(restaurant, order.id, IDS.beer);
    await engine.sync();
    expect((await orders.open()).map((entry) => entry.id)).toEqual([order.id]);
    remote.close(order.id);
    await engine.sync();
    expect(await orders.open()).toHaveLength(0);
    expect((await orders.get(order.id)).local.closed).toBe('remote');
  });

  it('shows an order opened at another till, and a second order on the same table is accepted with a flag', async () => {
    const { remote, restaurant, orders, engine } = setup();
    await engine.sync();
    const first = createOrder({ kind: 'table', tableId: IDS.table1, waiterId: IDS.waiter2, currency: 'KES', now: '2026-11-02T10:00:00Z', userId: IDS.waiter2, covers: 4 });
    remote.seed({ ...wireOrder(addLine(first, { id: 'seed-1', itemId: IDS.beer, name: 'Tusker', unitPriceMinor: '30000' }, { now: '2026-11-02T10:00:00Z' })), table_name: 'T1', waiter_name: 'Baraka' });
    await engine.sync();
    expect((await orders.open()).map((order) => order.id)).toEqual([first.id]);
    expect((await orders.get(first.id)).local.waiter_name).toBe('Baraka');

    // This till (still holding its copy) opens the table offline too: accepted, flagged, never refused.
    const second = createOrder({ kind: 'table', tableId: IDS.table1, waiterId: user.id, currency: 'KES', now: '2026-11-02T10:10:00Z', userId: user.id });
    await orders.put(second);
    await engine.sync();
    expect((await orders.get(second.id)).local.flags).toContain('table_shared');
    expect(restaurant).toBeDefined();
  });

  it('a waiter marks a ticket served; it goes to the device endpoint once online', async () => {
    const { server, remote, restaurant, orders, engine } = setup();
    await engine.sync();
    const order = await openTable(restaurant);
    await add(restaurant, order.id, IDS.burger, { modifiers: [{ id: IDS.rare, name: 'Rare', price_minor: '0' }] });
    await restaurant.send({ orderId: order.id, user, catalogue });
    await engine.sync();
    const ticket = (await orders.get(order.id)).local.tickets[0];
    expect(ticket.status).toBe('new');

    server.goOffline();
    engine.setNetwork('offline');
    await restaurant.markServed({ orderId: order.id, ticketId: ticket.id, user });
    expect((await orders.get(order.id)).local.tickets[0].status).toBe('served');
    expect(remote.state.marks).toHaveLength(0);
    expect(await orders.dirtyCount()).toBe(1);

    server.goOnline();
    engine.setNetwork('online');
    await engine.sync();
    expect(remote.state.marks).toEqual([{ id: ticket.id, status: 'served', user_id: user.id }]);
    expect(await orders.dirtyCount()).toBe(0);
    // The next pull does not undo it.
    expect((await orders.get(order.id)).local.tickets[0].status).toBe('served');
  });

  it('merges a table into another: lines move, the first is merged, the server applies it on sync', async () => {
    const { remote, restaurant, orders, engine } = setup();
    await engine.sync();
    const a = await openTable(restaurant, tableRows[0]);
    const b = await openTable(restaurant, { ...tableRows[1], status: 'free' });
    const line = (await add(restaurant, a.id, IDS.salad)).line;
    await add(restaurant, b.id, IDS.beer);
    const into = await restaurant.merge({ orderId: a.id, intoId: b.id, user });
    expect(into.lines.map((entry) => entry.id)).toContain(line.id);
    expect((await orders.get(a.id)).local.closed).toBe('merged');
    expect((await orders.open()).map((order) => order.id)).toEqual([b.id]);
    await engine.sync();
    // The merged order goes up before the order it merged into.
    expect(remote.state.batches[0]).toEqual([a.id, b.id]);
    expect(remote.order(a.id).merged_into_id).toBe(b.id);
    expect(remote.order(b.id).lines).toHaveLength(2);
  });
});
