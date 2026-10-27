import { registerCartAdjuster, registerCatalogueExtension, registerTenderProvider } from '../pos/extensions';
import { loadFuelData } from './data';
import { fuelExtension } from './sale';
import { fuelVoucherTender } from './tender';

/**
 * The Fuel station module on the till (docs/modules/fuel-station.md, FUEL-18
 * to FUEL-22), through the POS app's generic seams. Without the module (or at
 * a location that is not a station) the server sends no `fuel_station` row
 * (RBAC-08, FUEL-02), so `catalogue.ext.fuel` is null and the voucher payment
 * method is hidden.
 *
 * A fuel cart carries `cart.ext.fuel` ({ shift_id, lines, vehicle? }); the
 * adjuster hands it to the sale as `extensions.fuel`, so the server links the
 * sale to its shift, nozzle and vehicle. It changes no amounts.
 */
export function registerFuel() {
  registerCatalogueExtension('fuel', loadFuelData);
  registerCartAdjuster('fuel', ({ cart }) => {
    const extension = fuelExtension(cart);
    return extension ? { adjustments: new Map(), extension } : {};
  });
  registerTenderProvider('fuel_voucher', fuelVoucherTender);
}

registerFuel();
