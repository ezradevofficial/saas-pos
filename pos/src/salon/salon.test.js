import { memoryBackend } from '../device/credentials';
import { uuidv7 } from '../lib/random';
import { buildCatalogue } from '../pos/catalogue';
import { cartReducer } from '../pos/cart';
import { tenderProvider } from '../pos/extensions';
import { createServices } from '../services/services';
import { fakeServer } from '../test/fakeServer';
import { salonServer } from '../test/salonServer';
import { testDatabase } from '../test/testDatabase';
import { checkoutCart, CheckoutError, splitTip } from './checkout';
import { dayList, daySummary } from './day';
import { wireAppointment, wireQueue } from './records';
import { BATCH } from './salonSync';
import { salonCreditTender } from './credit';

// SAL-12, SAL-13, SAL-16..SAL-18, SAL-29, NFR-04: the salon day on the till, offline first, uploaded through
// POST salon/device/sync (idempotent, per-record results), checked out through the normal sale path.

const id = (n) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const IDS = {
  company: id(1), list: id(2), vat: id(3), cash: id(4), credit: id(5), uom: id(7), cut: id(8), beard: id(9), tip: id(10), fee: id(11), gel: id(12),
  user: id(20), amina: id(31), brian: id(32), chair: id(33), svcCut: id(41), svcBeard: id(42),
  exempt: id(6), a1: id(51), a2: id(52), a3: id(53), l1: id(61), l2: id(62), l3: id(63), l4: id(64), cust: id(70), other: id(71), pack: id(81), pack2: id(82), q1: id(91), range: id(95),
};
const NOW = Date.parse('2026-11-02T06:00:00Z'); // 09:00 in Nairobi
const TZ = 'Africa/Nairobi';

const settings = { id: 'device', timezone: TZ, company: { id: IDS.company, base_currency: 'KES', reporting_currencies: [] }, location: { name: 'Westlands' } };
const currencies = [{ code: 'KES', decimals: 2, cash_rounding_minor: '100' }];
const taxCodes = [
  { id: IDS.vat, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] },
  { id: IDS.exempt, code: 'EXEMPT', kind: 'exempt', rates: [{ rate: '0.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] },
];
const priceLists = [{ id: IDS.list, name: 'Retail', currency: 'KES', tax_inclusive: true, is_default: true }];
const methods = {
  cash: { id: IDS.cash, type: 'cash', name: 'Cash', currency: 'KES', position: 1 },
  credit: { id: IDS.credit, type: 'salon_credit', name: 'Salon credit', currency: null, position: 90 },
};
const item = (itemId, name, tax = IDS.vat) => ({ id: itemId, code: name.slice(0, 3).toUpperCase(), name, base_uom_id: IDS.uom, tax_code_id: tax, sellable: true, uoms: [], barcodes: [] });
const items = [item(IDS.cut, 'Haircut'), item(IDS.beard, 'Beard trim'), item(IDS.tip, 'Tip', IDS.exempt), item(IDS.fee, 'No-show fee', IDS.exempt), item(IDS.gel, 'Hair gel')];
const price = (n, itemId, amount) => ({ id: id(200 + n), price_list_id: IDS.list, item_id: itemId, uom_id: IDS.uom, amount_minor: String(amount), currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' });
const prices = [price(1, IDS.cut, 150000), price(2, IDS.beard, 50000), price(3, IDS.tip, 1), price(4, IDS.fee, 1), price(5, IDS.gel, 80000)];
const user = { id: IDS.user, name: 'Zawadi', permissions: ['pos.sale.create', 'salon.appointment.serve', 'salon.appointment.create', 'salon.queue.manage'], limits: {} };

const ranges = [
  { id: IDS.range, document_type: 'pos.receipt', period: 'all', pattern: 'R-SAL-{000001}', from: 1, to: 200, next: 1 },
  { id: id(96), document_type: 'pos.refund', period: 'all', pattern: 'F-SAL-{0001}', from: 1, to: 500, next: 1 },
];
const salonSettings = { id: IDS.company, branch_id: id(300), location_id: id(301), timezone: TZ, slot_minutes: 15, cancel_window_hours: 24, late_cancel_fee_percent: '50', no_show_fee_percent: '50', deposit_percent: '20', tip_item_id: IDS.tip, fee_item_id: IDS.fee, online_enabled: false };
const hours = [['2026-11-02T05:00:00.000Z', '2026-11-02T14:00:00.000Z'], ['2026-11-03T05:00:00.000Z', '2026-11-03T14:00:00.000Z']];
const staff = [
  { id: IDS.amina, kind: 'staff', name: 'Amina', title: 'Stylist', color: 'c1', user_id: IDS.user, work: hours, off: [['2026-11-02T09:00:00.000Z', '2026-11-02T10:00:00.000Z']] },
  { id: IDS.brian, kind: 'staff', name: 'Brian', title: 'Barber', color: 'c2', user_id: null, work: hours, off: [] },
  { id: IDS.chair, kind: 'resource', name: 'Chair 1', title: 'chair', color: null, user_id: null, work: [], off: [] },
];
const services = [
  { id: IDS.svcCut, item_id: IDS.cut, name: 'Haircut', category: 'Hair', kind: 'service', duration_minutes: 30, buffer_minutes: 0, staff_ids: [], resource_ids: [], addon_ids: [], price_minor: '150000' },
  { id: IDS.svcBeard, item_id: IDS.beard, name: 'Beard trim', category: 'Hair', kind: 'service', duration_minutes: 15, buffer_minutes: 0, staff_ids: [IDS.brian], resource_ids: [], addon_ids: [], price_minor: '50000' },
];
const line = (n, service, itemId, staffId, staffName, start, end, priceMinor) => ({ id: id(n), service_id: service, item_id: itemId, name: service === IDS.svcCut ? 'Haircut' : 'Beard trim', kind: 'service', staff_id: staffId, staff_name: staffName, resource_id: null, starts_at: start, ends_at: end, price_minor: priceMinor });
const appointment = (appointmentId, number, extra) => ({
  number, status: 'booked', source: 'staff', client_id: IDS.cust, client_name: 'Wanjiru', has_alerts: false, currency: 'KES', fee_minor: '0', fee_status: 'none', deposit_required_minor: '0', deposit_balance_minor: '0', sale_id: null, ...extra, id: appointmentId,
});
const schedule = [
  appointment(IDS.a1, 'SAL-1', { starts_at: '2026-11-02T07:00:00.000Z', ends_at: '2026-11-02T07:30:00.000Z', total_minor: '150000', has_alerts: true, deposit_required_minor: '30000', deposit_balance_minor: '30000', lines: [line(61, IDS.svcCut, IDS.cut, IDS.amina, 'Amina', '2026-11-02T07:00:00.000Z', '2026-11-02T07:30:00.000Z', '150000')] }),
  appointment(IDS.a2, 'SAL-2', { starts_at: '2026-11-02T08:00:00.000Z', ends_at: '2026-11-02T08:15:00.000Z', total_minor: '50000', client_id: IDS.other, client_name: 'Otieno', lines: [line(62, IDS.svcBeard, IDS.beard, IDS.brian, 'Brian', '2026-11-02T08:00:00.000Z', '2026-11-02T08:15:00.000Z', '50000')] }),
  appointment(IDS.a3, 'SAL-3', { status: 'no_show', starts_at: '2026-11-03T07:00:00.000Z', ends_at: '2026-11-03T07:30:00.000Z', total_minor: '150000', fee_minor: '75000', fee_status: 'due', lines: [line(63, IDS.svcCut, IDS.cut, IDS.amina, 'Amina', '2026-11-03T07:00:00.000Z', '2026-11-03T07:30:00.000Z', '150000')] }),
];
const packages = [
  { id: IDS.pack, customer_id: IDS.cust, name: '5 haircuts', sessions_left: 2, session_value_minor: '120000', currency: 'KES', expires_on: '2026-12-31', service_ids: [IDS.svcCut] },
  { id: IDS.pack2, customer_id: IDS.other, name: 'Beard club', sessions_left: 3, session_value_minor: '40000', currency: 'KES', expires_on: '2026-12-31', service_ids: [] },
];
const queue = [{ id: IDS.q1, guest_name: 'Kamau', client_id: null, service_ids: [IDS.svcCut], staff_id: null, joined_at: '2026-11-02T05:50:00.000Z', position: 1, wait_minutes: 10 }];

async function setup() {
  const server = fakeServer({ now: () => NOW });
  server.define('settings', { mode: 'snapshot', rows: [{ ...server.state.settings }] });
  server.define('pos_number_ranges', { mode: 'snapshot', module: 'pos', rows: ranges });
  const remote = salonServer(server);
  remote.define({ settings: salonSettings, staff, services, schedule, queue, packages });
  const services_ = createServices({ database: testDatabase(), api: server, credentialsBackend: memoryBackend(), netInfo: null });
  await services_.engine.sync();
  const catalogue = buildCatalogue({ settings, currencies, taxCodes, priceLists, paymentMethods: Object.values(methods), items, prices, staff: [user], ext: { salon: { settings: salonSettings } }, now: NOW });
  return { server, remote, services: services_, salon: services_.salon, engine: services_.engine, catalogue };
}

const proof = { user_id: IDS.user, device_id: id(400), kid: 'k1', signed_at: '2026-11-02T05:59:00Z', signature: 'sig' };
const tender = (method, amountMinor, extra = {}) => ({ id: uuidv7(NOW), method, currency: 'KES', amountMinor: String(amountMinor), ...extra });
const codeOf = (fn) => {
  try {
    fn();
  } catch (error) {
    return error.code;
  }
  return null;
};
const until = async (check) => {
  for (let i = 0; i < 100; i += 1) {
    if (await check()) return;
    await new Promise((resolve) => setTimeout(resolve, 5));
  }
  throw new Error('condition not met');
};

describe('the schedule offline (SAL-29, NFR-04)', () => {
  it('shows the pulled week with no connection: by day, by staff, with status, alerts, deposits and fees', async () => {
    const { server, engine, salon } = await setup();
    server.goOffline();
    engine.setNetwork('offline');

    // The module is on this till only because the settings row was pulled.
    expect((await salon.settings()).id).toBe(IDS.company);
    const data = await salon.load();
    expect(data.staff.map((row) => row.name)).toEqual(['Amina', 'Brian']);
    expect(data.resources.map((row) => row.name)).toEqual(['Chair 1']);
    expect(data.services).toHaveLength(2);

    const today = dayList(data.appointments, { date: '2026-11-02', timeZone: TZ });
    expect(today.map((view) => view.number)).toEqual(['SAL-1', 'SAL-2']);
    expect(today[0]).toMatchObject({ status: 'booked', has_alerts: true, deposit_balance_minor: '30000' });
    expect(dayList(data.appointments, { date: '2026-11-02', timeZone: TZ, staffId: IDS.brian }).map((view) => view.number)).toEqual(['SAL-2']);
    const tomorrow = dayList(data.appointments, { date: '2026-11-03', timeZone: TZ });
    expect(tomorrow[0]).toMatchObject({ status: 'no_show', fee_status: 'due', fee_minor: '75000' });
    expect(daySummary(today)).toMatchObject({ total: 2, booked: 2 });
    expect(data.queue.map((entry) => [entry.guest_name, entry.position, entry.wait_minutes])).toEqual([['Kamau', 1, 10]]);
  });
});

describe('the steps of the day and the queue (SAL-12, SAL-13, SAL-29)', () => {
  it('queues check-in, start and finish with the person and proof, shows them at once and uploads them once', async () => {
    const { server, remote, services, salon, engine } = await setup();
    server.goOffline();
    engine.setNetwork('offline');

    const checkIn = await salon.step(IDS.a1, 'check_in', { user, actorProof: proof });
    expect(checkIn).toMatchObject({ kind: 'event', type: 'check_in', appointment_id: IDS.a1, user_id: IDS.user, actor_proof: proof });
    expect(checkIn.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7/);
    // The same step twice is one record.
    expect(await salon.step(IDS.a1, 'check_in', { user, actorProof: proof })).toBeNull();
    await salon.step(IDS.a1, 'start', { user, actorProof: proof });
    expect((await salon.load()).appointments.find((view) => view.id === IDS.a1)).toMatchObject({ status: 'in_service', local: { pending: true } });
    await salon.step(IDS.a1, 'finish', { user, actorProof: proof });
    expect((await salon.load()).appointments.find((view) => view.id === IDS.a1).status).toBe('completed');
    // A closed appointment takes no step.
    await expect(salon.step(IDS.a3, 'check_in', { user, actorProof: proof })).rejects.toMatchObject({ code: 'appointment_closed' });
    await expect(salon.step(id(999), 'check_in', { user, actorProof: proof })).rejects.toMatchObject({ code: 'appointment_unknown' });

    const entry = (await salon.load()).queue[0];
    await salon.joinQueue({ guestName: 'Njeri', guestPhone: '0722000111', serviceIds: [IDS.svcCut], user, actorProof: proof });
    expect((await salon.load()).queue.map((row) => row.guest_name)).toEqual(['Kamau', 'Njeri']);
    const seated = await salon.seatQueue({ entry, staffId: IDS.brian, user, actorProof: proof });
    const after = await salon.load();
    expect(after.queue.map((row) => row.guest_name)).toEqual(['Njeri']);
    // The seated walk-in is on the schedule at once, checked in with Brian.
    expect(after.appointments.find((view) => view.id === seated.appointment_id)).toMatchObject({ status: 'checked_in', source: 'walk_in', client_name: 'Kamau', lines: [{ staff_id: IDS.brian }] });

    expect(server.state.requests.filter((request) => request.method === 'POST')).toHaveLength(0);
    expect(await salon.records.pendingCount()).toBe(5);

    // Back online: the answer to the first upload is lost after the server stored it; the resend adds nothing.
    server.goOnline();
    engine.setNetwork('online');
    remote.loseNextAnswer();
    await engine.sync().catch(() => {});
    expect(remote.count('events')).toBe(3);
    const second = await engine.sync();
    expect(second.pushed.salon).toMatchObject({ stored: 5, rejected: 0 });
    expect(remote.count('events')).toBe(3);
    expect(remote.count('queue')).toBe(2);
    expect(await salon.records.pendingCount()).toBe(0);
    // Every record went with the person and proof.
    const sent = [...remote.state.stored.events.values(), ...remote.state.stored.queue.values()];
    expect(sent.every((record) => record.user_id === IDS.user && record.actor_proof?.kid === 'k1')).toBe(true);
    expect(services.salon).toBe(salon);
  });

  it('books a walk-in from the services pulled and refuses a person who is busy or off', async () => {
    const { salon } = await setup();
    // Amina is off 12:00 to 13:00 Nairobi time (09:00Z to 10:00Z); a haircut now (06:00Z) is fine.
    const booked = await salon.bookWalkIn({ wants: [{ service_id: IDS.svcCut, staff_id: IDS.amina }, { service_id: IDS.svcBeard }], guestName: 'Mercy', user, actorProof: proof, startMs: NOW });
    expect(booked.lines.map((l) => [l.staff_id, l.starts_at])).toEqual([[IDS.amina, '2026-11-02T06:00:00.000Z'], [IDS.brian, '2026-11-02T06:30:00.000Z']]);
    expect(booked).toMatchObject({ kind: 'appointment', source: 'walk_in', guest_name: 'Mercy', actor_proof: proof });
    // Amina's 06:00 haircut is taken: the same time again is refused on the till.
    await expect(salon.bookWalkIn({ wants: [{ service_id: IDS.svcCut, staff_id: IDS.amina }], guestName: 'Other', user, actorProof: proof, startMs: NOW })).rejects.toMatchObject({ code: 'slot_taken' });
    // Beard trims are Brian's only.
    await expect(salon.bookWalkIn({ wants: [{ service_id: IDS.svcBeard, staff_id: IDS.amina }], guestName: 'Other', user, actorProof: proof, startMs: NOW })).rejects.toMatchObject({ code: 'staff_unqualified' });
    await expect(salon.bookWalkIn({ wants: [{ service_id: IDS.svcCut, staff_id: IDS.amina }], guestName: 'Other', user, actorProof: proof, startMs: Date.parse('2026-11-02T09:10:00Z') })).rejects.toMatchObject({ code: 'staff_off' });
    await expect(salon.bookWalkIn({ wants: [{ service_id: IDS.svcCut }], user, actorProof: proof })).rejects.toMatchObject({ code: 'client_required' });
  });

  it('parks a record the server refuses (slot_taken, actor_unverified), shows it, and can send it again', async () => {
    const { remote, salon, engine } = await setup();
    const walkIn = await salon.bookWalkIn({ wants: [{ service_id: IDS.svcCut, staff_id: IDS.brian }], guestName: 'Mercy', user, actorProof: proof, startMs: NOW });
    const step = await salon.step(IDS.a2, 'check_in', { user, actorProof: proof });
    remote.reject(walkIn.id, 'slot_taken');
    remote.reject(step.id, 'actor_unverified');
    const summary = (await engine.sync()).pushed.salon;
    expect(summary).toMatchObject({ rejected: 2, stored: 0 });
    const rejected = await salon.rejected();
    expect(rejected.map((record) => [record.kind, record.local.error.code]).sort()).toEqual([['appointment', 'slot_taken'], ['event', 'actor_unverified']]);
    // A refused appointment and a refused step have no effect on the day.
    const view = await salon.load();
    expect(view.appointments.find((row) => row.id === walkIn.id)).toBeUndefined();
    expect(view.appointments.find((row) => row.id === IDS.a2).status).toBe('booked');
    expect(await salon.records.pendingCount()).toBe(0);
    // Sent again after the cause is fixed.
    await salon.retry(step.id);
    expect(await salon.records.pendingCount()).toBe(1);
    expect(BATCH).toBe(100);
  });
});

describe('checkout (SAL-16, SAL-22)', () => {
  it('rings the appointment as a normal sale with the tip, the fee and the right extensions', async () => {
    const { services, catalogue, salon } = await setup();
    const { selling } = services;
    const shift = await selling.openShift({ user, actorProof: null, openingFloat: [] });
    const data = await salon.load();
    const visit = data.appointments.find((view) => view.id === IDS.a1);
    const tips = splitTip('20000', [IDS.amina, IDS.brian]);
    expect(tips).toEqual([{ staff_id: IDS.amina, amount_minor: '10000' }, { staff_id: IDS.brian, amount_minor: '10000' }]);
    expect(splitTip('10001', [IDS.amina, IDS.brian]).map((share) => share.amount_minor)).toEqual(['5001', '5000']);

    const cart = checkoutCart({ appointment: visit, settings: salonSettings, catalogue, tips, now: NOW });
    expect(cart.lines.map((l) => [l.name, l.unitPriceMinor, l.taxInclusive])).toEqual([['Haircut', '150000', true], ['Tip', '20000', false]]);
    expect(cart.lines[0].id).toBe(IDS.l1);
    expect(cart.customer).toMatchObject({ id: IDS.cust, name: 'Wanjiru' });
    expect(cart.ext.salon).toEqual({ appointment_id: IDS.a1, tips });

    const sale = await selling.completeSale({ shift, user, actorProof: null, cart, catalogue, tenders: [tender(methods.cash, 170000)] });
    expect(sale.extensions.salon).toEqual({ appointment_id: IDS.a1, tips });
    expect(sale.lines.map((l) => l.item_id)).toEqual([IDS.cut, IDS.tip]);
    // The visit is paid on the till at once.
    await until(async () => (await salon.load()).appointments.find((view) => view.id === IDS.a1).status === 'paid');
    await expect(salon.step(IDS.a1, 'check_in', { user, actorProof: null })).rejects.toMatchObject({ code: 'appointment_closed' });
    const paidView = (await salon.load()).appointments.find((view) => view.id === IDS.a1);
    expect(() => checkoutCart({ appointment: paidView, settings: salonSettings, catalogue })).toThrow(CheckoutError);
    expect(codeOf(() => checkoutCart({ appointment: paidView, settings: salonSettings, catalogue }))).toBe('already_paid');
  });

  it('rings the fee of a no-show as a fee sale, only while it is due', async () => {
    const { services, catalogue, salon } = await setup();
    const { selling } = services;
    const shift = await selling.openShift({ user, actorProof: null, openingFloat: [] });
    const noShow = (await salon.load()).appointments.find((view) => view.id === IDS.a3);
    expect(codeOf(() => checkoutCart({ appointment: noShow, settings: salonSettings, catalogue }))).toBe('appointment_closed');
    const cart = checkoutCart({ appointment: noShow, settings: salonSettings, catalogue, fee: true, now: NOW });
    expect(cart.lines).toHaveLength(1);
    expect(cart.lines[0]).toMatchObject({ itemId: IDS.fee, unitPriceMinor: '75000', taxInclusive: false });
    expect(cart.ext.salon).toEqual({ appointment_id: IDS.a3, fee: true });
    const sale = await selling.completeSale({ shift, user, actorProof: null, cart, catalogue, tenders: [tender(methods.cash, 75000)] });
    expect(sale.extensions.salon).toEqual({ appointment_id: IDS.a3, fee: true });
    await until(async () => (await salon.load()).appointments.find((view) => view.id === IDS.a3).fee_status === 'paid');
    const settled = (await salon.load()).appointments.find((view) => view.id === IDS.a3);
    expect(codeOf(() => checkoutCart({ appointment: settled, settings: salonSettings, catalogue, fee: true }))).toBe('no_fee_due');
    // Without the fee item in the settings nothing is rung.
    expect(codeOf(() => checkoutCart({ appointment: { ...noShow, fee_status: 'due' }, settings: { ...salonSettings, fee_item_id: null }, catalogue, fee: true }))).toBe('fee_item_missing');
  });
});

describe('salon credit at the payment screen (SAL-17, SAL-18)', () => {
  const prepare = (services, catalogue, cart, amountMinor) => tenderProvider('salon_credit', catalogue).prepare({ amountMinor: String(amountMinor), currency: 'KES', cart, catalogue, database: services.database, online: false });

  it('is offered only while the salon data is on the till', async () => {
    const { catalogue } = await setup();
    expect(tenderProvider('salon_credit', catalogue)).toBe(salonCreditTender);
    expect(tenderProvider('salon_credit', { ...catalogue, ext: {} })).toBeNull();
  });

  it('pays with the deposit first, never more than it holds, and counts what the till already spent from it', async () => {
    const { services, catalogue, salon } = await setup();
    const { selling } = services;
    const shift = await selling.openShift({ user, actorProof: null, openingFloat: [] });
    const visit = (await salon.load()).appointments.find((view) => view.id === IDS.a1);
    const cart = checkoutCart({ appointment: visit, settings: salonSettings, catalogue, now: NOW });

    const first = await prepare(services, catalogue, cart, 170000);
    expect(first).toMatchObject({ amountMinor: '30000', tender: { source: 'deposit', appointment_id: IDS.a1 } });
    const held = { ...cart, tenders: [tender(methods.credit, 30000, { tender: first.tender })] };
    // The cart already holds the whole deposit: the next payment is a package session (Wanjiru's haircut package).
    const second = await prepare(services, catalogue, held, 140000);
    expect(second).toMatchObject({ amountMinor: '120000', tender: { source: 'package', package_id: IDS.pack, service_id: IDS.svcCut, appointment_id: IDS.a1 }, label: '5 haircuts' });

    // Completing the sale puts the deposit and a session in the till's spending until the next pull.
    const payments = [tender(methods.credit, 30000, { tender: first.tender }), tender(methods.credit, 120000, { tender: second.tender })];
    const sale = await selling.completeSale({ shift, user, actorProof: null, cart, catalogue, tenders: payments });
    expect(sale.payments.map((p) => p.tender)).toEqual([first.tender, second.tender]);
    expect(sale.local.spent.map((s) => [s.type, s.tender.source, s.amountMinor])).toEqual([['salon_credit', 'deposit', '30000'], ['salon_credit', 'package', '120000']]);

    // A new cart for the same appointment: the deposit is spent, one package session is left (2 pulled, 1 spent).
    const again = checkoutCart({ appointment: visit, settings: salonSettings, catalogue, now: NOW, cartId: uuidv7(NOW) });
    const third = await prepare(services, catalogue, again, 150000);
    expect(third.tender).toMatchObject({ source: 'package', package_id: IDS.pack });
    // Once on the cart it cannot be used again: 2 sessions pulled, 1 spent by the sale above, 1 held here, none left.
    const twice = { ...again, tenders: [tender(methods.credit, 120000, { tender: third.tender })] };
    await expect(prepare(services, catalogue, twice, 30000)).rejects.toMatchObject({ code: 'package_none' });
  });

  it('refuses what cannot be honoured, with a code the screen translates', async () => {
    const { services, catalogue, salon } = await setup();
    const data = await salon.load();
    const noDeposit = data.appointments.find((view) => view.id === IDS.a2);
    const cartFor = (appointment, patch = {}) => ({ ...checkoutCart({ appointment, settings: salonSettings, catalogue, now: NOW }), ...patch });

    // Not a salon sale.
    await expect(prepare(services, catalogue, { id: 'x', lines: [], tenders: [], ext: {} }, 1000)).rejects.toMatchObject({ code: 'no_appointment', messageKey: 'salon.errors.no_appointment' });
    // A package of another customer, or one that does not cover the service, is not offered.
    const beard = cartFor(noDeposit);
    const ok = await prepare(services, catalogue, beard, 50000);
    expect(ok).toMatchObject({ amountMinor: '40000', tender: { source: 'package', package_id: IDS.pack2, service_id: IDS.svcBeard } });
    const wrongCustomer = cartFor(noDeposit, { customer: { id: id(999), name: 'Stranger' } });
    await expect(prepare(services, catalogue, wrongCustomer, 50000)).rejects.toMatchObject({ code: 'package_none' });
    const noCustomer = cartFor(noDeposit, { customer: null });
    await expect(prepare(services, catalogue, noCustomer, 50000)).rejects.toMatchObject({ code: 'customer_required' });
    // An expired package is not offered.
    await services.database.write(async () => {
      const [row] = await services.database.get('salon_packages').query().fetch();
      await row.update((r) => r._setRaw('data', JSON.stringify({ ...JSON.parse(r._raw.data), expires_on: '2026-10-01' })));
    });
    await expect(prepare(services, catalogue, cartFor(data.appointments.find((view) => view.id === IDS.a1), { tenders: [tender(methods.credit, 30000, { tender: { source: 'deposit', appointment_id: IDS.a1 } })] }), 100000)).rejects.toMatchObject({ code: 'package_none' });
    // A fee is never paid by a package.
    const feeCart = checkoutCart({ appointment: data.appointments.find((view) => view.id === IDS.a3), settings: salonSettings, catalogue, fee: true, now: NOW });
    await expect(prepare(services, catalogue, feeCart, 1000)).rejects.toMatchObject({ code: 'deposit_empty' });
  });

  it('lets the cart reducer keep the tender and the ext of the salon', async () => {
    const { catalogue, salon } = await setup();
    const visit = (await salon.load()).appointments.find((view) => view.id === IDS.a1);
    const cart = checkoutCart({ appointment: visit, settings: salonSettings, catalogue, now: NOW });
    const next = cartReducer(cart, { type: 'addTender', saleId: cart.id, tender: tender(methods.credit, 1000) });
    expect(next.ext.salon.appointment_id).toBe(IDS.a1);
    expect(next.tenders).toHaveLength(1);
  });
});

describe('upload shape', () => {
  it('names the client of a booking and of a queue entry in salon_client_id', () => {
    const booked = wireAppointment({ id: id(90), user_id: id(91), client_id: id(92), starts_at: '2026-11-02T08:00:00.000Z', lines: [{ service_id: id(8) }] });
    const queued = wireQueue({ id: id(93), user_id: id(91), action: 'join', client_id: id(92) });
    expect(booked.salon_client_id).toBe(id(92));
    expect(queued.salon_client_id).toBe(id(92));
    expect(booked.client_id).toBeUndefined();
    expect(queued.client_id).toBeUndefined();
  });
});
