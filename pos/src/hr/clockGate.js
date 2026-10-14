import { verifyOffline } from '../auth/pinCrypto';
import { uuidv7 } from '../lib/random';
import { PUNCH_KIND } from './clockStore';

/**
 * HR-22, HR-23, NFR-04: clocking in and out on the till.
 *
 * - The clock-in PIN is HR's own (not the till sign-in PIN, HR decision 1),
 *   checked only offline, against the synced verifier under the device's
 *   current secret: HMAC-SHA256(secret, "hr-clock:v1:{employee_id}:" ||
 *   PBKDF2 key). The PIN is never stored.
 * - Five consecutive wrong PINs lock the employee on this till until a new
 *   PIN arrives through sync (another version or salt in the synced row).
 *   A right PIN starts the count again.
 * - The punch's kind is the opposite of the employee's last punch on this
 *   till today (local day); the first of the day is `in`. Its time is the
 *   server's clock as the till knows it (engine.serverNow).
 * - A punch is written with its outbox row in one transaction and uploads
 *   with the next push (POST hr/till/punches), idempotent by its own UUID v7.
 */
export const CLOCK_PREFIX = 'hr-clock';
export const CLOCK_MAX_ATTEMPTS = 5;
const KEEP_PUNCHES_MS = 35 * 24 * 60 * 60 * 1000;

/** Midnight (local time) of the day `at` falls in, in ms. */
export function startOfLocalDay(at) {
  const day = new Date(at);
  day.setHours(0, 0, 0, 0);
  return day.getTime();
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
      await clockStore.saveAttempt({ employeeId: member.id, failedAttempts, locked, pinStamp: state.pinStamp });
      return locked ? { ok: false, reason: 'locked' } : { ok: false, reason: 'incorrect', attemptsLeft: CLOCK_MAX_ATTEMPTS - failedAttempts };
    }
    if (state.failedAttempts > 0) await clockStore.saveAttempt({ employeeId: member.id, failedAttempts: 0, locked: false, pinStamp: state.pinStamp });

    const at = now();
    const kind = nextKind(await clockStore.lastPunchSince(member.id, startOfLocalDay(at)));
    const id = uuidv7(at);
    const payload = { id, employee_id: member.id, kind, occurred_at: new Date(at).toISOString() };
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

    /** in or out: what the employee's next punch today would be. */
    async nextKindFor(employeeId) {
      return nextKind(await clockStore.lastPunchSince(employeeId, startOfLocalDay(now())));
    },

    /**
     * The clock-in list: each employee with `lockedHere` and `today` (their
     * latest punch today on this till, with its upload state, or null).
     * Old punches the server holds are forgotten here.
     */
    async board() {
      const at = now();
      await clockStore.prunePunches(at - KEEP_PUNCHES_MS);
      const [staff, punches] = await Promise.all([clockStore.clockStaff(), clockStore.punchesSince(startOfLocalDay(at))]);
      const latest = new Map();
      for (const punch of punches) if (!latest.has(punch.employeeId)) latest.set(punch.employeeId, punch);
      return Promise.all(staff.map(async (member) => ({ ...member, lockedHere: (await localState(member)).locked, today: latest.get(member.id) ?? null })));
    },
  };
}
