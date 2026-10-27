import { allocate, daysUntil, lotVerdict, plain, prescriptionProblem, rulesOf, toBase } from './engine';

// PHA-07, PHA-08, PHA-16, PHA-17: the till's batch and prescription rules, pure.

const settings = { near_expiry_days: 90, min_sale_days: 0, prescription_classes: ['prescription_only', 'controlled'], pharmacist_classes: ['controlled'], prescription_valid_days: 30 };
const TODAY = '2026-10-13';
const lot = (id, expires_on, qty, extra = {}) => ({ id: `bal-${id}`, item_id: 'item', lot_id: id, batch_number: id.toUpperCase(), expires_on, qty, blocked: null, ...extra });

describe('lot verdicts', () => {
  it('counts days to expiry and refuses expired, recalled, quarantined and too-close lots', () => {
    expect(daysUntil(TODAY, '2026-10-14')).toBe(1);
    expect(lotVerdict(lot('a', '2026-10-12', '5'), settings, TODAY)).toMatchObject({ refuse: 'lot_expired', daysLeft: -1 });
    expect(lotVerdict(lot('a', '2026-10-13', '5'), settings, TODAY).refuse).toBeNull();
    expect(lotVerdict(lot('a', '2027-06-01', '5', { blocked: 'recalled' }), settings, TODAY).refuse).toBe('lot_recalled');
    expect(lotVerdict(lot('a', '2027-06-01', '5', { blocked: 'quarantined' }), settings, TODAY).refuse).toBe('lot_quarantined');
    expect(lotVerdict(lot('a', '2026-10-20', '5'), { ...settings, min_sale_days: 14 }, TODAY).refuse).toBe('lot_too_close');
  });

  it('warns inside the near-expiry window (the tenant’s own number)', () => {
    expect(lotVerdict(lot('a', '2027-01-10', '5'), settings, TODAY).warn).toBe('near_expiry');
    expect(lotVerdict(lot('a', '2027-06-01', '5'), settings, TODAY).warn).toBeNull();
    expect(lotVerdict(lot('a', '2027-01-10', '5'), { ...settings, near_expiry_days: 30 }, TODAY).warn).toBeNull();
  });
});

describe('allocation', () => {
  const lots = [lot('c', '2027-06-01', '20'), lot('x', '2026-10-01', '10'), lot('b', '2027-01-10', '2')];

  it('takes first-expiry-first-out and never touches an expired lot', () => {
    const result = allocate({ lots, used: new Map(), settings, today: TODAY, qty: toBase(null, null, '3') });
    expect(result.taken.map((row) => [row.lot_id, row.qty, row.warn])).toEqual([['b', '2', 'near_expiry'], ['c', '1', null]]);
    expect(result).toMatchObject({ short: '0', reason: null });
  });

  it('subtracts what this till already sold since the last pull', () => {
    const result = allocate({ lots, used: new Map([['b', '2'], ['c', '19']]), settings, today: TODAY, qty: toBase(null, null, '2') });
    expect(result).toMatchObject({ short: '1', reason: 'lot_short' });
    expect(result.taken.map((row) => [row.lot_id, row.qty])).toEqual([['c', '1']]);
  });

  it('honours a chosen lot first, then fills from the earliest', () => {
    const result = allocate({ lots, used: new Map(), settings, today: TODAY, qty: toBase(null, null, '5'), picks: [{ lot_id: 'c', qty: '3' }] });
    expect(result.taken.map((row) => [row.lot_id, row.qty])).toEqual([['c', '3'], ['b', '2']]);
  });

  it('refuses a chosen expired lot', () => {
    expect(allocate({ lots, used: new Map(), settings, today: TODAY, qty: toBase(null, null, '1'), picks: [{ lot_id: 'x', qty: '1' }] }).reason).toBe('lot_expired');
  });

  it('gives the lot problem, not a shortage, when only unsellable stock exists', () => {
    expect(allocate({ lots: [lot('x', '2026-10-01', '10')], used: new Map(), settings, today: TODAY, qty: toBase(null, null, '1') })).toMatchObject({ reason: 'lot_expired', taken: [] });
    expect(allocate({ lots: [], used: new Map(), settings, today: TODAY, qty: toBase(null, null, '1') }).reason).toBe('lot_short');
  });

  it('converts a sold pack into base units with the item’s unit factor', () => {
    const item = { base_uom_id: 'tab', uoms: [{ uom_id: 'box', factor: '10' }] };
    expect(plain(toBase(item, 'box', '2'))).toBe('20');
    expect(plain(toBase(item, 'tab', '2.5'))).toBe('2.5');
    expect(plain(toBase(item, null, '3'))).toBe('3');
  });
});

describe('item rules and prescriptions', () => {
  it('reads the rules from the profile and the tenant’s settings', () => {
    expect(rulesOf({ class: 'prescription_only', batch_tracked: true }, settings)).toMatchObject({ rx: true, pharmacist: false, lots: true });
    expect(rulesOf({ class: 'controlled' }, settings)).toMatchObject({ rx: true, pharmacist: true });
    expect(rulesOf({ class: 'otc', restricted_sale: true }, settings)).toMatchObject({ rx: false, pharmacist: true, buyer: true });
    expect(rulesOf({ class: 'prescription_only' }, { ...settings, prescription_classes: [] }).rx).toBe(false);
    expect(rulesOf(undefined, settings)).toMatchObject({ rx: false, pharmacist: false, lots: false });
  });

  it('wants every field, a real date, and a prescription within its validity', () => {
    const ok = { prescriber_name: 'Dr A', prescriber_licence: 'L1', facility: 'Clinic', patient_name: 'Amina', prescribed_on: '2026-10-01' };
    expect(prescriptionProblem(ok, settings, TODAY)).toBeNull();
    expect(prescriptionProblem(null, settings, TODAY)).toBe('prescription_needed');
    expect(prescriptionProblem({ ...ok, facility: ' ' }, settings, TODAY)).toBe('prescription_needed');
    expect(prescriptionProblem({ ...ok, prescribed_on: '01/10/2026' }, settings, TODAY)).toBe('prescription_needed');
    expect(prescriptionProblem({ ...ok, prescribed_on: '2026-10-20' }, settings, TODAY)).toBe('prescription_needed');
    expect(prescriptionProblem({ ...ok, prescribed_on: '2026-08-01' }, settings, TODAY)).toBe('prescription_stale');
    expect(prescriptionProblem({ ...ok, prescribed_on: '2026-08-01' }, { ...settings, prescription_valid_days: null }, TODAY)).toBeNull();
  });
});
