import { dayPlan, isoWeekday, planSummary, routesDueOn, stopsDueOn } from './plan';
import { runViews } from './runs';

// VAN-05: the route plan for a day, computed on the device from the pulled routes.

const route = {
  id: 'r1',
  name: 'Route A',
  visit_days: [1, 3],
  stops: [
    { id: 's3', customer_id: 'c3', customer_name: 'Third', position: 3, visit_days: [] },
    { id: 's1', customer_id: 'c1', customer_name: 'First', position: 1, visit_days: [] },
    { id: 's2', customer_id: 'c2', customer_name: 'Second', position: 2, visit_days: [2] },
  ],
};
// 2026-11-02 is a Monday, 11-03 a Tuesday, 11-04 a Wednesday, 11-08 a Sunday.
const day = (iso) => Date.parse(iso);

describe('route plan by weekday (VAN-05)', () => {
  it('reads the ISO weekday in the company time zone, not the phone or UTC', () => {
    expect(isoWeekday(day('2026-11-02T10:00:00Z'), 'Africa/Nairobi')).toBe(1);
    expect(isoWeekday(day('2026-11-08T12:00:00Z'), 'Africa/Nairobi')).toBe(7);
    // 22:30 UTC on Monday is already Tuesday 01:30 in Nairobi.
    expect(isoWeekday(day('2026-11-02T22:30:00Z'), 'Africa/Nairobi')).toBe(2);
    expect(isoWeekday(day('2026-11-02T22:30:00Z'), 'UTC')).toBe(1);
  });

  it('lists the stops due, in route order, with a stop override replacing the route days', () => {
    expect(stopsDueOn(route, day('2026-11-02T08:00:00Z'), 'Africa/Nairobi').map((stop) => stop.id)).toEqual(['s1', 's3']);
    expect(stopsDueOn(route, day('2026-11-03T08:00:00Z'), 'Africa/Nairobi').map((stop) => stop.id)).toEqual(['s2']);
    expect(stopsDueOn(route, day('2026-11-04T08:00:00Z'), 'Africa/Nairobi').map((stop) => stop.id)).toEqual(['s1', 's3']);
    expect(stopsDueOn(route, day('2026-11-05T08:00:00Z'), 'Africa/Nairobi')).toEqual([]);
  });

  it('finds the routes due today', () => {
    const other = { id: 'r2', name: 'Route B', visit_days: [5], stops: [{ id: 'x', customer_id: 'cx', position: 1, visit_days: [] }] };
    expect(routesDueOn([route, other], day('2026-11-06T08:00:00Z'), 'Africa/Nairobi').map((entry) => entry.id)).toEqual(['r2']);
    expect(routesDueOn([route, other], day('2026-11-02T08:00:00Z'), 'Africa/Nairobi').map((entry) => entry.id)).toEqual(['r1']);
  });

  it('gives each stop its status from the run visits: pending until visited', () => {
    const visits = [
      { id: 'v1', stop_id: 's1', customer_id: 'c1', status: 'sold', updated_at: '2026-11-02T08:30:00Z' },
      { id: 'v3', stop_id: 's3', customer_id: 'c3', status: 'no_sale', no_sale_reason: 'closed', updated_at: '2026-11-02T09:00:00Z' },
    ];
    const plan = dayPlan({ route, visits, at: day('2026-11-02T10:00:00Z'), timeZone: 'Africa/Nairobi' });
    expect(plan.map((entry) => [entry.stop.id, entry.status])).toEqual([['s1', 'sold'], ['s3', 'no_sale']]);
    const fresh = dayPlan({ route, visits: [], at: day('2026-11-02T10:00:00Z'), timeZone: 'Africa/Nairobi' });
    expect(planSummary(fresh)).toMatchObject({ total: 2, done: 0, pending: 2 });
    expect(planSummary(plan)).toMatchObject({ total: 2, done: 2, sold: 1, no_sale: 1 });
  });

  it('matches a visit to a customer on the route even without a stop id (a visit made before a pull)', () => {
    const plan = dayPlan({ route, visits: [{ id: 'v', customer_id: 'c1', status: 'visited', updated_at: 'x' }], at: day('2026-11-02T10:00:00Z'), timeZone: 'Africa/Nairobi' });
    expect(plan[0].status).toBe('visited');
  });
});

describe('runs as the screens see them (VAN-06, VAN-21)', () => {
  const local = { id: 'run-1', route_id: 'r1', user_id: 'u1', started_at: '2026-11-02T06:00:00Z', local: { date: '2026-11-02' } };
  const count = (id, extra = {}) => ({ id, run_id: 'run-1', local: {}, ...extra });

  it('an open run stays open until a count is submitted, then counts', () => {
    expect(runViews({ localRuns: [local] })[0]).toMatchObject({ status: 'open', count: { status: 'none' } });
    expect(runViews({ localRuns: [local], counts: [count('c1')] })[0]).toMatchObject({ status: 'counting', count: { status: 'queued' } });
  });

  it('a balanced count closes the run at once; a rejected one reopens it for a recount', () => {
    const balanced = count('c1', { local: { reconciliation: { status: 'balanced' } } });
    expect(runViews({ localRuns: [local], counts: [balanced] })[0].status).toBe('closed');
    const server = { id: 'run-1', status: 'open', started_at: local.started_at, count: { id: 'c1', status: 'rejected', attempt: 1 } };
    expect(runViews({ localRuns: [local], serverRuns: [server], counts: [count('c1')] })[0]).toMatchObject({ status: 'open', count: { status: 'rejected' } });
    // A newer local attempt waits for its answer.
    expect(runViews({ localRuns: [local], serverRuns: [server], counts: [count('c1'), count('c2')] })[0]).toMatchObject({ status: 'counting', count: { status: 'queued' } });
  });

  it('brings a run back from the server after a reinstall', () => {
    const server = { id: 'run-9', route_id: 'r1', status: 'open', run_date: '2026-11-02', started_at: '2026-11-02T06:00:00Z', count: null };
    expect(runViews({ serverRuns: [server] })[0]).toMatchObject({ id: 'run-9', status: 'open', local: false });
  });
});
