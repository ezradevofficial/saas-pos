import fixture from '../../../api/tests/Fixtures/loyalty/promotion-cases.json';
import { evaluatePromotions, normaliseCode, pointsEarned, pointsToReverse, redeemValue } from './engine';

// LOY-10: the shared cases (api/tests/Fixtures/loyalty/promotion-cases.json) give the same
// result here as on the server (api/modules/Loyalty/tests/PromotionEngineFixturesTest.php).
const contextOf = (overrides = {}) => ({
  ...fixture.context,
  ...overrides,
  local: { ...fixture.context.local, ...(overrides.local ?? {}) },
});

describe('promotions engine (shared cases)', () => {
  it('has cases of every type', () => {
    expect([...new Set(fixture.cases.flatMap((c) => c.promotions.map((p) => p.type)))].sort()).toEqual(['amount_off', 'bundle', 'buy_x_get_y', 'percent']);
  });

  it.each(fixture.cases.map((c) => [c.name, c]))('%s', (_name, c) => {
    expect(evaluatePromotions(c.lines, contextOf(c.context), c.promotions)).toEqual(c.expected);
  });

  it.each(fixture.cases.map((c) => [c.name, c]))('%s, whatever the order promotions arrive in', (_name, c) => {
    expect(evaluatePromotions(c.lines, contextOf(c.context), [...c.promotions].reverse())).toEqual(c.expected);
  });
});

describe('points (shared cases)', () => {
  it.each(fixture.points.earn.map((c) => [c.name, c]))('earn: %s', (_name, c) => {
    expect(pointsEarned(c.eligible_minor, c.earn_points, c.earn_spend_minor)).toBe(c.expected);
  });

  it.each(fixture.points.reverse.map((c) => [c.name, c]))('reverse: %s', (_name, c) => {
    expect(pointsToReverse(c.earned, c.sale_total_minor, c.refunded_minor, c.already_reversed)).toBe(c.expected);
  });

  it.each(fixture.points.redeem.map((c) => [c.name, c]))('redeem: %s', (_name, c) => {
    expect(redeemValue(c.points, c.point_value_minor)).toBe(c.expected);
  });
});

it('normalises coupon codes as the server does', () => {
  expect(normaliseCode('  save 10 ')).toBe('SAVE10');
  expect(normaliseCode('save-10')).toBe('SAVE-10');
});
