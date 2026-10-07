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

/** Companies the user can view (core.company.view), visible ones only. */
export function useCompanies() {
  const { can, isLoading } = usePermissions()
  const allowed = !isLoading && can('core.company.view')
  const query = useQuery({
    queryKey: ['companies', 'switcher'],
    queryFn: () => api.get('companies?per_page=200'),
    enabled: allowed,
  })
  return { allowed, ready: !isLoading && (!allowed || query.isSuccess), companies: query.data?.data ?? [] }
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
  const value = match ? match.id : canSeeAll ? ALL_COMPANIES : (companies[0]?.id ?? ALL_COMPANIES)
  const resolved = value === ALL_COMPANIES ? null : value

  // X-Company-Id always matches what the switcher shows: a stored company
  // the user no longer sees is replaced by the resolved one.
  useEffect(() => {
    if (ready && stored !== resolved) chooseCompany(resolved ?? ALL_COMPANIES)
  }, [ready, stored, resolved])

  const company = match ?? (value === ALL_COMPANIES ? (companies.length === 1 ? companies[0] : null) : companies[0])
  return { value, company: company ?? null, companies, canSeeAll }
}
