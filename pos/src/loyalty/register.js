import { registerCartAdjuster, registerCartPanel, registerCatalogueExtension, registerTenderProvider } from '../pos/extensions';
import { LoyaltyCartPanel } from './LoyaltyCartPanel';
import { loadLoyaltyData, promotionAdjuster } from './promotions';
import { creditTender, giftCardTender, pointsTender } from './tenders';

/**
 * The Loyalty module on the till (docs/modules/loyalty.md), through the
 * POS app's generic seams. Without the module the server sends none of its
 * entities (RBAC-08), so the data is empty and nothing applies.
 */
export function registerLoyalty() {
  registerCatalogueExtension('loyalty', loadLoyaltyData);
  registerCartAdjuster('loyalty', promotionAdjuster);
  registerTenderProvider('points', pointsTender);
  registerTenderProvider('voucher', giftCardTender);
  registerTenderProvider('credit', creditTender);
  registerCartPanel('loyalty', LoyaltyCartPanel);
}

registerLoyalty();
