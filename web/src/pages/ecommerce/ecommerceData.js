// The E-commerce and delivery back office (docs/modules/ecommerce.md):
// permissions, statuses and their tones, the module's query key, and the
// stores, riders and actions its pages share. It reads only core lists and
// the E-commerce API (the module stands alone, RBAC-08).
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'

/** RBAC-09: who may open each page (the API checks again at each location). */
export const STORE_VIEW = 'ecommerce.store.view'
export const STORE_MANAGE = 'ecommerce.store.manage'
export const ORDER_VIEW = 'ecommerce.order.view'
export const ORDER_CREATE = 'ecommerce.order.create'
export const ORDER_MANAGE = 'ecommerce.order.manage'
export const ORDER_CANCEL = 'ecommerce.order.cancel'
export const ORDER_REFUND = 'ecommerce.order.refund'
export const DELIVERY_VIEW = 'ecommerce.delivery.view'
export const DELIVERY_ASSIGN = 'ecommerce.delivery.assign'
export const DELIVERY_TRACK = 'ecommerce.delivery.track'
export const RIDER_MANAGE = 'ecommerce.rider.manage'
export const CASHUP_VIEW = 'ecommerce.cashup.view'
export const CASHUP_MANAGE = 'ecommerce.cashup.manage'
export const WHATSAPP_VIEW = 'ecommerce.whatsapp.view'
export const WHATSAPP_REPLY = 'ecommerce.whatsapp.reply'
export const REPORT_VIEW = 'ecommerce.report.view'
export const RIDERS_VIEW = [DELIVERY_VIEW, RIDER_MANAGE]
export const SETTINGS_VIEW = [STORE_VIEW, STORE_MANAGE]

/** The values the API sends (Models' constants in api/modules/Ecommerce). */
export const ORDER_STATUSES = ['placed', 'accepted', 'picking', 'ready', 'out_for_delivery', 'delivered', 'cancelled', 'returned']
export const PAYMENT_STATUSES = ['pending', 'paid', 'cod', 'cod_collected', 'failed', 'refund_due', 'refunded']
export const CHANNELS = ['web', 'phone', 'whatsapp']
export const FULFILMENTS = ['delivery', 'pickup']
export const PAYMENT_KINDS = ['mpesa', 'card', 'cod', 'points', 'gift_card']
export const DELIVERY_COLUMNS = ['unassigned', 'assigned', 'picked_up']
export const STORE_STATUSES = ['draft', 'live', 'paused']

/** Status as a dot and a word: waiting amber, under way teal, done green, refused red, closed grey. */
export const TONES = {
  placed: 'warning',
  accepted: 'info',
  picking: 'info',
  ready: 'info',
  out_for_delivery: 'info',
  delivered: 'success',
  cancelled: 'neutral',
  returned: 'danger',
  pending: 'warning',
  paid: 'success',
  cod: 'info',
  cod_collected: 'success',
  failed: 'danger',
  refund_due: 'warning',
  refunded: 'neutral',
  unassigned: 'warning',
  assigned: 'info',
  picked_up: 'info',
  draft: 'neutral',
  live: 'success',
  paused: 'warning',
  open: 'info',
  converted: 'success',
  closed: 'neutral',
}

/** A quantity string without its trailing zeros ("2.500000" reads "2.5", "10" stays "10"). */
export const qtyText = (qty) => (String(qty ?? '').includes('.') ? String(qty).replace(/\.?0+$/, '') : String(qty ?? ''))

/** ECOM-19: an order's lines that can still go back (their returnable quantity is above zero). */
export const returnableLines = (order) => (order.lines ?? []).filter((line) => Number(line.returnable_qty ?? 0) > 0)

/** The module's query key, so a change refreshes its lists and details. */
export const ecomKey = ['ecommerce']

/** Options of named records ({id, name}) for a picker. */
export const namedOptions = (records) => records.map((record) => ({ value: record.id, label: record.name }))

/** The stores the user sees. */
export function useStores() {
  const query = useQuery({ queryKey: [...ecomKey, 'stores'], queryFn: () => api.get('ecommerce/stores?per_page=200'), staleTime: 30_000, retry: false })
  return { ...query, stores: query.data?.data ?? [] }
}

/** ECOM-26: the riders the user sees (active ones). */
export function useRiders(enabled = true) {
  const query = useQuery({ queryKey: [...ecomKey, 'riders'], queryFn: () => api.get('ecommerce/riders'), enabled, staleTime: 30_000, retry: false })
  return (query.data?.data ?? []).filter((rider) => rider.active && !rider.archived_at)
}

/**
 * A record action (`POST path` with an optional body): a toast says it was
 * done, the module's lists and details refresh; a refusal shows as a toast.
 */
export function useEcomAction({ onDone } = {}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ path, body, method = 'post' }) => api[method](path, body ?? {}),
    onSuccess: async (response, variables) => {
      if (variables.done) toast.success(t(variables.done))
      onDone?.(response, variables)
      await queryClient.invalidateQueries({ queryKey: ecomKey })
    },
    onError: (error) => toast.error(errorMessage(error)),
  })
}
