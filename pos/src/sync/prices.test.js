import { testDatabase } from '../test/testDatabase';
import { localDate, multiplyMinor, priceFor, resolvePrice } from './prices';
import { createSyncStore } from './store';

// MD-03, NFR-04: the till prices offline by the server's PriceResolver rules.

const item = { id: 'i1', base_uom_id: 'pc', uoms: [{ uom_id: 'pack', factor: '6' }, { uom_id: 'half', factor: '0.5' }, { uom_id: 'kg', factor: '1.333' }] };
const price = (id, uom_id, amount_minor, effective_from, min_quantity = '1', currency = 'KES') => ({
  id,
  price_list_id: 'pl',
  item_id: 'i1',
  uom_id,
  amount_minor,
  currency,
  effective_from,
  min_quantity,
});

describe('resolvePrice', () => {
  const prices = [
    price('p1', 'pc', '10000', '2026-01-01'),
    price('p2', 'pc', '12000', '2026-10-01'), // later start, same break
    price('p3', 'pc', '9000', '2026-09-01', '10'), // quantity break
    price('p4', 'pc', '8000', '2026-12-01', '10'), // not yet effective
    price('p5', 'pack', '60000', '2026-01-01'),
  ];
  const at = (uomId, quantity = '1', day = '2026-10-08') => resolvePrice({ item, uomId, prices, day, quantity });

  it('takes the latest start among the effective prices of the unit', () => {
    expect(at('pc')).toMatchObject({ amountMinor: '12000', source: 'unit', itemPriceId: 'p2' });
    expect(at('pc', '1', '2026-09-30')).toMatchObject({ itemPriceId: 'p1' });
  });

  it('takes the highest quantity break that applies', () => {
    expect(at('pc', '10')).toMatchObject({ amountMinor: '9000', itemPriceId: 'p3' });
    expect(at('pc', '9.5')).toMatchObject({ itemPriceId: 'p2' });
  });

  it('prefers an explicit unit price over the derived one', () => {
    expect(at('pack')).toMatchObject({ amountMinor: '60000', source: 'unit' });
  });

  it('derives from the base unit for quantity × factor, rounded once half up', () => {
    // 0.5 × 12000 = 6000; 2 halves = 1 piece: no break.
    expect(at('half', '2')).toMatchObject({ amountMinor: '6000', source: 'base', factor: '0.5' });
    // 1.333 × 12000 = 15996.
    expect(at('kg')).toMatchObject({ amountMinor: '15996', source: 'base' });
    // 8 kg = 10.664 pieces: the 10-piece break applies; 1.333 × 9000 = 11997.
    expect(at('kg', '8')).toMatchObject({ amountMinor: '11997', itemPriceId: 'p3' });
  });

  it('returns null for an unknown unit, a base unit without price, or nothing effective', () => {
    expect(at('box')).toBeNull();
    expect(resolvePrice({ item, uomId: 'pc', prices: [], day: '2026-10-08' })).toBeNull();
    expect(at('pc', '1', '2025-12-31')).toBeNull();
  });

  it('rounds half up in minor units (CDF has none)', () => {
    expect(multiplyMinor('135', '0.5')).toBe('68');
    expect(multiplyMinor('1001', '1.5')).toBe('1502');
    expect(multiplyMinor('1000', '0.3333')).toBe('333');
    expect(multiplyMinor('900719925474099312', '2')).toBe('1801439850948198624');
  });

  it('dates in the selling time zone', () => {
    const late = Date.parse('2026-10-08T22:30:00Z');
    expect(localDate(late, 'Africa/Nairobi')).toBe('2026-10-09');
    expect(localDate(late, 'Africa/Kinshasa')).toBe('2026-10-08');
  });
});

describe('priceFor', () => {
  it('reads the synced item and its prices', async () => {
    const database = testDatabase();
    const store = createSyncStore(database);
    await store.applyPage('items', { upserts: [{ id: 'i1', code: 'A', name: 'Soda', base_uom_id: 'pc', uoms: [{ uom_id: 'pack', factor: '6' }], barcodes: [] }], tombstones: [], cursor: 'c', has_more: false }, 1);
    await store.applyPage('item_prices', { upserts: [price('p1', 'pc', '5000', '2026-01-01')], tombstones: [], cursor: 'c', has_more: false }, 1);

    await expect(priceFor(database, { itemId: 'i1', uomId: 'pack', priceListId: 'pl', day: '2026-10-08' })).resolves.toMatchObject({ amountMinor: '30000', currency: 'KES', source: 'base' });
    await expect(priceFor(database, { itemId: 'nope', uomId: 'pc', priceListId: 'pl', day: '2026-10-08' })).resolves.toBeNull();

    await store.applyPage('item_prices', { upserts: [], tombstones: ['p1'], cursor: 'd', has_more: false }, 2);
    await expect(priceFor(database, { itemId: 'i1', uomId: 'pc', priceListId: 'pl', day: '2026-10-08' })).resolves.toBeNull();
  });
});
