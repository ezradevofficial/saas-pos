import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { fromShell } from '@/api/shellBootstrap'
import { useAuth } from '@/auth/useAuth'
import { usePermissions } from '@/auth/usePermissions'
import { useCompanies } from './companySelection'
import { useCustomFormTypes } from '@/lib/customForms'
import { applyNavigationLayout, homeOf, NAV_GROUPS, visibleGroups, withFormsGroup } from './navigation'

export const NAVIGATION_KEY = ['config', 'navigation', 'resolved']

/**
 * LAY-02: the navigation layout that applies to the signed-in user (their
 * role's, else the organisation's, else none). An unreadable layout falls
 * back to the catalogue's own order: the menu never breaks (LAY-07).
 */
export function useNavigationLayout() {
  const { token, user, enrolmentRequired } = useAuth()
  const query = useQuery({
    queryKey: NAVIGATION_KEY,
    queryFn: () => fromShell('navigation', () => api.get('config/navigation/resolved')),
    enabled: Boolean(token && user && !enrolmentRequired),
    staleTime: 60_000,
    retry: false,
  })
  return { layout: query.data?.data?.payload ?? null, isLoading: query.isPending && Boolean(token && user) }
}

/**
 * The sidebar the user sees: the catalogue laid out by their navigation
 * layout, then filtered by permissions and modules (hiding never grants,
 * RBAC-09); and their home page when the layout names one they may open.
 */
export function useNavigation() {
  const permissions = usePermissions()
  const { companies, ready } = useCompanies()
  const { layout, isLoading } = useNavigationLayout()
  // CF-04: the user's custom forms, as a group of their own.
  const { types } = useCustomFormTypes()
  // HR-31, PAY-24: self-service shows while HR or Payroll is active and the user is linked to an employee (GET hr/me answers with a record, 204 when not linked; Payroll brings HR's employee core).
  const selfService = permissions.hasModule('hr') || permissions.hasModule('payroll')
  const hrSelf = useQuery({ queryKey: ['hr', 'me'], queryFn: () => fromShell('hr_me', () => api.get('hr/me')), enabled: !permissions.isLoading && selfService, retry: false, staleTime: 60_000 })
  const isEmployee = selfService && hrSelf.isSuccess && Boolean(hrSelf.data?.data)
  const groups = permissions.isLoading ? [] : withFormsGroup(visibleGroups(applyNavigationLayout(NAV_GROUPS, layout), { ...permissions, hasCompany: ready && companies.length > 0, isEmployee }), types)
  return { groups, home: homeOf(layout, groups), isLoading: isLoading || permissions.isLoading }
}
