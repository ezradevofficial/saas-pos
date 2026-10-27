import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Back, CalendarDate, Detail, DetailState, LotState, Qty, ReasonDialog } from './PharmacyParts'
import { LOT_QUARANTINE, pharmacyKey, RECALL_VIEW } from './pharmacyData'

/**
 * PHA-04, PHA-05, PHA-11: one lot: its batch number, dates and state, its
 * stock at each place, and (for those who may) Quarantine and Release with
 * a reason. A lot under a recall is released by lifting the recall.
 */
export default function LotDetail() {
  const { t } = useTranslation()
  const { lotId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [action, setAction] = useState(null)
  const key = [...pharmacyKey, 'lot', lotId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`pharmacy/lots/${lotId}`) })
  const lot = query.data?.data
  const back = <Back to="/pharmacy/lots">{t('pharmacy.lot.back')}</Back>

  if (!lot) return <DetailState query={query} back={back} notFound={t('pharmacy.lot.notFound')} />

  const done = async (message) => {
    setAction(null)
    toast.success(message)
    await queryClient.invalidateQueries({ queryKey: pharmacyKey })
  }
  const stockColumns = [
    { key: 'location', label: t('pharmacy.columns.location'), render: (row) => row.location?.name },
    { key: 'qty', label: t('pharmacy.columns.qty'), align: 'end', render: (row) => <Qty value={row.qty} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={lot.item?.code}
        title={`${lot.item?.name} · ${lot.batch_number}`}
        description={<LotState state={lot.state} days={lot.days_left} />}
        actions={
          can(LOT_QUARANTINE) ? (
            lot.status === 'quarantined' ? (
              <Button disabled={Boolean(lot.recall_id)} onClick={() => setAction('release')}>
                {t('pharmacy.lot.release')}
              </Button>
            ) : (
              <Button variant="danger" onClick={() => setAction('quarantine')}>
                {t('pharmacy.lot.quarantine')}
              </Button>
            )
          ) : null
        }
      />
      {lot.recall_id ? (
        <Alert tone="danger" title={t('pharmacy.lot.recalled')}>
          {can(RECALL_VIEW) ? <Link to={`/pharmacy/recalls/${lot.recall_id}`} className="text-label text-primary hover:text-primary-hover">{t('pharmacy.lot.openRecall')}</Link> : t('pharmacy.lot.recalledHelp')}
        </Alert>
      ) : null}
      {lot.state === 'expired' ? <Alert tone="warning" title={t('pharmacy.lot.expiredHelp')} /> : null}
      <div className="flex flex-col gap-5">
        <Card title={t('pharmacy.lot.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('pharmacy.columns.batch_number')}>{lot.batch_number}</Detail>
            <Detail label={t('pharmacy.columns.manufactured_on')}>
              <CalendarDate value={lot.manufactured_on} />
            </Detail>
            <Detail label={t('pharmacy.columns.expires_on')}>
              <CalendarDate value={lot.expires_on} />
            </Detail>
            <Detail label={t('pharmacy.columns.days_left')}>
              <Qty value={String(lot.days_left)} />
            </Detail>
            <Detail label={t('pharmacy.lot.supplier')}>{lot.supplier?.name ?? <span className="text-ink-muted">—</span>}</Detail>
            <Detail label={t('pharmacy.lot.company')}>{lot.company?.name}</Detail>
            {lot.quarantine_reason ? <Detail label={t('pharmacy.lot.quarantineReason')}>{lot.quarantine_reason}</Detail> : null}
          </dl>
        </Card>
        <Card title={t('pharmacy.lot.stock')}>
          <DataTable columns={stockColumns} rows={lot.balances ?? []} caption={t('pharmacy.lot.stock')} />
        </Card>
      </div>
      <ReasonDialog
        open={action === 'quarantine'}
        title={t('pharmacy.lot.quarantineTitle')}
        help={t('pharmacy.lot.quarantineHelp')}
        label={t('pharmacy.lot.reasonLabel')}
        action={t('pharmacy.lot.quarantine')}
        path={`pharmacy/lots/${lotId}/quarantine`}
        onClose={() => setAction(null)}
        onDone={() => done(t('pharmacy.lot.quarantined'))}
      />
      <ReasonDialog
        open={action === 'release'}
        title={t('pharmacy.lot.releaseTitle')}
        help={t('pharmacy.lot.releaseHelp')}
        label={t('pharmacy.lot.reasonLabel')}
        action={t('pharmacy.lot.release')}
        path={`pharmacy/lots/${lotId}/release`}
        onClose={() => setAction(null)}
        onDone={() => done(t('pharmacy.lot.released'))}
      />
    </>
  )
}
