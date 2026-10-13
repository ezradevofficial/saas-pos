import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router'
import { PlatformAuthContext, usePlatformAuth } from './usePlatformAuth'
import { getPlatformToken, onPlatformAuthEvent, platformApi, setPlatformToken } from './platformClient'

/**
 * The platform staff session (ADR 013): a token from sign-in or setup and
 * the staff member it names. `fullAccess` is false while the token may only
 * enrol two-factor.
 */
export function PlatformAuthProvider({ children }) {
  const queryClient = useQueryClient()
  const [token, setToken] = useState(getPlatformToken)
  const me = useQuery({
    queryKey: ['platform', 'me', token],
    queryFn: () => platformApi.get('platform/me'),
    enabled: Boolean(token),
    retry: false,
  })

  useEffect(() => onPlatformAuthEvent(() => setToken(null)), [])

  const signIn = useCallback(
    (nextToken, user) => {
      setPlatformToken(nextToken)
      queryClient.setQueryData(['platform', 'me', nextToken], { data: user })
      setToken(nextToken)
    },
    [queryClient],
  )

  const signOut = useCallback(async () => {
    try {
      await platformApi.post('platform/auth/sign-out')
    } catch {
      // The session ends here either way.
    }
    setPlatformToken(null)
    queryClient.removeQueries({ queryKey: ['platform'] })
    setToken(null)
  }, [queryClient])

  const refresh = useCallback(() => queryClient.invalidateQueries({ queryKey: ['platform', 'me'] }), [queryClient])

  const user = me.data?.data ?? null
  const value = useMemo(
    () => ({
      token,
      user,
      loading: Boolean(token) && me.isPending,
      fullAccess: Boolean(user?.two_factor_enabled && user?.status === 'active'),
      can: (ability) => Boolean(user?.abilities?.includes(ability)),
      signIn,
      signOut,
      refresh,
    }),
    [token, user, me.isPending, signIn, signOut, refresh],
  )

  return <PlatformAuthContext.Provider value={value}>{children}</PlatformAuthContext.Provider>
}

/** The console needs a staff session with two-factor done; else sign-in or enrolment. */
export function RequirePlatformAccess({ children }) {
  const { token, user, loading, fullAccess } = usePlatformAuth()
  const location = useLocation()
  if (!token) return <Navigate to="/platform/sign-in" replace state={{ from: location.pathname }} />
  if (loading || !user) return null
  if (!fullAccess) return <Navigate to="/platform/enrol" replace />
  return children ?? <Outlet />
}
