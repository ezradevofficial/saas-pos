import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, Detail, DetailState, Flags, Litres, RecordStatus, When } from './FuelParts'
import { fuelKey, useStations, currencyAt } from './fuelData'
import { SettlementFigures, TenderTable } from './SettlementParts'

/** FUEL-09 to FUEL-13: one shift: each nozzle's readings and litres (meter against recorded sales), and each attendant's settlement with tenders. */
export default function ShiftDetail() {
  const { t } = useTranslation()
  const { shiftId } = useParams()
  const { stations } = useStations()
  const query = useQuery({ queryKey: [...fuelKey, 'shift', shiftId], queryFn: () => api.get(`fuel/shifts/${shiftId}`) })
  const shift = query.data?.data
  const back = <Back to="/fuel/shifts">{t('fuel.shift.back')}</Back>
  if (!shift) return <DetailState query={query} back={back} notFound={t('fuel.shift.notFound')} />

  const currency = shift.settlements?.[0]?.currency ?? currencyAt(stations, shift.location?.id)
  const nozzleColumns = [
    { key: 'nozzle', label: t('fuel.fields.nozzle'), render: (row) => <span className="font-medium text-ink">{row.nozzle?.label}</span> },
    { key: 'attendant', label: t('fuel.columns.attendant'), render: (row) => row.attendant?.name ?? '' },
    { key: 'opening', label: t('fuel.columns.opening'), align: 'end', render: (row) => <Litres value={row.opening_litres} /> },
    { key: 'closing', label: t('fuel.columns.closing'), align: 'end', render: (row) => <Litres value={row.closing_litres} /> },
    { key: 'tests', label: t('fuel.columns.tests'), align: 'end', render: (row) => <Litres value={row.test_litres} /> },
    { key: 'meter', label: t('fuel.columns.meterLitres'), align: 'end', render: (row) => <Litres value={row.meter_litres} className="font-medium text-ink" /> },
    { key: 'recorded', label: t('fuel.columns.recordedLitres'), align: 'end', render: (row) => <Litres value={row.recorded_litres} /> },
    { key: 'unrecorded', label: t('fuel.columns.unrecordedLitres'), align: 'end', render: (row) => <Litres value={row.unrecorded_litres} /> },
    { key: 'expected', label: t('fuel.columns.expected'), align: 'end', render: (row) => <Amount minor={row.expected_minor} currency={currency} /> },
    { key: 'flags', label: t('fuel.columns.flags'), render: (row) => <Flags flags={row.flags} /> },
  ]
  return (
    <>
      {back}
      <PageHeader eyebrow={t('fuel.shift.eyebrow')} title={t('fuel.shift.title', { station: shift.location?.name })} description={<RecordStatus status={shift.status} />} />
      <div className="flex flex-col gap-5">
        <Card title={t('fuel.shift.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Detail label={t('fuel.columns.openedAt')}>
              <span className="flex flex-col">
                <When value={shift.opened_at} />
                <span className="text-caption text-ink-muted">{shift.opened_by?.name}</span>
              </span>
            </Detail>
            <Detail label={t('fuel.columns.closedAt')}>
              <span className="flex flex-col">
                <When value={shift.closed_at} />
                <span className="text-caption text-ink-muted">{shift.closed_by?.name}</span>
              </span>
            </Detail>
            <Detail label={t('fuel.columns.flags')}>
              <Flags flags={shift.flags} />
            </Detail>
            {shift.note ? <Detail label={t('fuel.fields.note')}>{shift.note}</Detail> : null}
          </dl>
        </Card>
        <Card title={t('fuel.shift.nozzles')} subtitle={t('fuel.shift.nozzlesHelp')}>
          <DataTable caption={t('fuel.shift.nozzles')} columns={nozzleColumns} rows={shift.nozzles ?? []} emptyText={t('fuel.shift.noNozzles')} />
        </Card>
        {(shift.settlements ?? []).length === 0 ? <Card title={t('fuel.shift.settlements')}><p className="text-body text-ink-muted">{t('fuel.shift.noSettlements')}</p></Card> : null}
        {(shift.settlements ?? []).map((settlement) => (
          <Card
            key={settlement.id}
            title={`${settlement.attendant?.name ?? ''} · ${settlement.number ?? t('fuel.settlement.open')}`}
            actions={
              <Link to={`/fuel/settlements/${settlement.id}`} className="text-label text-primary hover:text-primary-hover">
                {t('fuel.shift.openSettlement')}
              </Link>
            }
          >
            <div className="flex flex-col gap-5">
              <SettlementFigures settlement={settlement} />
              <TenderTable tenders={settlement.tenders} currency={settlement.currency} />
            </div>
          </Card>
        ))}
      </div>
    </>
  )
}
