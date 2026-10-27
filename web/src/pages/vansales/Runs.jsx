import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Alert, DataTable, Select, TextField } from '@/components/ds'
import { errorMessage } from '@/api/errorMessage'
import { PageHeader } from '@/layouts/PageHeader'
import { Pager, PerCurrency, Status } from './VanSalesParts'
import { RUN_STATUSES, statusLabel, useRoutes, useUsers, useVans, usePagedList } from './vansalesData'

/** VAN-06: the vans' selling days, filtered; open one for its visits, sales and count. */
export default function Runs() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { active: vans } = useVans()
  const { active: routes } = useRoutes()
  const users = useUsers()
  const [filters, setFilters] = useState({ van_id: '', route_id: '', rep_id: '', status: '', from: '', to: '' })
  const set = (field) => (event) => setFilters((current) => ({ ...current, [field]: event.target.value }))
  const all = (label, rows) => [{ value: '', label }, ...rows]
  const { query, rows, meta, page, setPage } = usePagedList('runs', 'van-sales/runs', filters)
  const columns = [
    { key: 'date', label: t('vansales.runs.date'), render: (run) => run.run_date },
    { key: 'van', label: t('vansales.runs.van'), render: (run) => run.van_name },
    { key: 'route', label: t('vansales.runs.route'), render: (run) => run.route_name ?? '' },
    { key: 'rep', label: t('vansales.runs.rep'), render: (run) => run.rep_name },
    { key: 'status', label: t('vansales.fields.status'), render: (run) => <Status status={run.status} /> },
    { key: 'visits', label: t('vansales.runs.visits'), numeric: true, align: 'end', render: (run) => `${run.sold_visit_count} / ${run.visit_count}` },
    { key: 'sales', label: t('vansales.runs.sales'), numeric: true, align: 'end', render: (run) => run.sales_count },
    { key: 'totals', label: t('vansales.runs.totals'), align: 'end', render: (run) => <PerCurrency values={run.sales_totals} field="total_minor" /> },
    { key: 'count', label: t('vansales.runs.count'), render: (run) => (run.reconciliation_status ? <Status status={run.reconciliation_status} /> : '') },
  ]
  return (
    <>
      <PageHeader title={t('vansales.runs.title')} description={t('vansales.runs.description')} />
      <div className="flex flex-col gap-5">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Select label={t('vansales.runs.van')} options={all(t('vansales.runs.allVans'), vans.map((van) => ({ value: van.id, label: van.name })))} value={filters.van_id} onChange={set('van_id')} />
          <Select label={t('vansales.runs.route')} options={all(t('vansales.runs.allRoutes'), routes.map((route) => ({ value: route.id, label: route.name })))} value={filters.route_id} onChange={set('route_id')} />
          <Select label={t('vansales.runs.rep')} options={all(t('vansales.runs.allReps'), users.map((user) => ({ value: user.id, label: user.name })))} value={filters.rep_id} onChange={set('rep_id')} />
          <Select label={t('vansales.fields.status')} options={all(t('vansales.runs.allStatuses'), RUN_STATUSES.map((entry) => ({ value: entry, label: statusLabel(t, entry) })))} value={filters.status} onChange={set('status')} />
          <TextField label={t('vansales.fields.from')} type="date" value={filters.from} onChange={set('from')} />
          <TextField label={t('vansales.fields.to')} type="date" value={filters.to} onChange={set('to')} />
        </div>
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {!query.isError ? <DataTable caption={t('vansales.runs.title')} columns={columns} rows={rows} loading={query.isPending} stale={query.isPlaceholderData} onRowClick={(run) => navigate(`/van-sales/runs/${run.id}`)} emptyText={t('vansales.runs.empty')} /> : null}
        <Pager meta={meta} page={page} onPage={setPage} />
      </div>
    </>
  )
}
