import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, DataTable, Dialog, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useDebounced } from '@/lib/useDebounced'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { cn } from '@/lib/utils'
import { CancelDialog, MergeDialog, SplitDialog, TransferDialog, VoidLineDialog } from './OrderDialogs'
import { LocationPicker } from './RestaurantParts'
import { ORDER_EDIT, ORDER_KINDS, ORDER_MERGE, ORDER_STATUSES, ORDER_TRANSFER, orderName, restaurantKey, SALE_VOID, STATUS_TONES, statusLabel, useCanAt, useLocationChoice, useWaiters } from './restaurantData'

const BILL_ROWS = [
  ['lines_minor', 'billLines'],
  ['service_charge_minor', 'serviceCharge'],
  ['service_tax_minor', 'serviceTax'],
  ['tip_minor', 'tip'],
  ['total_minor', 'total'],
]

/** One order with its lines, tickets, settlements and bill, and what the user may do to it. */
function OrderDetail({ orderId, location, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const zone = useTimeZone(location?.company_id)
  const can = useCanAt(location)
  const [action, setAction] = useState(null)
  const query = useQuery({ queryKey: [...restaurantKey, 'order', orderId], queryFn: () => api.get(`restaurant/orders/${orderId}`) })
  const order = query.data?.data
  const lines = order?.lines ?? []
  const isOpen = order?.status === 'open'
  const sentAny = lines.some((line) => line.sent_at)
  const canCancel = isOpen && can(ORDER_EDIT) && !sentAny && !(order?.settlements ?? []).length

  const lineColumns = [
    {
      key: 'name',
      label: t('restaurant.orders.item'),
      wrap: true,
      render: (line) => (
        <div className="flex flex-col gap-1">
          <span className={cn(line.void && 'text-ink-muted line-through')}>{line.name}</span>
          {(line.modifiers ?? []).length ? <span className="text-caption text-ink-muted">{line.modifiers.map((modifier) => modifier.name).join(', ')}</span> : null}
          {line.note ? <span className="text-caption text-ink-muted">{line.note}</span> : null}
          {line.void ? (
            <StatusBadge tone="danger">
              {line.void.after_send ? t('restaurant.orders.voidAfterSend') : t('restaurant.orders.voidMark')}
              {line.void.reason ? ` · ${line.void.reason}` : ''}
            </StatusBadge>
          ) : null}
        </div>
      ),
    },
    { key: 'qty', label: t('restaurant.orders.qty'), numeric: true, render: (line) => line.qty },
    { key: 'course', label: t('restaurant.orders.course'), numeric: true, render: (line) => line.course },
    { key: 'seat', label: t('restaurant.orders.seat'), numeric: true, render: (line) => line.seat ?? '' },
    { key: 'price', label: t('restaurant.orders.unitPrice'), align: 'end', render: (line) => <Money amount={line.unit_price_minor} currency={order.currency} /> },
    { key: 'ticket', label: t('restaurant.orders.sent'), render: (line) => (line.ticket_status ? statusLabel(t, line.ticket_status) : t('restaurant.orders.notSent')) },
    {
      key: 'actions',
      label: <span className="sr-only">{t('restaurant.fields.actions')}</span>,
      align: 'end',
      render: (line) =>
        isOpen && !line.void && can(SALE_VOID) ? (
          <Button variant="ghost" aria-label={t('restaurant.orders.voidLineNamed', { name: line.name })} onClick={() => setAction({ type: 'void', line })}>
            {t('restaurant.orders.voidLine')}
          </Button>
        ) : null,
    },
  ]

  return (
    <>
      <Dialog open size="lg" title={order ? orderName(t, order) : t('restaurant.orders.detail')} onClose={onClose} footer={<Button onClick={onClose}>{t('restaurant.orders.close')}</Button>}>
        {query.isPending ? <p>{t('common.loading')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {order ? (
          <div className="flex flex-col gap-5 text-ink">
            <div className="flex flex-wrap items-center gap-x-5 gap-y-1 text-ink-muted">
              <StatusBadge tone={STATUS_TONES[order.status] ?? 'neutral'}>{statusLabel(t, order.status)}</StatusBadge>
              <span>{order.waiter_name}</span>
              <span>{t('restaurant.floor.covers', { count: order.covers })}</span>
              <span>{formatDateTime(order.opened_at, locale, zone)}</span>
              {(order.flags ?? []).map((flag) => (
                <StatusBadge key={flag} tone="warning">
                  {t(`restaurant.orders.flags.${flag}`, { defaultValue: flag })}
                </StatusBadge>
              ))}
            </div>
            <div className="flex flex-wrap gap-2">
              {isOpen && can(ORDER_TRANSFER) ? <Button onClick={() => setAction({ type: 'transfer' })}>{t('restaurant.orders.transfer')}</Button> : null}
              {isOpen && can(ORDER_MERGE) ? <Button onClick={() => setAction({ type: 'merge' })}>{t('restaurant.orders.merge')}</Button> : null}
              <Button onClick={() => setAction({ type: 'split' })}>{t('restaurant.orders.split')}</Button>
              {canCancel ? (
                <Button variant="danger" onClick={() => setAction({ type: 'cancel' })}>
                  {t('restaurant.orders.cancel')}
                </Button>
              ) : null}
            </div>
            <DataTable caption={t('restaurant.orders.lines')} columns={lineColumns} rows={lines} emptyText={t('restaurant.orders.noLines')} />
            {order.bill ? (
              <dl className="ml-auto grid w-full max-w-field grid-cols-[1fr_auto] gap-x-6 gap-y-1">
                {BILL_ROWS.map(([key, label]) => (
                  <div key={key} className="col-span-2 grid grid-cols-subgrid">
                    <dt className={cn('text-ink-muted', key === 'total_minor' && 'font-medium text-ink')}>{t(`restaurant.orders.${label}`)}</dt>
                    <dd className="text-right">
                      <Money amount={order.bill[key]} currency={order.currency} />
                    </dd>
                  </div>
                ))}
              </dl>
            ) : null}
            <section className="flex flex-col gap-2">
              <h3 className="text-h3 text-ink">{t('restaurant.orders.tickets')}</h3>
              {(order.tickets ?? []).length ? (
                <ul className="flex flex-col gap-1">
                  {order.tickets.map((ticket) => (
                    <li key={ticket.id} className="flex flex-wrap items-center gap-3">
                      <span className="tabular-nums">{t('restaurant.kds.ticket', { number: ticket.number })}</span>
                      <span className="text-ink-muted">{ticket.station_name}</span>
                      <StatusBadge tone={STATUS_TONES[ticket.status] ?? 'neutral'}>{statusLabel(t, ticket.status)}</StatusBadge>
                      {ticket.kind !== 'order' ? <span className="text-caption text-ink-muted">{t(`restaurant.kds.${ticket.kind}`)}</span> : null}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-ink-muted">{t('restaurant.orders.noTickets')}</p>
              )}
            </section>
            <section className="flex flex-col gap-2">
              <h3 className="text-h3 text-ink">{t('restaurant.orders.settlements')}</h3>
              {(order.settlements ?? []).length ? (
                <ul className="flex flex-col gap-1">
                  {order.settlements.map((settlement) => (
                    <li key={settlement.id} className="flex flex-wrap items-center gap-3">
                      <span>{t('restaurant.orders.settlementPart', { part: settlement.part, parts: settlement.parts })}</span>
                      <Money amount={settlement.total_minor} currency={order.currency} />
                      <span className="text-ink-muted">{formatDateTime(settlement.settled_at, locale, zone)}</span>
                      {settlement.voided_at ? <StatusBadge tone="danger">{t('restaurant.orders.voidMark')}</StatusBadge> : null}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-ink-muted">{t('restaurant.orders.noSettlements')}</p>
              )}
            </section>
          </div>
        ) : null}
      </Dialog>
      {order && action?.type === 'transfer' ? <TransferDialog order={order} onClose={() => setAction(null)} /> : null}
      {order && action?.type === 'merge' ? <MergeDialog order={order} onClose={() => setAction(null)} /> : null}
      {order && action?.type === 'void' ? <VoidLineDialog order={order} line={action.line} onClose={() => setAction(null)} /> : null}
      {order && action?.type === 'cancel' ? <CancelDialog order={order} onClose={() => setAction(null)} /> : null}
      {order && action?.type === 'split' ? <SplitDialog order={order} onClose={() => setAction(null)} /> : null}
    </>
  )
}

/**
 * REST-03 to REST-05, REST-11, REST-14, REST-15: open orders and tabs of a
 * location with their filters, and one order's detail with the back-office
 * actions: transfer to another waiter, merge, void a line, cancel an empty
 * order, and a preview of a split bill.
 */
export default function Orders() {
  const { t } = useTranslation()
  const locale = useLocale()
  const zone = useTimeZone()
  const places = useLocationChoice()
  const { location, isPending: placesPending } = places
  const waiters = useWaiters()
  const [filters, setFilters] = useState({ status: 'open', kind: '', waiter_id: '', search: '' })
  const [selected, setSelected] = useState(null)
  const search = useDebounced(filters.search.trim())
  const params = new URLSearchParams({ location_id: location?.id ?? '', per_page: '50' })
  for (const [key, value] of Object.entries({ status: filters.status, kind: filters.kind, waiter_id: filters.waiter_id, search })) if (value) params.set(key, value)
  const query = useQuery({ queryKey: [...restaurantKey, 'orders', params.toString()], queryFn: () => api.get(`restaurant/orders?${params.toString()}`), enabled: Boolean(location) })
  const orders = query.data?.data ?? []
  const set = (field) => (event) => setFilters((current) => ({ ...current, [field]: event.target.value }))
  const columns = [
    { key: 'name', label: t('restaurant.orders.order'), render: (row) => orderName(t, row) },
    { key: 'kind', label: t('restaurant.orders.kind'), render: (row) => t(`restaurant.orders.kinds.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'waiter', label: t('restaurant.orders.waiter'), render: (row) => row.waiter_name },
    { key: 'covers', label: t('restaurant.orders.covers'), numeric: true, render: (row) => row.covers },
    { key: 'status', label: t('restaurant.fields.status'), render: (row) => <StatusBadge tone={STATUS_TONES[row.status] ?? 'neutral'}>{statusLabel(t, row.status)}</StatusBadge> },
    { key: 'opened', label: t('restaurant.orders.opened'), render: (row) => formatDateTime(row.opened_at, locale, zone) },
    { key: 'total', label: t('restaurant.orders.total'), align: 'end', render: (row) => (row.bill ? <Money amount={row.bill.total_minor} currency={row.currency} /> : '') },
  ]

  return (
    <>
      <PageHeader title={t('restaurant.orders.title')} description={t('restaurant.orders.description')} />
      <div className="flex flex-col gap-5">
        <LocationPicker choice={places} />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Select label={t('restaurant.fields.status')} options={[{ value: '', label: t('restaurant.orders.allStatuses') }, ...ORDER_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))]} value={filters.status} onChange={set('status')} />
          <Select label={t('restaurant.orders.kind')} options={[{ value: '', label: t('restaurant.orders.allKinds') }, ...ORDER_KINDS.map((kind) => ({ value: kind, label: t(`restaurant.orders.kinds.${kind}`) }))]} value={filters.kind} onChange={set('kind')} />
          <Select label={t('restaurant.orders.waiter')} options={[{ value: '', label: t('restaurant.orders.allWaiters') }, ...waiters.map((user) => ({ value: user.id, label: user.name }))]} value={filters.waiter_id} onChange={set('waiter_id')} />
          <TextField label={t('restaurant.orders.search')} type="search" placeholder={t('restaurant.orders.searchPlaceholder')} value={filters.search} onChange={set('search')} />
        </div>
        {placesPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {!placesPending && !location ? <p className="text-ink-muted">{t('restaurant.noLocation')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {location && !query.isError ? <DataTable caption={t('restaurant.orders.title')} columns={columns} rows={orders} loading={query.isPending} selectedId={selected} onRowClick={(row) => setSelected(row.id)} emptyText={t('restaurant.orders.empty')} /> : null}
      </div>
      {selected ? <OrderDetail key={selected} orderId={selected} location={location} onClose={() => setSelected(null)} /> : null}
    </>
  )
}
