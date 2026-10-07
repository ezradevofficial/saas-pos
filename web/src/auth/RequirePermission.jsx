import { NoAccess } from '@/pages/NotFound'
import { usePermissions } from './usePermissions'

/** Shows the page only when the user has `permission` somewhere (RBAC-09; the API checks again). */
export function RequirePermission({ permission, children }) {
  const { can, isLoading } = usePermissions()
  if (isLoading) return null
  return can(permission) ? children : <NoAccess />
}
