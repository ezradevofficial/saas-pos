/**
 * LAY-05, LAY-07: the sell screen's layout on the till. The server sends the
 * published `pos_layout` of the till's location (else its branch's,
 * company's or tenant's) already merged with what it holds (entity
 * `pos_layout`); the till merges once more with its own copy of the
 * catalogue, so categories and items that arrive while it is offline
 * still show, and ones it no longer holds are skipped:
 *
 * - categories in the layout's order, then those it does not name (in
 *   name order, shown); hidden ones never get a chip;
 * - pinned items first, then by category (in the layout's order), by name
 *   or by best-sellers (then by name);
 * - quick buttons for an item, a category or an action, at most 8;
 * - keypad (the sale panel) right or left on a tablet, always at the
 *   bottom on a phone;
 * - the customer display's content.
 *
 * Colours are token names from a fixed set and become token classes here,
 * built once per layout (never per render). No hex ever reaches the screen.
 */

export const TILE_SIZES = ['compact', 'standard', 'large'];
export const ORDERS = ['category', 'name', 'best_sellers'];
export const ACTIONS = ['hold', 'customer', 'cash_in', 'cash_out', 'sales'];
export const MAX_QUICK_BUTTONS = 8;

/** Category tile colours (tokens only, BR-01): background of the tile and chip, and a dot of the stronger shade. */
export const CATEGORY_COLOURS = {
  'primary-tint': { tile: 'bg-primary-tint', dot: 'bg-primary' },
  'surface-300': { tile: 'bg-surface-300', dot: 'bg-ink-muted' },
  'success-tint': { tile: 'bg-success-tint', dot: 'bg-success' },
  'warning-tint': { tile: 'bg-warning-tint', dot: 'bg-warning' },
  'danger-tint': { tile: 'bg-danger-tint', dot: 'bg-danger' },
};

export const DEFAULT_LAYOUT = Object.freeze({
  grid: { tablet_columns: 4, phone_columns: 2, tile_size: 'standard' },
  products: { pinned: [], order: 'name' },
  categories: [],
  quick_buttons: [],
  keypad: 'right',
  customer_display: { welcome: null, show_lines: true, show_second_currency: true, show_logo: true },
});

const within = (value, min, max, fallback) => (Number.isInteger(value) && value >= min && value <= max ? value : fallback);
const pick = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);
const bool = (value, fallback) => (typeof value === 'boolean' ? value : fallback);
const lower = (id) => (typeof id === 'string' ? id.toLowerCase() : null);

/**
 * The layout the screen draws: `row` is the synced `pos_layout` row (or
 * null), `categories` the till's categories (id, name), `itemIds` a Set or
 * Map of the items it holds. Never throws: anything unreadable falls back
 * to the defaults.
 */
export function mergeLayout(row, { categories = [], itemIds = new Set(), bestSellers } = {}) {
  const stored = row && typeof row === 'object' ? (row.layout ?? row) : {};
  const grid = stored.grid && typeof stored.grid === 'object' ? stored.grid : {};
  const products = stored.products && typeof stored.products === 'object' ? stored.products : {};
  const display = stored.customer_display && typeof stored.customer_display === 'object' ? stored.customer_display : {};
  const defaults = DEFAULT_LAYOUT;

  const known = new Map(categories.map((category) => [lower(category.id), category]));
  const placed = new Set();
  const merged = [];
  for (const entry of Array.isArray(stored.categories) ? stored.categories : []) {
    const id = lower(entry?.id);
    if (!id || !known.has(id) || placed.has(id)) continue;
    placed.add(id);
    merged.push({
      id: known.get(id).id,
      name: known.get(id).name,
      hidden: entry.hidden === true,
      color: CATEGORY_COLOURS[entry.color] ? entry.color : null,
      image: entry.image && typeof entry.image === 'object' && entry.image.id ? { source: entry.image.source, id: entry.image.id } : null,
    });
  }
  const rest = categories.filter((category) => !placed.has(lower(category.id))).sort((a, b) => String(a.name).localeCompare(String(b.name)));
  for (const category of rest) merged.push({ id: category.id, name: category.name, hidden: false, color: null, image: null });

  const holds = (id) => (typeof itemIds.has === 'function' ? itemIds.has(id) : false);
  const itemId = (id) => {
    if (typeof id !== 'string') return null;
    if (holds(id)) return id;
    const found = [...(itemIds.keys?.() ?? [])].find((candidate) => lower(candidate) === lower(id));
    return found ?? null;
  };

  const pinned = [];
  for (const id of Array.isArray(products.pinned) ? products.pinned : []) {
    const found = itemId(id);
    if (found && !pinned.includes(found)) pinned.push(found);
  }

  const categoryIds = new Map(merged.map((category) => [lower(category.id), category.id]));
  const buttons = [];
  const seen = new Set();
  for (const button of Array.isArray(stored.quick_buttons) ? stored.quick_buttons : []) {
    if (buttons.length >= MAX_QUICK_BUTTONS || !button || typeof button !== 'object') continue;
    let next = null;
    if (button.type === 'action' && ACTIONS.includes(button.action)) next = { type: 'action', action: button.action };
    else if (button.type === 'item' && itemId(button.id)) next = { type: 'item', id: itemId(button.id) };
    else if (button.type === 'category' && categoryIds.has(lower(button.id))) next = { type: 'category', id: categoryIds.get(lower(button.id)) };
    if (!next) continue;
    const key = `${next.type}:${next.id ?? next.action}`;
    if (seen.has(key)) continue;
    seen.add(key);
    buttons.push(next);
  }

  const welcome = typeof display.welcome === 'string' && display.welcome.trim() ? display.welcome : null;

  return {
    grid: {
      tablet_columns: within(grid.tablet_columns, 3, 6, defaults.grid.tablet_columns),
      phone_columns: within(grid.phone_columns, 2, 3, defaults.grid.phone_columns),
      tile_size: pick(grid.tile_size, TILE_SIZES, defaults.grid.tile_size),
    },
    products: { pinned, order: pick(products.order, ORDERS, defaults.products.order) },
    categories: merged,
    quick_buttons: buttons,
    keypad: pick(stored.keypad, ['right', 'left'], defaults.keypad),
    customer_display: {
      welcome,
      show_lines: bool(display.show_lines, true),
      show_second_currency: bool(display.show_second_currency, true),
      show_logo: bool(display.show_logo, true),
    },
    best_sellers: Array.isArray(bestSellers ?? row?.best_sellers) ? (bestSellers ?? row.best_sellers).filter((id) => holds(id)) : [],
  };
}

/**
 * The product tiles in the layout's order: pinned first (in the layout's
 * order), then by category (the layout's category order, then name), by
 * name, or by best-sellers (then name). Tiles keep their identity, so a
 * memoised tile never re-renders for a new order.
 */
export function orderTiles(tiles, layout) {
  const pinned = new Map(layout.products.pinned.map((id, index) => [id, index]));
  const byName = (a, b) => String(a.name).localeCompare(String(b.name));
  let rank;
  if (layout.products.order === 'category') {
    const position = new Map(layout.categories.map((category, index) => [category.id, index]));
    rank = (tile) => (tile.categoryId != null && position.has(tile.categoryId) ? position.get(tile.categoryId) : Number.MAX_SAFE_INTEGER);
  } else if (layout.products.order === 'best_sellers') {
    const position = new Map(layout.best_sellers.map((id, index) => [id, index]));
    rank = (tile) => (position.has(tile.id) ? position.get(tile.id) : Number.MAX_SAFE_INTEGER);
  } else {
    rank = () => 0;
  }
  const first = tiles.filter((tile) => pinned.has(tile.id)).sort((a, b) => pinned.get(a.id) - pinned.get(b.id));
  const others = tiles.filter((tile) => !pinned.has(tile.id)).sort((a, b) => rank(a) - rank(b) || byName(a, b));
  return [...first, ...others];
}

/** The tile size's classes, chosen once (NFR-03: never rebuilt per render). Every size keeps the 48px touch target. */
export const TILE_SIZE_CLASSES = {
  compact: { tile: 'min-h-12 gap-1 p-2', name: 'text-body' },
  standard: { tile: 'min-h-12 gap-3 p-4', name: 'text-body-lg' },
  large: { tile: 'min-h-12 gap-6 p-6', name: 'text-h3' },
};
