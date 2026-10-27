import { registerCartAdjuster, registerCatalogueExtension, registerTenderProvider } from '../pos/extensions';
import { salonCreditTender } from './credit';

/**
 * SAL-16, SAL-17, SAL-29: the Salon module on the till, through the POS app's generic seams (extensions.js). Without
 * the module the server sends no `salon_settings` row, so nothing here applies and a sale is an ordinary sale.
 *
 * - catalogue extension `salon`: the company's salon rules (their presence is the gate);
 * - cart adjuster: a cart carrying `ext.salon` ({ appointment_id, tips?, sellers?, fee? }) hands it to the sale as
 *   `extensions.salon`; it changes no amounts. Who earns what is decided by the server from its own appointment;
 * - tender provider for payment methods of type `salon_credit`: deposits and package sessions (credit.js).
 */
export function registerSalon() {
  registerCatalogueExtension('salon', async (database) => {
    const rows = await database.get('salon_settings').query().fetch();
    const settings = rows[0]?.data ?? null;
    return settings ? { settings } : null;
  });
  registerCartAdjuster('salon', ({ cart }) => (cart?.ext?.salon ? { adjustments: new Map(), extension: cart.ext.salon } : {}));
  registerTenderProvider('salon_credit', salonCreditTender);
}

registerSalon();
