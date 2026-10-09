import { createContext, useCallback, useContext, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { useSession } from '../auth/session';
import { uuidv7 } from '../lib/random';
import { useServices } from '../services/services';
import { useSyncStatus } from '../sync/useSyncStatus';
import { check } from './authority';
import { cartReducer, computeCart, emptyCart, isWholeQty } from './cart';
import { planLineEdit } from './lineEdit';
import { findByCode, loadCatalogue } from './catalogue';
import { DOCUMENT_TYPES, drawNumber } from './numbering';
import { refundBaseMajor } from './payloads';
import { extend } from './tax';

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

  const [dayTick, setDayTick] = useState(0);

  useEffect(() => {
    let active = true;
    loadCatalogue({ database }).then((loaded) => active && setCatalogue(loaded));
    return () => {
      active = false;
    };
  }, [database, lastPulledAt, dayTick]);

  // POS-11: at the company's midnight, sellability and prices of the new day apply (dated tax rates and prices).
  useEffect(() => {
    if (!catalogue) return undefined;
    const timer = setInterval(() => {
      if (catalogue.dayAt(Date.now()) !== catalogue.day) setDayTick((tick) => tick + 1);
    }, 60 * 1000);
    return () => clearInterval(timer);
  }, [catalogue]);

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

  // The open cart survives an app restart; one already completed (a crash after the sale) is dropped.
  const [cartLoaded, setCartLoaded] = useState(false);
  useEffect(() => {
    let active = true;
    (async () => {
      const saved = await posStore.openCart();
      if (active && saved?.lines?.length && !(await posStore.sale(saved.id))) dispatch({ type: 'load', cart: saved });
      if (active) setCartLoaded(true);
    })();
    return () => {
      active = false;
    };
  }, [posStore]);

  useEffect(() => {
    if (!cartLoaded) return undefined;
    const timer = setTimeout(() => {
      posStore.saveOpenCart(cart).catch(() => {});
    }, 250);
    return () => clearTimeout(timer);
  }, [cart, cartLoaded, posStore]);

  useEffect(() => {
    refreshNumber();
  }, [refreshNumber, catalogue, lastSale]);

  const activeList = useCallback((customer) => {
    const loaded = refs.current.catalogue;
    const own = customer?.price_list_id ? loaded?.listById.get(customer.price_list_id) : null;
    // A customer's list applies only in the sale currency (the API refuses another).
    return own && own.currency === loaded.saleCurrency ? own : loaded?.defaultList ?? null;
  }, []);

  /**
   * AUTH-08: resolves { override, approvedBy, proof } — override null when the
   * signed-in person may do it on their own right (their proof attached),
   * a manager's override otherwise — or null when nobody allowed it.
   * `quiet` never asks a manager (the +/− buttons).
   */
  const authorize = useCallback((action, value, reference, { quiet = false } = {}) => {
    const { session: current } = refs.current;
    if (check(current.user, action, value).allowed) return Promise.resolve({ override: null, approvedBy: current.user.id, proof: current.actorProof });
    if (quiet) return Promise.resolve(null);
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
    const listPriceFor = (line) => (qty) => {
      const { catalogue: loaded } = current();
      const item = loaded?.itemById.get(line.itemId);
      return item && line.priceListId ? (loaded.priceFor(item, line.uomId, line.priceListId, String(qty))?.amountMinor ?? null) : null;
    };

    /** POS-07: one edit of a line from its NEW values (lineEdit.js); resolves { ok, reason?, notice? }. */
    async function editLine(lineId, changes, { quiet = false } = {}) {
      const line = lineOf(lineId);
      if (!line) return { ok: false, reason: 'line_unknown' };
      const plan = await planLineEdit({ line, changes, listPriceFor: listPriceFor(line), authorize: (action, value, reference) => authorize(action, value, reference, { quiet }), quiet });
      if (plan.ok) dispatch({ type: 'edit', id: lineId, patch: plan.patch });
      return plan;
    }

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
        // Only a plain line with a whole quantity takes one more; a weighed or hand-priced line stays as it is.
        const same = sale.lines.find((line) => line.itemId === item.id && line.uomId === uomId && isWholeQty(line.qty) && !line.priceSet && !line.priceOverride && line.discountMinor === '0');
        if (same) {
          editLine(same.id, { qty: String(BigInt(same.qty) + 1n) }, { quiet: true });
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

      /** A barcode or item code typed or scanned (hardware or camera scanners). */
      addByCode(code) {
        const found = findByCode(current().catalogue, code);
        if (!found?.item) return 'code_unknown';
        return this.addItem(found.item.id, found.uomId ?? undefined);
      },

      editLine,

      /**
       * +/− on a whole quantity. A discount is re-checked on the new gross
       * without asking a manager: kept when still allowed, else cleared
       * (resolves notice 'discount_cleared').
       */
      async step(lineId, delta) {
        const line = lineOf(lineId);
        if (!line || !isWholeQty(line.qty)) return null;
        const next = BigInt(line.qty) + BigInt(delta);
        if (next <= 0n) {
          dispatch({ type: 'remove', id: lineId });
          return null;
        }
        return (await editLine(lineId, { qty: String(next) }, { quiet: true })).notice ?? null;
      },

      remove: (lineId) => dispatch({ type: 'remove', id: lineId }),

      /** POS-08: name a customer (or none); lines not priced by hand are re-priced on the customer's list. */
      setCustomer(customer) {
        const { catalogue: loaded, cart: sale } = current();
        const list = activeList(customer);
        const lines = sale.lines.map((line) => {
          if (line.priceSet || line.priceOverride || !list || line.priceListId === list.id) return line;
          const item = loaded.itemById.get(line.itemId);
          const price = item ? loaded.priceFor(item, line.uomId, list.id, line.qty) : null;
          if (!price) return line;
          const repriced = { ...line, priceListId: list.id, unitPriceMinor: price.amountMinor, listPriceMinor: price.amountMinor, taxInclusive: Boolean(list.tax_inclusive) };
          // A discount above the new price cannot stand.
          return BigInt(line.discountMinor) > extend(repriced.unitPriceMinor, repriced.qty) ? { ...repriced, discountMinor: '0', override: null, discountBy: null } : repriced;
        });
        dispatch({ type: 'customer', customer: customer ? { id: customer.id, name: customer.name, phone: customer.phones?.[0]?.number ?? null, price_list_id: customer.price_list_id ?? null } : null, priceListId: list?.id ?? null, lines });
      },

      /** Payments in progress, kept with the cart. */
      setTenders: (tenders) => dispatch({ type: 'tenders', tenders }),

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
        // POS-04: pay-ins and pay-outs both need pos.cash.move (the server checks both) or a manager.
        const approval = await authorize(kind, null, reference);
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
      /** RBAC-06: the refund's base-currency value is computed the server's way (refundBaseMajor). */
      async refund({ sale, requested, method, currency, reason }) {
        const { session: who, shift: open, catalogue: loaded } = current();
        const reference = uuidv7();
        const quote = await selling.refundQuote({ sale, requested });
        const approval = await authorize('refund', refundBaseMajor(sale, quote.lines, loaded.money.decimals), reference);
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
