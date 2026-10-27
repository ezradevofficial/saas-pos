import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, DataTable, KpiTile, ListView, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { saveFile } from '@/lib/files'
import { formatInteger } from '@/lib/format'
import { formatAmount, moneyText } from '@/lib/money'
import { useServerList } from '@/lib/useServerList'
import { useLocale } from '@/lib/useLocale'
import { FolioList } from './Folios'
import { BranchSwitch, Day, NoProperty } from './HotelParts'
import { addDays, choiceField, dateField, hotelKey, TASK_KINDS, useBranchChoice } from './hotelData'
import { stayColumns, taskColumns } from './stayColumns'

const KINDS = ['occupancy', 'arrivals', 'departures', 'in-house', 'no-shows', 'folio-balances', 'housekeeping']

/** The occupancy report as CSV text: one row per day, then each currency's revenue, ADR and RevPAR. */
function occupancyCsv(report, currencies, t, locale) {
  const quote = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`
  const head = [t('hotel.columns.date'), t('hotel.reports.occupancy.source'), t('hotel.reports.occupancy.available'), t('hotel.reports.occupancy.sold'), t('hotel.reports.occupancy.percent'), ...currencies.flatMap((code) => [`${t('hotel.reports.occupancy.revenue')} ${code}`, `${t('hotel.reports.occupancy.adr')} ${code}`, `${t('hotel.reports.occupancy.revpar')} ${code}`])]
  const lines = report.days.map((day) => [day.date, t(`hotel.reports.occupancy.sources.${day.source}`), day.rooms_available, day.rooms_sold, day.occupancy_percent, ...currencies.flatMap((code) => ['net_minor', 'adr_minor', 'revpar_minor'].map((field) => formatAmount(day.revenue[code]?.[field] ?? 0, code, locale)))])
  return [head, ...lines].map((line) => line.map(quote).join(',')).join('\n')
}

/** HOT-31: occupancy, ADR and RevPAR per day for a date range, from the night audit where it ran and live or forecast elsewhere. */
function Occupancy({ branchId, timezone }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const today = todayIn(timezone)
  const [from, setFrom] = useState(addDays(today, -13))
  const [to, setTo] = useState(today)
  const query = useQuery({ queryKey: [...hotelKey, 'occupancy', branchId, from, to], queryFn: () => api.get(`hotel/reports/occupancy?branch_id=${branchId}&from=${from}&to=${to}`), enabled: Boolean(branchId && from && to && to >= from) })
  const report = query.data?.data
  const currencies = report ? [...new Set(report.days.flatMap((day) => Object.keys(day.revenue)))] : []
  const percent = (value) => `${new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(value ?? 0)} %`
  const columns = [
    { key: 'date', label: t('hotel.columns.date'), render: (row) => <Day value={row.date} /> },
    { key: 'source', label: t('hotel.reports.occupancy.source'), render: (row) => t(`hotel.reports.occupancy.sources.${row.source}`) },
    { key: 'available', label: t('hotel.reports.occupancy.available'), align: 'end', render: (row) => formatInteger(row.rooms_available, locale) },
    { key: 'sold', label: t('hotel.reports.occupancy.sold'), align: 'end', render: (row) => formatInteger(row.rooms_sold, locale) },
    { key: 'percent', label: t('hotel.reports.occupancy.percent'), align: 'end', render: (row) => <span className="tabular-nums">{percent(row.occupancy_percent)}</span> },
    ...currencies.flatMap((code) => [
      { key: `rev-${code}`, label: `${t('hotel.reports.occupancy.revenue')} ${code}`, align: 'end', render: (row) => moneyText(row.revenue[code]?.net_minor ?? 0, code, locale) },
      { key: `adr-${code}`, label: `${t('hotel.reports.occupancy.adr')} ${code}`, align: 'end', render: (row) => moneyText(row.revenue[code]?.adr_minor ?? 0, code, locale) },
      { key: `revpar-${code}`, label: `${t('hotel.reports.occupancy.revpar')} ${code}`, align: 'end', render: (row) => moneyText(row.revenue[code]?.revpar_minor ?? 0, code, locale) },
    ]),
  ]
  return (
    <section className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-3">
        <TextField type="date" label={t('hotel.filters.from')} value={from} onChange={(event) => setFrom(event.target.value)} />
        <TextField type="date" label={t('hotel.filters.to')} value={to} error={to < from ? t('hotel.reports.occupancy.rangeOrder') : undefined} onChange={(event) => setTo(event.target.value)} />
        <Button variant="secondary" icon="download" disabled={!report} onClick={() => saveFile(new Blob([occupancyCsv(report, currencies, t, locale)], { type: 'text/csv;charset=utf-8' }), `hotel-occupancy-${from}-${to}.csv`)}>
          {t('hotel.reports.occupancy.export')}
        </Button>
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {report ? (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <KpiTile label={t('hotel.reports.occupancy.percent')} value={percent(report.totals.occupancy_percent)} />
            <KpiTile label={t('hotel.reports.occupancy.sold')} value={`${formatInteger(report.totals.rooms_sold, locale)} / ${formatInteger(report.totals.rooms_available, locale)}`} />
            {Object.entries(report.totals.revenue).map(([code, row]) => (
              <KpiTile key={code} label={`${t('hotel.reports.occupancy.adr')} · ${t('hotel.reports.occupancy.revpar')} ${code}`} value={`${moneyText(row.adr_minor, code, locale)} · ${moneyText(row.revpar_minor, code, locale)}`} />
            ))}
          </div>
          <DataTable caption={t('hotel.reports.occupancy.title')} columns={columns} rows={report.days.map((day) => ({ ...day, id: day.date }))} />
          <p className="text-caption text-ink-muted">{t('hotel.reports.occupancy.help')}</p>
        </>
      ) : null}
      {query.isPending && branchId && to >= from ? <p className="text-body text-ink-muted">{t('common.loading')}</p> : null}
    </section>
  )
}

/** HOT-31: arrivals, departures, in-house and no-shows of a day. */
function StayReport({ kind, branchId }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const list = useServerList({
    id: `hotel-report-${kind}`,
    endpoint: `hotel/reports/${kind}`,
    params: { branch: branchId },
    queryKey: [...hotelKey, 'report', kind, branchId],
    filters: { date: '' },
    defaultSort: 'arrival_on',
    columns: stayColumns(t),
  })
  return (
    <ListView
      list={list}
      title={t(`hotel.reports.${kind}.title`)}
      searchable={false}
      filterFields={[dateField('date', t('hotel.reports.date'))]}
      emptyText={t('hotel.reports.empty')}
      onRowClick={(row) => navigate(`/hotel/reservations/${row.reservation_id}`)}
    />
  )
}

/** HOT-31: the housekeeping report: tasks with who did them and when. */
function HousekeepingReport({ branchId }) {
  const { t } = useTranslation()
  const list = useServerList({
    id: 'hotel-report-housekeeping',
    endpoint: 'hotel/reports/housekeeping',
    params: { branch: branchId },
    queryKey: [...hotelKey, 'report', 'housekeeping', branchId],
    filters: { status: '', kind: '', date: '' },
    defaultSort: '-business_date',
    columns: taskColumns(t),
  })
  const status = { name: 'status', label: t('hotel.columns.status'), options: [{ value: '', label: t('hotel.filters.openTasks') }, { value: 'all', label: t('hotel.filters.all') }, ...['pending', 'in_progress', 'done', 'inspected'].map((value) => ({ value, label: t(`hotel.states.${value}`) }))] }
  return (
    <ListView
      list={list}
      title={t('hotel.reports.housekeeping.title')}
      searchable={false}
      filterFields={[status, choiceField(t, 'kind', TASK_KINDS, t('hotel.columns.kind'), 'hotel.taskKinds'), dateField('date', t('hotel.reports.date'))]}
      emptyText={t('hotel.reports.empty')}
    />
  )
}

/** HOT-31: the reports, one tab each; the lists export in the formats of every list (EXP-01). */
export default function Reports() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { kind } = useParams()
  const choice = useBranchChoice()
  if (!KINDS.includes(kind)) return <Navigate to="/hotel/reports/occupancy" replace />
  const branchId = choice.branch?.id
  return (
    <>
      <PageHeader title={t('hotel.reports.title')} description={t('hotel.reports.description')} />
      <BranchSwitch choice={choice} />
      <Tabs items={KINDS.map((value) => ({ value, label: t(`hotel.reports.${value}.tab`) }))} value={kind} onChange={(next) => navigate(`/hotel/reports/${next}`)} />
      {!choice.branch ? <NoProperty choice={choice} /> : null}
      {choice.branch && kind === 'occupancy' ? <Occupancy key={branchId} branchId={branchId} timezone={choice.timezone} /> : null}
      {choice.branch && ['arrivals', 'departures', 'in-house', 'no-shows'].includes(kind) ? <StayReport key={`${kind}-${branchId}`} kind={kind} branchId={branchId} /> : null}
      {choice.branch && kind === 'folio-balances' ? <FolioList key={branchId} branchId={branchId} report /> : null}
      {choice.branch && kind === 'housekeeping' ? <HousekeepingReport key={branchId} branchId={branchId} /> : null}
    </>
  )
}
