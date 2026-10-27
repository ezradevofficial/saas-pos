import { localDate } from '../sync/prices';

/**
 * VAN-06, VAN-21: the rep's runs as the screens see them, from the runs this
 * device made (local) and the runs the server pulled (`van_sales_runs`, which
 * also brings the run back after a reinstall and carries the server's answer
 * to the count). Pure functions.
 *
 * A run is `open`, `counting` (an end-of-day count is waiting for the
 * server's decision) or `closed`. The server's row wins, except that a count
 * this device made and the server has not answered yet is `counting`.
 */

/** The server's answer to the latest count: { status: none | queued | parked | balanced | pending | approved | rejected, ... }. */
export function countOutcome({ latest = null, server = null }) {
  if (!latest && !server?.id) return { status: 'none' };
  // The pulled row speaks for the latest count unless this device made a newer attempt since.
  if (server?.id && (!latest || server.id === latest.id)) return { status: server.status, attempt: server.attempt ?? null, id: server.id };
  if (latest.local?.error) return { status: 'parked', code: latest.local.error.code, id: latest.id };
  const reconciliation = latest.local?.reconciliation;
  if (reconciliation) return { status: reconciliation.status, id: latest.id, reconciliation };
  return { status: 'queued', id: latest.id };
}

export function runView({ local = null, server = null, counts = [] }) {
  const base = local ?? server;
  if (!base) return null;
  const latest = counts[counts.length - 1] ?? null;
  const outcome = countOutcome({ latest, server: server?.count ?? null });
  let status = server?.status ?? 'open';
  if (status !== 'closed') {
    if (outcome.status === 'balanced' || outcome.status === 'approved') status = 'closed';
    else if (['queued', 'pending', 'balanced'].includes(outcome.status) || (latest && outcome.status !== 'rejected' && outcome.status !== 'parked')) status = 'counting';
    else if (outcome.status === 'rejected' || outcome.status === 'parked') status = 'open';
  }
  return {
    id: base.id,
    route_id: base.route_id ?? null,
    user_id: base.user_id ?? null,
    started_at: base.started_at,
    run_date: server?.run_date ?? local?.local?.date ?? null,
    status,
    count: outcome,
    local: Boolean(local),
  };
}

/** Every run, newest first. */
export function runViews({ localRuns = [], serverRuns = [], counts = [] }) {
  const ids = new Set([...localRuns.map((run) => run.id), ...serverRuns.map((run) => run.id)]);
  return [...ids]
    .map((id) =>
      runView({
        local: localRuns.find((run) => run.id === id) ?? null,
        server: serverRuns.find((run) => run.id === id) ?? null,
        counts: counts.filter((count) => count.run_id === id),
      }),
    )
    .filter(Boolean)
    .sort((a, b) => String(b.started_at).localeCompare(String(a.started_at)));
}

/** The one open run (VAN-06), or the newest counting one when none is open; null when neither. */
export function currentRun(views) {
  return views.find((view) => view.status === 'open') ?? null;
}

/** Whether a run is from an earlier day than `at` in `timeZone` (the rep forgot to count yesterday). */
export const isStale = (run, at, timeZone) => Boolean(run?.started_at) && localDate(Date.parse(run.started_at), timeZone) !== localDate(at, timeZone);
