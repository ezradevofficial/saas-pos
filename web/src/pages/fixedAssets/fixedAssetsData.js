// The Fixed assets back office (docs/modules/fixed-assets.md): permissions,
// statuses and their tones, methods, and the companies, places and
// categories its pickers and filters offer.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'

/** RBAC-09: who may open each page (the API checks again at each asset's place). */
export const ASSET_VIEW = 'fixed_assets.asset.view'
export const ASSET_MANAGE = 'fixed_assets.asset.manage'
export const CATEGORY_MANAGE = 'fixed_assets.category.manage'
export const DEPRECIATION_VIEW = 'fixed_assets.depreciation.view'
export const DEPRECIATION_POST = 'fixed_assets.depreciation.post'
export const MAINTENANCE_VIEW = 'fixed_assets.maintenance.view'
export const LABEL_PRINT = 'fixed_assets.label.print'
export const REPORT_VIEW = 'fixed_assets.report.view'
export const TAX_VIEW = 'fixed_assets.tax.view'
export const SETTINGS_EDIT = 'fixed_assets.settings.edit'

export const ASSET_STATUSES = ['draft', 'active', 'disposed', 'archived']
export const METHODS = ['straight_line', 'declining_balance', 'units_of_production', 'none']
export const DISPOSAL_KINDS = ['sale', 'scrap', 'write_off']
export const WORK_ORDER_STATUSES = ['open', 'in_progress', 'done', 'cancelled']

/** Status as a dot and a word: waiting amber, in use green, done grey, refused red. */
export const STATUS_TONES = {
  draft: 'warning',
  active: 'success',
  disposed: 'neutral',
  archived: 'neutral',
  pending: 'warning',
  approved: 'info',
  applied: 'success',
  posted: 'success',
  reversed: 'neutral',
  rejected: 'danger',
  cancelled: 'neutral',
  open: 'warning',
  in_progress: 'info',
  done: 'success',
}

export const statusLabel = (t, status) => t(`fixedAssets.statuses.${status}`, { defaultValue: status })

/** Query keys of the module, so a change refreshes its lists. */
export const faKey = ['fixed-assets']

const list = (path, key) => ({ queryKey: [...faKey, ...key], queryFn: () => api.get(path), staleTime: 60_000, retry: false })

/** The locations the user sees, with their branches (archived ones left out of pickers). */
export function useLocations() {
  const query = useQuery({ queryKey: ['locations', 'fixed-assets'], queryFn: () => api.get('locations?per_page=200'), staleTime: 60_000 })
  return (query.data?.data ?? []).filter((location) => !location.archived_at)
}

/** The categories of the companies the user works in (active only unless `all`). */
export function useCategories(all = false) {
  const query = useQuery(list(`fixed-assets/categories?per_page=200${all ? '&status=all' : ''}`, ['categories', all ? 'all' : 'active']))
  return query.data?.data ?? []
}

/** A company's settings (convention, tax book and its classes). */
export function useSettings(companyId) {
  const query = useQuery({ ...list(`fixed-assets/companies/${companyId}/settings`, ['settings', companyId]), enabled: Boolean(companyId) })
  return query.data?.data ?? null
}

export const locationLabel = (location) => (location.branch?.name ? `${location.name} · ${location.branch.name}` : location.name)
