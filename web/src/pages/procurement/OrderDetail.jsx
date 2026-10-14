import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, CalendarDate, CancelDialog, Detail, DetailState, DocumentFlow, EmailDialog, LineName, Qty, Status, When } from './ProcurementParts'
import { useDocumentAction, useDownload } from './procurementHelpers'
import { INVOICE_CREATE, procurementKey, RECEIPT_CREATE } from './procurementData'

/**
 * PRC-13, WF-10: one purchase order: supplier, place, currency and rate,
 * its lines with what was received, rejected and invoiced, totals in its
 * currency and in base currency once submitted, where its approval flow
 * is, and Submit, Edit, Cancel, Send, PDF, "Receive goods" and "Enter
 * invoice" as the reader may (`can`).
 */
export default function OrderDetail() {
  const { t } = useTranslation()
  const { orderId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [cancelling, setCancelling] = useState(false)
  const [sending, setSending] = useState(false)
  const key = [...procurementKey, 'order', orderId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`procurement/orders/${orderId}`) })
  const action = useDocumentAction(key)
  const download = useDownload()
  const order = query.data?.data
  const back = <Back to="/procurement/orders">{t('procurement.orders.back')}</Back>
  if (!order) return <DetailState query={query} back={back} notFound={t('procurement.order.notFound')} />

  const company = order.company?.id
  const rateNeeded = (order.lines ?? []).some((line) => line.rate_needed)
  const columns = [
    { key: 'line_no', label: t('procurement.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    { key: 'item', label: t('procurement.columns.item'), wrap: true, render: (line) => <LineName line={line} /> },
    { key: 'qty', label: t('procurement.columns.qty'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
    { key: 'unit_price', label: t('procurement.columns.unitPrice'), align: 'end', render: (line) => <Amount value={line.unit_price} /> },
    {
      key: 'tax',
      label: t('procurement.columns.tax'),
      wrap: true,
      render: (line) =>
        line.rate_needed ? (
          <StatusBadge tone="warning">{t('procurement.order.rateNeeded')}</StatusBadge>
        ) : line.tax_code ? (
          <span className="flex flex-col">
            <span>{line.tax_code.name}</span>
            {line.tax_rate !== null && line.tax_rate !== undefined ? <span className="text-caption text-ink-muted tabular-nums">{`${line.tax_rate} %`}</span> : null}
          </span>
        ) : (
          <span className="text-ink-muted">—</span>
        ),
    },
    { key: 'total', label: t('procurement.columns.lineTotal'), align: 'end', render: (line) => <Amount value={line.total} /> },
    { key: 'received', label: t('procurement.columns.received'), align: 'end', render: (line) => <Qty value={line.received_qty} /> },
    { key: 'rejected', label: t('procurement.columns.rejected'), align: 'end', render: (line) => <Qty value={line.rejected_qty} /> },
    { key: 'invoiced', label: t('procurement.columns.invoiced'), align: 'end', render: (line) => <Qty value={line.invoiced_qty} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('procurement.order.eyebrow')}
        title={order.number ?? t('procurement.draftNumber')}
        description={<Status status={order.status} />}
        actions={
          <>
            {order.can?.edit ? <Button icon="edit" onClick={() => navigate(`/procurement/orders/${order.id}/edit`)}>{t('procurement.actions.edit')}</Button> : null}
            {order.can?.cancel ? (
              <Button variant="danger" onClick={() => setCancelling(true)}>
                {t('procurement.order.cancel')}
              </Button>
            ) : null}
            {order.can?.print ? (
              <Button icon="print" loading={download.isPending} onClick={() => download.mutate({ path: `procurement/orders/${order.id}/pdf`, name: `${order.number ?? 'purchase-order'}.pdf` })}>
                {t('procurement.order.pdf')}
              </Button>
            ) : null}
            {order.can?.send ? (
              <Button icon="mail" onClick={() => setSending(true)}>
                {t('procurement.order.send')}
              </Button>
            ) : null}
            {order.can?.invoice && can(INVOICE_CREATE) ? <Button onClick={() => navigate(`/procurement/invoices/new?order=${order.id}`)}>{t('procurement.order.enterInvoice')}</Button> : null}
            {order.can?.receive && can(RECEIPT_CREATE) ? (
              <Button variant="primary" onClick={() => navigate(`/procurement/receipts/new?order=${order.id}`)}>
                {t('procurement.order.receive')}
              </Button>
            ) : null}
            {order.can?.submit ? (
              <Button variant="pay" loading={action.isPending} disabled={rateNeeded} onClick={() => action.mutate({ path: `procurement/orders/${order.id}/submit`, done: 'procurement.order.submitted' })}>
                {t('procurement.order.submit')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        {rateNeeded && order.status === 'draft' ? (
          <Alert tone="warning" title={t('procurement.order.rateNeededTitle')}>
            {t('procurement.order.rateNeededHelp')}
          </Alert>
        ) : null}
        <Card title={t('procurement.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('procurement.fields.supplier')}>{order.party?.name}</Detail>
            <Detail label={t('procurement.fields.branch')}>
              <span className="flex flex-col">
                <span>{order.branch?.name}</span>
                <span className="text-caption text-ink-muted">{[order.company?.name, order.location?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            <Detail label={t('procurement.fields.orderDate')}>
              <CalendarDate value={order.order_date} />
            </Detail>
            <Detail label={t('procurement.fields.expectedOn')}>
              <CalendarDate value={order.expected_on} />
            </Detail>
            <Detail label={t('procurement.fields.taxInclusive')}>{order.tax_inclusive ? t('procurement.yes') : t('procurement.no')}</Detail>
            <Detail label={t('procurement.columns.subtotal')}>
              <Amount value={order.subtotal} />
            </Detail>
            <Detail label={t('procurement.columns.tax')}>
              <Amount value={order.tax} />
            </Detail>
            <Detail label={t('procurement.columns.total')}>
              <Amount value={order.total} />
            </Detail>
            {order.base_total && order.base_total.currency !== order.currency ? (
              <Detail label={t('procurement.columns.baseTotal')}>
                <span className="flex flex-col">
                  <Amount value={order.base_total} />
                  {order.fx?.rate ? <span className="text-caption text-ink-muted tabular-nums">{t('procurement.rate', { rate: order.fx.rate })}</span> : null}
                </span>
              </Detail>
            ) : null}
            <Detail label={t('procurement.fields.requestedBy')}>
              <span className="flex flex-col">
                <span>{order.requested_by?.name}</span>
                <span className="text-caption text-ink-muted">
                  <When value={order.created_at} companyId={company} />
                </span>
              </span>
            </Detail>
            {order.requisition_id ? (
              <Detail label={t('procurement.fields.requisition')}>
                <Link to={`/procurement/requisitions/${order.requisition_id}`} className="text-primary hover:text-primary-hover">
                  {t('procurement.order.openRequisition')}
                </Link>
              </Detail>
            ) : null}
            {order.sent_at ? (
              <Detail label={t('procurement.order.sentAt')}>
                <When value={order.sent_at} companyId={company} />
              </Detail>
            ) : null}
            {order.terms ? <Detail label={t('procurement.fields.terms')}>{order.terms}</Detail> : null}
            {order.cancelled_at ? (
              <Detail label={t('procurement.fields.cancelled')}>
                <span className="flex flex-col">
                  <When value={order.cancelled_at} companyId={company} />
                  <span className="text-caption text-ink-muted">{order.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('procurement.lines.title')}>
          <DataTable caption={t('procurement.lines.title')} columns={columns} rows={order.lines ?? []} />
        </Card>
        <DocumentFlow type="procurement.po" id={order.id} started={order.status !== 'draft'} />
      </div>
      {order.can?.send ? (
        <EmailDialog
          key={sending ? 'open' : 'closed'}
          open={sending}
          title={t('procurement.order.sendTitle', { number: order.number ?? '' })}
          help={t('procurement.order.sendHelp')}
          path={`procurement/orders/${order.id}/send`}
          onClose={() => setSending(false)}
          onDone={async (response) => {
            setSending(false)
            if (response?.data) queryClient.setQueryData(key, response)
            toast.success(t('procurement.order.sent'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
      {order.can?.cancel ? (
        <CancelDialog
          open={cancelling}
          title={t('procurement.order.cancelTitle', { number: order.number ?? t('procurement.draftNumber') })}
          help={t('procurement.order.cancelHelp')}
          path={`procurement/orders/${order.id}/cancel`}
          onClose={() => setCancelling(false)}
          onDone={async (response) => {
            setCancelling(false)
            if (response?.data) queryClient.setQueryData(key, response)
            toast.success(t('procurement.order.cancelled'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
    </>
  )
}
