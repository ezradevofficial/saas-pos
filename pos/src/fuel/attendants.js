import { attestSignIn, newSignInSession } from '../auth/attestation';

/**
 * FUEL-09, AUTH-07: an attendant assigned to a nozzle confirms with their own PIN on the till, the way a
 * sign-in does: the PIN gate checks it (offline against the stored material, online by the server, which then
 * records the session) and the device signs the attestation (`actor_proof`) that goes up with the shift as
 * `attendant_proof`. Works offline. Resolves { ok: true, proof } (proof is null when the till has no device
 * secret: the server then flags the attendant as unverified) or { ok: false, reason, attemptsLeft? } with the
 * PIN gate's reasons (incorrect, locked, not_set, online_required, ...).
 */
export async function confirmAttendant({ pinGate, engine, credentials, store, userId, pin }) {
  const session = newSignInSession({ engine });
  const result = await pinGate.signIn({ userId, kind: 'pin', input: pin, session });
  if (!result.ok) return { ok: false, reason: result.reason, attemptsLeft: result.attemptsLeft };
  return { ok: true, proof: await attestSignIn({ credentials, store, session, userId }) };
}

/**
 * The proofs of a shift's attendants: the person signed in is proved by their own sign-in, everyone else by
 * the PIN confirmation made here. `confirmed` = { [userId]: proof }. Returns { [userId]: proof } for the
 * attendants of `attendantIds` that have one.
 */
export function attendantProofs({ attendantIds, userId, actorProof, confirmed = {} }) {
  const proofs = {};
  for (const id of new Set(attendantIds)) {
    const proof = id === userId ? actorProof : confirmed[id];
    if (proof) proofs[id] = proof;
  }
  return proofs;
}

/** The attendants of the chosen rows that still need a PIN confirmation: [{ id, name }]. */
export function unconfirmed({ rows, userId, actorProof, confirmed = {} }) {
  const seen = new Map();
  for (const row of rows) if (row.attendantId && !seen.has(row.attendantId)) seen.set(row.attendantId, row.attendantName ?? '');
  const proved = attendantProofs({ attendantIds: [...seen.keys()], userId, actorProof, confirmed });
  // The person signed in is already proved by their own sign-in (a till without a device secret has none to send).
  return [...seen.entries()].filter(([id]) => id !== userId && !(id in proved) && !(id in confirmed)).map(([id, name]) => ({ id, name }));
}
