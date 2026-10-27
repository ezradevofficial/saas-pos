import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { Back, Detail, DetailState, Qty, ReasonDialog, RecordStatus } from './PharmacyParts'
import { pharmacyKey, RECALL_LIFT } from './pharmacyData'

/**
 * PHA-22, PHA-23: one recall: the batch and why, how many lots it blocks, the
 * customers who bought it (refunds and voids netted; sales without a named
 * customer are counted apart) and Lift with a reason.
 */
export default function RecallDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { recallId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [lifting, setLifting] = useState(false)
  const key = [...pharmacyKey, 'recall', recallId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`pharmacy/recalls/${recallId}`) })
  const customers = useQuery({ queryKey: [...key, 'customers'], queryFn: () => api.get(`pharmacy/recalls/${recallId}/customers`), enabled: Boolean(query.data?.data) })
  const recall = query.data?.data
  const back = <Back to="/pharmacy/recalls">{t('pharmacy.recalls.back')}</Back>

  if (!recall) return <DetailState query={query} back={back} notFound={t('pharmacy.recall.notFound')} />

  const buyers = customers.data?.data?.customers ?? []
  const unattributed = customers.data?.data?.unattributed
  const columns = [
    { key: 'customer', label: t('pharmacy.recall.customer'), render: (row) => <span className="text-ink">{row.customer?.name}</span> },
    { key: 'phone', label: t('pharmacy.recall.phone'), render: (row) => <span className="tabular-nums">{row.customer?.phone ?? ''}</span> },
    { key: 'receipt', label: t('pharmacy.columns.receipt_number'), render: (row) => row.receipt_number ?? '' },
    { key: 'qty', label: t('pharmacy.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty} /> },
    { key: 'sold_at', label: t('pharmacy.recall.soldAt'), render: (row) => <span className="tabular-nums">{formatDate(row.sold_at, locale)}</span> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={recall.item?.code}
        title={`${recall.item?.name} · ${recall.batch_number}`}
        description={<RecordStatus status={recall.status} />}
        actions={
          recall.status === 'open' && can(RECALL_LIFT) ? (
            <Button onClick={() => setLifting(true)}>{t('pharmacy.recall.lift')}</Button>
          ) : null
        }
      />
      {recall.status === 'open' ? <Alert tone="danger" title={t('pharmacy.recall.openNote', { count: recall.lots_count })} /> : null}
      <div className="flex flex-col gap-5">
        <Card title={t('pharmacy.recall.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('pharmacy.recalls.reason')}>{recall.reason}</Detail>
            <Detail label={t('pharmacy.recalls.reference')}>{recall.reference ?? <span className="text-ink-muted">—</span>}</Detail>
            <Detail label={t('pharmacy.recalls.issuedAt')}>
              <span className="flex flex-col">
                <span className="tabular-nums">{formatDate(recall.issued_at, locale)}</span>
                <span className="text-caption text-ink-muted">{recall.issued_by?.name}</span>
              </span>
            </Detail>
            {recall.lifted_at ? (
              <Detail label={t('pharmacy.recall.liftedAt')}>
                <span className="flex flex-col">
                  <span className="tabular-nums">{formatDate(recall.lifted_at, locale)}</span>
                  <span className="text-caption text-ink-muted">{recall.lift_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('pharmacy.recall.customers')} subtitle={t('pharmacy.recall.customersHelp')}>
          <div className="flex flex-col gap-3">
            <DataTable caption={t('pharmacy.recall.customers')} columns={columns} rows={buyers} loading={customers.isPending} emptyText={t('pharmacy.recall.noCustomers')} />
            {unattributed && unattributed.sales > 0 ? <p className="text-body text-ink-muted">{t('pharmacy.recall.unattributed', { count: unattributed.sales, qty: unattributed.qty })}</p> : null}
          </div>
        </Card>
      </div>
      <ReasonDialog
        open={lifting}
        title={t('pharmacy.recall.liftTitle')}
        help={t('pharmacy.recall.liftHelp')}
        label={t('pharmacy.lot.reasonLabel')}
        action={t('pharmacy.recall.lift')}
        path={`pharmacy/recalls/${recallId}/lift`}
        onClose={() => setLifting(false)}
        onDone={async (response) => {
          setLifting(false)
          if (response?.data) queryClient.setQueryData(key, response)
          toast.success(t('pharmacy.recall.lifted'))
          await queryClient.invalidateQueries({ queryKey: pharmacyKey })
        }}
      />
    </>
  )
}
