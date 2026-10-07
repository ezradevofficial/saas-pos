import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { api } from '@/api/client'
import { useAuth } from './AuthProvider'

/**
 * Whether `permissions` (from GET me/permissions) allow `name`, optionally at
 * one scope `{type, id}`. A tenant-wide grant covers every scope; otherwise
 * the scope must match exactly. This only decides what the UI shows
 * (RBAC-09); the API checks every action again.
 */
export function allows(permissions, name, scope) {
  const entry = permissions.find((permission) => permission.name === name)
  if (!entry) return false
  if (!scope) return true
  return entry.scopes.some((s) => s.type === 'tenant' || (s.type === scope.type && s.id === scope.id))
}

/** True when `name` is granted tenant-wide (not only at some companies, branches or locations). */
export function tenantWide(permissions, name) {
  return permissions.some((p) => p.name === name && p.scopes.some((s) => s.type === 'tenant'))
}

export function usePermissions() {
  const { token, user, enrolmentRequired } = useAuth()
  const query = useQuery({
    queryKey: ['me', 'permissions'],
    queryFn: () => api.get('me/permissions'),
    enabled: Boolean(token && user && !enrolmentRequired),
    staleTime: 60_000,
  })

  return useMemo(() => {
    const permissions = query.data?.permissions ?? []
    const modules = query.data?.modules ?? []
    return {
      permissions,
      modules,
      isLoading: query.isPending,
      can: (name, scope) => allows(permissions, name, scope),
      tenantWide: (name) => tenantWide(permissions, name),
      hasModule: (module) => modules.includes(module),
    }
  }, [query.data, query.isPending])
}
