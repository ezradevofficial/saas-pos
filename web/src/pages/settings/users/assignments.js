// RBAC-04: what a role assignment (role + scope) is offered from and how it
// reads. The scope lists are the active companies, branches and locations
// the user sees; the API checks again that the user may grant there.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'

/** StatusBadge tone per user status. */
export const USER_TONES = { active: 'success', pending: 'warning', deactivated: 'neutral' }

export const SCOPE_TYPES = ['tenant', 'company', 'branch', 'location']

/**
 * A blank row on the narrowest kind of place offered (least access by
 * default); never the whole organisation unless someone chooses it.
 */
export const emptyAssignment = (scopeTypes = []) => ({
  role_id: '',
  scope_type: scopeTypes.filter((type) => type !== 'tenant').at(-1) ?? 'location',
  scope_id: '',
})

/**
 * The row as shown: once the scope lists have loaded, a kind of place the
 * user cannot offer becomes the narrowest one they can (the whole
 * organisation only when nothing narrower exists). Until then the row is
 * left alone, so a still-loading list never widens it.
 */
export function offeredRow(row, scopeTypes, ready) {
  if (!row || !ready || !scopeTypes.length || scopeTypes.includes(row.scope_type)) return row
  const narrowest = scopeTypes.filter((type) => type !== 'tenant').at(-1) ?? scopeTypes.at(-1)
  return { ...row, scope_type: narrowest, scope_id: '' }
}

/** Active roles, for role pickers (system roles first, as the API sorts them). */
export function useRoles() {
  const query = useQuery({ queryKey: ['roles', 'options'], queryFn: () => api.get('roles?per_page=200') })
  return { ...query, roles: query.data?.data ?? [] }
}

/** Active companies, branches and locations the user sees, by scope type. */
export function useScopes() {
  const companies = useQuery({ queryKey: ['companies', 'scopes'], queryFn: () => api.get('companies?per_page=200') })
  const branches = useQuery({ queryKey: ['branches', 'scopes'], queryFn: () => api.get('branches?per_page=200') })
  const locations = useQuery({ queryKey: ['locations', 'scopes'], queryFn: () => api.get('locations?per_page=200') })
  return {
    isPending: companies.isPending || branches.isPending || locations.isPending,
    company: companies.data?.data ?? [],
    branch: branches.data?.data ?? [],
    location: locations.data?.data ?? [],
  }
}

/** The label of a scope record: a branch names its company, a location its branch. */
export function scopeLabel(type, record) {
  if (!record) return ''
  if (type === 'branch' && record.company?.name) return `${record.name} · ${record.company.name}`
  if (type === 'location' && record.branch?.name) return `${record.name} · ${record.branch.name}`
  return record.name
}

/** The scope types this user can offer: the whole organisation only with a tenant-wide grant. */
export function useScopeTypes(scopes) {
  const { tenantWide } = usePermissions()
  return SCOPE_TYPES.filter((type) => (type === 'tenant' ? tenantWide('core.role.assign') : scopes[type].length > 0))
}

/** API body for one assignment row. */
export function assignmentBody(row) {
  return { role_id: row.role_id, scope_type: row.scope_type, scope_id: row.scope_type === 'tenant' ? null : row.scope_id }
}
