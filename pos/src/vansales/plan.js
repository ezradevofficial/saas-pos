import { localDate } from '../sync/prices';

/**
 * VAN-05, VAN-09: the route plan for a day, computed on the device from the
 * pulled routes (no connection needed). Weekdays are ISO 1 (Monday) to 7
 * (Sunday), read in the company's (or branch's) time zone, never the phone's.
 */
export const VISIT_STATUSES = ['pending', 'visited', 'sold', 'no_sale', 'skipped'];
export const NO_SALE_REASONS = ['closed', 'no_need', 'has_stock', 'no_money', 'credit_hold', 'not_found', 'other'];

/** ISO weekday (1 to 7) of the instant `at` in `timeZone`. */
export function isoWeekday(at, timeZone) {
  const [year, month, day] = localDate(at, timeZone).split('-').map(Number);
  const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
  return weekday === 0 ? 7 : weekday;
}

/** The days a stop is due: its own override, else its route's. */
export const stopDays = (route, stop) => (Array.isArray(stop?.visit_days) && stop.visit_days.length ? stop.visit_days : route?.visit_days ?? []).map(Number);

/** The stops of `route` due on the day of `at`, in the route's order. */
export function stopsDueOn(route, at, timeZone) {
  const weekday = isoWeekday(at, timeZone);
  return [...(route?.stops ?? [])].filter((stop) => stopDays(route, stop).includes(weekday)).sort((a, b) => Number(a.position ?? 0) - Number(b.position ?? 0));
}

/** The routes with at least one stop due on the day of `at` (or, with no stops yet, visited that day). */
export function routesDueOn(routes, at, timeZone) {
  const weekday = isoWeekday(at, timeZone);
  return routes.filter((route) => (route.stops?.length ? stopsDueOn(route, at, timeZone).length > 0 : (route.visit_days ?? []).map(Number).includes(weekday)));
}

/** The latest visit (by `updated_at`) to a stop's customer in this run. */
export function visitOfStop(visits, stop) {
  const mine = visits.filter((visit) => (visit.stop_id && visit.stop_id === stop.id) || visit.customer_id === stop.customer_id);
  return mine.sort((a, b) => String(b.updated_at ?? '').localeCompare(String(a.updated_at ?? '')))[0] ?? null;
}

/**
 * The day's plan: each due stop with its status for this run (a dot and a
 * word on screen): pending when not visited yet.
 */
export function dayPlan({ route, visits = [], at, timeZone }) {
  return stopsDueOn(route, at, timeZone).map((stop) => {
    const visit = visitOfStop(visits, stop);
    return { stop, visit, status: visit?.status && VISIT_STATUSES.includes(visit.status) ? visit.status : 'pending' };
  });
}

/** Counts per status for the plan header. */
export function planSummary(plan) {
  const counts = Object.fromEntries(VISIT_STATUSES.map((status) => [status, 0]));
  for (const entry of plan) counts[entry.status] += 1;
  return { total: plan.length, done: plan.length - counts.pending, ...counts };
}
