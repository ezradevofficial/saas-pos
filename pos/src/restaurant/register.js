import { registerCartAdjuster } from '../pos/extensions';

/**
 * REST-16: the Restaurant module on the till, through the POS app's generic
 * seams. A part of a bill is loaded as a cart carrying `ext.restaurant`
 * ({ order_id, mode, part, parts, final, line_ids, covers }); this adjuster
 * hands it to the sale as `extensions.restaurant`, so the server links the
 * sale to its order. It changes no amounts. A sale without it is a retail
 * sale and is untouched.
 */
registerCartAdjuster('restaurant', ({ cart }) => (cart?.ext?.restaurant ? { adjustments: new Map(), extension: cart.ext.restaurant } : {}));
