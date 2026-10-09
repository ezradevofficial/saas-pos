import { Q } from '@nozbe/watermelondb';

/**
 * POS-01..POS-06, NFR-04: the till's own selling records (schema v4).
 *
 * - pos_sales: completed sales as sold (the payload sent, plus local
 *   extras: the rate rows used, the customer's name), for receipts, voids
 *   and returns, offline.
 * - pos_records: voids, refunds and cash movements.
 * - pos_shifts: shifts opened on this till, with their closing.
 * - pos_held: parked carts (POS-02); they never reach the server.
 * - pos_counters: receipt numbers used per range and document type.
 *
 * prepare*() return WatermelonDB operations for engine.enqueue's `prepare`,
 * so a record and its upload commit in one transaction. Reads return the
 * stored objects.
 */
export function createPosStore(database) {
  const table = (name) => database.get(name);

  async function findOne(name, id) {
    try {
      return await table(name).find(id);
    } catch {
      return null;
    }
  }

  async function prepareUpsert(name, id, columns) {
    const existing = await findOne(name, id);
    if (existing) {
      return existing.prepareUpdate((record) => {
        for (const [key, value] of Object.entries(columns)) record._setRaw(key, value);
      });
    }
    return table(name).prepareCreateFromDirtyRaw({ id, ...columns });
  }

  const rows = (records) => records.map((record) => record.data).filter(Boolean);
  const write = async (operations) => {
    const list = (await Promise.all(operations)).filter(Boolean);
    if (list.length) await database.write(() => database.batch(...list));
  };

  const store = {
    database,

    // -- Sales -------------------------------------------------------------

    prepareSale(sale) {
      return prepareUpsert('pos_sales', sale.id, {
        receipt_number: sale.receipt_number,
        shift_id: sale.shift_id,
        status: sale.local?.status ?? 'completed',
        sold_at: Date.parse(sale.sold_at) || Date.now(),
        data: JSON.stringify(sale),
      });
    },

    async sale(id) {
      return (await findOne('pos_sales', id))?.data ?? null;
    },

    async saleByReceipt(number) {
      const found = await table('pos_sales').query(Q.where('receipt_number', String(number).trim())).fetch();
      return found[0]?.data ?? null;
    },

    async salesOfShift(shiftId) {
      return rows(await table('pos_sales').query(Q.where('shift_id', shiftId), Q.sortBy('sold_at', Q.desc)).fetch());
    },

    async recentSales(limit = 50) {
      return rows(await table('pos_sales').query(Q.sortBy('sold_at', Q.desc), Q.take(limit)).fetch());
    },

    // -- Voids, refunds, cash movements --------------------------------------

    prepareRecord(kind, record, { saleId = null, shiftId, at = Date.now() }) {
      return prepareUpsert('pos_records', record.id, {
        kind,
        sale_id: saleId,
        shift_id: shiftId,
        created_at: at,
        data: JSON.stringify(record),
      });
    },

    async recordsOfSale(saleId) {
      const found = await table('pos_records').query(Q.where('sale_id', saleId), Q.sortBy('created_at', Q.asc)).fetch();
      return found.map((record) => ({ ...record.data, recordKind: record._raw.kind }));
    },

    async recordsOfShift(shiftId) {
      const found = await table('pos_records').query(Q.where('shift_id', shiftId), Q.sortBy('created_at', Q.asc)).fetch();
      return found.map((record) => ({ ...record.data, recordKind: record._raw.kind }));
    },

    // -- Shifts ------------------------------------------------------------

    prepareShift(shift) {
      return prepareUpsert('pos_shifts', shift.id, {
        status: shift.closing ? 'closed' : 'open',
        opened_at: Date.parse(shift.opened_at) || Date.now(),
        data: JSON.stringify(shift),
      });
    },

    async openShift() {
      const found = await table('pos_shifts').query(Q.where('status', 'open'), Q.sortBy('opened_at', Q.desc), Q.take(1)).fetch();
      return found[0]?.data ?? null;
    },

    async shift(id) {
      return (await findOne('pos_shifts', id))?.data ?? null;
    },

    /** The open shift the server knows for this till (a reinstalled till resumes it). */
    async serverOpenShift() {
      const found = await table('pos_open_shift').query().fetch();
      return found[0]?.data ?? null;
    },

    // -- Held sales (POS-02) -----------------------------------------------

    async held() {
      return rows(await table('pos_held').query(Q.sortBy('created_at', Q.desc)).fetch());
    },

    async hold(cart, at = Date.now()) {
      await write([prepareUpsert('pos_held', cart.id, { created_at: at, data: JSON.stringify({ ...cart, heldAt: at }) })]);
    },

    async unhold(id) {
      const record = await findOne('pos_held', id);
      if (record) await database.write(() => database.batch(record.prepareDestroyPermanently()));
    },

    // -- Receipt counters (NUM-02) -----------------------------------------

    async counters(documentType) {
      return (await findOne('pos_counters', documentType))?.data ?? {};
    },

    prepareCounters(documentType, used) {
      return prepareUpsert('pos_counters', documentType, { data: JSON.stringify(used) });
    },

    async numberRanges() {
      return rows(await table('pos_number_ranges').query().fetch());
    },

    // -- Synced master data for selling ------------------------------------

    async all(name) {
      return rows(await table(name).query().fetch());
    },

    write,
  };

  return store;
}
