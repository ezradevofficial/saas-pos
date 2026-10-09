import { createContext, useCallback, useContext, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { useSession } from '../auth/session';
import { uuidv7 } from '../lib/random';
import { useServices } from '../services/services';
import { useSyncStatus } from '../sync/useSyncStatus';
import { check } from './authority';
import { cartReducer, computeCart, emptyCart } from './cart';
import { findByCode, loadCatalogue } from './catalogue';
import { DOCUMENT_TYPES, drawNumber } from './numbering';
import { discountPercent, extend } from './tax';

/**
 * POS-01..POS-09, NFR-03: the selling state of the till, above the sign-in
 * screen so a user switch keeps the shift and the current sale (AUTH-07).
 *
 * Three contexts keep re-renders small: data (catalogue, shift, held
 * sales, next receipt number; changes on sync or shift events), cart (the
 * current sale and its computed totals; changes on every tap) and actions
 * (stable functions; reading the latest state through refs, so the
 * product grid never re-renders when the cart changes).
 *
 * Restricted actions (discount, price, void, refund, pay-out) go through
 * authorize(): the signed-in person's own right (permission and RBAC-06
 * limit from their staff row), else a manager's override on this till
 * (AUTH-08, the override dialog), else nothing happens.
 */
const DataContext = createContext(null);
const CartContext = createContext(null);
const ActionsContext = createContext(null);

export function PosProvider({ children }) {
  const { database, posStore, selling } = useServices();
  const session = useSession();
  const { lastPulledAt } = useSyncStatus();
  const [catalogue, setCatalogue] = useState(null);
  const [cart, dispatch] = useReducer(cartReducer, undefined, () => emptyCart());
  const [shift, setShift] = useState(undefined);
  const [held, setHeld] = useState([]);
  const [nextReceipt, setNextReceipt] = useState(null);
  const [overrideRequest, setOverrideRequest] = useState(null);
  const [lastSale, setLastSale] = useState(null);

  const refs = useRef({});
  refs.current = { cart, catalogue, shift, session };

  useEffect(() => {
    let active = true;
    loadCatalogue({ database }).then((loaded) => active && setCatalogue(loaded));
    return () => {
      active = false;
    };
  }, [database, lastPulledAt]);

  const refreshShift = useCallback(async () => {
    let open = await posStore.openShift();
    if (!open) {
      // POS-04: a shift the server holds open for this till (a reinstall) is resumed, once.
      const server = await posStore.serverOpenShift();
      if (server && !(await posStore.shift(server.id))) open = await selling.adoptServerShift(server);
    }
    setShift(open ?? null);
  }, [posStore, selling]);

  const refreshHeld = useCallback(async () => setHeld(await posStore.held()), [posStore]);

  const refreshNumber = useCallback(async () => {
    const loaded = refs.current.catalogue;
    if (!loaded) return;
    const [ranges, used] = await Promise.all([posStore.numberRanges(), posStore.counters(DOCUMENT_TYPES.receipt)]);
    setNextReceipt(drawNumber({ ranges, used, documentType: DOCUMENT_TYPES.receipt, at: Date.now(), timeZone: loaded.timeZone })?.number ?? null);
  }, [posStore]);

  useEffect(() => {
    refreshShift();
    refreshHeld();
  }, [refreshShift, refreshHeld, lastPulledAt]);

  useEffect(() => {
    refreshNumber();
  }, [refreshNumber, catalogue, lastSale]);

  const activeList = useCallback((customer) => {
    const loaded = refs.current.catalogue;
    const own = customer?.price_list_id ? loaded?.listById.get(customer.price_list_id) : null;
    // A customer's list applies only in the sale currency (the API refuses another).
    return own && own.currency === loaded.saleCurrency ? own : loaded?.defaultList ?? null;
  }, []);

  /** AUTH-08: resolves { override } or { override: null } (own right), or null when nobody allowed it. */
  const authorize = useCallback((action, value, reference) => {
    const { session: current } = refs.current;
    if (check(current.user, action, value).allowed) return Promise.resolve({ override: null, approvedBy: current.user.id });
    return new Promise((resolve) => setOverrideRequest({ action, value, reference, resolve }));
  }, []);

  const finishOverride = useCallback((result) => {
    setOverrideRequest((request) => {
      request?.resolve(result);
      return null;
    });
  }, []);

  const actions = useMemo(() => {
    const current = () => refs.current;
    const lineOf = (id) => current().cart.lines.find((line) => line.id === id);

    return {
      /** Adds an item (a tile or a scanned code); resolves null or an error code. */
      addItem(itemId, uomOverride) {
        const { catalogue: loaded, cart: sale } = current();
        const item = loaded?.itemById.get(itemId);
        const tile = loaded?.tiles.find((candidate) => candidate.id === itemId);
        if (!item || !tile) return 'item_unknown';
        if (!tile.sellable) return tile.reason;
        const uomId = uomOverride ?? loaded.salesUom(item);
        const list = activeList(sale.customer);
        const same = sale.lines.find((line) => line.itemId === item.id && line.uomId === uomId && !line.priceOverride && line.discountMinor === '0');
        if (same) {
          const qty = String(BigInt(same.qty.split('.')[0]) + 1n);
          const price = loaded.priceFor(item, uomId, same.priceListId ?? list?.id, qty);
          dispatch({ type: 'setQty', id: same.id, qty, listPriceMinor: price?.amountMinor ?? null });
          return null;
        }
        const price = list ? loaded.priceFor(item, uomId, list.id, '1') : null;
        if (!price) return 'price_missing';
        dispatch({
          type: 'add',
          item,
          uomId,
          uomCode: loaded.uoms.get(uomId)?.code ?? null,
          price: price.amountMinor,
          listPriceMinor: price.amountMinor,
          priceListId: list.id,
          taxInclusive: Boolean(list.tax_inclusive),
        });
        return null;
      },

      /** A barcode or item code typed or scanned (hardware scanners type and press Enter). */
      addByCode(code) {
        const found = findByCode(current().catalogue, code);
        if (!found?.item) return 'code_unknown';
        return this.addItem(found.item.id, found.uomId ?? undefined);
      },

      setQty(lineId, qty) {
        const line = lineOf(lineId);
        if (!line) return;
        const { catalogue: loaded } = current();
        const item = loaded.itemById.get(line.itemId);
        const price = item && line.priceListId && /[1-9]/.test(String(qty)) ? loaded.priceFor(item, line.uomId, line.priceListId, String(qty)) : null;
        dispatch({ type: 'setQty', id: lineId, qty: String(qty), listPriceMinor: price?.amountMinor ?? null });
      },

      step(lineId, delta) {
        const line = lineOf(lineId);
        if (!line) return;
        const whole = /^\d+$/.test(line.qty);
        if (!whole) return;
        const next = BigInt(line.qty) + BigInt(delta);
        if (next <= 0n) dispatch({ type: 'remove', id: lineId });
        else this.setQty(lineId, String(next));
      },

      remove: (lineId) => dispatch({ type: 'remove', id: lineId }),

      /** POS-07: a line discount within the person's limit, else a manager's override. Resolves whether it was applied. */
      async discount(lineId, discountMinor) {
        const line = lineOf(lineId);
        if (!line) return false;
        const gross = extend(line.unitPriceMinor, line.qty);
        if (BigInt(discountMinor) > gross) return false;
        if (BigInt(discountMinor) === 0n) {
          dispatch({ type: 'discount', id: lineId, discountMinor: '0', override: null });
          return true;
        }
        const approval = await authorize('discount', discountPercent(discountMinor, String(gross)), lineId);
        if (!approval) return false;
        dispatch({ type: 'discount', id: lineId, discountMinor, override: approval.override, actorProof: approval.override ? null : current().session.actorProof });
        return true;
      },

      /** POS-07: a price other than the list price (pos.price.override or an override). */
      async price(lineId, unitPriceMinor) {
        const line = lineOf(lineId);
        if (!line) return false;
        if (String(unitPriceMinor) === String(line.listPriceMinor)) {
          dispatch({ type: 'price', id: lineId, unitPriceMinor, override: null });
          return true;
        }
        const approval = await authorize('price', null, lineId);
        if (!approval) return false;
        dispatch({ type: 'price', id: lineId, unitPriceMinor, override: approval.override, actorProof: approval.override ? null : current().session.actorProof });
        return true;
      },

      /** POS-08: name a customer (or none); lines are repriced on the customer's list. */
      setCustomer(customer) {
        const { catalogue: loaded, cart: sale } = current();
        const list = activeList(customer);
        const lines = sale.lines.map((line) => {
          if (line.priceOverride || !list || line.priceListId === list.id) return line;
          const item = loaded.itemById.get(line.itemId);
          const price = item ? loaded.priceFor(item, line.uomId, list.id, line.qty) : null;
          return price ? { ...line, priceListId: list.id, unitPriceMinor: price.amountMinor, listPriceMinor: price.amountMinor, taxInclusive: Boolean(list.tax_inclusive) } : line;
        });
        dispatch({ type: 'customer', customer: customer ? { id: customer.id, name: customer.name, phone: customer.phones?.[0]?.number ?? null, price_list_id: customer.price_list_id ?? null } : null, priceListId: list?.id ?? null, lines });
      },

      clear: () => dispatch({ type: 'clear' }),

      /** POS-02: park the current sale on this till. */
      async hold() {
        const { cart: sale } = current();
        if (!sale.lines.length) return;
        await posStore.hold(sale);
        dispatch({ type: 'clear' });
        await refreshHeld();
      },

      async resume(id) {
        const { cart: sale } = current();
        const parked = (await posStore.held()).find((entry) => entry.id === id);
        if (!parked) return;
        if (sale.lines.length) await posStore.hold(sale);
        await posStore.unhold(id);
        const { heldAt: _heldAt, ...restored } = parked;
        dispatch({ type: 'load', cart: restored });
        await refreshHeld();
      },

      async discard(id) {
        await posStore.unhold(id);
        await refreshHeld();
      },

      async openShift(openingFloat) {
        const { session: who } = current();
        const opened = await selling.openShift({ user: who.user, actorProof: who.actorProof, openingFloat });
        setShift(opened);
        return opened;
      },

      async closeShift(counted, note) {
        const { session: who, shift: open } = current();
        const closed = await selling.closeShift({ shift: open, user: who.user, actorProof: who.actorProof, counted, note });
        return closed;
      },

      /** After the cash-up summary: the till waits for the next shift. */
      shiftDone: () => setShift(null),

      /** POS-04: pay-in, or pay-out (pos.cash.move or an override). Resolves the movement or null. */
      async cashMovement({ kind, currency, amountMinor, reason }) {
        const { session: who, shift: open } = current();
        const reference = uuidv7();
        const approval = kind === 'pay_out' ? await authorize('pay_out', null, reference) : { override: null };
        if (!approval) return null;
        return selling.cashMovement({ shift: open, user: who.user, actorProof: who.actorProof, kind, currency, amountMinor, reason, override: approval.override ? approval.override : undefined });
      },

      /** POS-01, POS-03: complete with the tenders; clears the cart and resolves the sale. */
      async complete(tenders, changeCurrency) {
        const { session: who, shift: open, cart: sale, catalogue: loaded } = current();
        const list = activeList(sale.customer);
        const completed = await selling.completeSale({ shift: open, user: who.user, actorProof: who.actorProof, cart: sale, catalogue: loaded, tenders, changeCurrency, priceListId: list?.id });
        dispatch({ type: 'clear' });
        setLastSale(completed);
        return completed;
      },

      /** POS-05: void a sale of this shift. */
      async voidSale(sale, reason) {
        const { session: who, shift: open } = current();
        const reference = uuidv7();
        const approval = await authorize('void', null, reference);
        if (!approval) return null;
        return selling.voidSale({ sale, shift: open, user: who.user, actorProof: who.actorProof, reason, override: approval.override ?? undefined });
      },

      /** POS-05: refund lines; `baseMajor` is the refund in the base currency (RBAC-06 limit). */
      async refund({ sale, requested, method, currency, reason, baseMajor }) {
        const { session: who, shift: open, catalogue: loaded } = current();
        const reference = uuidv7();
        const approval = await authorize('refund', baseMajor, reference);
        if (!approval) return null;
        return selling.refund({ sale, shift: open, user: who.user, actorProof: who.actorProof, requested, method, currency, reason, override: approval.override ?? undefined, catalogue: loaded, id: reference });
      },

      refundQuote: (args) => selling.refundQuote(args),
      finishOverride,
      refreshShift,
    };
  }, [activeList, authorize, finishOverride, posStore, refreshHeld, refreshShift, selling]);

  const computed = useMemo(() => (catalogue ? computeCart(cart, { taxCodes: catalogue.taxCodes, day: catalogue.day }) : null), [cart, catalogue]);
  const cartValue = useMemo(() => ({ cart, computed }), [cart, computed]);
  const data = useMemo(
    () => ({ catalogue, shift, held, nextReceipt, overrideRequest, lastSale }),
    [catalogue, shift, held, nextReceipt, overrideRequest, lastSale],
  );

  return (
    <ActionsContext.Provider value={actions}>
      <DataContext.Provider value={data}>
        <CartContext.Provider value={cartValue}>{children}</CartContext.Provider>
      </DataContext.Provider>
    </ActionsContext.Provider>
  );
}

export const usePosData = () => useContext(DataContext);
export const usePosCart = () => useContext(CartContext);
export const usePosActions = () => useContext(ActionsContext);
