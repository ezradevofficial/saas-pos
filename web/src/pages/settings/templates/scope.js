// Where a document template applies (TPL-01, LAY-06): the whole tenant, a company or a branch.

export const TENANT_SCOPE = { type: 'tenant', id: null }

/** `tenant`, `company:<id>` or `branch:<id>` (the URL's ?scope=) as { type, id }. */
export function parseScope(value) {
  const [type, id] = String(value ?? '').split(':')
  return (type === 'company' || type === 'branch') && id ? { type, id } : TENANT_SCOPE
}

export const scopeValue = (scope) => (scope?.type && scope.type !== 'tenant' && scope.id ? `${scope.type}:${scope.id}` : 'tenant')

/** The query string of `templates/types` and `templates/preview` for a scope. */
export const scopeQuery = (scope) => (scope.type === 'tenant' ? 'scope_type=tenant' : `scope_type=${scope.type}&scope_id=${encodeURIComponent(scope.id)}`)

/** The scope and the ones above it, for permission checks in the UI (the API checks again, RBAC-04). */
export function scopeChain(scope, branches = []) {
  if (scope.type === 'company') return [{ type: 'company', id: scope.id }]
  if (scope.type === 'branch') {
    const branch = branches.find((entry) => entry.id === scope.id)
    return [
      { type: 'company', id: branch?.company_id ?? branch?.company?.id },
      { type: 'branch', id: scope.id },
    ]
  }
  return []
}

/** Whether the user holds `name` where the template lives: tenant-wide for the tenant's own. */
export function allowedAt(permissions, name, scope, branches) {
  if (scope.type === 'tenant') return permissions.tenantWide(name)
  return permissions.canWithin(name, scopeChain(scope, branches))
}
