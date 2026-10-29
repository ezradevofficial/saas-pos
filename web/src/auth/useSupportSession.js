import { useAuth } from './useAuth'

/**
 * RBAC-13: whether this sign-in is platform staff in a support session, and
 * whether it is read-only. The API refuses a read-only session's writes; the
 * UI hides what would write, also actions no permission guards (a personal
 * dashboard, onboarding steps, preferences). A session never approves or
 * makes credentials, so delegation and SSO links are hidden in any session.
 */
export function useSupportSession() {
  const { user } = useAuth()
  const session = user?.support_session ?? null
  return { active: Boolean(session), readOnly: session?.mode === 'read' }
}
