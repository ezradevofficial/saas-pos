import { uuidv7 } from '../lib/random';
import { signActorProof } from './pinCrypto';

/**
 * AUTH-07: a till sign-in session. Made before the PIN is checked, so an
 * online check can send it for the server to record; signed once the PIN
 * is right. `signedInAt` is the server's clock as the till knows it.
 */
export function newSignInSession({ engine, now = Date.now }) {
  return { sessionId: uuidv7(now()), signedInAt: new Date(engine.serverNow()).toISOString() };
}

/** The `actor_proof` for `userId` in `session`, or null when the till has no device secret (records are then flagged). */
export async function attestSignIn({ credentials, store, session, userId }) {
  const [deviceSecret, device] = await Promise.all([credentials.secret(), store.device()]);
  if (!device?.id) return null;
  return signActorProof({ deviceSecret, deviceId: device.id, sessionId: session.sessionId, userId, signedInAt: session.signedInAt });
}
