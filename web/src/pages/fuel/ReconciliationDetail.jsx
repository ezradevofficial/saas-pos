import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { Alert, Card } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Back, Detail, DetailState, DocumentFlow, Flags, Litres, RecordStatus, When } from './FuelParts'
import { fuelKey } from './fuelData'

/** The figures of a reconciliation: baseline, deliveries, sales, expected, dip and variance against the tolerance. */
export function ReconciliationFigures({ reconciliation: r }) {
  const { t } = useTranslation()
  return (
    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Detail label={t('fuel.reconciliation.previous')}>
        <span className="flex flex-col">
          <Litres value={r.previous_litres} />
          <span className="text-caption text-ink-muted">
            <When value={r.baseline_at} />
          </span>
        </span>
      </Detail>
      <Detail label={t('fuel.reconciliation.deliveries')}>
        <Litres value={r.deliveries_litres} />
      </Detail>
      <Detail label={t('fuel.reconciliation.sales')}>
        <Litres value={r.sales_litres} />
      </Detail>
      <Detail label={t('fuel.columns.expectedLitres')}>
        <Litres value={r.expected_litres} />
      </Detail>
      <Detail label={t('fuel.columns.dipLitres')}>
        <Litres value={r.dip_litres} />
      </Detail>
      <Detail label={t('fuel.columns.varianceLitres')}>
        <Litres value={r.variance_litres} signed className="font-medium" />
      </Detail>
      <Detail label={t('fuel.reconciliation.tolerance')}>
        <Litres value={r.tolerance_litres} />
      </Detail>
      <Detail label={t('fuel.columns.flags')}>
        <Flags flags={r.flags} />
      </Detail>
    </dl>
  )
}

/** FUEL-24, FUEL-26: one tank reconciliation and where its approval is. */
export default function ReconciliationDetail() {
  const { t } = useTranslation()
  const { reconciliationId } = useParams()
  const query = useQuery({ queryKey: [...fuelKey, 'reconciliation', reconciliationId], queryFn: () => api.get(`fuel/reconciliations/${reconciliationId}`) })
  const reconciliation = query.data?.data
  const back = <Back to="/fuel/reconciliations">{t('fuel.reconciliation.back')}</Back>
  if (!reconciliation) return <DetailState query={query} back={back} notFound={t('fuel.reconciliation.notFound')} />

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('fuel.reconciliation.eyebrow')}
        title={reconciliation.number}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <RecordStatus status={reconciliation.status} />
            <span>{[reconciliation.tank ? `${reconciliation.tank.code} · ${reconciliation.tank.name}` : '', reconciliation.location?.name].filter(Boolean).join(' · ')}</span>
          </span>
        }
      />
      <div className="flex flex-col gap-5">
        <Card title={t('fuel.reconciliation.figures')} subtitle={t('fuel.reconciliation.figuresHelp')}>
          <div className="flex flex-col gap-5">
            <ReconciliationFigures reconciliation={reconciliation} />
            {reconciliation.decided_by ? (
              <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Detail label={t('fuel.settlement.decidedBy')}>
                  <span className="flex flex-col">
                    <span>{reconciliation.decided_by.name}</span>
                    <span className="text-caption text-ink-muted">
                      <When value={reconciliation.decided_at} />
                    </span>
                  </span>
                </Detail>
                {reconciliation.posted_at ? (
                  <Detail label={t('fuel.reconciliation.postedAt')}>
                    <When value={reconciliation.posted_at} />
                  </Detail>
                ) : null}
              </dl>
            ) : null}
          </div>
        </Card>
        {reconciliation.status === 'pending_approval' ? (
          <Alert tone="info" title={t('fuel.reconciliation.whoApproves')}>
            {t('fuel.reconciliation.whoApprovesHelp')}{' '}
            <Link to="/approvals" className="text-primary hover:text-primary-hover">
              {t('fuel.settlement.openInbox')}
            </Link>
          </Alert>
        ) : null}
        <DocumentFlow type="fuel.tank_variance" id={reconciliation.id} />
      </div>
    </>
  )
}
