/**
 * LAY-05: the POS layout as the designer edits it, and the same merge and
 * order rules the till applies (pos/src/pos/layout.js), so the preview
 * shows what the till will draw.
 */
export const COLOURS = ['primary-tint', 'surface-300', 'success-tint', 'warning-tint', 'danger-tint']
export const TILE_SIZES = ['compact', 'standard', 'large']
export const ORDERS = ['category', 'name', 'best_sellers']
export const ACTIONS = ['hold', 'customer', 'cash_in', 'cash_out', 'sales']
export const MAX_QUICK_BUTTONS = 8
export const MAX_PINNED = 48
export const WELCOME_MAX = 120

export const DEFAULT_LAYOUT = {
  grid: { tablet_columns: 4, phone_columns: 2, tile_size: 'standard' },
  products: { pinned: [], order: 'name' },
  categories: [],
  quick_buttons: [],
  keypad: 'right',
  customer_display: { welcome: null, show_lines: true, show_second_currency: true, show_logo: true },
}

const pick = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback)
const within = (value, min, max, fallback) => (Number.isInteger(value) && value >= min && value <= max ? value : fallback)

/**
 * The stored payload in full, merged with the tenant's categories (LAY-07):
 * the layout's order, then categories it does not name (by name, shown);
 * ones that no longer exist are skipped.
 */
export function layoutFrom(payload, categories = []) {
  const stored = payload && typeof payload === 'object' ? payload : {}
  const grid = stored.grid ?? {}
  const products = stored.products ?? {}
  const display = stored.customer_display ?? {}
  const known = new Map(categories.map((category) => [category.id, category]))
  const placed = new Set()
  const merged = []
  for (const entry of Array.isArray(stored.categories) ? stored.categories : []) {
    if (!entry?.id || !known.has(entry.id) || placed.has(entry.id)) continue
    placed.add(entry.id)
    merged.push({ id: entry.id, hidden: entry.hidden === true, color: COLOURS.includes(entry.color) ? entry.color : null, image: entry.image?.id ? { source: entry.image.source, id: entry.image.id } : null })
  }
  const rest = categories.filter((category) => !placed.has(category.id)).sort((a, b) => String(a.name).localeCompare(String(b.name)))
  for (const category of rest) merged.push({ id: category.id, hidden: false, color: null, image: null })

  return {
    grid: {
      tablet_columns: within(grid.tablet_columns, 3, 6, DEFAULT_LAYOUT.grid.tablet_columns),
      phone_columns: within(grid.phone_columns, 2, 3, DEFAULT_LAYOUT.grid.phone_columns),
      tile_size: pick(grid.tile_size, TILE_SIZES, DEFAULT_LAYOUT.grid.tile_size),
    },
    products: { pinned: Array.isArray(products.pinned) ? products.pinned.filter((id) => typeof id === 'string') : [], order: pick(products.order, ORDERS, 'name') },
    categories: merged,
    quick_buttons: (Array.isArray(stored.quick_buttons) ? stored.quick_buttons : []).filter((button) => button && typeof button === 'object').slice(0, MAX_QUICK_BUTTONS),
    keypad: pick(stored.keypad, ['right', 'left'], 'right'),
    customer_display: {
      welcome: typeof display.welcome === 'string' ? display.welcome : null,
      show_lines: display.show_lines !== false,
      show_second_currency: display.show_second_currency !== false,
      show_logo: display.show_logo !== false,
    },
  }
}

/** What is saved: the layout with an empty welcome text as null. */
export function cleanLayout(layout) {
  const welcome = layout.customer_display.welcome?.trim() ? layout.customer_display.welcome : null
  return { ...layout, customer_display: { ...layout.customer_display, welcome } }
}

/** The preview's tiles: pinned first, then by category order or name (best-sellers are known only at the till: by name). */
export function previewTiles(items, layout) {
  const pinned = new Map(layout.products.pinned.map((id, index) => [id, index]))
  const position = new Map(layout.categories.map((category, index) => [category.id, index]))
  const colour = new Map(layout.categories.map((category) => [category.id, category.color]))
  const rank = (item) => (layout.products.order === 'category' ? (position.get(item.category_id) ?? Number.MAX_SAFE_INTEGER) : 0)
  const first = items.filter((item) => pinned.has(item.id)).sort((a, b) => pinned.get(a.id) - pinned.get(b.id))
  const rest = items.filter((item) => !pinned.has(item.id)).sort((a, b) => rank(a) - rank(b) || String(a.name).localeCompare(String(b.name)))
  return [...first, ...rest].map((item) => ({ ...item, color: colour.get(item.category_id) ?? null }))
}

/** A quick button's stable key. */
export const buttonKey = (button) => `${button.type}:${button.id ?? button.action}`

/** Moves the entry with `activeId` to where `overId` is (dnd-kit's drop). */
export function move(list, keyOf, activeId, overId) {
  const from = list.findIndex((entry) => keyOf(entry) === activeId)
  const to = list.findIndex((entry) => keyOf(entry) === overId)
  if (from < 0 || to < 0 || from === to) return list
  const next = [...list]
  const [moved] = next.splice(from, 1)
  next.splice(to, 0, moved)
  return next
}
