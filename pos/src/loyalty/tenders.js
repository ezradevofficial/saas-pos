import { Q } from '@nozbe/watermelondb';
import { sha256 } from '@noble/hashes/sha2.js';
import { can } from '../pos/authority';
import { NetworkError } from '../sync/api';
import { redeemValue } from './engine';

/**
 * LOY-05, LOY-15, LOY-22, LOY-23: points, gift cards and store credit as
 * tenders at the till (extensions.js tender providers).
 *
 * Offline rule (docs/modules/loyalty.md, Decisions): a balance is the last
 * synced one less what this till spent from it in sales made since that
 * entity was last pulled; online, a gift card's live balance is asked for
 * first (less this till's sales not yet acknowledged). The server never
 * refuses a completed sale: what slips through is posted and flagged.
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

/** The SHA-256 of a normalised gift card code (upper case, no spaces or dashes), as the server keeps it. */
export function codeHash(code) {
  const normalised = String(code ?? '').replace(/[\s-]+/g, '').toUpperCase();
  return [...sha256(new TextEncoder().encode(normalised))].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

const one = async (database, table, ...conditions) => (await database.get(table).query(...conditions).fetch())[0]?.data ?? null;

/** When `entity` was last pulled (ms), or 0. */
async function pulledAt(database, entity) {
  const rows = await database.get('sync_state').query(Q.where('id', entity)).fetch();
  return Number(rows[0]?._raw?.pulled_at ?? 0);
}

/**
 * What this till's own sales spent with tenders of `type` matching `match`,
 * in minor units or points (`points: true`): sales made after `since`, or
 * the sales whose ids are in `saleIds`.
 */
export async function spentLocally(database, { type, match, since = 0, saleIds = null, points = false }) {
  const sales = (await database.get('pos_sales').query(Q.where('sold_at', Q.gte(since))).fetch()).map((record) => record.data).filter(Boolean);
  let total = 0n;
  for (const sale of sales) {
    if (saleIds && !saleIds.has(sale.id)) continue;
    if (sale.local?.status === 'voided') continue;
    for (const spent of sale.local?.spent ?? []) {
      if (spent.type !== type || !match(spent, sale)) continue;
      total += BigInt(points ? spent.tender.points : spent.amountMinor);
    }
  }
  return total;
}

/** Ids of this till's sales not yet acknowledged by the server. */
async function unacknowledgedSales(database) {
  const rows = await database.get('outbox').query(Q.where('kind', 'pos.sales'), Q.where('status', Q.notEq('acknowledged'))).fetch();
  return new Set(rows.map((row) => row._raw.record_id));
}

function programmeOf(catalogue) {
  const programme = catalogue.ext?.loyalty?.programme;
  if (!programme?.active) throw new TenderError('loyalty_inactive');
  return programme;
}

/** LOY-05: points, in the programme currency, `points × point value`. */
export const pointsTender = {
  input: 'points',
  currencies: (method, catalogue) => [catalogue.ext?.loyalty?.programme?.currency ?? method.currency ?? catalogue.saleCurrency],
  async available({ database, customerId }) {
    const member = await one(database, 'loyalty_members', Q.where('customer_id', customerId), Q.where('kind', 'points'));
    const spent = await spentLocally(database, { type: 'points', match: (_s, sale) => sale.local?.customer_id === customerId, since: await pulledAt(database, 'loyalty_members'), points: true });
    return BigInt(member?.balance ?? '0') - spent;
  },
  async prepare({ input, cart, catalogue, database, currency }) {
    const programme = programmeOf(catalogue);
    if (!cart.customer?.id) throw new TenderError('customer_required');
    if (currency !== programme.currency) throw new TenderError('points_currency', { currency: programme.currency });
    const points = String(input ?? '').trim();
    if (!/^[1-9]\d{0,17}$/.test(points)) throw new TenderError('points_invalid');
    if (BigInt(points) < BigInt(programme.min_redeem_points ?? '0')) throw new TenderError('points_below_minimum', { minimum: String(programme.min_redeem_points) });
    const available = await this.available({ database, customerId: cart.customer.id });
    if (BigInt(points) > available) throw new TenderError('points_insufficient', { available: String(available > 0n ? available : 0n) });
    return { amountMinor: redeemValue(points, programme.point_value_minor), tender: { points }, label: points };
  },
};

/** LOY-22, LOY-23: a gift card or voucher by its code (hashed on the till). */
export const giftCardTender = {
  input: 'code',
  currencies: (method, catalogue) => [method.currency ?? catalogue.saleCurrency],
  async lookup({ code, database, api, online }) {
    const hash = codeHash(code);
    let card = await one(database, 'loyalty_gift_cards', Q.where('code_hash', hash));
    let live = false;
    if (online && api) {
      try {
        const response = await api.post('loyalty/device/gift-cards/lookup', { code_hash: hash });
        if (response.status === 200) {
          card = { ...card, ...response.body.data, code_hash: hash };
          live = true;
        } else if (response.status === 404) card = null;
      } catch (error) {
        if (!(error instanceof NetworkError)) throw error;
      }
    }
    if (!card) throw new TenderError('gift_card_unknown');
    const spent = await spentLocally(database, {
      type: 'voucher',
      match: (entry) => entry.tender?.code_hash === hash,
      ...(live ? { saleIds: await unacknowledgedSales(database) } : { since: await pulledAt(database, 'loyalty_gift_cards') }),
    });
    return { card, hash, available: BigInt(card.balance_minor ?? '0') - spent, live };
  },
  async prepare({ input, amountMinor, currency, database, api, online }) {
    const { card, hash, available } = await this.lookup({ code: input, database, api, online });
    if (card.status === 'inactive') throw new TenderError('gift_card_inactive');
    if (card.status === 'blocked') throw new TenderError('gift_card_blocked');
    if (card.expires_on && card.expires_on < new Date().toISOString().slice(0, 10)) throw new TenderError('gift_card_expired');
    if (card.currency !== currency) throw new TenderError('gift_card_currency', { currency: card.currency });
    if (available <= 0n) throw new TenderError('gift_card_empty');
    // The amount asked, else what is due, never more than the card holds.
    const wanted = BigInt(amountMinor ?? available);
    const amount = wanted > available ? available : wanted;
    return { amountMinor: String(amount), tender: { code_hash: hash, last4: card.last4 }, label: `•••• ${card.last4}` };
  },
};

/** LOY-15: a sale on the customer's credit account, within their limit and to staff allowed to sell on credit. */
export const creditTender = {
  input: null,
  currencies: (method, catalogue) => [method.currency ?? catalogue.saleCurrency],
  async position({ database, customerId }) {
    const account = await one(database, 'loyalty_members', Q.where('customer_id', customerId), Q.where('kind', 'credit'));
    const customer = await one(database, 'customers', Q.where('id', customerId));
    const spent = await spentLocally(database, { type: 'credit', match: (_s, sale) => sale.local?.customer_id === customerId, since: await pulledAt(database, 'loyalty_members') });
    return { account, limit: customer?.credit_limit ?? null, owed: BigInt(account?.balance ?? '0') + spent };
  },
  async prepare({ amountMinor, currency, cart, database, user }) {
    if (!cart.customer?.id) throw new TenderError('customer_required');
    if (!user?.owner && !can(user, 'loyalty.credit.sell')) throw new TenderError('credit_not_permitted');
    const { account, limit, owed } = await this.position({ database, customerId: cart.customer.id });
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
