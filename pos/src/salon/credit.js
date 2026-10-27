import { onCart, spentIn, TenderError, uncountedSales } from '../loyalty/tenders';
import { localDate } from '../sync/prices';
import { createSalonStore } from './salonStore';

/**
 * SAL-17, SAL-18: the `salon_credit` payment method type at the till (extensions.js tender provider), only while the
 * salon's data is on the till. It pays part of a visit with prepaid value:
 *
 * - **deposit** (`tender: { source: 'deposit', appointment_id }`): what the appointment holds, from the pulled
 *   schedule less what this till already spent from it that the server may not have counted (its sales not
 *   acknowledged and its sales after the last pull) and less what the cart already holds;
 * - **package** (`tender: { source: 'package', package_id, service_id }`): one session of a live package of the
 *   customer on the sale that covers a service of the cart, worth `session_value_minor`; sessions left are those
 *   pulled less what this till spent and what the cart holds, so a package is never put on two payments past its
 *   sessions, and a session already on the cart is not used twice.
 *
 * The deposit is used first; when it is spent the next payment is a package session. Offline the server cannot
 * be asked: it checks again when the sale arrives and keeps what it cannot honour as an exception, never refusing the
 * sale (device wins). `prepare()` resolves { amountMinor, tender, label } or throws a TenderError with a code the
 * payment screen translates (`salon.errors.*`).
 */
const refuse = (code, extra = {}) => new TenderError(code, { messageKey: `salon.errors.${code}`, ...extra });

export const salonActive = (catalogue) => Boolean(catalogue?.ext?.salon?.settings);

const sourceOf = (type) => (spent) => spent.type === 'salon_credit' && spent.tender?.source === type;

/** What an appointment's deposit has left for this till: the pulled balance less this till's uncounted spending and the cart. */
export async function depositLeft({ database, appointmentId, cart = null }) {
  const store = createSalonStore(database);
  const row = (await store.schedule()).find((appointment) => appointment.id === appointmentId);
  if (!row) return 0n;
  // A deposit the till's own sale used is counted in the pulled balance only after the next pull.
  const sales = await uncountedSales(database, 'salon_schedule');
  const spent = spentIn(sales, { type: 'salon_credit', match: (item) => item.tender?.source === 'deposit' && item.tender?.appointment_id === appointmentId });
  const held = onCart(cart, { type: 'salon_credit', match: (tender) => tender.tender?.source === 'deposit' && tender.tender?.appointment_id === appointmentId });
  const left = BigInt(row.deposit_balance_minor ?? '0') - spent - held;
  return left > 0n ? left : 0n;
}

/** The live packages of a customer, each with the sessions it has left for this till and its next session's value. */
export async function packagesOf({ database, customerId, cart = null, day }) {
  const store = createSalonStore(database);
  const sales = await uncountedSales(database, 'salon_packages');
  const used = new Map();
  const count = (id) => used.set(id, (used.get(id) ?? 0) + 1);
  for (const sale of sales) {
    for (const spent of sale.local?.spent ?? []) if (sourceOf('package')(spent)) count(spent.tender.package_id);
  }
  for (const tender of cart?.tenders ?? []) {
    if (tender.method?.type === 'salon_credit' && tender.tender?.source === 'package') count(tender.tender.package_id);
  }
  return (await store.packages())
    .filter((pack) => pack.customer_id === customerId && (!pack.expires_on || pack.expires_on >= day))
    .map((pack) => ({ ...pack, sessions_left: Number(pack.sessions_left) - (used.get(pack.id) ?? 0) }))
    .filter((pack) => pack.sessions_left > 0);
}

/** The salon services of the cart not yet paid by a package session of this cart. */
export function unpaidServices(cart) {
  const lines = cart?.ext?.salon_lines ?? {};
  const paid = new Set((cart?.tenders ?? []).filter((tender) => tender.method?.type === 'salon_credit' && tender.tender?.source === 'package').map((tender) => tender.tender.line_id));
  return Object.entries(lines).filter(([lineId]) => !paid.has(lineId)).map(([lineId, serviceId]) => ({ lineId, serviceId }));
}

export const salonCreditTender = {
  input: null,
  active: salonActive,
  currencies: (method, catalogue) => [method.currency ?? catalogue.saleCurrency],
  async prepare({ amountMinor, currency, cart, catalogue, database }) {
    const link = cart?.ext?.salon;
    const appointmentId = link?.appointment_id;
    if (!appointmentId) throw refuse('no_appointment');
    const requested = BigInt(amountMinor ?? '0');
    if (requested <= 0n) throw refuse('amount');
    const store = createSalonStore(database);
    const appointment = (await store.schedule()).find((row) => row.id === appointmentId);
    if (appointment && appointment.currency && appointment.currency !== currency) throw refuse('currency_mismatch', { currency: appointment.currency });

    const deposit = await depositLeft({ database, appointmentId, cart });
    if (deposit > 0n) {
      const amount = requested < deposit ? requested : deposit;
      return { amountMinor: String(amount), tender: { source: 'deposit', appointment_id: appointmentId }, label: null };
    }

    // No fee or tip is paid by a package, only the services it covers.
    if (link.fee) throw refuse('deposit_empty');
    if (!cart.customer?.id) throw refuse('customer_required');
    const day = localDate(Date.now(), catalogue.timeZone);
    const packs = await packagesOf({ database, customerId: cart.customer.id, cart, day });
    if (!packs.length) throw refuse('package_none');
    const wanted = unpaidServices(cart);
    if (!wanted.length) throw refuse('package_covers_nothing');
    for (const { lineId, serviceId } of wanted) {
      const pack = packs.find((candidate) => !candidate.service_ids?.length || candidate.service_ids.includes(serviceId));
      if (!pack) continue;
      const value = BigInt(pack.session_value_minor);
      const amount = requested < value ? requested : value;
      return { amountMinor: String(amount), tender: { source: 'package', package_id: pack.id, service_id: serviceId, appointment_id: appointmentId, line_id: lineId }, label: pack.name };
    }
    throw refuse('package_covers_nothing');
  },
};
