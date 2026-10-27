import { uuidv7 } from '../lib/random';
import { NetworkError } from '../sync/api';
import { accountEffects } from './credit';
import { currentRun, runViews } from './runs';
import { dayItems, expectedTenders } from './reconcile';

/**
 * VAN-06..VAN-10, VAN-14, VAN-17, VAN-18: what the rep does on the route,
 * offline first. Every action saves its record on the device before it
 * returns (vsStore.js); the sync engine uploads it later (vsSync.js). Actions
 * take the person (`user`) and the time zone as arguments so they run in Jest
 * without React.
 */
export class VanSalesError extends Error {
  constructor(code, extra = {}) {
    super(code);
    this.name = 'VanSalesError';
    this.code = code;
    Object.assign(this, extra);
  }
}

export function createVanSales({ records, selling, engine = null, api = null, now = () => Date.now() }) {
  const iso = () => new Date(engine?.serverNow ? engine.serverNow() : now()).toISOString();
  const offline = () => engine?.getStatus?.().network === 'offline';

  // The engine counts pending records in `pending`; tell it after every write so the scheduler uploads soon.
  if (engine?.refreshCounts) records.subscribe(() => engine.refreshCounts().catch(() => {}));

  const service = {
    records,

    /** The van row, or null: Van sales is active on this device only when it exists (VAN-01). */
    van: () => records.van(),

    // -- The day ---------------------------------------------------------------

    /** Every run, newest first (this device's and the server's). */
    async runs() {
      const [localRuns, serverRuns, counts] = await Promise.all([records.list('run'), records.serverRuns(), records.list('count')]);
      return runViews({ localRuns, serverRuns, counts });
    },

    /** The one open run (VAN-06), or null. */
    async currentRun() {
      return currentRun(await service.runs());
    },

    /** VAN-06: start the day on a route (or none). One open run: a second start is refused. */
    async startDay({ user, routeId = null, timeZone }) {
      if (await service.currentRun()) throw new VanSalesError('run_open');
      const startedAt = iso();
      const run = { id: uuidv7(now()), kind: 'run', run_id: '', route_id: routeId ?? undefined, user_id: user.id, started_at: startedAt, local: { date: startedAt.slice(0, 10), time_zone: timeZone ?? null } };
      run.run_id = run.id;
      return records.add(run);
    },

    /** The visits of a run: this device's, over the pulled ones the device lost (a reinstall). */
    async visits(runId) {
      const local = await records.list('visit', { runId });
      const pulled = (await records.serverRuns()).find((run) => run.id === runId)?.visits ?? [];
      const known = new Set(local.map((visit) => visit.id));
      return [...pulled.filter((visit) => !known.has(visit.id)).map((visit) => ({ ...visit, run_id: runId, local: {} })), ...local];
    },

    // -- Visits ------------------------------------------------------------------

    /** VAN-09: the rep arrives at a stop (or a customer off the route): the visit of this run is opened once. */
    async openVisit({ run, customer, stop = null, user }) {
      const existing = (await service.visits(run.id)).find((visit) => (stop && visit.stop_id === stop.id) || visit.customer_id === customer.id);
      if (existing) return existing;
      const at = iso();
      const visit = {
        id: uuidv7(now()),
        kind: 'visit',
        run_id: run.id,
        stop_id: stop?.id,
        customer_id: customer.id,
        user_id: user.id,
        status: 'visited',
        arrived_at: at,
        updated_at: at,
        local: { customer_name: customer.name ?? stop?.customer_name ?? '' },
      };
      return records.add(visit);
    },

    /** Changes a visit (last writer wins by `updated_at`, VAN-09); it uploads again. */
    async changeVisit(visitId, patch) {
      const saved = await records.update(visitId, (current) => ({ ...current, ...patch, updated_at: iso() }));
      if (!saved) throw new VanSalesError('visit_unknown');
      return saved;
    },

    noSale: (visitId, { reason, note }) => service.changeVisit(visitId, { status: 'no_sale', no_sale_reason: reason, note: note || undefined, left_at: iso() }),
    skip: (visitId, { note } = {}) => service.changeVisit(visitId, { status: 'skipped', note: note || undefined, left_at: iso() }),
    leave: (visitId) => service.changeVisit(visitId, { left_at: iso() }),

    /** VAN-24: one position at arrival, rounded to 4 decimals; no trail. */
    checkIn(visitId, position) {
      const round = (value) => Math.round(Number(value) * 10000) / 10000;
      const at = Number.isFinite(position.timestamp) ? new Date(position.timestamp).toISOString() : iso();
      const check_in = { lat: round(position.coords.latitude), lng: round(position.coords.longitude), accuracy_m: Math.round(Number(position.coords.accuracy ?? 0)), at };
      return service.changeVisit(visitId, { check_in });
    },

    /** VAN-09: a van sale completed in a visit marks it `sold`. Called after every sale; others are ignored. */
    async recordSale(sale) {
      const link = sale?.extensions?.van_sales;
      if (!link?.visit_id) return null;
      const visit = await records.get(link.visit_id);
      if (!visit || visit.status === 'sold') return visit;
      return service.changeVisit(visit.id, { status: 'sold', left_at: iso() });
    },

    // -- Credit position ---------------------------------------------------------

    /**
     * VAN-11: the customer's account with what this device made since the last pull
     * (credit sales and collections not counted in the pulled balance yet).
     */
    async accountPosition(customerId, currency) {
      const account = await records.account(customerId);
      const since = await records.pulledAt('van_sales_accounts');
      const [sales, collections] = await Promise.all([records.uncountedSales(since), records.ofCustomer(customerId, 'collection')]);
      const uncounted = collections.filter((collection) => collection.local.error == null && (!collection.local.result || (Date.parse(collection.collected_at) || 0) >= since));
      return { account, unsynced: accountEffects({ sales, collections: uncounted, customerId, currency }) };
    },

    // -- Collections -------------------------------------------------------------

    /**
     * VAN-17, VAN-14: money collected from a customer in cash or mobile money. It takes the next
     * number of the device's receipt range (never reused: the number and the record commit in one write).
     * An amount above the balance is stored too (flagged by the server), the screen having confirmed it.
     */
    collect({ run, visit = null, customer, method, currency, amountMinor, reference, user, timeZone }) {
      if (!['cash', 'mobile_money'].includes(method?.type)) throw new VanSalesError('method_invalid');
      if (!/^[1-9]\d{0,17}$/.test(String(amountMinor ?? ''))) throw new VanSalesError('amount');
      return selling
        .numbered({
        timeZone,
        write: async (number, at) => {
          const collection = {
            id: uuidv7(now()),
            kind: 'collection',
            run_id: run.id,
            visit_id: visit?.id,
            customer_id: customer.id,
            user_id: user.id,
            payment_method_id: method.id,
            currency,
            amount_minor: String(amountMinor),
            provider_reference: reference || undefined,
            receipt_seq: number.seq,
            receipt_number: number.number,
            number_range_id: number.rangeId,
            collected_at: at,
            local: { customer_name: customer.name ?? '', method_name: method.name, method_type: method.type },
          };
          const { record, operation } = await records.prepareAdd(collection);
          return { operations: [operation], result: record };
        },
      })
        .then((record) => {
          records.changed();
          return record;
        });
    },

    // -- Load sheets -------------------------------------------------------------

    /**
     * VAN-08: the rep counts each line and confirms (an online action). Offline, it says so and
     * the sheet stays as it is. `received` maps line id → quantity received (decimal string).
     */
    async confirmLoad({ load, received, user }) {
      if (offline()) throw new VanSalesError('offline');
      const lines = load.lines.map((line) => ({ id: line.id, qty_received: String(received[line.id] ?? line.qty) }));
      let response;
      try {
        response = await api.post(`van-sales/device/loads/${load.id}/confirm`, { user_id: user.id, lines });
      } catch (error) {
        if (error instanceof NetworkError) throw new VanSalesError('offline');
        throw error;
      }
      if (response.status !== 200 && response.status !== 201) {
        throw new VanSalesError(response.body?.code ?? 'confirm_failed', { status: response.status });
      }
      const sent = new Map(lines.map((line) => [line.id, line.qty_received]));
      await records.patchLoad(load.id, { status: 'confirmed', lines: load.lines.map((line) => ({ ...line, qty_confirmed: sent.get(line.id) })) });
      engine?.pull?.({ keys: ['van_sales_loads'] }).catch(() => {});
      return lines;
    },

    // -- The end-of-day count ----------------------------------------------------

    /** What the day came to on this device: items (load, sold, customer returns) and expected cash and mobile money. */
    async dayFigures(run) {
      const since = Date.parse(run.started_at) || 0;
      const [loads, sales, refunds, collections] = await Promise.all([records.loads(), records.salesSince(since), records.refundsSince(since), records.list('collection', { runId: run.id })]);
      const mine = loads.filter((load) => load.run_id === run.id || (!load.run_id && load.status === 'confirmed'));
      return { items: dayItems({ runId: run.id, loads: mine, sales, refunds }), expected: expectedTenders({ runId: run.id, sales, collections, refunds }), loads: mine };
    },

    /**
     * VAN-18: queue the count. `id` is made once by the screen, so a double press stores one count;
     * a recount after a rejection is a new attempt with a new id.
     */
    async submitCount({ id, run, user, lines, tenders, note }) {
      const countId = id ?? uuidv7(now());
      const count = {
        id: countId,
        kind: 'count',
        run_id: run.id,
        user_id: user.id,
        submitted_at: iso(),
        note: note || undefined,
        lines,
        tenders,
        local: {},
      };
      const saved = await records.add(count);
      if (!saved) throw new VanSalesError('record_exists', { id: countId });
      return saved;
    },

    /** Records the server refused, parked with their codes (the screen says so). */
    rejected: () => records.rejected(),

    /** Puts a parked record back in the queue (after the cause was fixed on the server). */
    retry: (id) => records.update(id, (current) => ({ ...current, local: { ...current.local, error: null, result: null } })),
  };

  return service;
}
