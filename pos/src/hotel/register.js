import { registerCatalogueExtension, registerTenderProvider } from '../pos/extensions';
import { loadHotelData } from './inHouse';
import { roomChargeTender } from './tender';

/**
 * The Hotel module on the till (docs/modules/hotel.md, HOT-22, HOT-35),
 * through the POS app's generic seams. Without the module the server sends
 * no `hotel_in_house` entity (RBAC-08), so the data is null and the
 * room-charge payment method is hidden.
 */
export function registerHotel() {
  registerCatalogueExtension('hotel', loadHotelData);
  registerTenderProvider('room_charge', roomChargeTender);
}

registerHotel();
