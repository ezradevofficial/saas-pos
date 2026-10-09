import { createApiClient } from './api';
import { createSyncEngine } from './engine';
import { createSyncStore, OUTBOX } from './store';
import { itemRow, priceRow, rateRow, recordedServer, saleBody, shapes, shiftBody } from '../test/recordedServer';
import { testDatabase } from '../test/testDatabase';

// NFR-04, NUM-02, POS-09 (ADR 004, phase 4 Task 7): the till's sync engine
// against the device API mocked at the fetch level, answering with the
// shapes the PHP endpoints recorded (src/test/fixtures/device-api.json,
// from api/modules/POS/tests/DeviceApiShapesTest.php).

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

function setup() {
  let time = Date.parse('2026-11-02T04:00:00Z');
  const now = () => time;
  const server = recordedServer({ now });
  const api = createApiClient({ baseUrl: 'https://api.example.test', getToken: async () => '1|device-token', fetchImpl: server.fetch });
  const database = testDatabase();
  const store = createSyncStore(database);
  const engine = createSyncEngine({ api, store, now, random: () => 0.5 });
  return { server, database, store, engine, now, advance: (ms) => (time += ms) };
}

function seedCatalogue(server, count) {
  const items = Array.from({ length: count }, (_, i) => itemRow(`item-${i}`, { barcodes: [{ barcode: `600${i}`, uom_id: 'u1' }] }));
  server.define('items', { rows: items });
  server.define('item_prices', { rows: items.map((item, i) => priceRow(`price-${i}`, item.id, 9000 * (i + 1))) });
  server.define('exchange_rates', { mode: 'snapshot', rows: [rateRow('rate-1', '130.00000000')] });
}

const ids = async (database, table) => (await database.get(table).query().fetch()).map((record) => record.id).sort();
const raw = async (database, table, id) => (await database.get(table).find(id))._raw;
const cursorSeq = (cursor) => Number(Buffer.from(cursor, 'base64url').toString().split('.').pop());

describe('offline proof: seven days without a connection', () => {
  it('queues hundreds of records for a week, then uploads each once in order and catches up on master data', async () => {
    const { server, engine, store, database, now, advance } = setup();
    seedCatalogue(server, 20);
    await engine.sync();
    expect(await ids(database, 'items')).toHaveLength(20);
    const frozen = await store.cursors(['items', 'item_prices']);

    server.goOffline();
    const sales = new Map();
    const shifts = [];
    let seq = 1;
    let offlineRuns = 0;

    for (let day = 0; day < 7; day += 1) {
      const shiftId = `shift-${day}`;
      const opening = shiftBody({ id: shiftId, openedAt: now() });
      shifts.push(shiftId);
      await engine.enqueue('pos.shifts', shiftId, opening, { group: shiftId });
      const today = [];

      for (let j = 0; j < 45; j += 1) {
        advance(10 * MINUTE);
        const id = `sale-${day}-${j}`;
        const body = saleBody({ id, shiftId, seq: seq++, soldAt: now(), lineId: `${id}-l1`, paymentId: `${id}-p1` });
        sales.set(id, body);
        today.push(id);
        await engine.enqueue('pos.sales', id, body, { group: shiftId });
        if (j % 15 === 14) {
          // The scheduler keeps trying; each attempt finds no network and changes nothing.
          const run = await engine.sync();
          expect(run.error.name).toBe('NetworkError');
          offlineRuns += 1;
        }
      }

      if (day === 1) await engine.enqueue('pos.cash_movements', 'move-1', { id: 'move-1', shift_id: shiftId, kind: 'pay_out', currency: 'KES', amount_minor: '50000' }, { group: shiftId });
      if (day === 3) await engine.enqueue('pos.voids', 'void-1', { id: 'void-1', sale_id: today[5], reason: 'Wrong item' }, { group: shiftId });
      if (day === 5) await engine.enqueue('pos.refunds', 'refund-1', { id: 'refund-1', sale_id: today[8], shift_id: shiftId, total_minor: '56250' }, { group: shiftId });
      const closing = { closed_by_id: opening.opened_by_id, closed_at: new Date(now()).toISOString(), counted: [{ currency: 'KES', amount_minor: '500000' }], note: null };
      await engine.enqueue('pos.shifts', shiftId, { ...opening, closing }, { group: shiftId });

      // Meanwhile the back office changes master data.
      if (day === 2) server.upsert('items', itemRow('item-3', { name: 'Renamed on day 2' }));
      if (day === 3) {
        server.remove('items', 'item-7');
        server.remove('item_prices', 'price-7');
      }
      if (day === 4) server.upsert('item_prices', priceRow('price-3', 'item-3', 99000));
      if (day === 5) {
        server.remove('exchange_rates', 'rate-1');
        server.upsert('exchange_rates', rateRow('rate-2', '131.00000000'));
      }
      advance(DAY - 45 * 10 * MINUTE);
    }

    const records = 7 * 2 + sales.size + 3;
    expect(sales.size).toBe(315);
    expect(offlineRuns).toBe(21);
    expect(engine.getStatus()).toMatchObject({ network: 'offline', pending: records, failed: 0 });
    // Offline attempts never count as tries: nothing is backed off when the network returns.
    expect((await store.entries(OUTBOX.PENDING)).every((entry) => entry.attempts === 0)).toBe(true);
    expect(server.state.firstStores.size).toBe(0);

    server.goOnline();
    const before = server.state.requests.length;
    const run = await engine.sync();

    expect(run.pushed).toMatchObject({ acknowledged: records, failed: 0, retried: 0, stopped: null });
    expect(await store.counts()).toEqual({ pending: 0, failed: 0 });
    // Every record reached the server once.
    expect(server.state.firstStores.size).toBe(7 + sales.size + 3);
    expect([...server.state.firstStores.values()].every((times) => times === 1)).toBe(true);
    const uploads = server.state.requests.slice(before).filter((request) => request.method === 'POST');
    expect(uploads.filter((request) => request.path === 'pos/sales').reduce((sum, request) => sum + request.body.sales.length, 0)).toBe(sales.size);
    expect(uploads.every((request) => Object.values(request.body)[0].length <= 50)).toBe(true);

    // Causes before effects: each shift opens before its sales and closes after them.
    const order = server.state.arrivals;
    for (const shiftId of shifts) {
      const opened = order.findIndex(([path, id, closing]) => path === 'pos/shifts' && id === shiftId && !closing);
      const closed = order.findIndex(([path, id, closing]) => path === 'pos/shifts' && id === shiftId && closing);
      const own = order.map(([path, id], index) => (path !== 'pos/shifts' && sales.get(id)?.shift_id === shiftId ? index : -1)).filter((index) => index >= 0);
      expect(own).toHaveLength(45);
      expect(opened).toBeLessThan(Math.min(...own));
      expect(closed).toBeGreaterThan(Math.max(...own));
    }
    // The device wins on its sales: what it sold is what the server got.
    for (const [id, body] of sales) expect(server.state.records.get(id).payload).toEqual(body);

    // The pull resumed from the cursors frozen a week ago and applied every change.
    const firstPull = server.state.requests.slice(before).find((request) => request.path === 'sync/pull');
    expect(firstPull.params.get('cursors[items]')).toBe(frozen.items);
    expect(firstPull.params.get('cursors[item_prices]')).toBe(frozen.item_prices);
    expect(await ids(database, 'items')).not.toContain('item-7');
    expect(await ids(database, 'item_barcodes')).not.toContain('item-7:0');
    expect((await raw(database, 'items', 'item-3')).name).toBe('Renamed on day 2');
    expect((await raw(database, 'item_prices', 'price-3')).amount_minor).toBe('99000');
    expect(await ids(database, 'item_prices')).not.toContain('price-7');
    expect(await ids(database, 'exchange_rates')).toEqual(['rate-2']);

    // A second run has nothing to send and nothing to apply.
    const quiet = await engine.sync();
    expect(quiet.pushed.acknowledged).toBe(0);
    expect(quiet.pulled.items).toEqual({ upserts: 0, tombstones: 0, pages: 1 });
  });
});

describe('offline proof: retries', () => {
  it('backs off exponentially on server errors, honouring Retry-After, then uploads', async () => {
    const { server, engine, store, now, advance } = setup();
    seedCatalogue(server, 1);
    await engine.enqueue('pos.shifts', 'shift-1', shiftBody({ id: 'shift-1', openedAt: now() }), { group: 'shift-1' });
    await engine.enqueue('pos.sales', 'sale-1', saleBody({ id: 'sale-1', shiftId: 'shift-1', seq: 1, soldAt: now(), lineId: 'l1', paymentId: 'p1' }), { group: 'shift-1' });
    server.once('pos/sales', () => server.json(503, { message: 'Service unavailable' }, { 'retry-after': '30' }));
    server.once('pos/sales', () => server.json(503, { message: 'Service unavailable' }));
    server.once('pos/sales', () => server.json(429, { message: 'Too many requests' }));

    const delays = [];
    for (let attempt = 0; attempt < 3; attempt += 1) {
      const summary = await engine.push();
      expect(summary.stopped).toMatch(/^http_(503|429)$/);
      const entry = (await store.entries(OUTBOX.PENDING)).find((row) => row.recordId === 'sale-1');
      expect(entry.attempts).toBe(attempt + 1);
      delays.push(entry.nextAttemptAt - now());

      // Not due yet: nothing is sent.
      const sent = server.requestsTo('pos/sales').length;
      await engine.push();
      expect(server.requestsTo('pos/sales')).toHaveLength(sent);
      advance(entry.nextAttemptAt - now());
    }

    // Retry-After 30 s beats the first backoff (3.75 s); then 5 s × 2^n with jitter (random 0.5): 7.5 s, 15 s.
    expect(delays).toEqual([30000, 7500, 15000]);
    expect(await engine.push()).toMatchObject({ acknowledged: 1, stopped: null });
    expect(server.state.firstStores.get('sale-1')).toBe(1);
  });

  it('keeps a record the server refuses as retryable (recorded shift_unknown) and sends it again after its backoff', async () => {
    const { server, engine, store, now, advance } = setup();
    // An app bug queued the sale before its shift.
    await engine.enqueue('pos.sales', 'sale-1', saleBody({ id: 'sale-1', shiftId: 'shift-1', seq: 1, soldAt: now(), lineId: 'l1', paymentId: 'p1' }));
    await engine.enqueue('pos.shifts', 'shift-1', shiftBody({ id: 'shift-1', openedAt: now() }));

    const summary = await engine.push();

    expect(summary).toMatchObject({ acknowledged: 1, failed: 0, retried: 1 });
    const [entry] = await store.entries(OUTBOX.PENDING);
    expect(entry).toMatchObject({ recordId: 'sale-1', attempts: 1, lastError: shapes.sales_retryable.body.results[0].error });
    expect(entry.nextAttemptAt - now()).toBe(3750);

    advance(3750);
    expect(await engine.push()).toMatchObject({ acknowledged: 1, failed: 0 });
    expect(await store.counts()).toEqual({ pending: 0, failed: 0 });
  });
});

describe('offline proof: duplicate uploads', () => {
  it('the PHP endpoint answers a resend exactly as the first upload', () => {
    expect(shapes.sales_resent).toEqual(shapes.sales_stored);
  });

  it('treats the stored answer to a resend as success when the first answer was lost', async () => {
    const { server, engine, store, now, advance } = setup();
    seedCatalogue(server, 1);
    await engine.enqueue('pos.shifts', 'shift-1', shiftBody({ id: 'shift-1', openedAt: now() }), { group: 'shift-1' });
    const sales = ['a', 'b', 'c'].map((id, i) => saleBody({ id, shiftId: 'shift-1', seq: i + 1, soldAt: now(), lineId: `${id}-l`, paymentId: `${id}-p` }));
    for (const sale of sales) await engine.enqueue('pos.sales', sale.id, sale, { group: 'shift-1' });
    // The server stores the batch, then the connection drops before the answer arrives.
    server.once('pos/sales', () => 'lost');

    const lost = await engine.sync();
    expect(lost.error.name).toBe('NetworkError');
    expect((await store.entries(OUTBOX.PENDING)).map((entry) => [entry.recordId, entry.attempts])).toEqual([
      ['a', 0],
      ['b', 0],
      ['c', 0],
    ]);
    const firstAnswers = sales.map((sale) => server.state.records.get(sale.id).result);

    advance(5 * MINUTE);
    await engine.sync();

    expect(await store.counts()).toEqual({ pending: 0, failed: 0 });
    expect(['a', 'b', 'c'].map((id) => server.state.firstStores.get(id))).toEqual([1, 1, 1]);
    const acknowledged = (await store.entries(OUTBOX.ACKNOWLEDGED)).filter((entry) => entry.kind === 'pos.sales');
    expect(acknowledged.map((entry) => entry.result)).toEqual(firstAnswers);
  });

  it('keeps a resend with another body for review (recorded payload_mismatch) and goes on', async () => {
    const { server, engine, store, now } = setup();
    await engine.enqueue('pos.shifts', 'shift-1', shiftBody({ id: 'shift-1', openedAt: now() }));
    const sale = saleBody({ id: 'sale-1', shiftId: 'shift-1', seq: 1, soldAt: now(), lineId: 'l1', paymentId: 'p1' });
    await engine.enqueue('pos.sales', 'sale-1', sale);
    await engine.push();

    // The same id queued again with other content (an app bug), then a good sale.
    await engine.enqueue('pos.sales', 'sale-1', { ...sale, offline: false });
    await engine.enqueue('pos.sales', 'sale-2', saleBody({ id: 'sale-2', shiftId: 'shift-1', seq: 2, soldAt: now(), lineId: 'l2', paymentId: 'p2' }));
    const summary = await engine.push();

    expect(summary).toMatchObject({ acknowledged: 1, failed: 1, retried: 0 });
    const [failed] = await store.entries(OUTBOX.FAILED);
    expect(failed).toMatchObject({ recordId: 'sale-1', lastError: { code: 'payload_mismatch', retryable: false } });
    expect(server.state.records.get('sale-1').payload).toEqual(sale);
  });
});

describe('offline proof: pull cursors', () => {
  it('resumes from the last applied page after the connection drops mid-pull', async () => {
    const { server, engine, store, database } = setup();
    server.define('items', { rows: Array.from({ length: 1200 }, (_, i) => itemRow(`i${String(i).padStart(4, '0')}`)) });
    let pulls = 0;
    server.state.script.push({ path: 'sync/pull', once: false, answer: () => (++pulls === 2 ? 'network' : null) });

    await expect(engine.pull()).rejects.toThrow('network_error');
    const saved = await store.cursors(['items']);
    expect(cursorSeq(saved.items)).toBe(500);
    expect(await ids(database, 'items')).toHaveLength(500);

    const counts = await engine.pull();

    expect(counts.items).toEqual({ upserts: 700, tombstones: 0, pages: 2 });
    expect(await ids(database, 'items')).toHaveLength(1200);
    const sent = server.requestsTo('sync/pull').map((request) => request.params.get('cursors[items]'));
    expect(sent.map((cursor) => (cursor ? cursorSeq(cursor) : 0))).toEqual([0, 500, 500, 1000]);
  });
});

describe('offline proof: server wins on master data', () => {
  it('overwrites local copies, removes tombstoned rows and replaces snapshots, while queued sales keep their prices', async () => {
    const { server, engine, database, now } = setup();
    server.define('items', { rows: ['a', 'b', 'c'].map((id) => itemRow(id, { barcodes: [{ barcode: `600${id}`, uom_id: 'u1' }] })) });
    server.define('item_prices', { rows: [priceRow('pa', 'a', 56250), priceRow('pb', 'b', 45000)] });
    server.define('exchange_rates', { mode: 'snapshot', rows: [rateRow('r1', '130.00000000')] });
    await engine.sync();

    // The till's copy drifted (or was edited locally) while it sold item a at its price.
    await database.write(async () => {
      const item = await database.get('items').find('a');
      await item.update((record) => record._setRaw('name', 'Local edit'));
    });
    await engine.enqueue('pos.shifts', 'shift-1', shiftBody({ id: 'shift-1', openedAt: now() }));
    const sale = saleBody({ id: 'sale-1', shiftId: 'shift-1', seq: 1, soldAt: now(), lineId: 'l1', paymentId: 'p1', unitPriceMinor: 56250 });
    await engine.enqueue('pos.sales', 'sale-1', sale);

    server.upsert('items', itemRow('a', { name: 'Server name' }));
    server.upsert('item_prices', priceRow('pa', 'a', 60750));
    server.remove('items', 'b');
    server.remove('item_prices', 'pb');
    server.remove('exchange_rates', 'r1');
    server.upsert('exchange_rates', rateRow('r2', '131.00000000'));
    await engine.sync();

    const a = await raw(database, 'items', 'a');
    expect([a.name, JSON.parse(a.data).name]).toEqual(['Server name', 'Server name']);
    expect(await ids(database, 'items')).toEqual(['a', 'c']);
    // The server's row for a has no barcode any more: the till's is removed with it.
    expect(await ids(database, 'item_barcodes')).toEqual(['c:0']);
    expect((await raw(database, 'item_prices', 'pa')).amount_minor).toBe('60750');
    expect(await ids(database, 'item_prices')).toEqual(['pa']);
    expect(await ids(database, 'exchange_rates')).toEqual(['r2']);
    // Device wins on the completed sale: uploaded at the price it was sold at.
    expect(server.state.records.get('sale-1').payload.lines[0].unit_price_minor).toBe('56250');
  });
});
