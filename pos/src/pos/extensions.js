/**
 * LOY-27: the till's generic seams for modules (the POS code never names
 * one). A module registers, once at start-up:
 *
 * - a catalogue extension: data it loads with the catalogue from its synced
 *   tables (`catalogue.ext[key]`), reloaded after every pull;
 * - a cart adjuster: discounts it vouches for on cart lines (provenance
 *   `adjustments: [{source, reference, amount_minor, label}]`, sent with the
 *   sale so the server does not treat them as manual discounts, core
 *   DiscountSources) and data for the sale's `extensions[key]`;
 * - a tender provider per payment method type: what the payment screen asks
 *   for (`input`), the currencies it pays in, and `prepare()`, which checks
 *   the tender (offline rules included) and gives the payment's `tender`;
 * - a cart panel: a component under the customer on the sale panel.
 *
 * Adjusters and providers are pure or read the local database only, so the
 * till works offline.
 */
const catalogueExtensions = new Map();
const cartAdjusters = new Map();
const tenderProviders = new Map();
const cartPanels = new Map();

export function registerCatalogueExtension(key, loader) {
  catalogueExtensions.set(key, loader);
}

/** { key: data } for every registered extension (an extension that fails loads as null). */
export async function loadCatalogueExtensions(database, base) {
  const out = {};
  for (const [key, loader] of catalogueExtensions) {
    try {
      out[key] = await loader(database, base);
    } catch {
      out[key] = null;
    }
  }
  return out;
}

/** adjuster({ lines, cart, catalogue, at }) → { adjustments: Map(lineId → [...]), extension?: object } */
export function registerCartAdjuster(key, adjuster) {
  cartAdjusters.set(key, adjuster);
}

/**
 * Every adjuster's discounts by line id (concatenated) and the sale's
 * extensions by key. `lines` are the cart lines (unit price, quantity).
 */
export function runCartAdjusters({ lines, cart, catalogue, at }) {
  const adjustments = new Map();
  const extensions = {};
  if (!catalogue) return { adjustments, extensions };
  for (const [key, adjuster] of cartAdjusters) {
    const result = adjuster({ lines, cart, catalogue, at }) ?? {};
    for (const [lineId, list] of result.adjustments ?? []) adjustments.set(lineId, [...(adjustments.get(lineId) ?? []), ...list]);
    if (result.extension && Object.keys(result.extension).length) extensions[key] = result.extension;
  }
  return { adjustments, extensions };
}

export function registerTenderProvider(type, provider) {
  tenderProviders.set(type, provider);
}

/** The provider of a payment method type, or null (cash, mobile money and cards have none). */
export function tenderProvider(type) {
  return tenderProviders.get(type) ?? null;
}

/** A component shown under the customer on the sale panel (balances, coupons). */
export function registerCartPanel(key, Component) {
  cartPanels.set(key, Component);
}

export function cartPanelComponents() {
  return [...cartPanels.entries()];
}

/** Tests only. */
export function resetExtensions() {
  catalogueExtensions.clear();
  cartAdjusters.clear();
  tenderProviders.clear();
  cartPanels.clear();
}
