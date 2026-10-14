import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Button, Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, CalendarDate, CancelDialog, Detail, DetailState, DocumentFlow, LineName, Qty, Status, When } from './ProcurementParts'
import { useDocumentAction } from './procurementHelpers'
import { PO_CREATE, procurementKey, RFQ_CREATE, TENDER_CREATE } from './procurementData'

/**
 * PRC-02, WF-10: one requisition: where and why, its lines with estimated
 * prices, where its approval flow is, Submit and Edit while a draft,
 * Cancel while it waits, and, once approved, the next document: an RFQ,
 * a tender or a purchase order.
 */
export default function RequisitionDetail() {
  const { t } = useTranslation()
  const { requisitionId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [cancelling, setCancelling] = useState(false)
  const key = [...procurementKey, 'requisition', requisitionId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`procurement/requisitions/${requisitionId}`) })
  const action = useDocumentAction(key)
  const requisition = query.data?.data
  const back = <Back to="/procurement/requisitions">{t('procurement.requisitions.back')}</Back>
  if (!requisition) return <DetailState query={query} back={back} notFound={t('procurement.requisition.notFound')} />

  const company = requisition.company?.id
  const from = `?requisition=${requisition.id}`
  const columns = [
    { key: 'line_no', label: t('procurement.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    { key: 'item', label: t('procurement.columns.item'), wrap: true, render: (line) => <LineName line={line} /> },
    { key: 'qty', label: t('procurement.columns.qty'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
    { key: 'price', label: t('procurement.columns.estimatedUnitPrice'), align: 'end', render: (line) => <Amount value={line.estimated_unit_price} /> },
    { key: 'dimensions', label: t('procurement.columns.dimensions'), wrap: true, render: (line) => [line.cost_centre?.name, line.project?.name].filter(Boolean).join(' · ') },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('procurement.requisition.eyebrow')}
        title={requisition.number ?? t('procurement.draftNumber')}
        description={<Status status={requisition.status} />}
        actions={
          <>
            {requisition.can?.edit ? <Button icon="edit" onClick={() => navigate(`/procurement/requisitions/${requisition.id}/edit`)}>{t('procurement.actions.edit')}</Button> : null}
            {requisition.can?.cancel ? (
              <Button variant="danger" onClick={() => setCancelling(true)}>
                {t('procurement.requisition.cancel')}
              </Button>
            ) : null}
            {requisition.can?.order && can(RFQ_CREATE) ? <Button onClick={() => navigate(`/procurement/rfqs/new${from}`)}>{t('procurement.requisition.startRfq')}</Button> : null}
            {requisition.can?.order && can(TENDER_CREATE) ? <Button onClick={() => navigate(`/procurement/tenders/new${from}`)}>{t('procurement.requisition.startTender')}</Button> : null}
            {requisition.can?.order && can(PO_CREATE) ? (
              <Button variant="primary" onClick={() => navigate(`/procurement/orders/new${from}`)}>
                {t('procurement.requisition.startOrder')}
              </Button>
            ) : null}
            {requisition.can?.submit ? (
              <Button variant="pay" loading={action.isPending} onClick={() => action.mutate({ path: `procurement/requisitions/${requisition.id}/submit`, done: 'procurement.requisition.submitted' })}>
                {t('procurement.requisition.submit')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        <Card title={t('procurement.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('procurement.fields.branch')}>
              <span className="flex flex-col">
                <span>{requisition.branch?.name}</span>
                <span className="text-caption text-ink-muted">{[requisition.company?.name, requisition.location?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            {requisition.department ? <Detail label={t('procurement.fields.department')}>{requisition.department.name}</Detail> : null}
            <Detail label={t('procurement.fields.requestedBy')}>
              <span className="flex flex-col">
                <span>{requisition.requested_by?.name}</span>
                <span className="text-caption text-ink-muted">
                  <When value={requisition.created_at} companyId={company} />
                </span>
              </span>
            </Detail>
            <Detail label={t('procurement.fields.neededBy')}>
              <CalendarDate value={requisition.needed_by} />
            </Detail>
            <Detail label={t('procurement.columns.estimatedTotal')}>
              <Amount value={requisition.estimated_total} />
            </Detail>
            {requisition.purpose ? <Detail label={t('procurement.fields.purpose')}>{requisition.purpose}</Detail> : null}
            {requisition.cancelled_at ? (
              <Detail label={t('procurement.fields.cancelled')}>
                <span className="flex flex-col">
                  <When value={requisition.cancelled_at} companyId={company} />
                  <span className="text-caption text-ink-muted">{requisition.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('procurement.lines.title')}>
          <DataTable caption={t('procurement.lines.title')} columns={columns} rows={requisition.lines ?? []} />
        </Card>
        <DocumentFlow type="procurement.requisition" id={requisition.id} started={requisition.status !== 'draft'} />
      </div>
      {requisition.can?.cancel ? (
        <CancelDialog
          open={cancelling}
          title={t('procurement.requisition.cancelTitle', { number: requisition.number ?? t('procurement.draftNumber') })}
          help={t('procurement.requisition.cancelHelp')}
          path={`procurement/requisitions/${requisition.id}/cancel`}
          onClose={() => setCancelling(false)}
          onDone={async (response) => {
            setCancelling(false)
            if (response?.data) queryClient.setQueryData(key, response)
            toast.success(t('procurement.requisition.cancelled'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
    </>
  )
}
