import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate, useParams } from 'react-router'
import { Card, DataTable, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Figure, FilterBar, FuelList, Litres, PartyPicker, StationSelect, TankSelect, When } from './FuelParts'
import { productLabel, REPORTS, useStations, useTanks } from './fuelData'
import { reconciliationColumns } from './reconciliationColumns'

const num = (value) => String(value ?? 0)

/** Totals per currency (money) under a report. */
function Totals({ title, columns, rows, caption }) {
  if (!rows?.length) return null
  return (
    <Card title={title}>
      <DataTable caption={caption ?? title} columns={columns} rows={rows.map((row, index) => ({ ...row, id: `total-${index}` }))} />
    </Card>
  )
}

/** FUEL-34, FUEL-38: each report's columns and what comes under the table (totals, resets). */
function reportConfig(kind, t) {
  const station = { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' }
  const money = (key, label, field, strong = false) => ({ key, label, align: 'end', render: (row) => <Amount minor={row[field]} currency={row.currency} signed={field.includes('variance') || field.includes('shortage')} className={strong ? 'font-medium text-ink' : undefined} /> })
  switch (kind) {
    case 'shift-summary':
      return {
        columns: [
          { key: 'opened', label: t('fuel.columns.openedAt'), render: (row) => <span className="font-medium text-ink"><When value={row.opened_at} /></span> },
          station,
          { key: 'status', label: t('fuel.columns.status'), render: (row) => t(`fuel.states.${row.status}`, { defaultValue: row.status }) },
          { key: 'litres', label: t('fuel.columns.meterLitres'), align: 'end', render: (row) => <Litres value={row.litres} /> },
          money('expected', t('fuel.columns.expected'), 'expected_minor'),
          money('collected', t('fuel.columns.collected'), 'collected_minor'),
          money('variance', t('fuel.columns.variance'), 'variance_minor', true),
          { key: 'attendants', label: t('fuel.columns.attendants'), align: 'end', numeric: true, render: (row) => num(row.attendants) },
          { key: 'pending', label: t('fuel.reports.pending'), align: 'end', numeric: true, render: (row) => num(row.pending) },
        ],
        footer: (data) => (
          <Totals
            title={t('fuel.reports.totals')}
            columns={[
              { key: 'currency', label: t('fuel.fields.currency'), render: (row) => row.currency },
              { key: 'litres', label: t('fuel.columns.meterLitres'), align: 'end', render: (row) => <Litres value={row.litres} /> },
              money('expected', t('fuel.columns.expected'), 'expected_minor'),
              money('collected', t('fuel.columns.collected'), 'collected_minor'),
              money('variance', t('fuel.columns.variance'), 'variance_minor', true),
            ]}
            rows={data.totals}
          />
        ),
      }
    case 'attendant-variance':
      return {
        columns: [
          { key: 'attendant', label: t('fuel.columns.attendant'), render: (row) => <span className="font-medium text-ink">{row.attendant?.name}</span> },
          { key: 'currency', label: t('fuel.fields.currency'), render: (row) => row.currency },
          { key: 'settlements', label: t('fuel.reports.settlements'), align: 'end', numeric: true, render: (row) => num(row.settlements) },
          money('expected', t('fuel.columns.expected'), 'expected_minor'),
          money('collected', t('fuel.columns.collected'), 'collected_minor'),
          money('variance', t('fuel.columns.variance'), 'variance_minor', true),
          money('shortage', t('fuel.reports.shortage'), 'shortage_minor'),
          { key: 'litres', label: t('fuel.columns.litresVariance'), align: 'end', render: (row) => <Litres value={row.litres_variance} signed /> },
          { key: 'pending', label: t('fuel.reports.pending'), align: 'end', numeric: true, render: (row) => num(row.pending) },
          { key: 'approved', label: t('fuel.states.approved'), align: 'end', numeric: true, render: (row) => num(row.approved) },
          { key: 'rejected', label: t('fuel.states.rejected'), align: 'end', numeric: true, render: (row) => num(row.rejected) },
          { key: 'auto', label: t('fuel.states.auto_accepted'), align: 'end', numeric: true, render: (row) => num(row.auto_accepted) },
        ],
      }
    case 'nozzle-meter-history':
      return {
        columns: [
          { key: 'opened', label: t('fuel.columns.openedAt'), render: (row) => <span className="font-medium text-ink"><When value={row.opened_at} /></span> },
          station,
          { key: 'nozzle', label: t('fuel.fields.nozzle'), render: (row) => row.nozzle?.label ?? '' },
          { key: 'attendant', label: t('fuel.columns.attendant'), render: (row) => row.attendant?.name ?? '' },
          { key: 'opening', label: t('fuel.columns.opening'), align: 'end', render: (row) => <Litres value={row.opening_litres} /> },
          { key: 'closing', label: t('fuel.columns.closing'), align: 'end', render: (row) => <Litres value={row.closing_litres} /> },
          { key: 'tests', label: t('fuel.columns.tests'), align: 'end', render: (row) => <Litres value={row.test_litres} /> },
          { key: 'reset', label: t('fuel.reports.resetLitres'), align: 'end', render: (row) => <Litres value={row.reset_litres} /> },
          { key: 'gap', label: t('fuel.reports.gapLitres'), align: 'end', render: (row) => <Litres value={row.gap_litres} /> },
          { key: 'litres', label: t('fuel.columns.litres'), align: 'end', render: (row) => <Litres value={row.litres} className="font-medium text-ink" /> },
        ],
        footer: (data) =>
          data.resets?.length ? (
            <Totals
              title={t('fuel.reports.resets')}
              columns={[
                { key: 'nozzle', label: t('fuel.fields.nozzle'), render: (row) => row.nozzle?.label },
                { key: 'before', label: t('fuel.resets.before'), align: 'end', render: (row) => <Litres value={row.reading_before} /> },
                { key: 'after', label: t('fuel.resets.after'), align: 'end', render: (row) => <Litres value={row.reading_after} /> },
                { key: 'reason', label: t('fuel.resets.reason'), wrap: true, render: (row) => row.reason },
                { key: 'decided', label: t('fuel.reports.decidedAt'), render: (row) => <When value={row.decided_at} /> },
              ]}
              rows={data.resets}
            />
          ) : null,
      }
    case 'tank-reconciliation':
      return {
        columns: reconciliationColumns(t),
        footer: (data) => (
          <Totals
            title={t('fuel.reports.perTank')}
            columns={[
              { key: 'tank', label: t('fuel.fields.tank'), render: (row) => (row.tank ? `${row.tank.code} · ${row.tank.name}` : '') },
              { key: 'count', label: t('fuel.reports.reconciliations'), align: 'end', numeric: true, render: (row) => num(row.reconciliations) },
              { key: 'sold', label: t('fuel.reports.soldLitres'), align: 'end', render: (row) => <Litres value={row.sold_litres} /> },
              { key: 'received', label: t('fuel.reports.receivedLitres'), align: 'end', render: (row) => <Litres value={row.received_litres} /> },
              { key: 'loss', label: t('fuel.reports.lossLitres'), align: 'end', render: (row) => <Litres value={row.loss_litres} signed /> },
              { key: 'gain', label: t('fuel.reports.gainLitres'), align: 'end', render: (row) => <Litres value={row.gain_litres} /> },
              { key: 'net', label: t('fuel.reports.netLitres'), align: 'end', render: (row) => <Litres value={row.net_litres} signed className="font-medium" /> },
            ]}
            rows={data.totals}
          />
        ),
      }
    case 'sales-by-product':
      return {
        columns: [
          station,
          { key: 'product', label: t('fuel.fields.product'), render: (row) => <span className="font-medium text-ink">{productLabel(row.product)}</span> },
          { key: 'currency', label: t('fuel.fields.currency'), render: (row) => row.currency },
          { key: 'sales', label: t('fuel.reports.sales'), align: 'end', numeric: true, render: (row) => num(row.sales) },
          { key: 'litres', label: t('fuel.columns.litres'), align: 'end', render: (row) => <Litres value={row.litres} /> },
          money('total', t('fuel.columns.total'), 'total_minor', true),
          money('average', t('fuel.reports.averagePrice'), 'average_price_minor'),
        ],
      }
    default:
      return {
        columns: [
          { key: 'sold', label: t('fuel.columns.soldAt'), render: (row) => <When value={row.sold_at} /> },
          { key: 'customer', label: t('fuel.columns.customer'), render: (row) => <span className="font-medium text-ink">{row.customer?.name}</span> },
          { key: 'vehicle', label: t('fuel.vehicles.registration'), render: (row) => row.vehicle_registration ?? '' },
          { key: 'odometer', label: t('fuel.reports.odometer'), align: 'end', render: (row) => <Figure value={row.odometer} /> },
          station,
          { key: 'product', label: t('fuel.fields.product'), render: (row) => productLabel(row.product) },
          { key: 'litres', label: t('fuel.columns.litres'), align: 'end', render: (row) => <Litres value={row.litres} /> },
          money('price', t('fuel.prices.perLitre'), 'unit_price_minor'),
          money('total', t('fuel.columns.total'), 'total_minor', true),
        ],
        footer: (data) => (
          <Totals
            title={t('fuel.reports.perCustomer')}
            columns={[
              { key: 'customer', label: t('fuel.columns.customer'), render: (row) => row.customer?.name },
              { key: 'currency', label: t('fuel.fields.currency'), render: (row) => row.currency },
              { key: 'sales', label: t('fuel.reports.sales'), align: 'end', numeric: true, render: (row) => num(row.sales) },
              { key: 'litres', label: t('fuel.columns.litres'), align: 'end', render: (row) => <Litres value={row.litres} /> },
              money('total', t('fuel.columns.total'), 'total_minor', true),
            ]}
            rows={data.totals}
          />
        ),
      }
  }
}

/** FUEL-34, FUEL-38: the six reports, each for a period (default the last 30 days) and a station. */
export default function Reports() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { kind } = useParams()
  const { stations } = useStations()
  const [filters, setFilters] = useState({ location_id: '', tank_id: '', from: '', to: '' })
  const [customer, setCustomer] = useState(null)
  const { tanks } = useTanks(filters.location_id)
  const set = (name) => (value) => setFilters((current) => ({ ...current, [name]: value }))
  if (!REPORTS.includes(kind)) return <Navigate to={`/fuel/reports/${REPORTS[0]}`} replace />
  const config = reportConfig(kind, t)
  const params = { location_id: filters.location_id, from: filters.from, to: filters.to, ...(kind === 'tank-reconciliation' ? { tank_id: filters.tank_id } : {}), ...(kind === 'fleet-statement' ? { customer_id: customer?.id ?? '' } : {}) }
  return (
    <>
      <PageHeader title={t('fuel.reports.title')} description={t(`fuel.reports.kinds.${kind}.help`)} />
      <Tabs items={REPORTS.map((value) => ({ value, label: t(`fuel.reports.kinds.${value}.title`) }))} value={kind} onChange={(next) => navigate(`/fuel/reports/${next}`)} />
      <FilterBar>
        <StationSelect all stations={stations} value={filters.location_id} onChange={(value) => setFilters((current) => ({ ...current, location_id: value, tank_id: '' }))} className="w-56" />
        {kind === 'tank-reconciliation' ? <TankSelect all tanks={tanks} value={filters.tank_id} onChange={set('tank_id')} className="w-56" /> : null}
        {kind === 'fleet-statement' ? <PartyPicker partyRole="customer" label={t('fuel.fields.customer')} value={customer} onChange={setCustomer} className="w-64" /> : null}
        <TextField type="date" label={t('fuel.filters.from')} help={t('fuel.reports.periodHelp')} value={filters.from} onChange={(event) => set('from')(event.target.value)} />
        <TextField type="date" label={t('fuel.filters.to')} value={filters.to} onChange={(event) => set('to')(event.target.value)} />
      </FilterBar>
      <FuelList key={kind} queryKey={`report-${kind}`} endpoint={`fuel/reports/${kind}`} params={params} columns={config.columns} caption={t(`fuel.reports.kinds.${kind}.title`)} emptyText={t('fuel.reports.empty')} footer={config.footer} />
    </>
  )
}
