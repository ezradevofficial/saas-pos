import { createContext, useCallback, useContext, useMemo, useState } from 'react';

/**
 * AUTH-07: who is working the till. Switching user signs the current
 * person out but keeps the shift (it belongs to the till's drawer, not to
 * the person), so the next cashier signs in and carries on.
 *
 * `pinChange` holds the PIN just typed while the server asks for a new
 * one (`must_change`), only until the change is done or skipped offline.
 */
const SessionContext = createContext(null);

export function SessionProvider({ children, initialUser = null }) {
  const [user, setUser] = useState(initialUser);
  const [shift, setShift] = useState(null);
  const [pinChange, setPinChange] = useState(null);

  const signIn = useCallback((result, typedPin) => {
    setUser(result.user);
    setPinChange(result.mustChange ? { userId: result.user.id, currentPin: typedPin } : null);
  }, []);

  const switchUser = useCallback(() => {
    setUser(null);
    setPinChange(null);
  }, []);

  const value = useMemo(
    () => ({ user, shift, setShift, signIn, switchUser, pinChange, finishPinChange: () => setPinChange(null) }),
    [user, shift, signIn, switchUser, pinChange],
  );
  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession() {
  const session = useContext(SessionContext);
  if (!session) throw new Error('useSession needs a SessionProvider');
  return session;
}
