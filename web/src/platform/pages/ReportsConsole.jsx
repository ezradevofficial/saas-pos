import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, DataTable, KpiTile, Money, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { platformApi } from '../platformClient'
import { useCatalogueKeyNames } from '../useCatalogueName'

const REPORTS = ['mrr', 'revenue', 'trials', 'churn', 'usage']

const monthStart = () => `${new Date().toISOString().slice(0, 7)}-01`
const today = () => new Date().toISOString().slice(0, 10)

/**
 * ADM-06: MRR, trials, churn, revenue by country, module and plan, and
 * usage totals, from the operator's data; each report also as CSV.
 * Amounts stay per currency (KES and USD are never added up).
 */
export default function ReportsConsole() {
  const { t } = useTranslation()
  const [range, setRange] = useState({ from: monthStart(), to: today() })
  const [applied, setApplied] = useState(range)
  const query = `from=${applied.from}&to=${applied.to}`
  const summary = useQuery({ queryKey: ['platform', 'reports', query], queryFn: () => platformApi.get(`platform/reports/summary?${query}`) })
  const download = useMutation({ mutationFn: (report) => platformApi.download(`platform/reports/${report}.csv?${query}`, `${report}-${applied.from}-${applied.to}.csv`) })
  const data = summary.data?.data
  const names = useCatalogueKeyNames()
  // Modules and plans by their catalogue names, never raw keys; `custom` is a
  // deal without a bundle and `outlet` the extra outlets line (ADM-06).
  const SPECIAL = { custom: t('admin.reports.customPlan'), outlet: t('admin.reports.extraOutlets') }
  const keyName = (key) => SPECIAL[key] ?? (names ? names.one(key) : '')
  const moneyColumns = (label, named = false) => [
    { key: 'key', label, render: (row) => (named ? keyName(row.key) : row.key) },
    { key: 'amount', label: t('admin.reports.amount'), render: (row) => <Money amount={row.amount_minor} currency={row.currency} /> },
  ]
  const tables = data
    ? [
        ['mrrByPlan', moneyColumns(t('admin.reports.plan'), true), data.mrr.by_plan],
        ['mrrByCountry', moneyColumns(t('admin.reports.country')), data.mrr.by_country],
        ['mrrByModule', moneyColumns(t('admin.reports.module'), true), data.mrr.by_module],
        ['revenueByCountry', moneyColumns(t('admin.reports.country')), data.revenue.by_country],
        ['revenueByModule', moneyColumns(t('admin.reports.module'), true), data.revenue.by_module],
        ['revenueByPlan', moneyColumns(t('admin.reports.plan'), true), data.revenue.by_plan],
      ]
    : []
  return (
    <>
      <PageHeader title={t('admin.reports.title')} description={t('admin.reports.description')} />
      <form
        className="flex flex-wrap items-end gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          setApplied(range)
        }}
      >
        <TextField type="date" label={t('admin.reports.from')} value={range.from} onChange={(event) => setRange((r) => ({ ...r, from: event.target.value }))} />
        <TextField type="date" label={t('admin.reports.to')} value={range.to} onChange={(event) => setRange((r) => ({ ...r, to: event.target.value }))} />
        <Button variant="primary" type="submit">{t('admin.reports.show')}</Button>
      </form>
      {summary.error ? <Alert tone="danger" title={errorMessage(summary.error)} /> : null}
      {download.error ? <Alert tone="danger" title={errorMessage(download.error)} /> : null}
      {data ? (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {data.mrr.by_currency.length ? (
              data.mrr.by_currency.map((row) => <KpiTile key={row.currency} label={t('admin.reports.mrr', { currency: row.currency })} value={<Money amount={row.amount_minor} currency={row.currency} size="lg" />} />)
            ) : (
              <KpiTile label={t('admin.reports.mrrNone')} value="0" />
            )}
            <KpiTile label={t('admin.reports.trialsStarted')} value={String(data.trials.started)} />
            <KpiTile label={t('admin.reports.trialsConverted')} value={data.trials.conversion_percent == null ? String(data.trials.converted) : t('admin.reports.convertedRate', { count: data.trials.converted, rate: data.trials.conversion_percent })} />
            <KpiTile label={t('admin.reports.churned')} value={data.churn.rate_percent == null ? String(data.churn.churned) : t('admin.reports.churnRate', { count: data.churn.churned, rate: data.churn.rate_percent })} />
          </div>
          <div className="grid gap-6 lg:grid-cols-2">
            {tables.map(([key, columns, rows]) => (
              <section key={key} className="flex flex-col gap-3">
                <h2 className="text-h2 text-ink">{t(`admin.reports.${key}`)}</h2>
                <DataTable caption={t(`admin.reports.${key}`)} columns={columns} rows={rows.map((row) => ({ ...row, id: `${row.key}-${row.currency}` }))} emptyText={t('admin.reports.empty')} />
              </section>
            ))}
            <section className="flex flex-col gap-3">
              <h2 className="text-h2 text-ink">{t('admin.reports.usage')}</h2>
              <DataTable
                caption={t('admin.reports.usage')}
                columns={[
                  { key: 'unit', label: t('admin.reports.unit'), render: (row) => t(`admin.units.${row.unit}`, { defaultValue: row.unit }) },
                  { key: 'tenants', label: t('admin.reports.tenants'), render: (row) => <span className="tabular-nums">{row.tenants}</span> },
                  { key: 'total', label: t('admin.reports.total'), render: (row) => <span className="tabular-nums">{row.total}</span> },
                ]}
                rows={data.usage.map((row) => ({ ...row, id: row.unit }))}
                emptyText={t('admin.reports.empty')}
              />
            </section>
          </div>
          <section className="flex flex-col gap-3">
            <h2 className="text-h2 text-ink">{t('admin.reports.downloads')}</h2>
            <div className="flex flex-wrap gap-2">
              {REPORTS.map((report) => (
                <Button key={report} icon="download" loading={download.isPending && download.variables === report} onClick={() => download.mutate(report)}>
                  {t(`admin.reports.csv.${report}`)}
                </Button>
              ))}
            </div>
          </section>
        </>
      ) : null}
    </>
  )
}
