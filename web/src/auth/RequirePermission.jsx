import { NoAccess } from '@/pages/NotFound'
import { usePermissions } from './usePermissions'

/**
 * Shows the page only when the user has `permission` (or any of a list)
 * somewhere, or, with `tenantWide`, at tenant scope: for tenant-wide
 * resources such as roles and the tenant's settings (RBAC-09; the API
 * checks again).
 */
export function RequirePermission({ permission, tenantWide = false, children }) {
  const permissions = usePermissions()
  if (permissions.isLoading) return null
  const names = Array.isArray(permission) ? permission : [permission]
  const allowed = tenantWide ? names.some((name) => permissions.tenantWide(name)) : permissions.can(permission)
  return allowed ? children : <NoAccess />
}
