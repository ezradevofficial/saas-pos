import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api, clearToken, getToken, onAuthEvent, setCompanyId, setToken } from '@/api/client'
import { setLocale } from '@/i18n'

const AuthContext = createContext(null)

const browserLanguage = () => (typeof navigator === 'undefined' ? undefined : navigator.language)

/**
 * The signed-in user (AUTH-01): the token lives in localStorage (see
 * api/client), the profile comes from GET me. The UI language follows the
 * user's locale, then the browser, then English (L10N-01).
 *
 * A token issued to a user who must still enrol a second factor (AUTH-03)
 * sets `enrolmentRequired`; RequireAuth then sends them to the enrolment page.
 */
export function AuthProvider({ children }) {
  const queryClient = useQueryClient()
  const [token, setTokenState] = useState(() => getToken())
  const [enrolmentRequired, setEnrolmentRequired] = useState(false)
  // True after the user chose to sign out: the sign-in page then forgets
  // where they were (no ?next=), unlike an expired session.
  const [signedOut, setSignedOut] = useState(false)

  useEffect(
    () =>
      onAuthEvent((event) => {
        if (event === 'unauthenticated') {
          setCompanyId(null)
          setTokenState(null)
          setEnrolmentRequired(false)
          queryClient.removeQueries()
        } else if (event === 'two_factor_enrollment_required') {
          setEnrolmentRequired(true)
        }
      }),
    [queryClient],
  )

  const me = useQuery({
    queryKey: ['me'],
    queryFn: async () => (await api.get('me')).data,
    enabled: Boolean(token),
  })

  const user = token ? (me.data ?? null) : null

  // L10N-01: the user's language, then the tenant's default, then the browser, then English.
  const tenantLocale = user?.tenant?.default_locale
  useEffect(() => {
    if (user) setLocale(user.locale, tenantLocale, browserLanguage())
  }, [user, user?.locale, tenantLocale])

  /** Completes a sign-in from a `{token, user, two_factor_enrollment_required}` response. */
  const signIn = useCallback(
    (response) => {
      queryClient.removeQueries()
      setToken(response.token)
      // A new sign-in starts from the user's default company, not the last user's.
      setCompanyId(null)
      queryClient.setQueryData(['me'], response.user)
      // The sign-in answer has the user only; GET me adds the tenant.
      queryClient.invalidateQueries({ queryKey: ['me'], exact: true })
      setEnrolmentRequired(Boolean(response.two_factor_enrollment_required))
      setSignedOut(false)
      setTokenState(response.token)
    },
    [queryClient],
  )

  const signOut = useCallback(async () => {
    try {
      await api.post('auth/sign-out')
    } catch {
      // The token is dropped locally whatever the server says.
    }
    clearToken()
    setCompanyId(null)
    setSignedOut(true)
    setTokenState(null)
    setEnrolmentRequired(false)
    queryClient.removeQueries()
    setLocale(browserLanguage())
  }, [queryClient])

  const enrolmentDone = useCallback(() => {
    setEnrolmentRequired(false)
    queryClient.invalidateQueries()
  }, [queryClient])

  let status = 'anonymous'
  if (token) status = user ? 'authenticated' : me.isError ? 'error' : 'loading'

  const retry = me.refetch
  const value = useMemo(
    () => ({ token, user, status, enrolmentRequired, signedOut, signIn, signOut, enrolmentDone, retry }),
    [token, user, status, enrolmentRequired, signedOut, signIn, signOut, enrolmentDone, retry],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside AuthProvider')
  return context
}
