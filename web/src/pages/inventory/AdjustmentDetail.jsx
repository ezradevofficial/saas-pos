import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { Alert, Button, Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, Back, CalendarDate, CancelDialog, Detail, DetailState, DocumentFlow, DocumentStatus, Qty, UnitCost } from './InventoryParts'
import { inventoryKey } from './inventoryData'

/**
 * INV-10, WF-10: one adjustment: its place, reason and note, its lines
 * (signed quantities, batches, serials, unit costs and values for
 * valuation holders), why it failed when it did, where its flow is, and
 * Cancel while it waits (`can.cancel`).
 */
export default function AdjustmentDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { adjustmentId } = useParams()
  const queryClient = useQueryClient()
  const companyOf = useCompanyOfRecord()
  const [cancelling, setCancelling] = useState(false)
  const key = [...inventoryKey, 'adjustment', adjustmentId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`inventory/adjustments/${adjustmentId}`) })
  const adjustment = query.data?.data
  const back = <Back to="/inventory/adjustments">{t('inventory.adjustments.back')}</Back>

  if (!adjustment) return <DetailState query={query} back={back} notFound={t('inventory.adjustment.notFound')} />

  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(adjustment.company?.id)) : null)

  const lineColumns = [
    { key: 'line_no', label: t('inventory.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    {
      key: 'item',
      label: t('inventory.columns.item'),
      wrap: true,
      render: (line) => (
        <span className="flex flex-col">
          <span className="text-ink">{line.item?.name}</span>
          <span className="font-mono text-caption text-ink-muted">{line.item?.code}</span>
        </span>
      ),
    },
    { key: 'qty', label: t('inventory.columns.qty'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
    {
      key: 'batch',
      label: t('inventory.columns.batchOrSerials'),
      wrap: true,
      render: (line) =>
        line.batch?.batch_number || line.batch_number ? (
          <span className="flex flex-col">
            <span>{line.batch?.batch_number ?? line.batch_number}</span>
            {line.expires_on ?? line.batch?.expires_on ? (
              <span className="text-caption text-ink-muted">
                <CalendarDate value={line.expires_on ?? line.batch?.expires_on} />
              </span>
            ) : null}
          </span>
        ) : line.serials?.length ? (
          <span className="font-mono text-caption">{line.serials.join(', ')}</span>
        ) : (
          ''
        ),
    },
    { key: 'unit_cost', label: t('inventory.columns.unitCost'), align: 'end', render: (line) => <UnitCost value={line.unit_cost} currency={line.value?.currency} /> },
    { key: 'value', label: t('inventory.columns.value'), align: 'end', render: (line) => <Amount value={line.value} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('inventory.adjustment.eyebrow')}
        title={adjustment.number}
        description={<DocumentStatus status={adjustment.status} />}
        actions={
          adjustment.can?.cancel ? (
            <Button variant="danger" onClick={() => setCancelling(true)}>
              {t('inventory.adjustment.cancel')}
            </Button>
          ) : null
        }
      />
      {adjustment.failure ? <Alert tone="danger" title={t('inventory.adjustment.failed')}>{t(`inventory.failures.${adjustment.failure}`, { defaultValue: t('inventory.adjustment.failedHelp') })}</Alert> : null}
      <div className="flex flex-col gap-5">
        <Card title={t('inventory.adjustment.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('inventory.fields.location')}>
              <span className="flex flex-col">
                <span>{adjustment.location?.name}</span>
                <span className="text-caption text-ink-muted">{[adjustment.company?.name, adjustment.branch?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            <Detail label={t('inventory.fields.reason')}>{t(`inventory.reasons.${adjustment.reason}`, { defaultValue: adjustment.reason })}</Detail>
            <Detail label={t('inventory.fields.requestedBy')}>
              <span className="flex flex-col">
                <span>{adjustment.requested_by?.name}</span>
                <span className="text-caption text-ink-muted tabular-nums">{when(adjustment.created_at)}</span>
              </span>
            </Detail>
            {adjustment.note ? <Detail label={t('inventory.fields.note')}>{adjustment.note}</Detail> : null}
            {adjustment.posted_at ? <Detail label={t('inventory.adjustment.postedAt')}>{when(adjustment.posted_at)}</Detail> : null}
            {adjustment.value_in ? (
              <Detail label={t('inventory.columns.valueIn')}>
                <Amount value={adjustment.value_in} />
              </Detail>
            ) : null}
            {adjustment.value_out ? (
              <Detail label={t('inventory.columns.valueOut')}>
                <Amount value={adjustment.value_out} />
              </Detail>
            ) : null}
            {adjustment.cancelled_at ? (
              <Detail label={t('inventory.fields.cancelled')}>
                <span className="flex flex-col">
                  <span className="tabular-nums">{when(adjustment.cancelled_at)}</span>
                  <span className="text-caption text-ink-muted">{adjustment.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('inventory.lines.title')}>
          <DataTable caption={t('inventory.lines.title')} columns={lineColumns} rows={adjustment.lines ?? []} />
        </Card>
        <DocumentFlow type="inventory.adjustment" id={adjustment.id} />
      </div>
      {adjustment.can?.cancel ? (
        <CancelDialog
          open={cancelling}
          title={t('inventory.adjustment.cancelTitle', { number: adjustment.number })}
          help={t('inventory.adjustment.cancelHelp')}
          path={`inventory/adjustments/${adjustment.id}/cancel`}
          onClose={() => setCancelling(false)}
          onDone={async (response) => {
            setCancelling(false)
            if (response?.data) queryClient.setQueryData(key, response)
            toast.success(t('inventory.adjustment.cancelled'))
            await queryClient.invalidateQueries({ queryKey: inventoryKey })
          }}
        />
      ) : null}
    </>
  )
}
