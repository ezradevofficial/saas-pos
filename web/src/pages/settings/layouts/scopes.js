import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'

/** `tenant:`, `role:<id>` or `user:<id>` as a config scope `{ type, id }`. */
export function parseScope(value) {
  const [type, id] = String(value ?? 'tenant:').split(':')
  return { type: type || 'tenant', id: id || null }
}

/**
 * Who a layout can be designed for (LAY-01, LAY-02): the organisation, one
 * of its roles (for holders of the layout permissions tenant-wide) and,
 * with `personal`, the user's own copy (anyone). Also what the user may do
 * to the organisation's and roles' layouts; the API checks again (RBAC-09).
 */
export function useLayoutScopes({ personal = false } = {}) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { tenantWide, isLoading } = usePermissions()
  const canView = ['core.layout.view', 'core.layout.edit', 'core.layout.publish'].some((name) => tenantWide(name))
  const roles = useQuery({ queryKey: ['roles', 'options'], queryFn: () => api.get('roles?per_page=200&sort=name'), enabled: canView, staleTime: 60_000 })
  const mine = `user:${user?.id ?? ''}`
  const options = [
    ...(personal ? [{ value: mine, label: t('layouts.scopes.me') }] : []),
    ...(canView ? [{ value: 'tenant:', label: t('layouts.scopes.everyone') }] : []),
    ...(canView ? (roles.data?.data ?? []).map((role) => ({ value: `role:${role.id}`, label: t('layouts.scopes.role', { name: role.name }) })) : []),
  ]
  return {
    options,
    mine,
    canView,
    isLoading,
    /** Whether the user may edit, and publish, the layout at `scope`. */
    can: (scope) => {
      const own = scope.type === 'user' && scope.id === user?.id
      return { edit: own || tenantWide('core.layout.edit'), publish: own || tenantWide('core.layout.publish') }
    },
  }
}
