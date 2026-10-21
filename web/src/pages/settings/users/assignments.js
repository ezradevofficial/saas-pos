// RBAC-04: what a role assignment (role + scope) is offered from and how it
// reads. Only grants the user may make are offered (useGrantOptions, from the
// API's own answer); the API checks every one again.
import { useQueries, useQuery } from '@tanstack/react-query'
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

/** The scope chain of a place (its own scope and the parents the client knows), for canWithin. */
export function chainOf(type, record, scopes) {
  if (type === 'company') return [{ type: 'company', id: record.id }]
  if (type === 'branch') return [{ type: 'company', id: record.company_id }, { type: 'branch', id: record.id }]
  const branch = scopes.branch.find((b) => b.id === record.branch_id)
  return [{ type: 'company', id: branch?.company_id }, { type: 'branch', id: record.branch_id }, { type: 'location', id: record.id }]
}

/** The place a row names, as `?grantable_at=` reads it: `tenant`, `<type>:<id>`, or `any` until a place is chosen. */
export function grantableKey(row) {
  if (!row) return 'any'
  if (row.scope_type === 'tenant') return 'tenant'
  return row.scope_id ? `${row.scope_type}:${row.scope_id}` : 'any'
}

/**
 * What this user may grant (RBAC-04, no privilege escalation), so pickers
 * never offer a refusal:
 * - places where they hold `core.role.assign` (and `core.user.invite` when
 *   inviting) at the place or above; the whole organisation only tenant-wide;
 * - the roles the API says they may grant at the row's place (`GET
 *   roles?grantable_at=`, by the grant's own rules: a permission of an
 *   inactive module their roles hold counts; Owner roles only for an
 *   Owner), or anywhere until a place is chosen.
 * `rows` are the rows on screen, so their places' roles are fetched.
 * The API checks every grant again.
 */
export function useGrantOptions({ invite = false, rows = [] } = {}) {
  const { canWithin, tenantWide, isLoading } = usePermissions()
  const scopes = useScopes()

  const needed = invite ? ['core.role.assign', 'core.user.invite'] : ['core.role.assign']
  const mayGrantAt = (chain) => needed.every((name) => canWithin(name, chain))
  const places = Object.fromEntries(
    ['company', 'branch', 'location'].map((type) => [type, scopes[type].filter((record) => mayGrantAt(chainOf(type, record, scopes)))]),
  )
  const tenantOk = needed.every((name) => tenantWide(name))
  const scopeTypes = SCOPE_TYPES.filter((type) => (type === 'tenant' ? tenantOk : places[type].length > 0))

  // `any` always (a row whose place is cleared falls back to it), and the whole
  // organisation when offered (a row may move to it once the places load).
  const keys = [...new Set(['any', ...(tenantOk ? ['tenant'] : []), ...rows.filter(Boolean).map(grantableKey)])]
  const purpose = invite ? 'invite' : 'assign'
  const grantable = useQueries({
    queries: keys.map((key) => ({
      queryKey: ['roles', 'grantable', purpose, key],
      queryFn: () => api.get(`roles?per_page=200&grantable_at=${encodeURIComponent(key)}&grantable_for=${purpose}`),
    })),
  })
  const byKey = Object.fromEntries(keys.map((key, index) => [key, grantable[index]]))

  // While a place's answer loads, the roles grantable somewhere stand in, so a chosen role is not cleared meanwhile.
  const rolesFor = (row) => (byKey[grantableKey(row)]?.data ?? byKey.any?.data)?.data ?? []
  const failed = grantable.find((query) => query.isError)

  return {
    ready: !isLoading && !scopes.isPending && !byKey.any.isPending,
    rolesError: failed ? failed.error : null,
    scopeTypes,
    places,
    rolesFor,
  }
}

/**
 * The row as shown: once the options have loaded, a kind of place the user
 * cannot offer becomes the narrowest one they can (the whole organisation
 * only when nothing narrower exists), and a place or role they cannot grant
 * is cleared. Until then the row is left alone, so a still-loading list
 * never widens it.
 */
export function offeredRow(row, options) {
  if (!row || !options.ready || !options.scopeTypes.length) return row
  let next = row
  if (!options.scopeTypes.includes(next.scope_type)) {
    const narrowest = options.scopeTypes.filter((type) => type !== 'tenant').at(-1) ?? options.scopeTypes.at(-1)
    next = { ...next, scope_type: narrowest, scope_id: '' }
  }
  if (next.scope_type !== 'tenant' && next.scope_id && !options.places[next.scope_type].some((place) => place.id === next.scope_id)) {
    next = { ...next, scope_id: '' }
  }
  if (next.role_id && !options.rolesFor(next).some((role) => role.id === next.role_id)) next = { ...next, role_id: '' }
  return next
}

/** API body for one assignment row. */
export function assignmentBody(row) {
  return { role_id: row.role_id, scope_type: row.scope_type, scope_id: row.scope_type === 'tenant' ? null : row.scope_id }
}
