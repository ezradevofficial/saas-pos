import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, DataTable, Money } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { ConfirmDialog } from './RestaurantParts'
import { orderError, orderName, restaurantKey } from './restaurantData'

/**
 * REST-11: the voids of sent lines that a till asked for and the server could
 * not prove, waiting for a reviewer who holds `pos.sale.void` (the API lists
 * only those and says whether the reader's own limit covers each). Apply uses
 * the order's void route, Reject the reject route; either takes the line off
 * this list. The line stays on the bill until it is applied.
 */
export default function PendingVoids() {
  const { t } = useTranslation()
  const locale = useLocale()
  const zone = useTimeZone()
  const queryClient = useQueryClient()
  const [asking, setAsking] = useState(null)
  const query = useQuery({ queryKey: [...restaurantKey, 'pending-voids'], queryFn: () => api.get('restaurant/pending-voids') })
  const rows = (query.data?.data ?? []).map((row) => ({ ...row, id: row.line_id, kind: row.order_kind }))
  const decide = useMutation({
    mutationFn: ({ type, row }) =>
      type === 'apply'
        ? api.post(`restaurant/orders/${row.order_id}/lines/${row.line_id}/void`, { reason: row.reason })
        : api.post(`restaurant/orders/${row.order_id}/lines/${row.line_id}/void/reject`, {}),
    onSuccess: (_data, { type }) => {
      toast.success(type === 'apply' ? t('restaurant.pending.applied') : t('restaurant.pending.rejected'))
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      setAsking(null)
    },
  })

  const columns = [
    { key: 'order', label: t('restaurant.orders.order'), render: (row) => orderName(t, row) },
    { key: 'item', label: t('restaurant.orders.item'), wrap: true, render: (row) => `${row.qty} × ${row.name}` },
    { key: 'value', label: t('restaurant.pending.value'), align: 'end', render: (row) => <Money amount={row.value_minor} currency={row.currency} /> },
    { key: 'by', label: t('restaurant.pending.askedBy'), render: (row) => row.asked_by_name ?? '' },
    { key: 'reason', label: t('restaurant.orders.reason'), wrap: true, render: (row) => row.reason },
    { key: 'at', label: t('restaurant.pending.askedAt'), render: (row) => formatDateTime(row.asked_at, locale, zone) },
    {
      key: 'actions',
      label: <span className="sr-only">{t('restaurant.fields.actions')}</span>,
      align: 'end',
      render: (row) => (
        <div className="flex flex-wrap items-center justify-end gap-2">
          {row.can_apply ? (
            <Button variant="primary" aria-label={t('restaurant.pending.applyNamed', { name: row.name })} onClick={() => setAsking({ type: 'apply', row })}>
              {t('restaurant.pending.apply')}
            </Button>
          ) : (
            <span className="text-caption text-ink-muted">{t('restaurant.pending.overLimit')}</span>
          )}
          <Button variant="ghost" aria-label={t('restaurant.pending.rejectNamed', { name: row.name })} onClick={() => setAsking({ type: 'reject', row })}>
            {t('restaurant.pending.reject')}
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('restaurant.pending.title')} description={t('restaurant.pending.description')} />
      <div className="flex flex-col gap-5">
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {!query.isError ? <DataTable caption={t('restaurant.pending.title')} columns={columns} rows={rows} loading={query.isPending} emptyText={t('restaurant.pending.empty')} /> : null}
      </div>
      <ConfirmDialog
        open={Boolean(asking)}
        title={asking?.type === 'apply' ? t('restaurant.pending.applyTitle') : t('restaurant.pending.rejectTitle')}
        message={asking ? t(asking.type === 'apply' ? 'restaurant.pending.applyAsk' : 'restaurant.pending.rejectAsk', { name: asking.row.name, qty: asking.row.qty, reason: asking.row.reason }) : ''}
        confirmLabel={asking?.type === 'apply' ? t('restaurant.pending.apply') : t('restaurant.pending.reject')}
        loading={decide.isPending}
        error={decide.error ? orderError(t, decide.error) : null}
        onConfirm={() => decide.mutate(asking)}
        onClose={() => {
          decide.reset()
          setAsking(null)
        }}
      />
    </>
  )
}
