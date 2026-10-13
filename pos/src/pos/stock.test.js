import { entityDefinition } from '../sync/entities';
import { buildCatalogue } from './catalogue';

// INV-17: the Inventory module's stock on hand at this till's location is
// stored from the sync and shown on the tiles; it never makes an item unsellable.
describe('stock on hand at the till', () => {
  const item = (id, name) => ({ id, code: name.toUpperCase(), name, category_id: null, base_uom_id: 'u', tax_code_id: 't', sellable: true, uoms: [], barcodes: [] });
  const base = {
    settings: { company: { base_currency: 'KES', timezone: 'Africa/Nairobi' } },
    currencies: [{ id: 'KES', code: 'KES', decimals: 2 }],
    taxCodes: [{ id: 't', code: 'VAT', rates: [{ rate: '16.0000', effective_from: '2020-01-01', effective_to: null }] }],
    priceLists: [{ id: 'pl', currency: 'KES', is_default: true, tax_inclusive: true }],
    items: [item('a', 'Soda'), item('b', 'Bread')],
    prices: ['a', 'b'].map((itemId) => ({ id: `p-${itemId}`, price_list_id: 'pl', item_id: itemId, uom_id: 'u', amount_minor: '10000', currency: 'KES', effective_from: '2026-01-01', min_quantity: '1' })),
  };

  it('is a registered sync entity stored by item', () => {
    const entity = entityDefinition('inventory_stock');
    expect(entity.table).toBe('inventory_stock');
    expect(entity.columns({ id: 's1', item_id: 'a', qty: '12' })).toEqual({ item_id: 'a' });
  });

  it('puts each item’s quantity on its tile, and nothing when Inventory sends none', () => {
    const withStock = buildCatalogue({ ...base, stock: [{ id: 's1', item_id: 'a', qty: '0' }] });
    const tiles = Object.fromEntries(withStock.tiles.map((tile) => [tile.name, tile]));
    expect(tiles.Soda.onHand).toBe('0');
    expect(tiles.Soda.sellable).toBe(true);
    expect(tiles.Bread.onHand).toBeNull();

    expect(buildCatalogue(base).tiles.every((tile) => tile.onHand === null)).toBe(true);
  });
});
