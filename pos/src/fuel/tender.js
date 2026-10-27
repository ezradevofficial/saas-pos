import { onCart, spentIn, TenderError, uncountedSales } from '../loyalty/tenders';
import { findVoucher, normalise } from './data';
import { can, FUEL } from './permissions';

/**
 * FUEL-21, FUEL-27, NFR-04: the `fuel_voucher` payment method type at the
 * till. The attendant types or scans the voucher code; the till checks the
 * synced voucher (it exists, has not expired, is in the sale's currency and
 * has a balance) and takes min(balance, amount due). The payment goes up
 * with `tender: { code }`. The server checks again when the sale arrives
 * and flags what it cannot accept (unknown, void, expired, wrong currency,
 * overdrawn); it never refuses a completed sale (device wins).
 *
 * Offline rule (as the loyalty gift cards): the balance is the last synced
 * one, less what this till spent from that voucher in sales the server may
 * not have counted yet (sales not acknowledged and sales after the entity's
 * last pull, `uncountedSales`), less what the cart already holds. So a
 * second sale on the same device sees the first one's redemption.
 *
 * Errors are codes the payment screen translates (`pos.loyalty.errors.*`):
 * fuel_voucher_code_required, amount, fuel_voucher_inactive, fuel_voucher_not_permitted,
 * fuel_voucher_unknown, fuel_voucher_expired, fuel_voucher_currency, fuel_voucher_empty.
 */
const holds = (code) => (spent) => normalise(spent.tender?.code) === code;

/** The balance still usable on this till (minor units, bigint): synced balance - spent here since - held in the cart. */
export async function availableBalance({ voucher, database, cart = null }) {
  const code = normalise(voucher.code);
  const spent = spentIn(await uncountedSales(database, 'fuel_vouchers'), { type: 'fuel_voucher', match: holds(code) });
  return BigInt(voucher.balance_minor ?? '0') - spent - onCart(cart, { type: 'fuel_voucher', match: (tender) => normalise(tender.tender?.code) === code });
}

export const fuelVoucherTender = {
  input: 'fuel_voucher',
  active: (catalogue) => Boolean(catalogue?.ext?.fuel),
  currencies: (method, catalogue) => [method.currency ?? catalogue.saleCurrency],
  async prepare({ input, amountMinor, currency, cart = null, catalogue, database, user }) {
    const code = normalise(input);
    if (!code) throw new TenderError('fuel_voucher_code_required');
    const due = BigInt(amountMinor ?? '0');
    if (due <= 0n) throw new TenderError('amount');
    if (!catalogue?.ext?.fuel) throw new TenderError('fuel_voucher_inactive');
    if (user && !can(user, FUEL.redeem)) throw new TenderError('fuel_voucher_not_permitted');

    const voucher = await findVoucher(database, code);
    if (!voucher) throw new TenderError('fuel_voucher_unknown');
    const today = catalogue.dayAt ? catalogue.dayAt(Date.now()) : catalogue.day;
    if (voucher.expires_on && voucher.expires_on < today) throw new TenderError('fuel_voucher_expired');
    if (voucher.currency !== currency) throw new TenderError('fuel_voucher_currency', { currency: voucher.currency });

    const available = await availableBalance({ voucher, database, cart });
    if (available <= 0n) throw new TenderError('fuel_voucher_empty');
    const applied = available < due ? available : due;
    return { amountMinor: String(applied), tender: { code }, label: code };
  },
};
