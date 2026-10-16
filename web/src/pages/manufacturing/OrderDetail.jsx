import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable, StageTracker } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, Back, CalendarDate, Detail, DetailState, DocumentFlow, InventoryOffNote, MfgStatus, OrderPrints, Qty } from './ManufacturingParts'
import { CancelDialog, CloseDialog, IssueDialog, ReportDialog, ReturnDialog } from './OrderDialogs'
import { kindLabel, manufacturingKey, ORDER_STAGES, statusLabel } from './manufacturingData'

/** MFG-10 to MFG-14: the stage an order has reached, and whether it stopped there. */
function stageOf(order) {
  switch (order.status) {
    case 'planned':
    case 'pending_approval':
      return { stage: 'planned', blocked: false }
    case 'rejected':
      return { stage: 'planned', blocked: true }
    case 'cancelled':
      return { stage: order.released_at ? 'released' : 'planned', blocked: true }
    default:
      return { stage: order.status, blocked: false }
  }
}

/** An item's name over its code. */
function ItemCell({ item }) {
  return (
    <span className="flex flex-col">
      <span className="text-ink">{item?.name}</span>
      <span className="font-mono text-caption text-ink-muted">{item?.code}</span>
    </span>
  )
}

/**
 * MFG-10 to MFG-16, MFG-24: one production order: its product, place,
 * quantities and dates, where it is (stage tracker, release flow), its
 * materials (required, standard, issued, returned, consumed, still to
 * issue), outputs, issues, returns and reports, its costs for cost
 * holders, and the actions the reader may take.
 */
export default function OrderDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { orderId } = useParams()
  const queryClient = useQueryClient()
  const companyOf = useCompanyOfRecord()
  const [dialog, setDialog] = useState(null)
  const key = [...manufacturingKey, 'order', orderId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`manufacturing/orders/${orderId}`) })
  const order = query.data?.data
  const release = useMutation({
    mutationFn: () => api.post(`manufacturing/orders/${orderId}/release`, {}),
    onSuccess: async (response) => {
      toast.success(response?.data?.status === 'pending_approval' ? t('manufacturing.order.sentForApproval') : t('manufacturing.order.released'))
      if (response?.data) queryClient.setQueryData(key, response)
      await queryClient.invalidateQueries({ queryKey: manufacturingKey })
    },
  })
  const back = <Back to="/manufacturing/orders">{t('manufacturing.orders.back')}</Back>

  if (!order) return <DetailState query={query} back={back} notFound={t('manufacturing.orders.notFound')} />

  const can = order.can ?? {}
  const unit = order.uom?.code ?? ''
  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(order.company?.id)) : '')
  const { stage, blocked } = stageOf(order)
  const done = (message) => async () => {
    setDialog(null)
    toast.success(message)
    await queryClient.invalidateQueries({ queryKey: manufacturingKey })
  }

  const materialColumns = [
    { key: 'item', label: t('manufacturing.columns.item'), wrap: true, render: (row) => <ItemCell item={row.item} /> },
    { key: 'unit', label: t('manufacturing.columns.unit'), render: (row) => row.uom?.code ?? '' },
    { key: 'required', label: t('manufacturing.columns.required'), align: 'end', render: (row) => <Qty value={row.qty_required} /> },
    { key: 'standard', label: t('manufacturing.columns.standard'), align: 'end', render: (row) => <Qty value={row.qty_standard} /> },
    { key: 'issued', label: t('manufacturing.columns.issued'), align: 'end', render: (row) => <Qty value={row.qty_issued} /> },
    { key: 'returned', label: t('manufacturing.columns.returned'), align: 'end', render: (row) => <Qty value={row.qty_returned} /> },
    { key: 'consumed', label: t('manufacturing.columns.consumed'), align: 'end', render: (row) => <Qty value={row.qty_consumed} /> },
    { key: 'open', label: t('manufacturing.columns.toIssue'), align: 'end', render: (row) => <Qty value={row.qty_open} className="font-medium" /> },
    ...(can.costs ? [{ key: 'value', label: t('manufacturing.columns.value'), align: 'end', render: (row) => <Amount value={row.value} /> }] : []),
  ]
  const outputColumns = [
    { key: 'kind', label: t('manufacturing.columns.kind'), render: (row) => kindLabel(t, row.kind) },
    { key: 'item', label: t('manufacturing.columns.item'), wrap: true, render: (row) => <ItemCell item={row.item} /> },
    { key: 'planned', label: t('manufacturing.columns.planned'), align: 'end', render: (row) => <Qty value={row.qty_planned} /> },
    { key: 'reported', label: t('manufacturing.columns.reported'), align: 'end', render: (row) => <Qty value={row.qty_reported} /> },
    { key: 'share', label: t('manufacturing.columns.costShare'), align: 'end', render: (row) => (row.kind === 'by_product' ? <Qty value={row.cost_share_percent} unit="%" /> : '') },
  ]
  const lineSummary = (lines) => (lines ?? []).map((line) => `${line.item?.name ?? ''} ${line.qty ?? line.qty_base ?? ''}${line.uom?.code ? ` ${line.uom.code}` : ''}`).join(', ')
  const issueColumns = [
    { key: 'number', label: t('manufacturing.columns.number'), render: (row) => <span className="font-medium tabular-nums">{row.number}</span> },
    { key: 'at', label: t('manufacturing.columns.issuedAt'), render: (row) => <span className="tabular-nums">{when(row.issued_at)}</span> },
    { key: 'by', label: t('manufacturing.columns.by'), render: (row) => row.issued_by?.name ?? '' },
    { key: 'lines', label: t('manufacturing.columns.lines'), wrap: true, render: (row) => lineSummary(row.lines) },
    { key: 'valued', label: t('manufacturing.columns.valued'), render: (row) => (row.valued ? t('manufacturing.yes') : t('manufacturing.notYet')) },
  ]
  const returnColumns = [
    { key: 'number', label: t('manufacturing.columns.number'), render: (row) => <span className="font-medium tabular-nums">{row.number}</span> },
    { key: 'at', label: t('manufacturing.columns.returnedAt'), render: (row) => <span className="tabular-nums">{when(row.returned_at)}</span> },
    { key: 'by', label: t('manufacturing.columns.by'), render: (row) => row.returned_by?.name ?? '' },
    { key: 'reason', label: t('manufacturing.columns.reason'), render: (row) => t(`manufacturing.returnReasons.${row.reason}`, { defaultValue: row.reason }) },
    { key: 'lines', label: t('manufacturing.columns.lines'), wrap: true, render: (row) => lineSummary(row.lines) },
  ]
  const reportColumns = [
    { key: 'number', label: t('manufacturing.columns.number'), render: (row) => <span className="font-medium tabular-nums">{row.number}</span> },
    { key: 'status', label: t('manufacturing.columns.status'), render: (row) => <MfgStatus status={row.status} /> },
    { key: 'at', label: t('manufacturing.columns.reportedAt'), render: (row) => <span className="tabular-nums">{when(row.reported_at)}</span> },
    { key: 'by', label: t('manufacturing.columns.by'), render: (row) => row.reported_by?.name ?? '' },
    { key: 'good', label: t('manufacturing.columns.good'), align: 'end', render: (row) => <Qty value={row.qty_good} unit={unit} /> },
    {
      key: 'scrap',
      label: t('manufacturing.columns.scrap'),
      align: 'end',
      render: (row) => (
        <span className="flex flex-col items-end">
          <Qty value={row.qty_scrap} unit={unit} />
          {row.scrap_reason ? <span className="text-caption text-ink-muted">{t(`manufacturing.scrapReasons.${row.scrap_reason}`, { defaultValue: row.scrap_reason })}</span> : null}
        </span>
      ),
    },
    { key: 'minutes', label: t('manufacturing.columns.labourMinutes'), align: 'end', numeric: true, render: (row) => row.labour_minutes ?? '' },
    ...(can.costs ? [{ key: 'cost', label: t('manufacturing.columns.cost'), align: 'end', render: (row) => <Amount value={row.cost} /> }] : []),
  ]
  const costs = [
    ['material_cost', t('manufacturing.costs.materials')],
    ['labour_cost', t('manufacturing.costs.labour')],
    ['overhead_cost', t('manufacturing.costs.overhead')],
    ['relieved', t('manufacturing.costs.received')],
    ['variance', t('manufacturing.costs.variance')],
  ].filter(([field]) => order[field])

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('manufacturing.order.eyebrow')}
        title={order.number}
        description={<MfgStatus status={order.status} />}
        actions={
          <div className="flex flex-wrap gap-2">
            {can.edit ? (
              <Button icon="edit" onClick={() => navigate(`/manufacturing/orders/${order.id}/edit`)}>
                {t('manufacturing.order.edit')}
              </Button>
            ) : null}
            {can.issue ? <Button onClick={() => setDialog('issue')}>{t('manufacturing.order.issue')}</Button> : null}
            {can.return ? <Button onClick={() => setDialog('return')}>{t('manufacturing.order.return')}</Button> : null}
            {can.close ? <Button onClick={() => setDialog('close')}>{t('manufacturing.order.close')}</Button> : null}
            {can.cancel ? (
              <Button variant="danger" onClick={() => setDialog('cancel')}>
                {t('manufacturing.order.cancel')}
              </Button>
            ) : null}
            {can.release ? (
              <Button variant="pay" loading={release.isPending} onClick={() => release.mutate()}>
                {t('manufacturing.order.release')}
              </Button>
            ) : null}
            {can.report ? (
              <Button variant="pay" onClick={() => setDialog('report')}>
                {t('manufacturing.order.report')}
              </Button>
            ) : null}
          </div>
        }
      />
      <div className="flex flex-col gap-5">
        <InventoryOffNote />
        {release.isError ? <Alert tone="danger" title={errorMessage(release.error)} /> : null}
        {order.ledger === 'none' ? <Alert tone="info" title={t('manufacturing.order.quantitiesOnly')} /> : null}
        <Card>
          <StageTracker stages={ORDER_STAGES.map((value) => statusLabel(t, value))} current={statusLabel(t, stage)} blocked={blocked} />
        </Card>
        <Card title={t('manufacturing.order.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('manufacturing.fields.product')}>
              <ItemCell item={order.item} />
            </Detail>
            <Detail label={t('manufacturing.fields.location')}>
              <span className="flex flex-col">
                <span>{order.location?.name}</span>
                <span className="text-caption text-ink-muted">{[order.company?.name, order.branch?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            <Detail label={t('manufacturing.columns.planned')}>
              <Qty value={order.qty} unit={unit} />
            </Detail>
            <Detail label={t('manufacturing.columns.good')}>
              <Qty value={order.qty_good} unit={unit} />
            </Detail>
            <Detail label={t('manufacturing.columns.scrap')}>
              <Qty value={order.qty_scrap} unit={unit} />
            </Detail>
            <Detail label={t('manufacturing.fields.plannedDates')}>
              <span className="flex gap-1">
                <CalendarDate value={order.planned_start} />
                <span className="text-ink-muted">–</span>
                <CalendarDate value={order.planned_end} />
              </span>
            </Detail>
            <Detail label={t('manufacturing.fields.plannedBy')}>{order.planned_by?.name}</Detail>
            {order.released_at ? (
              <Detail label={t('manufacturing.order.releasedBy')}>
                <span className="flex flex-col">
                  <span>{order.released_by?.name}</span>
                  <span className="text-caption text-ink-muted tabular-nums">{when(order.released_at)}</span>
                </span>
              </Detail>
            ) : null}
            {order.closed_at ? (
              <Detail label={t('manufacturing.order.closedBy')}>
                <span className="flex flex-col">
                  <span>{order.closed_by?.name}</span>
                  <span className="text-caption text-ink-muted tabular-nums">{when(order.closed_at)}</span>
                  {order.close_note ? <span className="text-caption text-ink-muted">{order.close_note}</span> : null}
                </span>
              </Detail>
            ) : null}
            {order.cancel_reason ? <Detail label={t('manufacturing.order.cancelReason')}>{order.cancel_reason}</Detail> : null}
            {order.note ? <Detail label={t('manufacturing.fields.note')}>{order.note}</Detail> : null}
          </dl>
        </Card>
        {costs.length ? (
          <Card title={t('manufacturing.costs.title')}>
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
              {costs.map(([field, label]) => (
                <Detail key={field} label={label}>
                  <Amount value={order[field]} className={field === 'variance' ? 'font-medium' : undefined} />
                </Detail>
              ))}
            </dl>
          </Card>
        ) : null}
        <Card title={t('manufacturing.order.materials')}>
          <DataTable caption={t('manufacturing.order.materials')} columns={materialColumns} rows={order.materials ?? []} emptyText={t('manufacturing.order.noMaterials')} />
        </Card>
        {order.outputs?.length ? (
          <Card title={t('manufacturing.order.outputs')}>
            <DataTable caption={t('manufacturing.order.outputs')} columns={outputColumns} rows={order.outputs} />
          </Card>
        ) : null}
        {order.issues?.length ? (
          <Card title={t('manufacturing.order.issues')}>
            <DataTable caption={t('manufacturing.order.issues')} columns={issueColumns} rows={order.issues} />
          </Card>
        ) : null}
        {order.returns?.length ? (
          <Card title={t('manufacturing.order.returns')}>
            <DataTable caption={t('manufacturing.order.returns')} columns={returnColumns} rows={order.returns} />
          </Card>
        ) : null}
        {order.reports?.length ? (
          <Card title={t('manufacturing.order.reports')}>
            <DataTable caption={t('manufacturing.order.reports')} columns={reportColumns} rows={order.reports} />
          </Card>
        ) : null}
        {order.status === 'pending_approval' || order.status === 'rejected' ? <DocumentFlow type="manufacturing.production_order" id={order.id} /> : null}
        {order.status !== 'cancelled' && order.status !== 'rejected' ? <OrderPrints orderId={order.id} /> : null}
      </div>
      {dialog === 'issue' ? <IssueDialog order={order} open onClose={() => setDialog(null)} onDone={done(t('manufacturing.issue.done'))} /> : null}
      {dialog === 'return' ? <ReturnDialog order={order} open onClose={() => setDialog(null)} onDone={done(t('manufacturing.return.done'))} /> : null}
      {dialog === 'report' ? <ReportDialog order={order} open onClose={() => setDialog(null)} onDone={done(t('manufacturing.report.done'))} /> : null}
      {dialog === 'close' ? <CloseDialog order={order} open onClose={() => setDialog(null)} onDone={done(t('manufacturing.order.closed'))} /> : null}
      {dialog === 'cancel' ? <CancelDialog order={order} open onClose={() => setDialog(null)} onDone={done(t('manufacturing.order.cancelled'))} /> : null}
    </>
  )
}
