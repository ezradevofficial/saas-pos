import { uuidv7 } from '../lib/random';
import { compare } from '../pos/exact';
import { FuelError, parseLitres, showLitres } from './meters';
import { can, FUEL } from './permissions';

/**
 * FUEL-17, FUEL-22: a meter reset request made on the till (POST
 * fuel/device/meter-resets). When a meter is replaced or rolls over, a
 * supervisor (`fuel.meter.request_reset`) records the reading before, the
 * reading after and a reason; a different person with `fuel.meter.approve_reset`
 * approves it in the back office. The till can only ask: it never changes a
 * reading itself. Works offline; the request waits in the outbox.
 */
export function resetPayload({ id, nozzleId, shiftId, before, after, reason, requestedById, requestedAt, actorProof }) {
  return Object.fromEntries(
    Object.entries({
      id,
      nozzle_id: nozzleId,
      shift_id: shiftId || undefined,
      reading_before: showLitres(before),
      reading_after: showLitres(after),
      reason,
      requested_by_id: requestedById,
      requested_at: requestedAt,
      actor_proof: actorProof ?? undefined,
    }).filter(([, value]) => value !== undefined),
  );
}

export function createFuelResets({ engine, now = () => Date.now(), serverNow = () => engine.serverNow() }) {
  return {
    /**
     * Throws FuelError: not_permitted, nozzle_required, reading_invalid, reset_not_lower (the reading after is
     * above the reading before), reason_required. Resolves the payload queued.
     */
    async request({ user, actorProof, nozzle, shift = null, before, after, reason }) {
      if (!can(user, FUEL.requestReset)) throw new FuelError('not_permitted');
      if (!nozzle) throw new FuelError('nozzle_required');
      const from = parseLitres(before);
      const to = parseLitres(after);
      if (from === null || to === null) throw new FuelError('reading_invalid');
      if (compare(to, from) > 0) throw new FuelError('reset_not_lower');
      const why = String(reason ?? '').trim();
      if (why.length < 2) throw new FuelError('reason_required');
      // The shift is named only when the nozzle is on it (the server refuses a nozzle that is not).
      const onShift = shift && shift.status === 'open' && shift.nozzles.some((row) => row.nozzle_id === nozzle.id) ? shift.id : null;
      const payload = resetPayload({ id: uuidv7(now()), nozzleId: nozzle.id, shiftId: onShift, before: from, after: to, reason: why.slice(0, 500), requestedById: user.id, requestedAt: new Date(serverNow()).toISOString(), actorProof });
      // Behind the shift's own record, so the open reaches the server first.
      await engine.enqueue('fuel.meter_resets', payload.id, payload, { group: onShift });
      return payload;
    },
  };
}
