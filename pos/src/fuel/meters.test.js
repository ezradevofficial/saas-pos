import fixture from './meters.fixture.json';
import {
  checkClosing,
  checkOpening,
  chartAt,
  dipToLitres,
  interpolate,
  lineTotal,
  litresFromAmount,
  meterLitres,
  parseCm,
  parseLitres,
  priceAt,
  quote,
  resetAdjustment,
  showLitres,
  unrecordedLitres,
} from './meters';

// FUEL-06, FUEL-11, FUEL-16, FUEL-19, FUEL-28: the till's forecourt maths against figures produced by
// the PHP classes (Meters, Litres, Strapping). See meters.fixture.json for how they were made.

describe('meter litres (FUEL-11)', () => {
  it.each(fixture.meter)('closing - opening - tests + reset adjustment: $opening to $closing = $litres', (row) => {
    expect(meterLitres(row)).toBe(row.litres);
  });

  it('never goes through a float: 0.1 + 0.2 style readings stay exact', () => {
    expect(meterLitres({ opening: '0.100', closing: '0.300' })).toBe('0.200');
    expect(meterLitres({ opening: '9007199254740.993', closing: '9007199254740.995' })).toBe('0.002');
  });

  it('unrecorded litres are the meter litres not rung up, never negative', () => {
    expect(unrecordedLitres('245.500', '240.000')).toBe('5.500');
    expect(unrecordedLitres('100.000', '101.500')).toBe('0.000');
  });

  it('a reset adjusts the litres by reading before - reading after', () => {
    expect(resetAdjustment('500', '100')).toBe('400.000');
  });
});

describe('litres from an amount and line totals (FUEL-19)', () => {
  it('rounds the litres DOWN to 3 decimals and the total HALF UP to the minor unit', () => {
    for (const row of fixture.amount) {
      const litres = litresFromAmount(row.amount_minor, row.price_minor);
      expect(litres).toBe(row.litres);
      expect(lineTotal(row.price_minor, litres)).toBe(row.total_minor);
    }
  });

  it.each(fixture.value)('price $price_minor x $litres L = $total_minor', (row) => {
    expect(lineTotal(row.price_minor, row.litres)).toBe(row.total_minor);
  });

  it('KES 1,000.00 at KES 180.50 a litre is 5.540 L and KES 999.97 (the charged amount can be a unit below the typed one)', () => {
    expect(quote({ mode: 'amount', amountMinor: '100000', priceMinor: '18050' })).toEqual({ litres: '5.540', totalMinor: '99997', priceMinor: '18050' });
  });

  it('CDF has no decimals: CDF 135,000 at CDF 2,850 a litre is 47.368 L and CDF 134,999', () => {
    expect(quote({ mode: 'amount', amountMinor: '135000', priceMinor: '2850' })).toMatchObject({ litres: '47.368', totalMinor: '134999' });
  });

  it('by litres rings the typed litres at the price', () => {
    expect(quote({ mode: 'litres', litres: '20', priceMinor: '18050' })).toEqual({ litres: '20.000', totalMinor: '361000', priceMinor: '18050' });
    expect(quote({ mode: 'litres', litres: '2,5', priceMinor: '15333' })).toMatchObject({ litres: '2.500', totalMinor: '38333' });
  });

  it('refuses what cannot be rung: no price, zero, more than 3 decimals, text', () => {
    expect(() => quote({ mode: 'litres', litres: '5', priceMinor: null })).toThrow(expect.objectContaining({ code: 'price_missing' }));
    expect(() => quote({ mode: 'litres', litres: '0', priceMinor: '18050' })).toThrow(expect.objectContaining({ code: 'litres_zero' }));
    expect(() => quote({ mode: 'litres', litres: '1.2345', priceMinor: '18050' })).toThrow(expect.objectContaining({ code: 'litres_invalid' }));
    expect(() => quote({ mode: 'litres', litres: 'abc', priceMinor: '18050' })).toThrow(expect.objectContaining({ code: 'litres_invalid' }));
    expect(() => quote({ mode: 'amount', amountMinor: '1', priceMinor: '18050' })).toThrow(expect.objectContaining({ code: 'litres_zero' }));
    expect(() => quote({ mode: 'amount', amountMinor: null, priceMinor: '18050' })).toThrow(expect.objectContaining({ code: 'amount_invalid' }));
  });
});

describe('dip to litres by the strapping chart (FUEL-06)', () => {
  const { points, cases, refused } = fixture.strapping;

  it.each(cases)('dip $dip_cm cm = $litres L', ({ dip_cm: dip, litres }) => {
    expect(interpolate(points, dip)).toBe(litres);
  });

  it('is 75 cm = 2000.000 L on [[0,0],[50,1000],[100,3000]]', () => {
    expect(interpolate([['0', '0'], ['50', '1000'], ['100', '3000']], '75')).toBe('2000.000');
  });

  it('refuses a dip outside the chart', () => {
    for (const dip of refused) expect(() => interpolate(points, dip)).toThrow(expect.objectContaining({ code: 'dip_out_of_range' }));
    expect(() => interpolate([], '10')).toThrow(expect.objectContaining({ code: 'dip_out_of_range' }));
  });

  it('uses the chart in force at the dip time (the latest started before it) and refuses when none is', () => {
    const tank = {
      strapping: [
        { id: 'c2', effective_from: '2026-06-01T00:00:00Z', points: [['0', '0.000'], ['100', '4000.000']] },
        { id: 'c1', effective_from: '2026-01-01T00:00:00Z', points: [['0', '0.000'], ['100', '3000.000']] },
      ],
    };
    expect(chartAt(tank.strapping, Date.parse('2026-03-01T00:00:00Z')).id).toBe('c1');
    expect(dipToLitres(tank, '50', Date.parse('2026-03-01T00:00:00Z'))).toEqual({ litres: '1500.000', chartId: 'c1' });
    expect(dipToLitres(tank, '50', Date.parse('2026-07-01T00:00:00Z'))).toEqual({ litres: '2000.000', chartId: 'c2' });
    expect(() => dipToLitres(tank, '50', Date.parse('2025-12-31T00:00:00Z'))).toThrow(expect.objectContaining({ code: 'chart_missing' }));
    expect(() => dipToLitres(tank, '5x', Date.parse('2026-07-01T00:00:00Z'))).toThrow(expect.objectContaining({ code: 'dip_invalid' }));
  });
});

describe('monotonic meters (FUEL-16)', () => {
  it('refuses an opening reading below the nozzle’s last one, naming it', () => {
    expect(() => checkOpening('999.999', '1000.000')).toThrow(expect.objectContaining({ code: 'meter_decreased', last: '1000.000' }));
  });

  it('allows an equal or higher opening reading and reports the gap', () => {
    expect(checkOpening('1000.000', '1000.000')).toEqual({ gap: '0.000' });
    expect(checkOpening('1012.500', '1000.000')).toEqual({ gap: '12.500' });
    expect(checkOpening('5', null)).toEqual({ gap: '0.000' });
  });

  it('refuses a closing reading below the opening one, and tests above the litres moved', () => {
    expect(() => checkClosing({ opening: '1000.000', reading: '999.000' })).toThrow(expect.objectContaining({ code: 'meter_decreased' }));
    expect(() => checkClosing({ opening: '1000.000', reading: '1010.000', tests: '10.001' })).toThrow(expect.objectContaining({ code: 'test_exceeds_litres' }));
    expect(checkClosing({ opening: '1000.000', reading: '1010.000', tests: '5' })).toEqual({ litres: '5.000' });
  });

  it('after an approved reset the floor is the reading after it and the litres are the server’s to compute', () => {
    expect(checkClosing({ opening: '5000.000', reading: '30.000', latestResetAfter: '10.000' })).toEqual({ litres: null });
    expect(() => checkClosing({ opening: '5000.000', reading: '9.000', latestResetAfter: '10.000' })).toThrow(expect.objectContaining({ code: 'meter_decreased' }));
  });

  it('reads typed numbers the way the API takes them', () => {
    expect(parseLitres(' 12,5 ')).toBe('12.5');
    expect(parseLitres('1.2345')).toBeNull();
    expect(parseLitres('-1')).toBeNull();
    expect(parseCm('75.25')).toBe('75.25');
    expect(parseCm('75.255')).toBeNull();
    expect(showLitres('12.5')).toBe('12.500');
  });
});

describe('the price in force (FUEL-08)', () => {
  const prices = [
    { id: 'a', product_id: 'p1', price_minor: '18000', effective_from: '2026-10-01T00:00:00Z' },
    { id: 'b', product_id: 'p1', price_minor: '18500', effective_from: '2026-10-15T06:00:00Z' },
    { id: 'c', product_id: 'p2', price_minor: '17000', effective_from: '2026-10-01T00:00:00Z' },
  ];

  it('is the latest row started at or before the time, per product', () => {
    expect(priceAt(prices, 'p1', Date.parse('2026-10-14T23:59:59Z')).price_minor).toBe('18000');
    expect(priceAt(prices, 'p1', Date.parse('2026-10-15T06:00:00Z')).price_minor).toBe('18500');
    expect(priceAt(prices, 'p2', Date.parse('2026-12-01T00:00:00Z')).price_minor).toBe('17000');
  });

  it('is none before the first row or for an unknown product', () => {
    expect(priceAt(prices, 'p1', Date.parse('2026-09-30T00:00:00Z'))).toBeNull();
    expect(priceAt(prices, 'p9', Date.now())).toBeNull();
  });
});
