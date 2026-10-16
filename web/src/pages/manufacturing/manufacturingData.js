// The Manufacturing back office (docs/modules/manufacturing.md): permissions,
// statuses and their tones, the reasons and kinds the API takes, the module's
// status (which integrations are on) and the query keys of its lists.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { useUnitOptions } from '@/pages/inventory/inventoryHelpers'

/** RBAC-09: who may open each page (any one is enough; the API checks again at each place). */
export const BOM_VIEW = 'manufacturing.bom.view'
export const BOM_MANAGE = 'manufacturing.bom.manage'
export const ORDER_VIEW = 'manufacturing.order.view'
export const ORDER_CREATE = 'manufacturing.order.create'
export const REPORT_VIEW = 'manufacturing.report.view'
export const SETTINGS_EDIT = 'manufacturing.settings.edit'

/** MFG-02: what a BOM makes. */
export const BOM_KINDS = ['production', 'recipe']
/** MFG-04: what a line is. */
export const LINE_KINDS = ['component', 'by_product']
/** MFG-10, MFG-11, MFG-14: an order's statuses (Order::STATUSES). */
export const ORDER_STATUSES = ['planned', 'pending_approval', 'released', 'in_progress', 'closed', 'cancelled', 'rejected']
/** MFG-18, MFG-19: the orders the consumption and yield reports cover. */
export const REPORT_STATUSES = ['released', 'in_progress', 'closed']
/** MFG-10 to MFG-14: the stages an order goes through. */
export const ORDER_STAGES = ['planned', 'released', 'in_progress', 'closed']
/** MFG-12: why materials come back. */
export const RETURN_REASONS = ['unused', 'excess', 'wrong_item', 'other']
/** MFG-13: why output was scrapped. */
export const SCRAP_REASONS = ['damaged', 'quality', 'process', 'other']
/** MFG-07, MFG-08: a backflush consumes or gives back. */
export const BACKFLUSH_KINDS = ['consume', 'return']

/** Status as a dot and a word: waiting is amber, running is blue, done is green, refused is red, stopped is grey. */
export const STATUS_TONES = {
  planned: 'neutral',
  pending_approval: 'warning',
  released: 'info',
  in_progress: 'info',
  closed: 'success',
  cancelled: 'neutral',
  rejected: 'danger',
  draft: 'warning',
  published: 'success',
  recorded: 'neutral',
  pending: 'warning',
  costed: 'info',
  received: 'success',
}

export const statusLabel = (t, status) => t(`manufacturing.statuses.${status}`, { defaultValue: status })
export const kindLabel = (t, kind) => t(`manufacturing.kinds.${kind}`, { defaultValue: kind })

/** Whether a decimal string is above zero (no floats). */
export const isPositive = (value) => /^\d+(\.\d+)?$/.test(String(value ?? '')) && /[1-9]/.test(String(value))

/** "Soap · SOAP": an item for pickers and cells. */
export const itemText = (item) => (item ? `${item.name} · ${item.code}` : '')

/** MFG-07, MFG-17: which integrations are on now (`{inventory_active, pos_active, backflush_active}`), or null. */
export function useManufacturingStatus() {
  const query = useQuery({ queryKey: ['manufacturing', 'status'], queryFn: () => api.get('manufacturing/status'), staleTime: 60_000, retry: false })
  return query.data?.data ?? null
}

/** An item with its units (base unit and others): the item as given when it has them, else read once. */
export function useItemUnits(item) {
  const known = Boolean(item?.base_uom_id)
  const query = useQuery({
    queryKey: ['items', 'detail', item?.id],
    queryFn: () => api.get(`items/${item.id}`),
    enabled: Boolean(item?.id) && !known,
    staleTime: 60_000,
    retry: false,
  })
  if (!item) return null
  return known ? item : { ...item, ...(query.data?.data ?? {}) }
}

/** A list's status filter (with "all statuses" first). */
export function statusFilter(t, statuses) {
  return { name: 'status', label: t('manufacturing.filters.status'), options: [{ value: '', label: t('manufacturing.filters.allStatuses') }, ...statuses.map((status) => ({ value: status, label: statusLabel(t, status) }))] }
}

/** A list's kind filter. */
export function kindFilter(t, kinds) {
  return { name: 'kind', label: t('manufacturing.filters.kind'), options: [{ value: '', label: t('manufacturing.filters.allKinds') }, ...kinds.map((kind) => ({ value: kind, label: kindLabel(t, kind) }))] }
}

/** Query keys of the module, so a change refreshes every list. */
export const manufacturingKey = ['manufacturing']

/**
 * INV-03: the units a line's quantity may be typed in (the item's base
 * unit first, then its others), keeping the unit the line already has
 * when the item's units cannot be read.
 */
export function useLineUnits(item, current) {
  const full = useItemUnits(item)
  const options = useUnitOptions(full)
  if (current?.id && !options.some((option) => option.value === current.id)) return [{ value: current.id, label: current.code || current.name || current.id }, ...options]
  return options
}
