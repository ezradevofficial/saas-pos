import { NetworkError } from '../sync/api';
import { onCart, spentIn, TenderError, uncountedSales } from '../loyalty/tenders';
import { findRoom, roomKey } from './inHouse';

/**
 * HOT-22, HOT-35, NFR-04: the `room_charge` payment method type at the till.
 * The cashier types the room number; the till shows the guest's name (the
 * tender's label) so the cashier can confirm it is the right guest, and the
 * sale goes up with `tender: { room, folio_id }`. The server checks the
 * charge again when the sale arrives and keeps what it cannot post as a
 * charge exception; it never refuses a completed sale (device wins).
 *
 * Offline rule (HOT-35): the room must be in the synced in-house list, its
 * folio open (`allowed`), in the sale's currency, and when a limit is set
 * `balance + this charge + what this till already charged to that room since
 * the last pull + what the cart already holds` must not pass it. "Since the
 * last pull" is the loyalty rule (uncountedSales): sales not yet
 * acknowledged and sales after that entity's last pull.
 *
 * Online, a fresh answer comes from POST hotel/device/room-lookup (the
 * guest, the folio, the server's verdict on the amount). The server does not
 * know this till's unsent sales, so the amount asked for is this charge plus
 * what the cart holds. If the server cannot be reached the synced data is
 * used. Only the room number and the guest's name are ever kept or shown.
 *
 * Errors are codes the payment screen translates (`pos.loyalty.errors.*`):
 * room_required, amount, module_inactive, room_unknown, not_in_house,
 * folio_settled, currency_mismatch, limit_exceeded.
 */
const SERVER_REASONS = new Set(['module_inactive', 'room_unknown', 'not_in_house', 'folio_settled', 'currency_mismatch', 'limit_exceeded']);

const match = (room) => (spent) => roomKey(spent.tender?.room) === roomKey(room);

async function askServer({ api, room, amountMinor, currency }) {
  const body = { room, currency };
  if (/^[1-9]\d{0,14}$/.test(String(amountMinor))) body.amount_minor = Number(amountMinor);
  try {
    const response = await api.post('hotel/device/room-lookup', body);
    if (response.status === 200 && response.body?.data) return response.body.data;
    if (response.status === 404) return { allowed: false, reason: 'module_inactive' };
  } catch (error) {
    if (!(error instanceof NetworkError)) throw error;
  }
  return null;
}

function finish(guest, amount) {
  const tender = { room: String(guest.room_number) };
  if (guest.folio_id) tender.folio_id = String(guest.folio_id);
  return { amountMinor: String(amount), tender, label: guest.guest_name ? `${guest.room_number} · ${guest.guest_name}` : String(guest.room_number) };
}

export const roomChargeTender = {
  input: 'room',
  active: (catalogue) => Boolean(catalogue?.ext?.hotel),
  currencies: (method, catalogue) => [method.currency ?? catalogue.saleCurrency],
  async prepare({ input, amountMinor, currency, cart = null, catalogue, database, api, online }) {
    const typed = String(input ?? '').trim();
    if (!typed) throw new TenderError('room_required');
    const amount = BigInt(amountMinor ?? '0');
    if (amount <= 0n) throw new TenderError('amount');
    const hotel = catalogue?.ext?.hotel;
    if (!hotel) throw new TenderError('module_inactive');

    const synced = findRoom(hotel, typed);
    const held = onCart(cart, { type: 'room_charge', match: match(typed) });

    if (online && api) {
      const live = await askServer({ api, room: typed, amountMinor: String(amount + held), currency });
      if (live) {
        if (!live.allowed) throw new TenderError(SERVER_REASONS.has(live.reason) ? live.reason : 'not_in_house');
        // The server's answer wins on who the guest is and which folio.
        return finish({ room_number: live.room_number ?? typed, guest_name: live.guest_name ?? '', folio_id: live.folio_id ?? synced?.folio_id ?? null }, amount);
      }
    }

    // Offline (or the server could not be reached): the synced list decides.
    if (!synced) throw new TenderError('not_in_house');
    if (!synced.allowed) throw new TenderError('folio_settled');
    if (synced.currency !== currency) throw new TenderError('currency_mismatch', { currency: synced.currency });
    if (synced.limit_minor != null && synced.limit_minor !== '') {
      const charged = spentIn(await uncountedSales(database, 'hotel_in_house'), { type: 'room_charge', match: match(synced.room_number) });
      const used = BigInt(synced.balance_minor ?? '0') + charged + held;
      if (used + amount > BigInt(synced.limit_minor)) {
        const left = BigInt(synced.limit_minor) - used;
        throw new TenderError('limit_exceeded', { available: String(left > 0n ? left : 0n) });
      }
    }
    return finish(synced, amount);
  },
};
