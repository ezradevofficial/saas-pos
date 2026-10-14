import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { Alert, Button, Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, CalendarDate, CancelDialog, Detail, DetailState, LineName, Qty, Status, When } from './ProcurementParts'
import { useDocumentAction } from './procurementHelpers'
import { procurementKey } from './procurementData'

/**
 * PRC-17: one landed cost: its charges (in their currencies and in base
 * currency once posted), the GRNs it applies to, and the allocation table
 * (what posting would allocate to each receipt line for a draft, what it
 * allocated once posted; the parts add up to the total exactly), or why
 * it cannot be allocated; Edit, Post and Cancel while a draft.
 */
export default function LandedCostDetail() {
  const { t } = useTranslation()
  const { landedCostId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState(false)
  const key = [...procurementKey, 'landed-cost', landedCostId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`procurement/landed-costs/${landedCostId}`) })
  const action = useDocumentAction(key)
  const landedCost = query.data?.data
  const back = <Back to="/procurement/landed-costs">{t('procurement.landedCosts.back')}</Back>
  if (!landedCost) return <DetailState query={query} back={back} notFound={t('procurement.landedCost.notFound')} />

  const company = landedCost.company?.id
  const allocation = landedCost.allocation ?? { lines: [] }
  const chargeColumns = [
    { key: 'description', label: t('procurement.fields.chargeDescription'), wrap: true, render: (charge) => charge.description },
    { key: 'party', label: t('procurement.fields.chargeSupplier'), render: (charge) => charge.party?.name ?? '' },
    { key: 'amount', label: t('procurement.fields.amount'), align: 'end', render: (charge) => <Amount value={charge.amount} /> },
    { key: 'base_amount', label: t('procurement.columns.baseAmount'), align: 'end', render: (charge) => <Amount value={charge.base_amount} /> },
  ]
  const allocationColumns = [
    {
      key: 'receipt',
      label: t('procurement.columns.receipt'),
      render: (line) =>
        line.receipt?.id ? (
          <Link to={`/procurement/receipts/${line.receipt.id}`} className="text-primary hover:text-primary-hover">
            {line.receipt.number ?? t('procurement.draftNumber')}
          </Link>
        ) : (
          ''
        ),
    },
    { key: 'item', label: t('procurement.columns.item'), wrap: true, render: (line) => (line.item ? <LineName line={line} /> : '') },
    { key: 'accepted', label: t('procurement.columns.accepted'), align: 'end', render: (line) => <Qty value={line.accepted_qty} /> },
    { key: 'basis', label: t('procurement.columns.basis', { method: t(`procurement.methods.${landedCost.method}`) }), align: 'end',
      // PRC-17: by value the basis is the goods' base value in minor units, shown as money; otherwise a quantity or weight.
      render: (line) =>
        landedCost.method === 'value' && allocation.base_currency ? (
          <Amount value={{ amount_minor: String(line.basis).split('.')[0], currency: allocation.base_currency }} />
        ) : (
          <Qty value={line.basis} />
        ),
    },
    { key: 'amount', label: t('procurement.columns.allocated'), align: 'end', render: (line) => <Amount value={line.amount} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('procurement.landedCost.eyebrow')}
        title={landedCost.number ?? t('procurement.draftNumber')}
        description={<Status status={landedCost.status} />}
        actions={
          <>
            {landedCost.can?.edit ? <Button icon="edit" onClick={() => navigate(`/procurement/landed-costs/${landedCost.id}/edit`)}>{t('procurement.actions.edit')}</Button> : null}
            {landedCost.can?.cancel ? (
              <Button variant="danger" onClick={() => setCancelling(true)}>
                {t('procurement.landedCost.cancel')}
              </Button>
            ) : null}
            {landedCost.can?.post ? (
              <Button variant="pay" loading={action.isPending} disabled={Boolean(allocation.error)} onClick={() => action.mutate({ path: `procurement/landed-costs/${landedCost.id}/post`, done: 'procurement.landedCost.posted' })}>
                {t('procurement.landedCost.post')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        <Card title={t('procurement.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('procurement.fields.branch')}>{[landedCost.company?.name, landedCost.branch?.name].filter(Boolean).join(' · ')}</Detail>
            <Detail label={t('procurement.fields.costDate')}>
              <CalendarDate value={landedCost.cost_date} />
            </Detail>
            <Detail label={t('procurement.fields.method')}>{t(`procurement.methods.${landedCost.method}`, { defaultValue: landedCost.method })}</Detail>
            {landedCost.description ? <Detail label={t('procurement.fields.description')}>{landedCost.description}</Detail> : null}
            <Detail label={t('procurement.columns.baseTotal')}>
              <Amount value={landedCost.base_total ?? allocation.total} />
            </Detail>
            <Detail label={t('procurement.fields.receipts')}>
              <span className="flex flex-wrap gap-2">
                {(landedCost.receipts ?? []).map((receipt) => (
                  <Link key={receipt.id} to={`/procurement/receipts/${receipt.id}`} className="text-primary hover:text-primary-hover">
                    {receipt.number ?? t('procurement.draftNumber')}
                  </Link>
                ))}
              </span>
            </Detail>
            {landedCost.posted_at ? (
              <Detail label={t('procurement.landedCost.postedAt')}>
                <When value={landedCost.posted_at} companyId={company} />
              </Detail>
            ) : null}
            {landedCost.cancelled_at ? (
              <Detail label={t('procurement.fields.cancelled')}>
                <span className="flex flex-col">
                  <When value={landedCost.cancelled_at} companyId={company} />
                  <span className="text-caption text-ink-muted">{landedCost.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('procurement.landedCost.charges')}>
          <DataTable caption={t('procurement.landedCost.charges')} columns={chargeColumns} rows={landedCost.charges ?? []} />
        </Card>
        <Card
          title={t('procurement.landedCost.allocationTitle')}
          subtitle={t(landedCost.status === 'posted' ? 'procurement.landedCost.allocationPosted' : 'procurement.landedCost.allocationPreview')}
          actions={allocation.total ? <Amount value={allocation.total} /> : null}
        >
          <div className="flex flex-col gap-4">
            {allocation.error ? (
              <Alert tone="danger" title={t('procurement.landedCost.allocationError')}>
                {allocation.error.message}
              </Alert>
            ) : null}
            <DataTable caption={t('procurement.landedCost.allocationTitle')} columns={allocationColumns} rows={(allocation.lines ?? []).map((line) => ({ ...line, id: line.receipt_line_id }))} emptyText={t('procurement.landedCost.noAllocation')} />
          </div>
        </Card>
      </div>
      {landedCost.can?.cancel ? (
        <CancelDialog
          open={cancelling}
          title={t('procurement.landedCost.cancelTitle', { number: landedCost.number ?? t('procurement.draftNumber') })}
          help={t('procurement.landedCost.cancelHelp')}
          path={`procurement/landed-costs/${landedCost.id}/cancel`}
          onClose={() => setCancelling(false)}
          onDone={async (response) => {
            setCancelling(false)
            if (response?.data) queryClient.setQueryData(key, response)
            toast.success(t('procurement.landedCost.cancelled'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
    </>
  )
}
