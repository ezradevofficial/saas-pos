import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, KpiTile, Money } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Flags, PerCurrency, QueryNotes, Status, TextLink, When } from './VanSalesParts'
import { RECONCILIATION_VIEW, VISIT_TRACK, vanSalesKey } from './vansalesData'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

/** VAN-24: the check-in points of a run, read only with the tracking permission and only on request. */
function Positions({ runId }) {
  const { t } = useTranslation()
  const [shown, setShown] = useState(false)
  const query = useQuery({ queryKey: [...vanSalesKey, 'positions', runId], queryFn: () => api.get(`van-sales/runs/${runId}/positions`), enabled: shown })
  const columns = [
    { key: 'customer', label: t('vansales.stops.customer'), render: (row) => row.customer_name },
    { key: 'at', label: t('vansales.positions.at'), render: (row) => <When value={row.at} /> },
    { key: 'lat', label: t('vansales.positions.lat'), numeric: true, render: (row) => row.lat },
    { key: 'lng', label: t('vansales.positions.lng'), numeric: true, render: (row) => row.lng },
    { key: 'accuracy', label: t('vansales.positions.accuracy'), numeric: true, render: (row) => t('vansales.positions.metres', { count: Math.round(Number(row.accuracy_m)) }) },
  ]
  return (
    <Card title={t('vansales.positions.title')} subtitle={t('vansales.positions.privacy')}>
      <div className="flex flex-col gap-4">
        {!shown ? (
          <div>
            <Button onClick={() => setShown(true)}>{t('vansales.positions.show')}</Button>
          </div>
        ) : null}
        {shown && query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {shown && !query.isError ? <DataTable caption={t('vansales.positions.title')} columns={columns} rows={(query.data?.data ?? []).map((row) => ({ ...row, id: row.visit_id }))} loading={query.isPending} emptyText={t('vansales.positions.empty')} /> : null}
      </div>
    </Card>
  )
}

/** VAN-06, VAN-09, VAN-10, VAN-17: one run with its visits, sales, collections, loads and count. */
export default function RunDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { runId } = useParams()
  const { can } = usePermissions()
  const query = useQuery({ queryKey: [...vanSalesKey, 'run', runId], queryFn: () => api.get(`van-sales/runs/${runId}`) })
  const run = query.data?.data
  const visitColumns = [
    { key: 'customer', label: t('vansales.stops.customer'), render: (visit) => visit.customer_name },
    { key: 'status', label: t('vansales.fields.status'), render: (visit) => <Status status={visit.status} /> },
    { key: 'reason', label: t('vansales.runs.reason'), wrap: true, render: (visit) => (visit.no_sale_reason ? t(`vansales.noSale.${visit.no_sale_reason}`, { defaultValue: visit.no_sale_reason }) : '') },
    { key: 'arrived', label: t('vansales.runs.arrived'), render: (visit) => <When value={visit.arrived_at} /> },
    { key: 'left', label: t('vansales.runs.left'), render: (visit) => <When value={visit.left_at} /> },
    { key: 'note', label: t('vansales.runs.note'), wrap: true, render: (visit) => visit.note ?? '' },
  ]
  const saleColumns = [
    { key: 'customer', label: t('vansales.stops.customer'), render: (sale) => sale.customer_name ?? t('vansales.runs.walkIn') },
    { key: 'at', label: t('vansales.runs.soldAt'), render: (sale) => <When value={sale.sold_at} /> },
    { key: 'total', label: t('vansales.runs.total'), align: 'end', render: (sale) => <Money amount={sale.total_minor} currency={sale.currency} /> },
    { key: 'credit', label: t('vansales.runs.onCredit'), align: 'end', render: (sale) => (sale.credit_minor && sale.credit_minor !== '0' ? <Money amount={sale.credit_minor} currency={sale.currency} /> : '') },
    { key: 'status', label: t('vansales.fields.status'), render: (sale) => <Status status={sale.status} /> },
    { key: 'flags', label: t('vansales.runs.flags'), render: (sale) => <Flags flags={sale.flags} /> },
    { key: 'review', label: t('vansales.credit.review'), render: (sale) => (sale.review_status ? <Status status={sale.review_status} /> : '') },
  ]
  const collectionColumns = [
    { key: 'customer', label: t('vansales.stops.customer'), render: (row) => row.customer_name },
    { key: 'receipt', label: t('vansales.collections.receipt'), render: (row) => row.receipt_number },
    { key: 'method', label: t('vansales.collections.method'), render: (row) => t(`vansales.methods.${row.method_type}`, { defaultValue: row.method_type }) },
    { key: 'amount', label: t('vansales.collections.amount'), align: 'end', render: (row) => <Money amount={row.amount_minor} currency={row.currency} /> },
    { key: 'status', label: t('vansales.fields.status'), render: (row) => <Status status={row.status} /> },
    { key: 'flags', label: t('vansales.runs.flags'), render: (row) => <Flags flags={row.flags} /> },
  ]
  const loadColumns = [
    { key: 'number', label: t('vansales.loads.number'), render: (load) => load.number },
    { key: 'from', label: t('vansales.loads.from'), render: (load) => load.from_location_name },
    { key: 'status', label: t('vansales.fields.status'), render: (load) => <Status status={load.status} /> },
    { key: 'lines', label: t('vansales.loads.lines'), wrap: true, render: (load) => (load.lines ?? []).map((line) => `${line.item_name} ${line.qty_confirmed ?? line.qty}`).join(', ') },
  ]
  const reconciliation = run?.reconciliation
  return (
    <>
      <TextLink to="/van-sales/runs">{t('vansales.runs.back')}</TextLink>
      <PageHeader title={run ? t('vansales.runs.detailTitle', { van: run.van_name, date: formatCalendarDate(run.run_date, locale) }) : t('vansales.runs.title')} description={run ? `${run.rep_name}${run.route_name ? ` · ${run.route_name}` : ''}` : undefined} />
      <div className="flex flex-col gap-6">
        <QueryNotes query={query} />
        {run ? (
          <>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-1 text-ink-muted">
              <Status status={run.status} />
              <span>{t('vansales.runs.startedAt')} <When value={run.started_at} /></span>
              {run.closed_at ? <span>{t('vansales.runs.closedAt')} <When value={run.closed_at} /></span> : null}
            </div>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <KpiTile label={t('vansales.runs.visits')} value={`${run.sold_visit_count} / ${run.visit_count}`} />
              <KpiTile label={t('vansales.runs.sales')} value={String(run.sales_count)} />
              <KpiTile label={t('vansales.runs.totals')} value={<PerCurrency values={run.sales_totals} field="total_minor" />} />
            </div>
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('vansales.runs.visitsTitle')}</h2>
              <DataTable caption={t('vansales.runs.visitsTitle')} columns={visitColumns} rows={run.visits ?? []} emptyText={t('vansales.runs.noVisits')} />
            </section>
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('vansales.runs.salesTitle')}</h2>
              <DataTable caption={t('vansales.runs.salesTitle')} columns={saleColumns} rows={run.sales ?? []} emptyText={t('vansales.runs.noSales')} />
            </section>
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('vansales.runs.collectionsTitle')}</h2>
              <DataTable caption={t('vansales.runs.collectionsTitle')} columns={collectionColumns} rows={run.collections ?? []} emptyText={t('vansales.runs.noCollections')} />
            </section>
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('vansales.runs.loadsTitle')}</h2>
              <DataTable caption={t('vansales.runs.loadsTitle')} columns={loadColumns} rows={run.loads ?? []} emptyText={t('vansales.runs.noLoads')} />
            </section>
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('vansales.runs.reconciliationTitle')}</h2>
              {reconciliation ? (
                <div className="flex flex-wrap items-center gap-x-5 gap-y-1">
                  <Status status={reconciliation.status} />
                  <span className="text-ink-muted">{t('vansales.reconciliations.attemptNumber', { number: reconciliation.attempt })}</span>
                  <span className="text-ink-muted">{t('vansales.reconciliations.stockVarianceLines', { count: reconciliation.stock_variance_lines })}</span>
                  {can(RECONCILIATION_VIEW) ? <TextLink to={`/van-sales/reconciliations/${reconciliation.id}`}>{t('vansales.runs.openCount')}</TextLink> : null}
                </div>
              ) : (
                <p className="text-ink-muted">{t('vansales.runs.noCount')}</p>
              )}
            </section>
            {can(VISIT_TRACK) ? <Positions runId={runId} /> : null}
          </>
        ) : null}
      </div>
    </>
  )
}
