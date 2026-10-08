// MD-02 on the client: units, categories and tax categories for the
// catalogue screens, and the item types. Lists are small (at most 200).
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { CATEGORY_VIEW, UOM_VIEW } from '@/layouts/navigation'

export const ITEM_TYPES = ['stock', 'service', 'non_stock', 'kit']
export const UOM_KINDS = ['count', 'weight', 'volume', 'length', 'time']
export const MAX_IMAGES = 8
export const MAX_IMAGE_BYTES = 2 * 1024 * 1024
export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp']

const TAX_VIEW = ['core.tax.view', 'core.tax.edit']

/** The tenant's units, archived ones included (`status=all`). */
export function useUoms() {
  const { can } = usePermissions()
  const query = useQuery({ queryKey: ['uoms'], queryFn: () => api.get('uoms?status=all&per_page=200'), enabled: can(UOM_VIEW) })
  const all = query.data?.data ?? []
  return { ...query, all, active: all.filter((uom) => !uom.archived_at) }
}

/** Item categories the user can view, archived ones included. */
export function useItemCategories() {
  const { can } = usePermissions()
  const query = useQuery({ queryKey: ['item-categories'], queryFn: () => api.get('item-categories?status=all&per_page=200'), enabled: can(CATEGORY_VIEW) })
  return { ...query, all: query.data?.data ?? [] }
}

/** Tax categories, for a user who may read taxes; others keep the item's as it is. */
export function useTaxCategories() {
  const { can } = usePermissions()
  const allowed = can(TAX_VIEW)
  const query = useQuery({ queryKey: ['tax-categories'], queryFn: () => api.get('tax-categories?per_page=200'), enabled: allowed })
  return { ...query, allowed, all: query.data?.data ?? [] }
}

/** Rows as a tree by `parent_id`, sorted by name; a row whose parent is not listed starts a branch. */
export function categoryTree(rows) {
  const ids = new Set(rows.map((row) => row.id))
  const children = new Map()
  for (const row of rows) {
    const parent = row.parent_id && ids.has(row.parent_id) ? row.parent_id : null
    if (!children.has(parent)) children.set(parent, [])
    children.get(parent).push(row)
  }
  const walk = (parent, depth) =>
    (children.get(parent) ?? [])
      .sort((a, b) => String(a.name ?? '').localeCompare(String(b.name ?? '')))
      .flatMap((row) => [{ row, depth }, ...walk(row.id, depth + 1)])
  return walk(null, 0)
}

/** Select options for categories in one scope (a company's, or shared: null), indented by depth. */
export function categoryOptions(categories, companyId) {
  const inScope = categories.filter((category) => !category.archived_at && (category.company_id ?? null) === (companyId || null))
  return categoryTree(inScope).map(({ row, depth }) => ({ value: row.id, label: `${'— '.repeat(depth)}${row.name}` }))
}

/** "PCS · Pieces" */
export const uomLabel = (uom) => (uom ? `${uom.code} · ${uom.name}` : '')

/** The record's name in the UI language, the other language when it has only one. */
export const localName = (record, locale) =>
  (String(locale).startsWith('fr') ? (record?.name_fr ?? record?.name_en) : (record?.name_en ?? record?.name_fr)) ?? record?.name ?? ''
