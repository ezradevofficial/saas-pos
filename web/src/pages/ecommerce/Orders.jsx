import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, ListView, Money, MoneyInput, Select, StatusBadge, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { When } from '@/pages/crm/CrmParts'
import {
  CHANNELS,
  DELIVERY_ASSIGN,
  ecomKey,
  FULFILMENTS,
  namedOptions,
  ORDER_CANCEL,
  ORDER_MANAGE,
  ORDER_REFUND,
  ORDER_STATUSES,
  PAYMENT_STATUSES,
  TONES,
  useEcomAction,
  useRiders,
  useStores,
} from './ecommerceData'

/** An order status as a dot and a word. */
export function OrderStatus({ status }) {
  const { t } = useTranslation()
  if (!status) return null
  return <StatusBadge tone={TONES[status] ?? 'neutral'}>{t(`ecommerce.statuses.${status}`, { defaultValue: status })}</StatusBadge>
}

/** A payment status as a dot and a word. */
export function PaymentStatus({ status }) {
  const { t } = useTranslation()
  if (!status) return null
  return <StatusBadge tone={TONES[status] ?? 'neutral'}>{t(`ecommerce.paymentStatuses.${status}`, { defaultValue: status })}</StatusBadge>
}

const ordersKey = [...ecomKey, 'orders']

/** ECOM-22: online, phone and WhatsApp orders, with filters, sort and export (EXP-01). */
export default function Orders() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { stores } = useStores()
  const columns = [
    { key: 'number', label: t('ecommerce.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink">{row.number}</span> },
    { key: 'status', label: t('ecommerce.columns.status'), sortKey: 'status', render: (row) => <OrderStatus status={row.status} /> },
    { key: 'payment_status', label: t('ecommerce.columns.payment'), render: (row) => <PaymentStatus status={row.payment_status} /> },
    { key: 'contact_name', label: t('ecommerce.columns.customer'), render: (row) => row.contact_name },
    { key: 'channel', label: t('ecommerce.columns.channel'), render: (row) => t(`ecommerce.channels.${row.channel}`) },
    { key: 'fulfilment', label: t('ecommerce.columns.fulfilment'), render: (row) => t(`ecommerce.fulfilments.${row.fulfilment}`) },
    { key: 'store', label: t('ecommerce.columns.store'), defaultHidden: true, render: (row) => row.store?.name ?? '' },
    { key: 'contact_phone', label: t('ecommerce.columns.phone'), defaultHidden: true, render: (row) => row.contact_phone },
    { key: 'total', label: t('ecommerce.columns.total'), sortKey: 'total', align: 'end', render: (row) => <Money amount={row.total_minor} currency={row.currency} /> },
    { key: 'placed_at', label: t('ecommerce.columns.placed'), sortKey: 'placed_at', render: (row) => <When value={row.placed_at} companyId={row.company?.id} /> },
  ]
  const list = useServerList({ id: 'ecommerce-orders', endpoint: 'ecommerce/orders', queryKey: ordersKey, filters: { status: '', payment_status: '', channel: '', fulfilment: '', store: '' }, defaultSort: '-placed_at', columns })
  const all = (name) => ({ value: '', label: t(`ecommerce.filters.all.${name}`) })
  const filterFields = [
    { name: 'status', label: t('ecommerce.filters.status'), options: [all('status'), { value: 'open', label: t('ecommerce.filters.open') }, ...ORDER_STATUSES.map((status) => ({ value: status, label: t(`ecommerce.statuses.${status}`) }))] },
    { name: 'payment_status', label: t('ecommerce.filters.payment'), options: [all('payment'), ...PAYMENT_STATUSES.map((status) => ({ value: status, label: t(`ecommerce.paymentStatuses.${status}`) }))] },
    { name: 'channel', label: t('ecommerce.filters.channel'), options: [all('channel'), ...CHANNELS.map((channel) => ({ value: channel, label: t(`ecommerce.channels.${channel}`) }))] },
    { name: 'fulfilment', label: t('ecommerce.filters.fulfilment'), options: [all('fulfilment'), ...FULFILMENTS.map((value) => ({ value, label: t(`ecommerce.fulfilments.${value}`) }))] },
    ...(stores.length > 1 ? [{ name: 'store', label: t('ecommerce.filters.store'), options: [all('store'), ...namedOptions(stores)] }] : []),
  ]
  const filtered = list.term || Object.values(list.filters).some(Boolean)
  return (
    <>
      <PageHeader title={t('ecommerce.orders.title')} description={t('ecommerce.orders.description')} />
      <ListView
        list={list}
        title={t('ecommerce.orders.title')}
        searchLabel={t('ecommerce.orders.search')}
        filterFields={filterFields}
        emptyText={filtered ? t('ecommerce.list.emptyFiltered') : t('ecommerce.orders.empty')}
        onRowClick={(row) => navigate(`/ecommerce/orders/${row.id}`)}
      />
    </>
  )
}

/** A reason (cancel, return) or a reference (refund) asked before an action. */
function ReasonDialog({ title, label, confirm, withFee = false, onClose, onConfirm, pending }) {
  const { t } = useTranslation()
  const [text, setText] = useState('')
  const [fee, setFee] = useState(false)
  return (
    <Dialog
      open
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('ecommerce.actions.back')}</Button>
          <Button variant="primary" loading={pending} disabled={!text.trim()} onClick={() => onConfirm({ text: text.trim(), fee })}>{confirm}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <TextField label={label} value={text} required onChange={(event) => setText(event.target.value)} />
        {withFee ? <Switch label={t('ecommerce.orders.refundFee')} checked={fee} onChange={setFee} /> : null}
      </div>
    </Dialog>
  )
}

/** ECOM-16 to ECOM-22, ECOM-27: an order's lines, totals, payments, history, delivery and the actions its status allows. */
export function OrderDetail() {
  const { t } = useTranslation()
  const { orderId } = useParams()
  const { can } = usePermissions()
  const [dialog, setDialog] = useState(null)
  const [rider, setRider] = useState('')
  const [collected, setCollected] = useState('')
  const query = useQuery({ queryKey: [...ordersKey, orderId], queryFn: () => api.get(`ecommerce/orders/${orderId}`) })
  const action = useEcomAction({ onDone: () => setDialog(null) })
  const riders = useRiders(can(DELIVERY_ASSIGN))
  const order = query.data?.data
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!order) return <p className="text-ink-muted">{t('common.loading')}</p>
  const act = (verb, body = {}, done = `ecommerce.orders.done.${verb}`) => action.mutate({ path: `ecommerce/orders/${order.id}/${verb}`, body, done })
  const manage = can(ORDER_MANAGE)
  const delivery = order.delivery
  const pickup = order.fulfilment === 'pickup'
  const before = ['placed', 'accepted', 'picking', 'ready']
  const buttons = []
  if (manage && order.status === 'placed') buttons.push(<Button key="accept" variant="pay" onClick={() => act('accept')} disabled={!['paid', 'cod'].includes(order.payment_status)}>{t('ecommerce.actions.accept')}</Button>)
  if (manage && order.status === 'accepted') buttons.push(<Button key="pick" onClick={() => act('pick')}>{t('ecommerce.actions.pick')}</Button>)
  if (manage && ['accepted', 'picking'].includes(order.status)) buttons.push(<Button key="ready" onClick={() => act('ready')}>{t('ecommerce.actions.ready')}</Button>)
  if (manage && !pickup && ['accepted', 'picking', 'ready'].includes(order.status)) buttons.push(<Button key="dispatch" variant="primary" disabled={!delivery?.rider} onClick={() => act('dispatch')}>{t('ecommerce.actions.dispatch')}</Button>)
  if (manage && ((pickup && ['accepted', 'picking', 'ready'].includes(order.status)) || order.status === 'out_for_delivery')) buttons.push(<Button key="deliver" variant="primary" onClick={() => act('deliver', collected ? { collected_minor: collected } : {})}>{t('ecommerce.actions.deliver')}</Button>)
  if (manage && ['out_for_delivery', 'delivered'].includes(order.status)) buttons.push(<Button key="return" variant="danger" onClick={() => setDialog('return')}>{t('ecommerce.actions.return')}</Button>)
  if (can(ORDER_CANCEL) && before.includes(order.status)) buttons.push(<Button key="cancel" variant="danger" onClick={() => setDialog('cancel')}>{t('ecommerce.actions.cancel')}</Button>)
  // ECOM-19: a refund-due order, or money held beyond the order (a second settlement, a wrong amount).
  const refundDue = order.payment_status === 'refund_due' || (order.payments ?? []).some((payment) => payment.status === 'refund_due')
  const flags = [...new Set((order.flags ?? []).map((flag) => flag.code))]
  if (can(ORDER_REFUND) && refundDue) buttons.push(<Button key="refund" onClick={() => setDialog('refund')}>{t('ecommerce.actions.refund')}</Button>)

  const totals = order.totals
  const lineColumns = [
    { key: 'name', label: t('ecommerce.columns.item'), render: (row) => row.name },
    { key: 'qty', label: t('ecommerce.columns.qty'), align: 'end', render: (row) => <span className="tabular-nums">{row.qty.replace(/\.?0+$/, '')}</span> },
    { key: 'price', label: t('ecommerce.columns.price'), align: 'end', render: (row) => <Money amount={row.unit_price_minor} currency={order.currency} /> },
    { key: 'discount', label: t('ecommerce.columns.discount'), align: 'end', render: (row) => (row.discount_minor !== '0' ? <Money amount={row.discount_minor} currency={order.currency} /> : '—') },
    { key: 'total', label: t('ecommerce.columns.total'), align: 'end', render: (row) => <Money amount={row.total_minor} currency={order.currency} /> },
  ]
  const totalRows = [
    ['subtotal', totals.subtotal_minor],
    ['discount', totals.discount_minor],
    ['tax', totals.tax_minor],
    ['delivery', totals.delivery_fee_minor],
    ['total', totals.total_minor],
    ['loyalty', totals.loyalty_paid_minor],
    ['due', totals.due_minor],
  ].filter(([key, value]) => ['total', 'due'].includes(key) || value !== '0')

  return (
    <>
      <PageHeader
        eyebrow={t(`ecommerce.channels.${order.channel}`)}
        title={t('ecommerce.orders.detailTitle', { number: order.number })}
        description={`${order.contact_name} · ${order.contact_phone}`}
        actions={buttons.length ? buttons : null}
      />
      {flags.length ? (
        <Alert tone="warning" title={t('ecommerce.orders.flagsTitle')}>
          {flags.map((code) => t(`ecommerce.flags.${code}`, { defaultValue: code })).join(' · ')}
        </Alert>
      ) : null}
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="flex flex-col gap-4 lg:col-span-2">
          <Card title={t('ecommerce.orders.lines')} subtitle={<span className="flex gap-3"><OrderStatus status={order.status} /><PaymentStatus status={order.payment_status} /></span>}>
            <DataTable caption={t('ecommerce.orders.lines')} columns={lineColumns} rows={order.lines} emptyText="—" />
            <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-1 text-body">
              {totalRows.map(([key, value]) => (
                <div key={key} className="contents">
                  <dt className={key === 'total' || key === 'due' ? 'font-medium text-ink' : 'text-ink-muted'}>{t(`ecommerce.totals.${key}`)}</dt>
                  <dd className="text-right"><Money amount={value} currency={order.currency} /></dd>
                </div>
              ))}
            </dl>
          </Card>
          <Card title={t('ecommerce.orders.history')}>
            <ol className="flex flex-col gap-2">
              {order.history.map((step, index) => (
                <li key={index} className="flex flex-wrap items-center gap-3 text-body">
                  <OrderStatus status={step.to} />
                  <span className="text-ink-muted"><When value={step.at} companyId={order.company?.id} /></span>
                  {step.user ? <span className="text-ink-muted">{step.user.name}</span> : null}
                  {step.note ? <span className="text-ink">{step.note}</span> : null}
                </li>
              ))}
            </ol>
          </Card>
        </div>
        <div className="flex flex-col gap-4">
          <Card title={t(`ecommerce.fulfilments.${order.fulfilment}`)}>
            <dl className="flex flex-col gap-2 text-body">
              {order.address ? <div><dt className="text-ink-muted">{t('ecommerce.orders.address')}</dt><dd>{[order.address.line, order.address.area].filter(Boolean).join(', ')}</dd></div> : null}
              {order.zone ? <div><dt className="text-ink-muted">{t('ecommerce.orders.zone')}</dt><dd>{order.zone.name}</dd></div> : null}
              {order.pickup_location ? <div><dt className="text-ink-muted">{t('ecommerce.orders.pickupAt')}</dt><dd>{order.pickup_location.name}</dd></div> : null}
              {delivery ? (
                <div>
                  <dt className="text-ink-muted">{t('ecommerce.orders.delivery')}</dt>
                  <dd className="flex flex-col gap-1">
                    <StatusBadge tone={TONES[delivery.status] ?? 'neutral'}>{t(`ecommerce.deliveryStatuses.${delivery.status}`)}</StatusBadge>
                    {delivery.rider ? <span>{delivery.rider.name}</span> : null}
                    {delivery.last_location ? <span className="text-caption text-ink-muted">{t('ecommerce.deliveries.lastSeen')} <When value={delivery.last_location.at} companyId={order.company?.id} /></span> : null}
                  </dd>
                </div>
              ) : null}
            </dl>
            {delivery && can(DELIVERY_ASSIGN) && ['unassigned', 'assigned'].includes(delivery.status) ? (
              <div className="mt-4 flex items-end gap-2">
                <Select label={t('ecommerce.deliveries.rider')} placeholder={t('ecommerce.deliveries.chooseRider')} options={namedOptions(riders)} value={rider || delivery.rider?.id || ''} onChange={(event) => setRider(event.target.value)} />
                <Button disabled={!rider} loading={action.isPending} onClick={() => action.mutate({ path: `ecommerce/deliveries/${delivery.id}/assign`, body: { rider_id: rider }, done: 'ecommerce.deliveries.assigned' })}>{t('ecommerce.actions.assign')}</Button>
              </div>
            ) : null}
            {manage && order.payment_status === 'cod' && (pickup || order.status === 'out_for_delivery') ? (
              <MoneyInput className="mt-4" label={t('ecommerce.orders.collected')} help={t('ecommerce.orders.collectedHelp')} currency={order.currency} value={collected} onChange={setCollected} />
            ) : null}
          </Card>
          <Card title={t('ecommerce.orders.payments')}>
            <ul className="flex flex-col gap-2 text-body">
              {order.payments.map((payment) => (
                <li key={payment.id} className="flex items-center justify-between gap-3">
                  <span className="flex flex-col">
                    <span>{t(`ecommerce.paymentKinds.${payment.kind}`)}</span>
                    <span className="text-caption text-ink-muted">{t(`ecommerce.paymentRowStatuses.${payment.status}`, { defaultValue: payment.status })}{payment.provider_reference ? ` · ${payment.provider_reference}` : ''}</span>
                  </span>
                  <Money amount={payment.amount_minor} currency={payment.currency} />
                </li>
              ))}
            </ul>
          </Card>
        </div>
      </div>
      {dialog === 'cancel' ? (
        <ReasonDialog title={t('ecommerce.orders.cancelTitle')} label={t('ecommerce.fields.reason')} confirm={t('ecommerce.actions.cancelOrder')} pending={action.isPending} onClose={() => setDialog(null)} onConfirm={({ text }) => act('cancel', { reason: text })} />
      ) : null}
      {dialog === 'return' ? (
        <ReasonDialog title={t('ecommerce.orders.returnTitle')} label={t('ecommerce.fields.reason')} confirm={t('ecommerce.actions.returnOrder')} withFee={order.status === 'delivered'} pending={action.isPending} onClose={() => setDialog(null)} onConfirm={({ text, fee }) => act('return', { reason: text, refund_fee: fee })} />
      ) : null}
      {dialog === 'refund' ? (
        <ReasonDialog title={t('ecommerce.orders.refundTitle')} label={t('ecommerce.fields.reference')} confirm={t('ecommerce.actions.refund')} pending={action.isPending} onClose={() => setDialog(null)} onConfirm={({ text }) => act('refund', { reference: text })} />
      ) : null}
    </>
  )
}
