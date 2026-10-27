import { registerAfterSale, registerCartAdjuster, registerCartPanel, registerCatalogueExtension } from '../pos/extensions';
import { PharmacyCartPanel } from './PharmacyCartPanel';
import { countLotsUsed, loadPharmacyData } from './pharmacy';
import { pharmacyAdjuster } from './plan';

/**
 * The Pharmacy module on the till (docs/modules/pharmacy.md), through the
 * POS app's generic seams. Without the module the server sends none of its
 * entities (RBAC-08), so the data is null and nothing applies.
 */
export function registerPharmacy() {
  registerCatalogueExtension('pharmacy', loadPharmacyData);
  registerCartAdjuster('pharmacy', pharmacyAdjuster);
  registerCartPanel('pharmacy', PharmacyCartPanel);
  registerAfterSale('pharmacy', countLotsUsed);
}

registerPharmacy();
