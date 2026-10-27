import { useTranslation } from 'react-i18next'
import { DataTable } from '@/components/ds'
import { Amount, Detail, Flags, Litres, RecordStatus, When } from './FuelParts'

/** FUEL-12: what an attendant declared, what the sales recorded and what was collected, per tender. */
export function TenderTable({ tenders, currency }) {
  const { t } = useTranslation()
  const columns = [
    { key: 'kind', label: t('fuel.columns.tender'), render: (row) => t(`fuel.tenders.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'declared', label: t('fuel.columns.declared'), align: 'end', render: (row) => <Amount minor={row.declared_minor} currency={currency} /> },
    { key: 'recorded', label: t('fuel.columns.recorded'), align: 'end', render: (row) => <Amount minor={row.recorded_minor} currency={currency} /> },
    { key: 'collected', label: t('fuel.columns.collected'), align: 'end', render: (row) => <Amount minor={row.collected_minor} currency={currency} className="font-medium text-ink" /> },
  ]
  return <DataTable caption={t('fuel.settlement.tenders')} columns={columns} rows={(tenders ?? []).map((row) => ({ ...row, id: row.kind }))} emptyText={t('fuel.settlement.noTenders')} />
}

/** FUEL-13: the figures of one settlement as a definition list. */
export function SettlementFigures({ settlement }) {
  const { t } = useTranslation()
  return (
    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Detail label={t('fuel.columns.status')}>
        <RecordStatus status={settlement.status} />
      </Detail>
      <Detail label={t('fuel.columns.expected')}>
        <Amount minor={settlement.expected_minor} currency={settlement.currency} />
      </Detail>
      <Detail label={t('fuel.columns.collected')}>
        <Amount minor={settlement.collected_minor} currency={settlement.currency} />
      </Detail>
      <Detail label={t('fuel.columns.variance')}>
        <Amount minor={settlement.variance_minor} currency={settlement.currency} signed className="font-medium" />
      </Detail>
      <Detail label={t('fuel.columns.meterLitres')}>
        <Litres value={settlement.meter_litres} />
      </Detail>
      <Detail label={t('fuel.columns.recordedLitres')}>
        <Litres value={settlement.recorded_litres} />
      </Detail>
      <Detail label={t('fuel.columns.litresVariance')}>
        <Litres value={settlement.litres_variance} signed />
      </Detail>
      <Detail label={t('fuel.columns.flags')}>
        <Flags flags={settlement.flags} />
      </Detail>
      {settlement.decided_by ? (
        <Detail label={t('fuel.settlement.decidedBy')}>
          <span className="flex flex-col">
            <span>{settlement.decided_by.name}</span>
            <span className="text-caption text-ink-muted">
              <When value={settlement.decided_at} />
            </span>
          </span>
        </Detail>
      ) : null}
    </dl>
  )
}
