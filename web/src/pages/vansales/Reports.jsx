import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { Alert, DataTable, KpiTile, Money, Select, Tabs, TextField } from '@/components/ds'
import { errorMessage } from '@/api/errorMessage'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { useTimeZone } from '@/lib/useTimeZone'
import { PerCurrency, QueryNotes } from './VanSalesParts'
import { isNonZero, useRoutes, useUsers, useVans, vanSalesKey } from './vansalesData'

/** A calendar day (YYYY-MM-DD) `days` before another. */
const daysBefore = (day, days) => new Date(Date.parse(`${day}T00:00:00Z`) - days * 86400000).toISOString().slice(0, 10)

/** VAN-25: stock on the vans, from Inventory's levels of the items last loaded. */
function VanStock() {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: [...vanSalesKey, 'report', 'van-stock'], queryFn: () => api.get('van-sales/reports/van-stock') })
  const vans = query.data?.data ?? []
  const columns = [
    { key: 'item', label: t('vansales.reconciliations.item'), render: (row) => row.item_name },
    { key: 'qty', label: t('vansales.reports.qty'), numeric: true, align: 'end', render: (row) => row.qty },
  ]
  return (
    <div className="flex flex-col gap-5">
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {query.data && query.data.inventory === false ? <Alert tone="info" title={t('vansales.reports.noInventory')} /> : null}
      {query.data && query.data.inventory !== false && vans.length === 0 ? <p className="text-ink-muted">{t('vansales.reports.noVanStock')}</p> : null}
      {vans.map((van) => (
        <section key={van.van_id} aria-label={van.van_name} className="flex flex-col gap-3">
          <h2 className="text-h3 text-ink">{van.van_name}</h2>
          <DataTable caption={van.van_name} columns={columns} rows={(van.items ?? []).map((item) => ({ ...item, id: item.item_id }))} emptyText={t('vansales.reports.vanEmpty')} />
        </section>
      ))}
    </div>
  )
}

/** VAN-25: sales, strike rate, collections, variances and credit outstanding. */
function Summary() {
  const { t } = useTranslation()
  const today = todayIn(useTimeZone())
  const { active: vans } = useVans()
  const { active: routes } = useRoutes()
  const users = useUsers()
  const [filters, setFilters] = useState({ from: '', to: '', route_id: '', rep_id: '', van_id: '' })
  const from = filters.from || daysBefore(today, 6)
  const to = filters.to || today
  const params = new URLSearchParams({ from, to })
  for (const key of ['route_id', 'rep_id', 'van_id']) if (filters[key]) params.set(key, filters[key])
  const query = useQuery({ queryKey: [...vanSalesKey, 'report', 'summary', params.toString()], queryFn: () => api.get(`van-sales/reports/summary?${params.toString()}`) })
  const report = query.data?.data
  const set = (field) => (event) => setFilters((current) => ({ ...current, [field]: event.target.value }))
  const all = (label, rows) => [{ value: '', label }, ...rows]
  const rowColumns = [
    { key: 'route', label: t('vansales.runs.route'), render: (row) => row.route_name ?? '' },
    { key: 'rep', label: t('vansales.runs.rep'), render: (row) => row.rep_name },
    { key: 'visits', label: t('vansales.reports.visits'), numeric: true, align: 'end', render: (row) => `${row.sold_visits} / ${row.visits}` },
    { key: 'strike', label: t('vansales.reports.strikeRate'), numeric: true, align: 'end', render: (row) => `${Number(row.strike_rate).toFixed(1)}%` },
    { key: 'count', label: t('vansales.reports.salesCount'), numeric: true, align: 'end', render: (row) => row.sales_count },
    { key: 'sales', label: t('vansales.reports.sales'), align: 'end', render: (row) => <PerCurrency values={row.sales} field="total_minor" /> },
    { key: 'collections', label: t('vansales.reports.collections'), align: 'end', render: (row) => <PerCurrency values={row.collections} /> },
  ]
  const cashColumns = [
    { key: 'date', label: t('vansales.runs.date'), render: (row) => row.run_date },
    { key: 'van', label: t('vansales.runs.van'), render: (row) => `${row.van_name} · ${row.rep_name}` },
    { key: 'kind', label: t('vansales.reconciliations.kind'), render: (row) => t(`vansales.tenderKinds.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'variance', label: t('vansales.reconciliations.variance'), align: 'end', render: (row) => <Money amount={row.variance_minor} currency={row.currency} tone={isNonZero(row.variance_minor) ? 'danger' : undefined} /> },
  ]
  const stockColumns = [
    { key: 'date', label: t('vansales.runs.date'), render: (row) => row.run_date },
    { key: 'van', label: t('vansales.runs.van'), render: (row) => `${row.van_name}` },
    { key: 'item', label: t('vansales.reconciliations.item'), render: (row) => row.item_name },
    { key: 'variance', label: t('vansales.reconciliations.variance'), numeric: true, align: 'end', render: (row) => row.variance_qty },
  ]
  return (
    <div className="flex flex-col gap-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <TextField label={t('vansales.fields.from')} type="date" value={from} max={to} onChange={set('from')} />
        <TextField label={t('vansales.fields.to')} type="date" value={to} min={from} onChange={set('to')} />
        <Select label={t('vansales.runs.route')} options={all(t('vansales.runs.allRoutes'), routes.map((route) => ({ value: route.id, label: route.name })))} value={filters.route_id} onChange={set('route_id')} />
        <Select label={t('vansales.runs.rep')} options={all(t('vansales.runs.allReps'), users.map((user) => ({ value: user.id, label: user.name })))} value={filters.rep_id} onChange={set('rep_id')} />
        <Select label={t('vansales.runs.van')} options={all(t('vansales.runs.allVans'), vans.map((van) => ({ value: van.id, label: van.name })))} value={filters.van_id} onChange={set('van_id')} />
      </div>
      <QueryNotes query={query} />
      {report ? (
        <>
          <section className="flex flex-col gap-3">
            <h2 className="text-h3 text-ink">{t('vansales.reports.creditOutstanding')}</h2>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {(report.credit_outstanding ?? []).length === 0 ? <p className="text-ink-muted">{t('vansales.reports.noCredit')}</p> : null}
              {(report.credit_outstanding ?? []).map((entry) => (
                <KpiTile key={entry.currency} label={entry.currency} value={<Money amount={entry.amount_minor} currency={entry.currency} size="lg" />} />
              ))}
            </div>
          </section>
          <section className="flex flex-col gap-3">
            <h2 className="text-h3 text-ink">{t('vansales.reports.byRep')}</h2>
            <DataTable caption={t('vansales.reports.byRep')} columns={rowColumns} rows={(report.rows ?? []).map((row) => ({ ...row, id: `${row.route_id}-${row.rep_id}` }))} emptyText={t('vansales.reports.empty')} />
          </section>
          <section className="flex flex-col gap-3">
            <h2 className="text-h3 text-ink">{t('vansales.reports.cashVariances')}</h2>
            <DataTable caption={t('vansales.reports.cashVariances')} columns={cashColumns} rows={(report.cash_variances ?? []).map((row) => ({ ...row, id: `${row.run_id}-${row.kind}-${row.currency}` }))} emptyText={t('vansales.reports.noVariances')} />
          </section>
          <section className="flex flex-col gap-3">
            <h2 className="text-h3 text-ink">{t('vansales.reports.stockVariances')}</h2>
            <DataTable caption={t('vansales.reports.stockVariances')} columns={stockColumns} rows={(report.stock_variances ?? []).map((row) => ({ ...row, id: `${row.run_id}-${row.item_id}` }))} emptyText={t('vansales.reports.noVariances')} />
          </section>
        </>
      ) : null}
    </div>
  )
}

/** VAN-25: the reports page, a summary for a period and the stock now on the vans. */
export default function Reports() {
  const { t } = useTranslation()
  const [tab, setTab] = useState('summary')
  return (
    <>
      <PageHeader title={t('vansales.reports.title')} description={t('vansales.reports.description')} />
      <div className="flex flex-col gap-5">
        <Tabs
          items={[
            { value: 'summary', label: t('vansales.reports.summaryTab') },
            { value: 'stock', label: t('vansales.reports.stockTab') },
          ]}
          value={tab}
          onChange={setTab}
        />
        {tab === 'summary' ? <Summary /> : <VanStock />}
      </div>
    </>
  )
}
