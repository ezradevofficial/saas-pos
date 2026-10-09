import { createContext, useCallback, useContext, useMemo, useState } from 'react';

/**
 * AUTH-07: who is working the till. Switching user signs the current
 * person out but keeps the shift and the current sale (they belong to the
 * till, not to the person, and live above this provider's sign-in gate),
 * so the next cashier signs in and carries on.
 *
 * `actor` is the sign-in attestation of the person signed in: { sessionId,
 * signedInAt, proof } where `proof` is the `actor_proof` every record they
 * make carries (null when the till has no device secret).
 *
 * `pinChange` holds the PIN just typed while the server asks for a new
 * one (`must_change`), only until the change is done or skipped offline.
 */
const SessionContext = createContext(null);

export function SessionProvider({ children, initialUser = null, initialActor = null }) {
  const [user, setUser] = useState(initialUser);
  const [actor, setActor] = useState(initialActor);
  const [shift, setShift] = useState(null);
  const [pinChange, setPinChange] = useState(null);

  const signIn = useCallback((result, typedPin, attestation = null) => {
    setUser(result.user);
    setActor(attestation);
    setPinChange(result.mustChange ? { userId: result.user.id, currentPin: typedPin } : null);
  }, []);

  const switchUser = useCallback(() => {
    setUser(null);
    setActor(null);
    setPinChange(null);
  }, []);

  const value = useMemo(
    () => ({ user, actor, actorProof: actor?.proof ?? null, shift, setShift, signIn, switchUser, pinChange, finishPinChange: () => setPinChange(null) }),
    [user, actor, shift, signIn, switchUser, pinChange],
  );
  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession() {
  const session = useContext(SessionContext);
  if (!session) throw new Error('useSession needs a SessionProvider');
  return session;
}
