// TEN-08 on the client: whether a master data type is shared by every
// company or kept per company, which decides whether a new record names
// its company. The API checks again under its sharing lock.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'

export const SHARED = 'shared'
export const PER_COMPANY = 'per_company'

// Who may read the modes (MasterDataSettingsRequest::READERS).
const READERS = [
  'core.master_data_settings.edit',
  'core.party.view',
  'core.party.create',
  'core.tax.view',
  'core.tax.edit',
  'core.item.create',
  'core.item_category.create',
]

// The data type each party role follows (PartyRoles::DATA_TYPES); a contact follows customers.
export const ROLE_DATA_TYPES = { customer: 'customers', supplier: 'suppliers', contact: 'customers', employee_link: 'employees' }

/** `{ modes: { items: 'shared', ... }, ready }`; types not listed read as shared. */
export function useSharingModes() {
  const { can, isLoading } = usePermissions()
  const allowed = !isLoading && can(READERS)
  const query = useQuery({ queryKey: ['master-data-settings'], queryFn: () => api.get('master-data/settings'), enabled: allowed, staleTime: 60_000 })
  const modes = Object.fromEntries((query.data?.data ?? []).map((setting) => [setting.data_type, setting.mode]))
  return { modes, ready: !isLoading && (!allowed || !query.isPending), error: query.error }
}

/** Whether a data type is kept per company. */
export const perCompany = (modes, dataType) => modes[dataType] === PER_COMPANY

/** A party with these roles is kept per company when any role's type is (PartyRoles::perCompany). */
export function rolesPerCompany(roles, modes) {
  return roles.some((role) => perCompany(modes, ROLE_DATA_TYPES[role] ?? 'customers'))
}
