import { useQuery } from '@tanstack/react-query'
import { useEffect, useSyncExternalStore } from 'react'
import { api, getCompanyId, setCompanyId } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'

export const ALL_COMPANIES = 'all'

// The chosen company is shared by every switcher on screen (sidebar and the
// phone sheet) and sent as X-Company-Id by the API client.
const listeners = new Set()
const subscribe = (listener) => {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function chooseCompany(id) {
  setCompanyId(id === ALL_COMPANIES ? null : id)
  for (const listener of listeners) listener()
}

/** True when every grant of core.company.view is tenant-wide or for whole companies. */
function companyViewer(permissions) {
  const scopes = permissions.find((permission) => permission.name === 'core.company.view')?.scopes ?? []
  return scopes.length > 0 && scopes.every((scope) => scope.type === 'tenant' || scope.type === 'company')
}

/**
 * Companies for the switcher: those the user can view (core.company.view),
 * else (a branch Accountant or Branch Manager) those the user holds any
 * role in (`me/companies`, RBAC-04; stage 7B walkthrough), so a
 * company-scoped screen always has one to show.
 */
export function useCompanies() {
  const { permissions, isLoading } = usePermissions()
  // GET companies lists the companies the user views at tenant or company
  // scope only; a Sales Rep or Branch Manager holds core.company.view at a
  // branch and would get none (stage 7D walkthrough), so they use me/companies.
  const viewer = !isLoading && companyViewer(permissions)
  const query = useQuery({
    queryKey: ['companies', 'switcher', viewer ? 'all' : 'mine'],
    queryFn: () => api.get(viewer ? 'companies?per_page=200' : 'me/companies'),
    enabled: !isLoading,
  })
  return { allowed: !isLoading, ready: !isLoading && query.isSuccess, companies: query.data?.data ?? [] }
}

/**
 * The company in use: the stored one if the user still sees it, otherwise
 * "all companies" for a tenant-wide user and the first company for others.
 * Returns `{ value, company }`; company is null for "all companies", unless
 * there is only one company.
 */
export function useCompanySelection() {
  const stored = useSyncExternalStore(subscribe, getCompanyId, getCompanyId)
  const { tenantWide } = usePermissions()
  const { companies, ready } = useCompanies()
  const canSeeAll = tenantWide('core.company.view')

  const match = companies.find((company) => company.id === stored)
  // Only one company: it is chosen, whoever the user is (stage 7B walkthrough).
  const value = match ? match.id : companies.length === 1 ? companies[0].id : canSeeAll ? ALL_COMPANIES : (companies[0]?.id ?? ALL_COMPANIES)
  const resolved = value === ALL_COMPANIES ? null : value

  // X-Company-Id always matches what the switcher shows: a stored company
  // the user no longer sees is replaced by the resolved one.
  useEffect(() => {
    if (ready && stored !== resolved) chooseCompany(resolved ?? ALL_COMPANIES)
  }, [ready, stored, resolved])

  const company = match ?? (value === ALL_COMPANIES ? (companies.length === 1 ? companies[0] : null) : companies[0])
  return { value, company: company ?? null, companies, canSeeAll }
}
