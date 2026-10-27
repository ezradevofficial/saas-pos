import { registerCartAdjuster, registerCatalogueExtension, registerTenderProvider } from '../pos/extensions';
import { TenderError } from '../loyalty/tenders';
import { decideCredit } from './credit';
import { createVsStore } from './vsStore';
import { createVanSales } from './vansales';

/**
 * VAN-10, VAN-11: Van sales on the till, through the POS app's generic
 * seams (extensions.js). Without the module the server sends no van row, so
 * nothing here applies and a sale is an ordinary sale.
 *
 * - catalogue extension `vansales`: the van row (its presence is the gate);
 * - cart adjuster: a cart carrying `ext.van_sales` ({ run_id, visit_id?,
 *   exchange_for_sale_id? }) hands it to the sale as `extensions.van_sales`;
 *   it changes no amounts;
 * - tender provider for payment methods of type `credit`, ahead of Loyalty's
 *   store credit: the amount is limited to the customer's available credit,
 *   decided on the device (credit.js), and the decision travels in the
 *   sale's `extensions.van_sales.credit`.
 */
export const vanActive = (catalogue) => Boolean(catalogue?.ext?.vansales?.van);

const refuse = (code, extra = {}) => new TenderError(code, { messageKey: `vansales.errors.${code}`, ...extra });

export const vanCreditTender = {
  input: null,
  active: vanActive,
  currencies: (method, catalogue) => [method.currency ?? catalogue.saleCurrency],
  async prepare({ amountMinor, currency, cart, database, online = true }) {
    const customerId = cart.customer?.id;
    const service = createVanSales({ records: createVsStore(database), selling: null });
    const { account, unsynced } = customerId ? await service.accountPosition(customerId, currency) : { account: null, unsynced: {} };
    const onCart = (cart.tenders ?? []).filter((tender) => tender.method?.type === 'credit' && tender.currency === currency).reduce((sum, tender) => sum + BigInt(tender.amountMinor), 0n);
    const decision = decideCredit({ account, customerId, currency, amountMinor, unsynced, onCartMinor: onCart, offline: !online });
    if (!decision.ok) throw refuse(decision.code, { available: decision.available, currency: decision.limitCurrency });
    return { amountMinor: String(amountMinor), tender: null, label: null, extension: { van_sales: { credit: decision.credit } } };
  },
};

export function registerVanSales() {
  registerCatalogueExtension('vansales', async (database) => {
    const rows = await database.get('van_sales_van').query().fetch();
    const van = rows[0]?.data ?? null;
    return van ? { van } : null;
  });
  registerCartAdjuster('van_sales', ({ cart }) => (cart?.ext?.van_sales ? { adjustments: new Map(), extension: cart.ext.van_sales } : {}));
  registerTenderProvider('credit', vanCreditTender, { first: true });
}

registerVanSales();
