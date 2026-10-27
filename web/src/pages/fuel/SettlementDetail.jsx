import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { Alert, Card } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Back, Detail, DetailState, DocumentFlow, RecordStatus, When } from './FuelParts'
import { flagCodes, fuelKey } from './fuelData'
import { SettlementFigures, TenderTable } from './SettlementParts'

/**
 * FUEL-13 to FUEL-15: one settlement: figures, tenders, the litres difference, the reopened
 * mark, and where its approval is. The approval is decided in the approvals inbox (FUEL-14).
 */
export default function SettlementDetail() {
  const { t } = useTranslation()
  const { settlementId } = useParams()
  const query = useQuery({ queryKey: [...fuelKey, 'settlement', settlementId], queryFn: () => api.get(`fuel/settlements/${settlementId}`) })
  const settlement = query.data?.data
  const back = <Back to="/fuel/settlements">{t('fuel.settlement.back')}</Back>
  if (!settlement) return <DetailState query={query} back={back} notFound={t('fuel.settlement.notFound')} />

  const reopened = Boolean(settlement.reopened_at) || flagCodes(settlement.flags).includes('reopened')
  return (
    <>
      {back}
      <PageHeader eyebrow={t('fuel.settlement.eyebrow')} title={settlement.number ?? t('fuel.settlement.open')} description={<RecordStatus status={settlement.status} />} />
      {reopened ? (
        <Alert tone="warning" title={t('fuel.settlement.reopened', { version: settlement.version })}>
          {t('fuel.settlement.reopenedHelp')} {settlement.reopened_at ? <When value={settlement.reopened_at} /> : null}
        </Alert>
      ) : null}
      <div className="flex flex-col gap-5">
        <Card title={t('fuel.settlement.figures')}>
          <div className="flex flex-col gap-5">
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Detail label={t('fuel.columns.attendant')}>{settlement.attendant?.name}</Detail>
              <Detail label={t('fuel.fields.station')}>{settlement.location?.name}</Detail>
              <Detail label={t('fuel.settlement.version')}>{settlement.version}</Detail>
              <Detail label={t('fuel.settlement.shift')}>
                <Link to={`/fuel/shifts/${settlement.shift_id}`} className="text-primary hover:text-primary-hover">
                  {t('fuel.settlement.openShift')}
                </Link>
              </Detail>
            </dl>
            <SettlementFigures settlement={settlement} />
          </div>
        </Card>
        <Card title={t('fuel.settlement.tenders')} subtitle={t('fuel.settlement.tendersHelp')}>
          <TenderTable tenders={settlement.tenders} currency={settlement.currency} />
        </Card>
        {settlement.status === 'pending_approval' ? (
          <Alert tone="info" title={t('fuel.settlement.whoApproves')}>
            {t('fuel.settlement.whoApprovesHelp')}{' '}
            <Link to="/approvals" className="text-primary hover:text-primary-hover">
              {t('fuel.settlement.openInbox')}
            </Link>
          </Alert>
        ) : null}
        <DocumentFlow type="fuel.shift_variance" id={settlement.id} />
      </div>
    </>
  )
}
