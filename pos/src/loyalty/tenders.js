import { Q } from '@nozbe/watermelondb';
import { sha256 } from '@noble/hashes/sha2.js';
import { can } from '../pos/authority';
import { NetworkError } from '../sync/api';
import { redeemValue } from './engine';

/**
 * LOY-05, LOY-15, LOY-22, LOY-23: points, gift cards and store credit as
 * tenders at the till (extensions.js tender providers), only while the
 * Loyalty module's data is on the till (H3: `catalogue.ext.loyalty`).
 *
 * Offline rule (docs/modules/loyalty.md, Decisions): a balance is the last
 * synced one less what this till spent from it that the server may not
 * have counted yet (M4: its sales not acknowledged, and its sales after
 * that entity's last pull, on the server's clock), less what the cart
 * already holds (M3). Online, a gift card's live balance is asked for
 * first (less this till's sales not acknowledged). The server never
 * refuses a completed sale: what slips through is posted and flagged.
 *
 * Gift cards (M8): the till holds H(H(code)) and looks a card up with it;
 * the payment carries H(code), the proof the server hashes once more.
 *
 * prepare() resolves { amountMinor, tender, label } or throws a TenderError
 * with a code the payment screen translates (`pos.loyalty.errors.{code}`).
 */
export class TenderError extends Error {
  constructor(code, extra = {}) {
    super(code);
    this.name = 'TenderError';
    this.code = code;
    Object.assign(this, extra);
  }
}

const hex = (bytes) => [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

/** H(code): the proof a payment carries (normalised: upper case, no spaces or dashes). */
export function codeProof(code) {
  const normalised = String(code ?? '').replace(/[\s-]+/g, '').toUpperCase();
  return hex(sha256(new TextEncoder().encode(normalised)));
}

/** H(H(code)): what the till and the server store and sync. */
export function codeHash(code) {
  return hex(sha256(new TextEncoder().encode(codeProof(code))));
}

const one = async (database, table, ...conditions) => (await database.get(table).query(...conditions).fetch())[0]?.data ?? null;

/** H3: the Loyalty module's tenders apply only while its data is on the till. */
const loyaltyOn = (catalogue) => Boolean(catalogue?.ext?.loyalty);

/**
 * M4: the ids of this till's sales the server may not have counted in
 * `entity` yet: sales not acknowledged, and sales made after the entity's
 * last pull. Sale times are on the server's clock (serverNow), so the pull
 * time is moved onto it with the skew the engine measured.
 */
export async function uncountedSales(database, entity) {
  const state = (await database.get('sync_state').query(Q.where('id', Q.oneOf([entity, '__meta']))).fetch());
  const pull = state.find((row) => row.id === entity);
  let skew = 0;
  try {
    skew = Number(JSON.parse(state.find((row) => row.id === '__meta')?._raw?.data ?? '{}').skewMs ?? 0);
  } catch {
    skew = 0;
  }
  const since = Number(pull?._raw?.pulled_at ?? 0) + skew;
  const unacknowledged = new Set((await database.get('outbox').query(Q.where('kind', 'pos.sales'), Q.where('status', Q.notEq('acknowledged'))).fetch()).map((row) => row._raw.record_id));
  const recent = (await database.get('pos_sales').query(Q.or(Q.where('sold_at', Q.gte(since)), Q.where('id', Q.oneOf([...unacknowledged])))).fetch()).map((record) => record.data).filter(Boolean);
  return recent.filter((sale) => sale.local?.status !== 'voided');
}

/** What `sales` spent with tenders of `type` matching `match` (minor units, or points). */
export function spentIn(sales, { type, match, points = false }) {
  let total = 0n;
  for (const sale of sales) {
    for (const spent of sale.local?.spent ?? []) {
      if (spent.type === type && match(spent, sale)) total += BigInt(points ? (spent.tender?.points ?? '0') : spent.amountMinor);
    }
  }
  return total;
}

/** M3: what the current cart already holds in tenders of `type` matching `match`. */
export function onCart(cart, { type, match = () => true, points = false }) {
  let total = 0n;
  for (const tender of cart?.tenders ?? []) {
    if (tender.method?.type === type && match(tender)) total += BigInt(points ? (tender.tender?.points ?? '0') : tender.amountMinor);
  }
  return total;
}

function programmeOf(catalogue) {
  const programme = catalogue.ext?.loyalty?.programme;
  if (!programme?.active) throw new TenderError('loyalty_inactive');
  return programme;
}

/** LOY-05: points, in the programme currency, `points × point value`. */
export const pointsTender = {
  input: 'points',
  active: loyaltyOn,
  currencies: (method, catalogue) => [catalogue.ext?.loyalty?.programme?.currency ?? method.currency ?? catalogue.saleCurrency],
  async available({ database, customerId, cart = null }) {
    const member = await one(database, 'loyalty_members', Q.where('customer_id', customerId), Q.where('kind', 'points'));
    const sales = await uncountedSales(database, 'loyalty_members');
    const spent = spentIn(sales, { type: 'points', match: (_s, sale) => sale.local?.customer_id === customerId, points: true });
    return BigInt(member?.balance ?? '0') - spent - onCart(cart, { type: 'points', points: true });
  },
  async prepare({ input, cart, catalogue, database, currency }) {
    const programme = programmeOf(catalogue);
    if (!cart.customer?.id) throw new TenderError('customer_required');
    if (currency !== programme.currency) throw new TenderError('points_currency', { currency: programme.currency });
    const points = String(input ?? '').trim();
    if (!/^[1-9]\d{0,17}$/.test(points)) throw new TenderError('points_invalid');
    if (BigInt(points) < BigInt(programme.min_redeem_points ?? '0')) throw new TenderError('points_below_minimum', { minimum: String(programme.min_redeem_points) });
    const available = await this.available({ database, customerId: cart.customer.id, cart });
    if (BigInt(points) > available) throw new TenderError('points_insufficient', { available: String(available > 0n ? available : 0n) });
    return { amountMinor: redeemValue(points, programme.point_value_minor), tender: { points }, label: points };
  },
};

/** LOY-22, LOY-23: a gift card or voucher by its code. */
export const giftCardTender = {
  input: 'code',
  active: loyaltyOn,
  currencies: (method, catalogue) => [method.currency ?? catalogue.saleCurrency],
  async lookup({ code, database, api, online, cart = null }) {
    const proof = codeProof(code);
    const hash = codeHash(code);
    let card = await one(database, 'loyalty_gift_cards', Q.where('code_hash', hash));
    let live = false;
    if (online && api) {
      try {
        const response = await api.post('loyalty/device/gift-cards/lookup', { code_hash: proof });
        if (response.status === 200) {
          card = { ...card, ...response.body.data, code_hash: hash };
          live = true;
        } else if (response.status === 404) card = null;
      } catch (error) {
        if (!(error instanceof NetworkError)) throw error;
      }
    }
    if (!card) throw new TenderError('gift_card_unknown');
    const sales = live ? await unacknowledgedSales(database) : await uncountedSales(database, 'loyalty_gift_cards');
    const mine = (entry) => entry.tender?.code_hash === proof;
    const spent = spentIn(sales, { type: 'voucher', match: mine }) + onCart(cart, { type: 'voucher', match: mine });
    return { card, proof, available: BigInt(card.balance_minor ?? '0') - spent, live };
  },
  async prepare({ input, amountMinor, currency, database, api, online, cart }) {
    const { card, proof, available } = await this.lookup({ code: input, database, api, online, cart });
    if (card.status === 'inactive') throw new TenderError('gift_card_inactive');
    if (card.status === 'blocked') throw new TenderError('gift_card_blocked');
    if (card.expires_on && card.expires_on < new Date().toISOString().slice(0, 10)) throw new TenderError('gift_card_expired');
    if (card.currency !== currency) throw new TenderError('gift_card_currency', { currency: card.currency });
    if (available <= 0n) throw new TenderError('gift_card_empty');
    // The amount asked, else what is due, never more than the card holds.
    const wanted = BigInt(amountMinor ?? available);
    const amount = wanted > available ? available : wanted;
    return { amountMinor: String(amount), tender: { code_hash: proof, last4: card.last4 }, label: `•••• ${card.last4}` };
  },
};

/** Sales of this till the server has not acknowledged. */
async function unacknowledgedSales(database) {
  const ids = (await database.get('outbox').query(Q.where('kind', 'pos.sales'), Q.where('status', Q.notEq('acknowledged'))).fetch()).map((row) => row._raw.record_id);
  if (!ids.length) return [];
  return (await database.get('pos_sales').query(Q.where('id', Q.oneOf(ids))).fetch()).map((record) => record.data).filter((sale) => sale && sale.local?.status !== 'voided');
}

/** LOY-15: a sale on the customer's credit account, within their limit and to staff allowed to sell on credit. */
export const creditTender = {
  input: null,
  active: loyaltyOn,
  currencies: (method, catalogue) => [method.currency ?? catalogue.saleCurrency],
  async position({ database, customerId, cart = null }) {
    const account = await one(database, 'loyalty_members', Q.where('customer_id', customerId), Q.where('kind', 'credit'));
    const customer = await one(database, 'customers', Q.where('id', customerId));
    const sales = await uncountedSales(database, 'loyalty_members');
    const spent = spentIn(sales, { type: 'credit', match: (_s, sale) => sale.local?.customer_id === customerId });
    return { account, limit: customer?.credit_limit ?? null, owed: BigInt(account?.balance ?? '0') + spent + onCart(cart, { type: 'credit' }) };
  },
  async prepare({ amountMinor, currency, cart, database, user }) {
    if (!cart.customer?.id) throw new TenderError('customer_required');
    if (!user?.owner && !can(user, 'loyalty.credit.sell')) throw new TenderError('credit_not_permitted');
    const { account, limit, owed } = await this.position({ database, customerId: cart.customer.id, cart });
    if (!account || account.status !== 'open') throw new TenderError('credit_no_account');
    if (account.currency !== currency) throw new TenderError('credit_currency', { currency: account.currency });
    const amount = BigInt(amountMinor ?? '0');
    if (amount <= 0n) throw new TenderError('amount');
    // A party without a limit has no bound (core MD-01); a limit in another currency cannot be checked here.
    if (limit && limit.currency === account.currency && owed + amount > BigInt(limit.amount_minor)) {
      const left = BigInt(limit.amount_minor) - owed;
      throw new TenderError('credit_limit_exceeded', { available: String(left > 0n ? left : 0n) });
    }
    return { amountMinor: String(amount), tender: null, label: null };
  },
};
