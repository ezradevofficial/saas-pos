import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { Button, Card, DataTable, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, CalendarDate, CancelDialog, Detail, DetailState, LineName, Qty, Status, When } from './ProcurementParts'
import { useDocumentAction } from './procurementHelpers'
import { procurementKey } from './procurementData'

/**
 * PRC-14: one goods received note: its PO, location and supplier, per
 * line the ordered, received, rejected and accepted quantities, batch,
 * expiry, serials and weight, the value in the PO's currency and in base
 * currency with any landed cost, whether it came on time, and Post or
 * Cancel while a draft.
 */
export default function ReceiptDetail() {
  const { t } = useTranslation()
  const { receiptId } = useParams()
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState(false)
  const key = [...procurementKey, 'receipt', receiptId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`procurement/receipts/${receiptId}`) })
  const action = useDocumentAction(key)
  const receipt = query.data?.data
  const back = <Back to="/procurement/receipts">{t('procurement.receipts.back')}</Back>
  if (!receipt) return <DetailState query={query} back={back} notFound={t('procurement.receipt.notFound')} />

  const company = receipt.company?.id
  const columns = [
    { key: 'line_no', label: t('procurement.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    { key: 'item', label: t('procurement.columns.item'), wrap: true, render: (line) => <LineName line={line} /> },
    { key: 'ordered', label: t('procurement.columns.ordered'), align: 'end', render: (line) => <Qty value={line.ordered_qty} unit={line.uom?.code} /> },
    { key: 'received', label: t('procurement.columns.received'), align: 'end', render: (line) => <Qty value={line.received_qty} /> },
    {
      key: 'rejected',
      label: t('procurement.columns.rejected'),
      align: 'end',
      wrap: true,
      render: (line) => (
        <span className="flex flex-col items-end">
          <Qty value={line.rejected_qty} />
          {line.reject_reason ? <span className="text-caption text-ink-muted">{line.reject_reason}</span> : null}
        </span>
      ),
    },
    { key: 'accepted', label: t('procurement.columns.accepted'), align: 'end', render: (line) => <Qty value={line.accepted_qty} /> },
    {
      key: 'tracking',
      label: t('procurement.columns.batchOrSerials'),
      wrap: true,
      render: (line) =>
        line.batch_number ? (
          <span className="flex flex-col">
            <span>{line.batch_number}</span>
            {line.expires_on ? (
              <span className="text-caption text-ink-muted">
                <CalendarDate value={line.expires_on} />
              </span>
            ) : null}
          </span>
        ) : line.serials?.length ? (
          <span className="font-mono text-caption">{line.serials.join(', ')}</span>
        ) : (
          ''
        ),
    },
    { key: 'weight', label: t('procurement.fields.weight'), align: 'end', render: (line) => <Qty value={line.weight} /> },
    { key: 'base_value', label: t('procurement.columns.baseValue'), align: 'end', render: (line) => <Amount value={line.base_value} /> },
    { key: 'landed', label: t('procurement.columns.landed'), align: 'end', render: (line) => <Amount value={line.landed} /> },
    {
      key: 'on_time',
      label: t('procurement.columns.onTime'),
      render: (line) =>
        line.on_time === null || line.on_time === undefined ? <span className="text-ink-muted">—</span> : <StatusBadge tone={line.on_time ? 'success' : 'warning'}>{t(line.on_time ? 'procurement.receipt.onTime' : 'procurement.receipt.late')}</StatusBadge>,
    },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('procurement.receipt.eyebrow')}
        title={receipt.number ?? t('procurement.draftNumber')}
        description={<Status status={receipt.status} />}
        actions={
          <>
            {receipt.can?.cancel ? (
              <Button variant="danger" onClick={() => setCancelling(true)}>
                {t('procurement.receipt.cancel')}
              </Button>
            ) : null}
            {receipt.can?.post ? (
              <Button variant="pay" loading={action.isPending} onClick={() => action.mutate({ path: `procurement/receipts/${receipt.id}/post`, done: 'procurement.receipt.posted' })}>
                {t('procurement.receipt.post')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        <Card title={t('procurement.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('procurement.fields.order')}>
              {receipt.order ? (
                <Link to={`/procurement/orders/${receipt.order.id}`} className="text-primary hover:text-primary-hover">
                  {receipt.order.number}
                </Link>
              ) : (
                '—'
              )}
            </Detail>
            <Detail label={t('procurement.fields.supplier')}>{receipt.party?.name}</Detail>
            <Detail label={t('procurement.fields.location')}>
              <span className="flex flex-col">
                <span>{receipt.location?.name}</span>
                <span className="text-caption text-ink-muted">{[receipt.company?.name, receipt.branch?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            <Detail label={t('procurement.columns.receivedAt')}>
              <When value={receipt.received_at} companyId={company} />
            </Detail>
            {receipt.delivery_note ? <Detail label={t('procurement.fields.deliveryNote')}>{receipt.delivery_note}</Detail> : null}
            <Detail label={t('procurement.columns.value')}>
              <Amount value={receipt.value} />
            </Detail>
            <Detail label={t('procurement.columns.baseValue')}>
              <Amount value={receipt.base_value} />
            </Detail>
            <Detail label={t('procurement.columns.landed')}>
              <Amount value={receipt.landed} />
            </Detail>
            {receipt.posted_at ? (
              <Detail label={t('procurement.receipt.postedAt')}>
                <When value={receipt.posted_at} companyId={company} />
              </Detail>
            ) : null}
            {receipt.note ? <Detail label={t('procurement.fields.note')}>{receipt.note}</Detail> : null}
            {receipt.cancelled_at ? (
              <Detail label={t('procurement.fields.cancelled')}>
                <span className="flex flex-col">
                  <When value={receipt.cancelled_at} companyId={company} />
                  <span className="text-caption text-ink-muted">{receipt.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('procurement.lines.title')}>
          <DataTable caption={t('procurement.lines.title')} columns={columns} rows={receipt.lines ?? []} />
        </Card>
      </div>
      {receipt.can?.cancel ? (
        <CancelDialog
          open={cancelling}
          title={t('procurement.receipt.cancelTitle', { number: receipt.number ?? t('procurement.draftNumber') })}
          help={t('procurement.receipt.cancelHelp')}
          path={`procurement/receipts/${receipt.id}/cancel`}
          onClose={() => setCancelling(false)}
          onDone={async (response) => {
            setCancelling(false)
            if (response?.data) queryClient.setQueryData(key, response)
            toast.success(t('procurement.receipt.cancelled'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
    </>
  )
}
