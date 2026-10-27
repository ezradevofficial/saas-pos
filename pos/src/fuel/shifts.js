import { Q } from '@nozbe/watermelondb';
import { uuidv7 } from '../lib/random';
import { compare } from '../pos/exact';
import { checkClosing, checkOpening, dipToLitres, FuelError, meterLitres, parseCm, parseLitres, priceAt, showLitres, unrecordedLitres, lineTotal } from './meters';
import { can, FUEL } from './permissions';
import { KINDS, summarise } from './tenders';

/**
 * FUEL-09, FUEL-10, FUEL-22, FUEL-31, NFR-04: the forecourt shift on the
 * till, offline first. A shift is opened with the opening reading and the
 * attendant of each nozzle in use, and closed with the closing reading of
 * every nozzle, the pump tests and the tenders each attendant hands in. Each
 * is written locally (`fuel_shifts`) and put in the outbox in one
 * transaction, grouped by the shift id so the open goes up before the close
 * (POST fuel/device/shifts); the sales name the shift in `extensions.fuel`.
 * Both records carry the sign-in attestation `actor_proof` of the person
 * signed in (AUTH-07). The server decides everything (FUEL-28): readings
 * below the last one, an incomplete close and the variance are its to say.
 *
 * Mirrors DeviceShiftsRequest and ShiftUploads: the closing repeats the
 * opening unchanged (the server compares its hash).
 */
const clean = (object) => Object.fromEntries(Object.entries(object).filter(([, value]) => value !== undefined && value !== null));

/** The `opening` object of a shift upload. */
export function openingPayload({ openedById, openedAt, actorProof, nozzles, dips }) {
  return clean({
    opened_by_id: openedById,
    opened_at: openedAt,
    actor_proof: actorProof ?? undefined,
    nozzles: nozzles.map((row) => ({ nozzle_id: row.nozzle_id, attendant_id: row.attendant_id, reading: showLitres(row.reading) })),
    dips: dips?.length ? dips.map(dipPayload) : undefined,
  });
}

/** The `closing` object of a shift upload. `tenders` = [{ attendant_id, tenders: [{ kind, amount_minor }] }]. */
export function closingPayload({ closedById, closedAt, actorProof, note, nozzles, tenders, dips }) {
  return clean({
    closed_by_id: closedById,
    closed_at: closedAt,
    actor_proof: actorProof ?? undefined,
    note: note || undefined,
    nozzles: nozzles.map((row) => clean({ nozzle_id: row.nozzle_id, reading: showLitres(row.reading), test_litres: row.test_litres ? showLitres(row.test_litres) : undefined })),
    tenders: tenders.map((entry) => ({ attendant_id: entry.attendant_id, tenders: entry.tenders.map((tender) => ({ kind: tender.kind, amount_minor: String(tender.amount_minor) })) })),
    dips: dips?.length ? dips.map(dipPayload) : undefined,
  });
}

const dipPayload = (dip) => clean({ id: dip.id, tank_id: dip.tank_id, dip_cm: dip.dip_cm, water_cm: dip.water_cm || undefined, taken_at: dip.taken_at });

/** One shift upload: the opening, and the closing once there is one. */
export const shiftPayload = ({ id, opening, closing }) => (closing ? { id, opening, closing } : { id, opening });

/** A short label of a nozzle for people: "P1 / N2". */
export const nozzleLabel = (nozzle) => `${nozzle.pump_code} / ${nozzle.code}`;

/**
 * FUEL-16: the last reading the till knows of a nozzle: the synced one, unless a shift closed here since
 * (its closing reading is newer than the sync). Resolves a decimal string or null (never read).
 */
export function lastKnownReading(nozzle, closedShifts = []) {
  let reading = nozzle.last_reading_litres ?? null;
  let at = nozzle.last_reading_at ? Date.parse(nozzle.last_reading_at) : -Infinity;
  for (const shift of closedShifts) {
    const row = shift.closing?.nozzles?.find((entry) => entry.nozzle_id === nozzle.id);
    const closedAt = shift.closing?.closed_at ? Date.parse(shift.closing.closed_at) : NaN;
    if (row && closedAt > at) {
      reading = row.reading;
      at = closedAt;
    }
  }
  return reading;
}

/**
 * FUEL-16, FUEL-17: after an approved reset the synced nozzle row carries the reading after it as its last
 * reading, below the shift's opening one: that is the floor of the closing reading (null: no reset to know of).
 */
export function resetFloor(nozzle, shiftNozzle, shift) {
  if (!nozzle?.last_reading_litres || !nozzle.last_reading_at) return null;
  if (Date.parse(nozzle.last_reading_at) < Date.parse(shift.opened_at)) return null;
  return compare(nozzle.last_reading_litres, shiftNozzle.opening_reading) < 0 ? showLitres(nozzle.last_reading_litres) : null;
}

/** The attendants of a shift in nozzle order, once each: [{ id, name }]. */
export function attendantsOf(shift) {
  const seen = new Map();
  for (const row of shift.nozzles) if (!seen.has(row.attendant_id)) seen.set(row.attendant_id, { id: row.attendant_id, name: row.attendant_name });
  return [...seen.values()];
}

/**
 * FUEL-10, FUEL-11, FUEL-13: the variance the attendants would see now, as a preview (the server decides and may
 * differ). Per attendant: expected = the value of the sales on their nozzles + the unrecorded litres x the price in
 * force at `at`; collected = the cash they declare + the other tenders their sales recorded.
 * `readings` = { [nozzleId]: { reading, tests } }, `declared` = { [attendantId]: { cash: minor } }.
 */
export function previewVariance({ shift, summary, readings, declared, fuel, at }) {
  return attendantsOf(shift).map((attendant) => {
    let expected = summary.attendants[attendant.id]?.valueMinor ?? 0n;
    for (const row of shift.nozzles.filter((entry) => entry.attendant_id === attendant.id)) {
      const entry = readings[row.nozzle_id];
      const closing = parseLitres(entry?.reading);
      const tests = parseLitres(entry?.tests || '0');
      if (closing === null || tests === null) continue;
      const meter = meterLitres({ opening: row.opening_reading, closing, tests });
      const recorded = summary.nozzles[row.nozzle_id]?.litres ?? '0.000';
      const unrecorded = unrecordedLitres(meter, recorded);
      const nozzle = fuel?.nozzles?.find((candidate) => candidate.id === row.nozzle_id);
      const price = nozzle ? priceAt(fuel.prices, nozzle.product_id, at)?.price_minor : null;
      if (price && compare(unrecorded, 0) > 0) expected += BigInt(lineTotal(price, unrecorded));
    }
    const others = Object.entries(summary.attendants[attendant.id]?.tenders ?? {}).filter(([kind]) => kind !== 'cash').reduce((sum, [, amount]) => sum + amount, 0n);
    const cash = declared[attendant.id]?.cash;
    const collected = cash === undefined || cash === '' ? null : BigInt(cash);
    return {
      attendantId: attendant.id,
      name: attendant.name,
      expectedMinor: expected,
      collectedMinor: collected === null ? null : collected + others,
      varianceMinor: collected === null ? null : collected + others - expected,
    };
  });
}

export function createFuelShifts({ engine, store, database, now = () => Date.now(), serverNow = () => engine.serverNow() }) {
  const table = () => database.get('fuel_shifts');
  let queue = Promise.resolve();
  const serial = (task) => {
    const run = queue.then(task, task);
    queue = run.catch(() => {});
    return run;
  };
  const iso = () => new Date(serverNow()).toISOString();

  async function find(id) {
    try {
      return await table().find(id);
    } catch {
      return null;
    }
  }

  async function prepareShift(shift) {
    const columns = { status: shift.status, opened_at: Date.parse(shift.opened_at) || now(), data: JSON.stringify(shift) };
    const existing = await find(shift.id);
    if (existing) return existing.prepareUpdate((record) => Object.entries(columns).forEach(([key, value]) => record._setRaw(key, value)));
    return table().prepareCreateFromDirtyRaw({ id: shift.id, ...columns });
  }

  const rows = (records) => records.map((record) => record.data).filter(Boolean);

  const service = {
    /** The forecourt shift open on this till, or null (resumes after a restart). */
    async current() {
      const open = await table().query(Q.where('status', 'open'), Q.sortBy('opened_at', Q.desc), Q.take(1)).fetch();
      return open[0]?.data ?? null;
    },

    async get(id) {
      return (await find(id))?.data ?? null;
    },

    /** The latest closed shifts kept here (their closing readings are the nozzles' last readings until a sync). */
    async closed(limit = 5) {
      return rows(await table().query(Q.where('status', 'closed'), Q.sortBy('opened_at', Q.desc), Q.take(limit)).fetch());
    },

    /** The sales rung on this till for a shift: running litres and value per nozzle and the tenders per attendant. */
    async running(shift) {
      const sales = rows(await database.get('pos_sales').query(Q.where('sold_at', Q.gte(Date.parse(shift.opened_at) - 5 * 60 * 1000))).fetch());
      return summarise(shift, sales);
    },

    /** The shift uploads the server kept for review (refused for good), with its message: [{ kind, recordId, code, message }]. */
    async problems() {
      const failed = await store.entries('failed');
      return failed
        .filter((entry) => entry.kind.startsWith('fuel.'))
        .map((entry) => ({ kind: entry.kind, recordId: entry.recordId, code: entry.lastError?.code ?? null, message: entry.lastError?.message ?? null }));
    },

    /**
     * FUEL-09: open a shift. `rows` = [{ nozzle (a synced fuel_nozzles row), attendantId, attendantName, reading }];
     * `dips` = [{ tank, dipCm, waterCm }] taken now (optional). Resolves the stored shift.
     * Throws FuelError: not_permitted, shift_open, nozzles_required, attendant_required, reading_invalid,
     * meter_decreased (with `nozzleId`, `last`), nozzle_duplicate, dip_*.
     */
    open: ({ user, actorProof, station, rows: nozzleRows, dips = [] }) =>
      serial(async () => {
        if (!can(user, FUEL.shiftOpen)) throw new FuelError('not_permitted');
        if (await service.current()) throw new FuelError('shift_open');
        if (!nozzleRows?.length) throw new FuelError('nozzles_required');
        const ids = nozzleRows.map((row) => row.nozzle.id);
        if (new Set(ids).size !== ids.length) throw new FuelError('nozzle_duplicate');
        const closed = await service.closed(10);
        const at = iso();
        const opened = [];
        for (const row of nozzleRows) {
          if (!row.attendantId) throw new FuelError('attendant_required', { nozzleId: row.nozzle.id });
          try {
            checkOpening(row.reading, lastKnownReading(row.nozzle, closed));
          } catch (error) {
            if (error instanceof FuelError) error.nozzleId = row.nozzle.id;
            throw error;
          }
          opened.push({ nozzle_id: row.nozzle.id, attendant_id: row.attendantId, reading: parseLitres(row.reading) });
        }
        const openingDips = dips.map((dip) => makeDip(dip, at, serverNow));
        const opening = openingPayload({ openedById: user.id, openedAt: at, actorProof, nozzles: opened, dips: openingDips });
        const shift = {
          id: uuidv7(now()),
          station_id: station?.id ?? null,
          status: 'open',
          opened_at: at,
          opened_by_id: user.id,
          opened_by_name: user.name,
          opening,
          nozzles: nozzleRows.map((row) => ({ nozzle_id: row.nozzle.id, label: nozzleLabel(row.nozzle), attendant_id: row.attendantId, attendant_name: row.attendantName ?? '', opening_reading: showLitres(parseLitres(row.reading)) })),
          dips: [],
          closing: null,
        };
        await engine.enqueue('fuel.shifts', shift.id, shiftPayload({ id: shift.id, opening }), { group: shift.id, prepare: async () => [await prepareShift(shift)] });
        return shift;
      }),

    /**
     * FUEL-23, FUEL-06: a tank dip during the shift, converted by the synced chart (outside it: refused
     * `dip_out_of_range`). It goes up with the close, as a `shift_close` dip. Resolves { dip, litres }.
     */
    addDip: ({ user, shift, tank, dipCm, waterCm }) =>
      serial(async () => {
        if (!can(user, FUEL.dip)) throw new FuelError('not_permitted');
        const current = await service.get(shift.id);
        if (!current || current.status !== 'open') throw new FuelError('no_shift');
        const dip = makeDip({ tank, dipCm, waterCm }, iso(), serverNow);
        const litres = dipToLitres(tank, dip.dip_cm, serverNow()).litres;
        const next = { ...current, dips: [...(current.dips ?? []), dip] };
        await database.write(async () => database.batch(await prepareShift(next)));
        return { dip, litres, shift: next };
      }),

    /**
     * FUEL-10: close the shift. `readings` = { [nozzleId]: { reading, tests } }, `declared` = { [attendantId]: { cash,
     * mobile_money?, card?, credit?, voucher? } } in minor units (digit strings; cash at least, zero allowed),
     * `dips` = more dips taken now (optional). Refused `close_incomplete` (with `missing`) until every nozzle has a
     * reading and every attendant has declared cash, `meter_decreased` and `test_exceeds_litres` as the server does.
     */
    close: ({ user, actorProof, shift, readings, declared, note, dips = [], nozzles: synced = [] }) =>
      serial(async () => {
        if (!can(user, FUEL.shiftClose)) throw new FuelError('not_permitted');
        const current = await service.get(shift.id);
        if (!current || current.status !== 'open') throw new FuelError('no_shift');
        const missing = [];
        for (const row of current.nozzles) if (!readings?.[row.nozzle_id]?.reading) missing.push(row.label);
        for (const attendant of attendantsOf(current)) {
          const cash = declared?.[attendant.id]?.cash;
          if (cash === undefined || cash === null || cash === '') missing.push(attendant.name);
        }
        if (missing.length) throw new FuelError('close_incomplete', { missing: missing.join(', ') });
        const closingNozzles = [];
        for (const row of current.nozzles) {
          const entry = readings[row.nozzle_id];
          try {
            checkClosing({ opening: row.opening_reading, reading: entry.reading, tests: entry.tests, latestResetAfter: resetFloor(synced.find((nozzle) => nozzle.id === row.nozzle_id), row, current) });
          } catch (error) {
            if (error instanceof FuelError) error.nozzleId = row.nozzle_id;
            throw error;
          }
          const tests = entry.tests ? parseLitres(entry.tests) : null;
          closingNozzles.push({ nozzle_id: row.nozzle_id, reading: parseLitres(entry.reading), test_litres: tests && compare(tests, 0) > 0 ? tests : undefined });
        }
        const at = iso();
        const tenders = attendantsOf(current).map((attendant) => ({
          attendant_id: attendant.id,
          tenders: KINDS.filter((kind) => declared[attendant.id][kind] !== undefined && declared[attendant.id][kind] !== '').map((kind) => ({ kind, amount_minor: declared[attendant.id][kind] })),
        }));
        const closeDips = [...(current.dips ?? []), ...dips.map((dip) => makeDip(dip, at, serverNow))];
        const closing = closingPayload({ closedById: user.id, closedAt: at, actorProof, note, nozzles: closingNozzles, tenders, dips: closeDips });
        const closed = { ...current, status: 'closed', closed_at: at, closing, closed_by_name: user.name };
        await engine.enqueue('fuel.shifts', current.id, shiftPayload({ id: current.id, opening: current.opening, closing }), { group: current.id, prepare: async () => [await prepareShift(closed)] });
        return closed;
      }),
  };
  return service;
}

/** A dip as the upload takes it; the cm are validated (up to 2 decimals) and the chart must cover them. */
function makeDip({ tank, dipCm, waterCm }, takenAt, serverNow) {
  const cm = parseCm(dipCm);
  if (cm === null) throw new FuelError('dip_invalid');
  const water = waterCm === undefined || waterCm === null || waterCm === '' ? null : parseCm(waterCm);
  if (waterCm && water === null) throw new FuelError('dip_invalid');
  dipToLitres(tank, cm, serverNow());
  return { id: uuidv7(serverNow()), tank_id: tank.id, dip_cm: cm, ...(water ? { water_cm: water } : {}), taken_at: takenAt };
}
