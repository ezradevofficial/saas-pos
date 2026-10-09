import { NetworkError } from '../sync/api';
import { verifyOffline } from './pinCrypto';

/**
 * AUTH-06, AUTH-07: staff sign-in at the till.
 *
 * - Offline first: when the staff row carries PIN material for the
 *   device's current secret, the PIN is checked on the device (fast, works
 *   with no connection). Staff without material (`offline: false`, or a
 *   secret mismatch) are checked online with POST pos/pin/verify.
 * - Lockout: wrong attempts count per user on this device; the fifth (the
 *   server's `pin.max_attempts`) locks the PIN here until a new PIN is set
 *   (a higher `pin_version` in the staff row). The server's own lock
 *   (`locked`) always applies. Offline counts are reported later
 *   (POST pos/pin/attempts, through the sync engine).
 * - `must_change`: the caller must ask for a new PIN before anything else
 *   when online; offline the sign-in goes ahead and the change waits.
 */
export const DEFAULT_MAX_ATTEMPTS = 5;

export function createPinGate({ store, api, credentials, now = () => Date.now(), onAttemptRecorded = () => {} }) {
  async function maxAttempts() {
    return (await store.meta()).pin?.max_attempts ?? DEFAULT_MAX_ATTEMPTS;
  }

  /** The user's local state, reset when the server has a newer PIN. */
  async function localState(staff) {
    const local = await store.pinAttempt(staff.id);
    const version = Number(staff.pin_version ?? 0);
    if (!local || Number(local.pinVersion ?? 0) < version) {
      return { failedAttempts: Number(staff.failed_attempts ?? 0), locked: false, pinVersion: version, fresh: true };
    }
    return { ...local, fresh: false };
  }

  async function recordFailure(staff, state, floor = 0) {
    const max = await maxAttempts();
    const failedAttempts = Math.max(state.failedAttempts + 1, floor);
    const locked = failedAttempts >= max;
    await store.savePinAttempt({
      userId: staff.id,
      failedAttempts,
      // Never lower a count the server has not heard of yet.
      reportFailed: !state.fresh && state.reported === false ? Math.max(state.reportFailed ?? 0, failedAttempts) : failedAttempts,
      locked,
      occurredAt: new Date(now()).toISOString(),
      pinVersion: state.pinVersion,
      reported: false,
    });
    onAttemptRecorded();
    return locked ? { ok: false, reason: 'locked' } : { ok: false, reason: 'incorrect', attemptsLeft: Math.max(0, max - failedAttempts) };
  }

  // The count starts again, but wrong attempts the server has not heard of yet are still reported.
  async function recordSuccess(staff, state) {
    if (state.failedAttempts > 0 || !state.fresh) {
      const unreported = !state.fresh && state.reported === false;
      await store.savePinAttempt({
        userId: staff.id,
        failedAttempts: 0,
        reportFailed: unreported ? state.reportFailed : 0,
        locked: false,
        occurredAt: unreported ? state.occurredAt : null,
        pinVersion: state.pinVersion,
        reported: !unreported,
      });
    }
  }

  async function verifyOnline(staff, kind, input, session) {
    if (!api) return { reason: 'online_required' };
    // AUTH-07: a session checked online is recorded by the server (till_sign_ins), so its proofs count as online.
    const attest = session ? { session_id: session.sessionId, signed_in_at: session.signedInAt } : {};
    try {
      return await api.post('pos/pin/verify', { user_id: staff.id, [kind]: input, ...attest });
    } catch (error) {
      if (error instanceof NetworkError) return { reason: 'online_required' };
      throw error;
    }
  }

  return {
    /**
     * `session` ({ sessionId, signedInAt }, AUTH-07) goes with an online
     * check so the server records the sign-in.
     * Resolves { ok: true, user, mustChange, checked: 'offline' | 'online' }
     * or { ok: false, reason: 'incorrect' (with attemptsLeft) | 'locked' |
     * 'not_set' | 'not_staff' | 'online_required' | 'access_lost' }.
     */
    async signIn({ userId, kind = 'pin', input, session = null }) {
      const staff = await store.staffMember(userId);
      if (!staff) return { ok: false, reason: 'not_staff' };
      const state = await localState(staff);
      if (staff.locked || state.locked) return { ok: false, reason: 'locked' };

      const success = async (checked, mustChange = staff.must_change) => {
        await recordSuccess(staff, state);
        return { ok: true, user: staff, mustChange: Boolean(mustChange), checked };
      };

      const material = staff[kind];
      if (material) {
        const deviceSecret = await credentials.secret();
        const result = await verifyOffline({ material, kind, userId: staff.id, input, deviceSecret });
        if (result.ok) {
          // The server still counts earlier wrong attempts: a correct online check clears them.
          if (Number(staff.failed_attempts ?? 0) > 0) verifyOnline(staff, kind, input, session).catch(() => {});
          return success('offline');
        }
        if (result.reason === 'incorrect') return recordFailure(staff, state);
        // not usable offline (secret rotated, unknown scheme): try online
      }

      const response = await verifyOnline(staff, kind, input, session);
      if (response.reason) return { ok: false, reason: response.reason };
      // The server's answer is fresher than the synced staff row.
      if (response.status === 200) return success('online', response.body?.data?.must_change ?? staff.must_change);
      const code = response.body?.code;
      if (response.status === 401 || response.status === 403) return { ok: false, reason: 'access_lost' };
      if (response.status === 423 || code === 'pin_locked') {
        await store.savePinAttempt({ userId: staff.id, failedAttempts: await maxAttempts(), locked: true, occurredAt: new Date(now()).toISOString(), pinVersion: state.pinVersion, reported: true });
        return { ok: false, reason: 'locked' };
      }
      if (code === 'pin_incorrect') {
        const left = Number(response.body?.attempts_left);
        const floor = Number.isFinite(left) ? (await maxAttempts()) - left : 0;
        return recordFailure(staff, state, floor);
      }
      if (code === 'pin_not_set') return { ok: false, reason: 'not_set' };
      if (code === 'not_staff_here') return { ok: false, reason: 'not_staff' };
      return { ok: false, reason: 'online_required' };
    },

    /** Whether the user is locked on this device (for the staff list). */
    async isLocked(staff) {
      const state = await localState(staff);
      return Boolean(staff.locked || state.locked);
    },
  };
}
