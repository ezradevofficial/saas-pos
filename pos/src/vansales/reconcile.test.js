import { dayItems, expectedTenders, parseCountQty, stockEquation, tenderVariances } from './reconcile';

// VAN-18, VAN-19, VAN-20: the count's equations, computed on the device.

const RUN = 'run-1';
const line = (id, itemId, name, qty) => ({ id, item_id: itemId, item_name: name, qty });
const sale = (id, lines, payments, extra = {}) => ({
  id,
  extensions: { van_sales: { run_id: RUN } },
  lines,
  payments,
  local: { methods: { cash: { type: 'cash' }, mpesa: { type: 'mobile_money' }, credit: { type: 'credit' } }, ...extra.local },
  change: extra.change,
});
const loads = [{ id: 'l1', status: 'confirmed', lines: [{ id: 'x', item_id: 'beer', item_name: 'Tusker', qty: '24', qty_confirmed: '20' }, { id: 'y', item_id: 'bread', item_name: 'Bread', qty: '10', qty_confirmed: '10' }] }];

describe('stock equation (VAN-19)', () => {
  it('load = sold + returned + damaged + variance, a shortage positive', () => {
    expect(stockEquation({ load_qty: '20', sold_qty: '12', returned_qty: '5', damaged_qty: '1' })).toMatchObject({ variance_qty: '2', balanced: false });
    expect(stockEquation({ load_qty: '20', sold_qty: '12', returned_qty: '7', damaged_qty: '1' })).toMatchObject({ variance_qty: '0', balanced: true });
    expect(stockEquation({ load_qty: '20', sold_qty: '12', returned_qty: '9', damaged_qty: '1' })).toMatchObject({ variance_qty: '-2', balanced: false });
  });

  it('keeps exact decimals', () => {
    expect(stockEquation({ load_qty: '10.5', sold_qty: '3.25', returned_qty: '7', damaged_qty: '0.1' }).variance_qty).toBe('0.15');
  });

  it('derives load and sold from the confirmed sheet, the sales and customer returns; voided sales are out', () => {
    const sales = [
      sale('s1', [line('l1', 'beer', 'Tusker', '5')], []),
      sale('s2', [line('l2', 'beer', 'Tusker', '2'), line('l3', 'bread', 'Bread', '3')], []),
      sale('s3', [line('l4', 'beer', 'Tusker', '9')], [], { local: { status: 'voided' } }),
      { ...sale('s4', [line('l5', 'beer', 'Tusker', '1')], []), extensions: { van_sales: { run_id: 'other' } } },
    ];
    const refunds = [{ sale_id: 's1', lines: [{ sale_line_id: 'l1', qty: '1' }] }];
    const items = dayItems({ runId: RUN, loads, sales, refunds });
    // The 20 loaded plus the 1 a customer returned; 7 sold (gross).
    expect(items).toEqual([
      { item_id: 'bread', item_name: 'Bread', load_qty: '10', sold_qty: '3', customer_returns: '0' },
      { item_id: 'beer', item_name: 'Tusker', load_qty: '21', sold_qty: '7', customer_returns: '1' },
    ].sort((a, b) => a.item_name.localeCompare(b.item_name)));
  });

  it('ignores a sheet that is not confirmed', () => {
    expect(dayItems({ runId: RUN, loads: [{ ...loads[0], status: 'released' }], sales: [] })).toEqual([]);
  });

  it('reads the typed count quantities', () => {
    expect(parseCountQty('')).toBe('0');
    expect(parseCountQty('3,5')).toBe('3.5');
    expect(parseCountQty('abc')).toBeNull();
    expect(parseCountQty('1.1234567')).toBeNull();
  });
});

describe('cash and mobile money equation (VAN-20)', () => {
  it('expects tenders less change, plus collections, less refunds; credit and voided sales never count', () => {
    const sales = [
      sale('s1', [], [{ id: 'cash', currency: 'KES', amount_minor: '100000' }], { change: { currency: 'KES', amount_minor: '5000' } }),
      sale('s2', [], [{ id: 'mpesa', currency: 'KES', amount_minor: '25000' }, { id: 'credit', currency: 'KES', amount_minor: '70000' }]),
      sale('s3', [], [{ id: 'cash', currency: 'USD', amount_minor: '2000' }]),
      sale('s4', [], [{ id: 'cash', currency: 'KES', amount_minor: '99999' }], { local: { status: 'voided' } }),
    ];
    const collections = [
      { run_id: RUN, currency: 'KES', amount_minor: '30000', local: { method_type: 'cash' } },
      { run_id: RUN, currency: 'KES', amount_minor: '12000', local: { method_type: 'mobile_money' } },
      { run_id: RUN, currency: 'KES', amount_minor: '77777', local: { method_type: 'cash', voided: true } },
    ];
    const refunds = [{ sale_id: 's1', payments: [{ currency: 'KES', amount_minor: '10000' }], local: { method_type: 'cash' } }, { sale_id: 's4', payments: [{ currency: 'KES', amount_minor: '5' }], local: { method_type: 'cash' } }];
    const expected = expectedTenders({ runId: RUN, sales, collections, refunds });
    expect(expected).toEqual([
      { kind: 'cash', currency: 'KES', expected_minor: '115000' },
      { kind: 'cash', currency: 'USD', expected_minor: '2000' },
      { kind: 'mobile_money', currency: 'KES', expected_minor: '37000' },
    ]);
  });

  it('variance = counted - expected', () => {
    const rows = tenderVariances([{ kind: 'cash', currency: 'KES', expected_minor: '115000' }, { kind: 'mobile_money', currency: 'KES', expected_minor: '37000' }], { 'cash:KES': '114000' });
    expect(rows).toEqual([
      { kind: 'cash', currency: 'KES', expected_minor: '115000', counted_minor: '114000', variance_minor: '-1000' },
      { kind: 'mobile_money', currency: 'KES', expected_minor: '37000', counted_minor: null, variance_minor: null },
    ]);
  });
});
