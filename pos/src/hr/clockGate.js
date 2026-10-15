import { verifyOffline } from '../auth/pinCrypto';
import { uuidv7 } from '../lib/random';
import { ATTEMPT_KIND, PUNCH_KIND } from './clockStore';

/**
 * HR-22, HR-23, NFR-04: clocking in and out on the till.
 *
 * - The clock-in PIN is HR's own (not the till sign-in PIN, HR decision 1),
 *   checked only offline, against the synced verifier under the device's
 *   current secret: HMAC-SHA256(secret, "hr-clock:v1:{employee_id}:" ||
 *   PBKDF2 key). The PIN is never stored.
 * - Five consecutive wrong PINs lock the employee on this till until a new
 *   PIN arrives through sync (another version or salt in the synced row).
 *   A right PIN starts the count again. Every wrong PIN (and the lockout)
 *   is written with a report for the server (POST hr/till/clock-attempts),
 *   queued and retried like the punches.
 * - The punch's kind follows the employee's latest punch on this till in
 *   the last 16 hours (the server's overnight window, Punches::nextKind):
 *   `out` after an `in`, else `in`. Punches the server rejected do not
 *   count. Its time is the server's clock as the till knows it
 *   (engine.serverNow), sent with an explicit offset (+00:00).
 * - A punch is written with its outbox row in one transaction and uploads
 *   with the next push (POST hr/till/punches), idempotent by its own UUID v7.
 */
export const CLOCK_PREFIX = 'hr-clock';
export const CLOCK_MAX_ATTEMPTS = 5;
/** A punch older than this never decides the next kind (HR-22: the overnight window). */
export const PUNCH_WINDOW_MS = 16 * 60 * 60 * 1000;
const KEEP_PUNCHES_MS = 35 * 24 * 60 * 60 * 1000;

/** ISO 8601 in UTC with an explicit offset: 2026-10-14T05:02:00.000+00:00 (HrRequest::INSTANT). */
export function isoInstant(at) {
  return new Date(at).toISOString().replace(/Z$/, '+00:00');
}

/** in after nothing or an out; out after an in. */
export function nextKind(lastPunch) {
  return lastPunch?.kind === 'in' ? 'out' : 'in';
}

/** Names the PIN the synced row carries: a new PIN changes the version or, at least, the salt. */
export const pinStamp = (member) => `${Number(member?.version ?? 0)}:${member?.pin?.salt ?? ''}`;

export function createClockGate({ clockStore, engine, credentials, now = () => engine.serverNow() }) {
  /** The employee's count on this till, started again for a new PIN. */
  async function localState(member) {
    const stamp = pinStamp(member);
    const saved = await clockStore.attempt(member.id);
    if (!saved || saved.pinStamp !== stamp) return { failedAttempts: 0, locked: false, pinStamp: stamp };
    return saved;
  }

  // One check per employee at a time: a check reads the count, derives the key (slow), writes the
  // count back and picks the next kind; two at once would lose a wrong try or punch in twice.
  const queues = new Map();
  function serial(key, task) {
    const previous = queues.get(key) ?? Promise.resolve();
    const run = previous.then(task, task);
    const settled = run.catch(() => {});
    queues.set(key, settled);
    settled.then(() => {
      if (queues.get(key) === settled) queues.delete(key);
    });
    return run;
  }

  async function check({ employeeId, input }) {
    const member = await clockStore.clockStaffMember(employeeId);
    if (!member) return { ok: false, reason: 'not_found' };
    const state = await localState(member);
    if (state.locked) return { ok: false, reason: 'locked' };

    const deviceSecret = await credentials.secret();
    const result = await verifyOffline({ material: member.pin, prefix: CLOCK_PREFIX, userId: member.id, input, deviceSecret });
    if (!result.ok) {
      // not_set, unsupported, no_secret: this till cannot check the PIN; nothing is counted.
      if (result.reason !== 'incorrect') return { ok: false, reason: result.reason };
      const failedAttempts = state.failedAttempts + 1;
      const locked = failedAttempts >= CLOCK_MAX_ATTEMPTS;
      // The count and its report commit together: a crash never loses the report or counts twice.
      const at = now();
      const reportId = uuidv7(at);
      const report = { id: reportId, employee_id: member.id, failed_attempts: failedAttempts, locked, at: isoInstant(at) };
      await engine.enqueue(ATTEMPT_KIND, reportId, report, {
        prepare: async () => [await clockStore.prepareAttempt({ employeeId: member.id, failedAttempts, locked, pinStamp: state.pinStamp })],
      });
      return locked ? { ok: false, reason: 'locked' } : { ok: false, reason: 'incorrect', attemptsLeft: CLOCK_MAX_ATTEMPTS - failedAttempts };
    }
    if (state.failedAttempts > 0) await clockStore.saveAttempt({ employeeId: member.id, failedAttempts: 0, locked: false, pinStamp: state.pinStamp });

    const at = now();
    const kind = nextKind(await clockStore.lastPunchSince(member.id, at - PUNCH_WINDOW_MS));
    const id = uuidv7(at);
    const payload = { id, employee_id: member.id, kind, occurred_at: isoInstant(at) };
    await engine.enqueue(PUNCH_KIND, id, payload, { prepare: async () => [clockStore.preparePunch({ id, employeeId: member.id, kind, occurredAt: at })] });
    return { ok: true, punch: { id, kind, occurredAt: at }, employee: { id: member.id, name: member.name, number: member.number } };
  }

  return {
    /**
     * Check the clock-in PIN and record the punch. Resolves { ok: true,
     * punch: { id, kind, occurredAt }, employee } or { ok: false, reason:
     * 'incorrect' (with attemptsLeft) | 'locked' | 'not_found' | 'not_set'
     * | 'unsupported' | 'no_secret' }.
     */
    punch(request) {
      return serial(request.employeeId, () => check(request));
    },

    /** in or out: what the employee's next punch would be. */
    async nextKindFor(employeeId) {
      return nextKind(await clockStore.lastPunchSince(employeeId, now() - PUNCH_WINDOW_MS));
    },

    /**
     * The clock-in list: each employee with `lockedHere`, `latest` (their
     * latest punch here in the last 16 hours the server has not rejected,
     * with its upload state, or null) and `rejected` (a punch of that
     * window the server rejected). Old punches the server holds are
     * forgotten here.
     */
    async board() {
      const at = now();
      await clockStore.prunePunches(at - KEEP_PUNCHES_MS);
      const [staff, punches] = await Promise.all([clockStore.clockStaff(), clockStore.punchesSince(at - PUNCH_WINDOW_MS)]);
      const latest = new Map();
      const rejected = new Set();
      for (const punch of punches) {
        if (punch.upload === 'rejected') rejected.add(punch.employeeId);
        else if (!latest.has(punch.employeeId)) latest.set(punch.employeeId, punch);
      }
      return Promise.all(
        staff.map(async (member) => ({ ...member, lockedHere: (await localState(member)).locked, latest: latest.get(member.id) ?? null, rejected: rejected.has(member.id) })),
      );
    },
  };
}
