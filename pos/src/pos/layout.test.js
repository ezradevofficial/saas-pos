import { buildCatalogue } from './catalogue';
import { CATEGORY_COLOURS, DEFAULT_LAYOUT, mergeLayout, orderTiles } from './layout';

// LAY-05, LAY-07: the till merges the synced layout with what it holds.
const DRINKS = '00000000-0000-4000-8000-000000000001';
const SNACKS = '00000000-0000-4000-8000-000000000002';
const BAKERY = '00000000-0000-4000-8000-000000000003';
const GONE = '00000000-0000-4000-8000-000000000009';
const COLA = '00000000-0000-4000-8000-000000000010';
const CHIPS = '00000000-0000-4000-8000-000000000011';
const BREAD = '00000000-0000-4000-8000-000000000012';

const categories = [
  { id: DRINKS, name: 'Drinks' },
  { id: SNACKS, name: 'Snacks' },
  { id: BAKERY, name: 'Bakery' },
];
const items = new Map([
  [COLA, { id: COLA }],
  [CHIPS, { id: CHIPS }],
  [BREAD, { id: BREAD }],
]);

const row = {
  id: 'layout',
  layout: {
    grid: { tablet_columns: 5, phone_columns: 3, tile_size: 'large' },
    products: { pinned: [GONE, CHIPS], order: 'category' },
    categories: [
      { id: SNACKS, hidden: false, color: 'warning-tint', image: null },
      { id: GONE, hidden: false, color: 'primary-tint', image: null },
      { id: DRINKS, hidden: true, color: '#ff0000', image: { source: 'brand_asset', id: GONE } },
    ],
    quick_buttons: [
      { type: 'action', action: 'hold' },
      { type: 'item', id: GONE },
      { type: 'category', id: SNACKS },
      { type: 'action', action: 'hold' },
      { type: 'action', action: 'dance' },
    ],
    keypad: 'left',
    customer_display: { welcome: 'Karibu', show_lines: false, show_second_currency: true, show_logo: false },
  },
  best_sellers: [BREAD, GONE],
};

describe('mergeLayout', () => {
  it('keeps the layout order, skips what the till no longer holds and adds new categories last', () => {
    const layout = mergeLayout(row, { categories, itemIds: items });

    expect(layout.grid).toEqual({ tablet_columns: 5, phone_columns: 3, tile_size: 'large' });
    expect(layout.categories.map((category) => [category.name, category.hidden, category.color])).toEqual([
      ['Snacks', false, 'warning-tint'],
      // A colour outside the token set is dropped, never drawn.
      ['Drinks', true, null],
      // New since the layout was published: last, shown.
      ['Bakery', false, null],
    ]);
    expect(layout.products).toEqual({ pinned: [CHIPS], order: 'category' });
    expect(layout.quick_buttons).toEqual([
      { type: 'action', action: 'hold' },
      { type: 'category', id: SNACKS },
    ]);
    expect(layout.keypad).toBe('left');
    expect(layout.customer_display).toEqual({ welcome: 'Karibu', show_lines: false, show_second_currency: true, show_logo: false });
    expect(layout.best_sellers).toEqual([BREAD]);
  });

  it('falls back to the defaults when nothing (or nonsense) is synced', () => {
    for (const nothing of [null, {}, { layout: 'nope' }, { layout: { grid: { tablet_columns: 12, tile_size: 'huge' }, keypad: 'top', quick_buttons: 'x' } }]) {
      const layout = mergeLayout(nothing, { categories, itemIds: items });
      expect(layout.grid).toEqual(DEFAULT_LAYOUT.grid);
      expect(layout.keypad).toBe('right');
      expect(layout.quick_buttons).toEqual([]);
      expect(layout.categories.map((category) => category.name)).toEqual(['Bakery', 'Drinks', 'Snacks']);
    }
  });

  it('keeps at most 8 quick buttons', () => {
    const many = ['hold', 'customer', 'cash_in', 'cash_out', 'sales'].map((action) => ({ type: 'action', action }));
    const layout = mergeLayout({ layout: { quick_buttons: [...many, ...categories.map((c) => ({ type: 'category', id: c.id })), { type: 'item', id: COLA }] } }, { categories, itemIds: items });
    expect(layout.quick_buttons).toHaveLength(8);
  });

  it('maps every colour to token classes only', () => {
    for (const classes of Object.values(CATEGORY_COLOURS)) {
      expect(classes.tile).toMatch(/^bg-[a-z0-9-]+$/);
      expect(classes.dot).toMatch(/^bg-[a-z0-9-]+$/);
    }
  });
});

describe('orderTiles', () => {
  const tiles = [
    { id: COLA, name: 'Coca-Cola', categoryId: DRINKS },
    { id: CHIPS, name: 'Chips', categoryId: SNACKS },
    { id: BREAD, name: 'Bread', categoryId: BAKERY },
  ];

  it('puts pinned items first, then by the layout category order', () => {
    const layout = mergeLayout({ layout: { products: { pinned: [COLA], order: 'category' }, categories: [{ id: BAKERY }, { id: SNACKS }] } }, { categories, itemIds: items });
    expect(orderTiles(tiles, layout).map((tile) => tile.name)).toEqual(['Coca-Cola', 'Bread', 'Chips']);
  });

  it('orders by best-sellers, then by name', () => {
    const layout = mergeLayout({ layout: { products: { order: 'best_sellers' } }, best_sellers: [CHIPS] }, { categories, itemIds: items });
    expect(orderTiles(tiles, layout).map((tile) => tile.name)).toEqual(['Chips', 'Bread', 'Coca-Cola']);
  });

  it('orders by name by default', () => {
    expect(orderTiles(tiles, mergeLayout(null, { categories, itemIds: items })).map((tile) => tile.name)).toEqual(['Bread', 'Chips', 'Coca-Cola']);
  });
});

describe('buildCatalogue with a layout', () => {
  it('orders tiles, colours them by category, and leaves hidden categories out of the chips', () => {
    const item = (id, name, categoryId) => ({ id, code: name.toUpperCase(), name, category_id: categoryId, base_uom_id: 'u', tax_code_id: 't', sellable: true, uoms: [], barcodes: [] });
    const catalogue = buildCatalogue({
      settings: { company: { base_currency: 'KES', timezone: 'Africa/Nairobi' } },
      currencies: [{ id: 'KES', code: 'KES', decimals: 2 }],
      taxCodes: [{ id: 't', code: 'VAT', rates: [{ rate: '16.0000', effective_from: '2020-01-01', effective_to: null }] }],
      priceLists: [{ id: 'pl', currency: 'KES', is_default: true, tax_inclusive: true }],
      categories,
      items: [item(COLA, 'Coca-Cola', DRINKS), item(CHIPS, 'Chips', SNACKS), item(BREAD, 'Bread', BAKERY)],
      prices: [COLA, CHIPS, BREAD].map((itemId) => ({ id: `p-${itemId}`, price_list_id: 'pl', item_id: itemId, uom_id: 'u', amount_minor: '10000', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' })),
      layout: row,
    });

    expect(catalogue.tiles.map((tile) => [tile.name, tile.color])).toEqual([
      // Pinned, then the layout's category order (a hidden category still orders its items).
      ['Chips', 'warning-tint'],
      ['Coca-Cola', null],
      ['Bread', null],
    ]);
    expect(catalogue.categories.map((category) => [category.name, category.colours?.tile ?? null])).toEqual([
      ['Snacks', 'bg-warning-tint'],
      ['Bakery', null],
    ]);
    expect(catalogue.layout.keypad).toBe('left');
  });
});
