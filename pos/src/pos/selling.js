import { uuidv7 } from '../lib/random';
import { NetworkError } from '../sync/api';
import { computeCart } from './cart';
import { DOCUMENT_TYPES, drawNumber, nextToReport } from './numbering';
import { cashMovementPayload, refundAmounts, refundPayload, refundTender, salePayload, shiftPayload, voidPayload } from './payloads';
import { calculateTender } from './tender';

/**
 * POS-01..POS-06, POS-09, NFR-03, NFR-04: what the till records, offline
 * first. Every record is written locally and put in the outbox in one
 * transaction (engine.enqueue with `prepare`), grouped by its shift so the
 * shift goes up before its sales, and its sales before their voids,
 * refunds and the shift's close (ADR 004). Nothing here waits on the
 * network: a top-up of receipt numbers is asked for in the background
 * when the till runs low and is online.
 *
 * Every record names the person signed in (cashier_id and the like) and
 * carries their sign-in attestation `actor_proof` (AUTH-07); a manager's
 * override (AUTH-08) goes where the API takes one.
 */
export class SellingError extends Error {
  constructor(code, extra = {}) {
    super(code);
    this.name = 'SellingError';
    this.code = code;
    Object.assign(this, extra);
  }
}

export function createSelling({ engine, posStore, api, now = () => Date.now(), serverNow = () => engine.serverNow(), log = () => {} }) {
  let queue = Promise.resolve();
  // One record at a time: receipt numbers are drawn and committed in order.
  const serial = (task) => {
    const run = queue.then(task, task);
    queue = run.catch(() => {});
    return run;
  };

  const iso = () => new Date(serverNow()).toISOString();
  const offline = () => engine.getStatus().network === 'offline';

  async function draw(documentType, at, timeZone) {
    const [ranges, used] = await Promise.all([posStore.numberRanges(), posStore.counters(documentType)]);
    const number = drawNumber({ ranges, used, documentType, at, timeZone });
    if (!number) throw new SellingError('no_receipt_numbers', { documentType });
    return number;
  }

  /** NUM-02: ask for more numbers (online only); resolves whether a request was made. */
  async function topUp(documentType, timeZone) {
    if (offline()) return false;
    try {
      const [ranges, used] = await Promise.all([posStore.numberRanges(), posStore.counters(documentType)]);
      const year = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric' }).format(new Date(serverNow()));
      const next = nextToReport(ranges, used, documentType, year);
      const response = await api.post('pos/number-ranges', next ? { document_type: documentType, next } : { document_type: documentType });
      if (response.status === 200) await engine.pull({ keys: ['pos_number_ranges'] });
      return true;
    } catch (error) {
      if (!(error instanceof NetworkError)) log('number range top-up failed', error);
      return false;
    }
  }

  function afterDraw(number, documentType, timeZone) {
    if (number.topUp) topUp(documentType, timeZone);
  }

  return {
    topUp,

    /** POS-04: open a shift with an opening float per currency. */
    openShift: ({ user, actorProof, openingFloat }) =>
      serial(async () => {
        const shift = { id: uuidv7(now()), opened_by_id: user.id, opened_by_name: user.name, opened_at: iso(), opening_float: openingFloat, actor_proof: actorProof ?? null };
        const payload = shiftPayload({ id: shift.id, openedById: user.id, openedAt: shift.opened_at, openingFloat, actorProof });
        await engine.enqueue('pos.shifts', shift.id, payload, { group: shift.id, prepare: async () => [await posStore.prepareShift({ ...shift, payload })] });
        return { ...shift, payload };
      }),

    /** A shift the server holds open for this till (a reinstall) is adopted, never opened twice. */
    adoptServerShift: (serverShift) =>
      serial(async () => {
        const shift = {
          id: serverShift.id,
          opened_by_id: serverShift.opened_by,
          opened_at: serverShift.opened_at,
          opening_float: (serverShift.opening_float ?? []).map((row) => ({ currency: row.currency, amountMinor: String(row.amount_minor) })),
          adopted: true,
        };
        await posStore.write([posStore.prepareShift(shift)]);
        return shift;
      }),

    /** POS-04: close with the cash counted per currency; expected cash is computed and shown after. */
    closeShift: ({ shift, user, actorProof, counted, note }) =>
      serial(async () => {
        const closing = { closedById: user.id, closedAt: iso(), counted, note, actorProof };
        const base = shift.payload ?? { id: shift.id, opened_by_id: shift.opened_by_id, opened_at: shift.opened_at, opening_float: shift.opening_float.map((row) => ({ currency: row.currency, amount_minor: String(row.amountMinor) })) };
        const payload = shiftPayload({
          id: shift.id,
          openedById: base.opened_by_id,
          openedAt: base.opened_at,
          openingFloat: base.opening_float.map((row) => ({ currency: row.currency, amountMinor: row.amount_minor })),
          actorProof: base.actor_proof,
          closing,
        });
        const closed = { ...shift, payload, closing: { ...closing, closed_by_name: user.name } };
        await engine.enqueue('pos.shifts', shift.id, payload, { group: shift.id, prepare: async () => [await posStore.prepareShift(closed)] });
        return closed;
      }),

    /** POS-04: cash paid in or out, with a reason (a pay-out needs pos.cash.move or an override). */
    cashMovement: ({ shift, user, actorProof, kind, currency, amountMinor, reason, override }) =>
      serial(async () => {
        const id = override?.reference ?? uuidv7(now());
        const payload = cashMovementPayload({ id, shiftId: shift.id, userId: user.id, kind, currency, amountMinor, reason, occurredAt: iso(), override, actorProof });
        await engine.enqueue('pos.cash_movements', id, payload, { group: shift.id, prepare: async () => [await posStore.prepareRecord('cash_movement', payload, { shiftId: shift.id, at: now() })] });
        return payload;
      }),

    /**
     * POS-01, POS-03: complete the sale. `tenders` = [{ id, method, currency,
     * amountMinor, reference?, status? }]; the tender maths must settle the
     * total. Resolves the stored sale (payload plus local extras).
     */
    completeSale: ({ shift, user, actorProof, cart, catalogue, tenders, changeCurrency, priceListId }) =>
      serial(async () => {
        if (!shift) throw new SellingError('no_shift');
        const at = serverNow();
        const computed = computeCart(cart, { taxCodes: catalogue.taxCodes, day: catalogue.day });
        if (!computed.lines.length) throw new SellingError('empty_sale');
        if (computed.blocked.length) throw new SellingError('lines_blocked', { blocked: computed.blocked });
        const currency = catalogue.saleCurrency;
        const result = calculateTender({
          due: { minor: computed.totals.total_minor, currency },
          tenders: tenders.map((tender) => ({ ...tender, amountMinor: tender.amountMinor })),
          changeCurrency: changeCurrency ?? currency,
          money: catalogue.money,
          at,
        });
        if (!result.settled) throw new SellingError('sale_underpaid', { remaining: result.remaining });

        const number = await draw(DOCUMENT_TYPES.receipt, at, catalogue.timeZone);
        const soldAt = new Date(at).toISOString();
        const payments = result.lines.map((line) => ({
          id: line.tender.id,
          methodId: line.tender.method.id,
          currency: line.tender.currency,
          amountMinor: String(line.tender.amountMinor),
          inSaleMinor: line.inDue.minor,
          rate: line.rate,
          reference: line.tender.reference,
          status: line.tender.status,
        }));
        const lines = computed.lines.map((line) => ({ ...line, actorProof: line.override || line.priceOverride || line.discountMinor !== '0' || line.unitPriceMinor !== line.listPriceMinor ? (line.actorProof ?? actorProof) : undefined }));
        const payload = salePayload({
          id: cart.id,
          shiftId: shift.id,
          cashierId: user.id,
          actorProof,
          customerId: cart.customer?.id,
          number,
          soldAt,
          offline: offline(),
          currency,
          priceListId: priceListId ?? catalogue.defaultList?.id,
          lines,
          totals: computed.totals,
          payments,
          change: result.change,
          changeRate: result.changeRate,
        });
        const sale = {
          ...payload,
          local: {
            status: 'completed',
            cashier_name: user.name,
            customer_name: cart.customer?.name ?? null,
            methods: Object.fromEntries(tenders.map((tender) => [tender.id, { name: tender.method.name, type: tender.method.type }])),
            rate_ids: [...new Set([...result.lines.map((line) => line.rate?.id), result.changeRate?.id].filter(Boolean))],
            rounding_minor: result.roundingMinor,
            fiscal: 'pending',
            uoms: Object.fromEntries(computed.lines.map((line) => [line.id, line.uomCode])),
          },
        };
        await engine.enqueue('pos.sales', sale.id, payload, {
          group: shift.id,
          prepare: async () => [await posStore.prepareSale(sale), await posStore.prepareCounters(DOCUMENT_TYPES.receipt, number.used)],
        });
        afterDraw(number, DOCUMENT_TYPES.receipt, catalogue.timeZone);
        return sale;
      }),

    /** POS-05: void a whole sale of the open shift (pos.sale.void or an override). */
    voidSale: ({ sale, shift, user, actorProof, reason, override }) =>
      serial(async () => {
        if (sale.shift_id !== shift?.id) throw new SellingError('void_other_shift');
        const records = await posStore.recordsOfSale(sale.id);
        if (records.some((record) => record.recordKind === 'void')) throw new SellingError('sale_already_voided');
        if (records.some((record) => record.recordKind === 'refund')) throw new SellingError('sale_has_refunds');
        const id = override?.reference ?? uuidv7(now());
        const payload = voidPayload({ id, saleId: sale.id, voidedById: user.id, voidedAt: iso(), reason, override, actorProof });
        const voided = { ...sale, local: { ...sale.local, status: 'voided', void_id: id } };
        await engine.enqueue('pos.voids', id, payload, {
          group: shift.id,
          prepare: async () => [await posStore.prepareRecord('void', payload, { saleId: sale.id, shiftId: shift.id, at: now() }), await posStore.prepareSale(voided)],
        });
        return payload;
      }),

    /** What a refund of `requested` lines comes to, after earlier refunds of the sale. */
    refundQuote: async ({ sale, requested }) => {
      const earlier = (await posStore.recordsOfSale(sale.id)).filter((record) => record.recordKind === 'refund');
      const already = {};
      for (const refund of earlier) for (const line of refund.lines) already[line.sale_line_id] = String(Number(already[line.sale_line_id] ?? 0) + Number(line.qty));
      return { ...refundAmounts(sale, requested, already), already };
    },

    /**
     * POS-05: refund some lines (partial quantities) at the sale's own rates,
     * paid by `method` in `currency` (pos.sale.refund within the limit, or
     * an override). The refund number comes from the device's refund range.
     */
    refund: ({ sale, shift, user, actorProof, requested, method, currency, reason, override, catalogue, id: givenId }) =>
      serial(async () => {
        if (!shift) throw new SellingError('no_shift');
        if (sale.local?.status === 'voided') throw new SellingError('sale_already_voided');
        const earlier = (await posStore.recordsOfSale(sale.id)).filter((record) => record.recordKind === 'refund');
        const already = {};
        for (const refund of earlier) for (const line of refund.lines) already[line.sale_line_id] = String(Number(already[line.sale_line_id] ?? 0) + Number(line.qty));
        const amounts = refundAmounts(sale, requested, already);
        if (BigInt(amounts.totalMinor) <= 0n) throw new SellingError('refund_empty');
        const tender = refundTender(sale, amounts.totalMinor, currency, catalogue.money.decimals);
        if (!tender) throw new SellingError('refund_currency');
        const at = serverNow();
        const number = await draw(DOCUMENT_TYPES.refund, at, catalogue.timeZone);
        const id = givenId ?? override?.reference ?? uuidv7(now());
        const payload = refundPayload({
          id,
          saleId: sale.id,
          shiftId: shift.id,
          cashierId: user.id,
          number,
          refundedAt: new Date(at).toISOString(),
          reason,
          totalMinor: amounts.totalMinor,
          lines: amounts.lines.map((line) => ({ id: uuidv7(now()), saleLineId: line.saleLineId, qty: line.qty })),
          payments: [{ id: uuidv7(now()), methodId: method.id, currency, amountMinor: tender.amountMinor, inSaleMinor: tender.inSaleMinor, rate: tender.rate }],
          saleCurrency: sale.currency,
          override,
          actorProof,
        });
        const record = { ...payload, local: { method_type: method.type, method_name: method.name, lines: amounts.lines } };
        await engine.enqueue('pos.refunds', id, payload, {
          group: shift.id,
          prepare: async () => [await posStore.prepareRecord('refund', record, { saleId: sale.id, shiftId: shift.id, at: now() }), await posStore.prepareCounters(DOCUMENT_TYPES.refund, number.used)],
        });
        afterDraw(number, DOCUMENT_TYPES.refund, catalogue.timeZone);
        return record;
      }),
  };
}

/**
 * POS-04: a shift's expected cash per currency, from what this till
 * recorded (ShiftCash::expected): opening float + cash tendered on its
 * sales (voided ones excluded) − change given + pay-ins − pay-outs − cash
 * refunds. The server recomputes it from what it accepted.
 */
export function expectedCash({ shift, sales, records, cashMethodIds }) {
  const totals = new Map();
  const addTo = (currency, minor) => totals.set(currency, (totals.get(currency) ?? 0n) + BigInt(minor));
  for (const row of shift.opening_float ?? []) addTo(row.currency, row.amountMinor ?? row.amount_minor);
  for (const sale of sales) {
    if (sale.local?.status === 'voided') continue;
    for (const payment of sale.payments) if (cashMethodIds.has(payment.payment_method_id)) addTo(payment.currency, payment.amount_minor);
    if (sale.change) addTo(sale.change.currency, -BigInt(sale.change.amount_minor));
  }
  for (const record of records) {
    if (record.recordKind === 'cash_movement') addTo(record.currency, record.kind === 'pay_out' ? -BigInt(record.amount_minor) : BigInt(record.amount_minor));
    if (record.recordKind === 'refund') for (const payment of record.payments) if (cashMethodIds.has(payment.payment_method_id)) addTo(payment.currency, -BigInt(payment.amount_minor));
  }
  return totals;
}
