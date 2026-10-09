import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable, KpiTile, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { todayIn } from '@/lib/dates'
import { formatInteger } from '@/lib/format'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { Amount } from './PosParts'
import { useAmountText } from './useAmountText'
import { methodLabel, usePlaces } from './posData'

const PERIODS = ['today', 'week', 'month', 'custom']

/** A calendar day moved by `days` ("2026-10-09" + 1 → "2026-10-10"), never through a local clock. */
function addDays(date, days) {
  const [year, month, day] = date.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10)
}

/** The period's first and last days: today, this week (from Monday) or this month, in the company's zone. */
function periodRange(period, today, custom = {}) {
  if (period === 'week') {
    const [year, month, day] = today.split('-').map(Number)
    const weekday = (new Date(Date.UTC(year, month - 1, day)).getUTCDay() + 6) % 7
    return { from: addDays(today, -weekday), to: today }
  }
  if (period === 'month') return { from: `${today.slice(0, 8)}01`, to: today }
  if (period === 'custom') return { from: custom.from || today, to: custom.to || custom.from || today }
  return { from: today, to: today }
}

/** Company, branch and location rows of the consolidation, indented by level. */
function placeRows(companies) {
  return companies.flatMap((company) => [
    { id: company.company.id, level: 0, name: company.company.name, count: company.sales_count, total: company.total, average: company.average_ticket, reporting: company.reporting },
    ...company.branches.flatMap((branch) => [
      { id: branch.branch.id, level: 1, name: branch.branch.name, count: branch.sales_count, total: branch.total },
      ...branch.locations.map((location) => ({ id: location.location.id, level: 2, name: location.location.name, count: location.sales_count, total: location.total })),
    ]),
  ])
}

const INDENT = ['', 'pl-4', 'pl-10']

/**
 * TEN-07: completed sales across the companies, branches and locations the
 * user reaches, for today, this week, this month or chosen days (each
 * company's own calendar), in each company's base currency and converted to
 * a reporting currency at the company's rate (CUR-04). The count, average
 * ticket, payments by method and the items that sell most.
 */
export default function SalesDashboard() {
  const { t } = useTranslation()
  const locale = useLocale()
  const zone = useTimeZone()
  const amount = useAmountText()
  const places = usePlaces()
  const { options: currencies } = useMoneyDefaults(null)
  const [params, setParams] = useSearchParams()
  const period = PERIODS.includes(params.get('period')) ? params.get('period') : 'today'
  const company = params.get('company') ?? ''
  const branch = params.get('branch') ?? ''
  const currency = params.get('currency') ?? ''
  const range = periodRange(period, todayIn(zone), { from: params.get('from') ?? '', to: params.get('to') ?? '' })

  const set = (changes) => {
    const next = new URLSearchParams(params)
    for (const [name, value] of Object.entries(changes)) {
      if (value) next.set(name, value)
      else next.delete(name)
    }
    setParams(next, { replace: true })
  }

  const search = new URLSearchParams({ from: range.from, to: range.to })
  if (company) search.set('company', company)
  if (branch) search.set('branch', branch)
  if (currency) search.set('currency', currency)
  const query = useQuery({ queryKey: ['pos-insights', search.toString()], queryFn: () => api.get(`pos/insights?${search}`), placeholderData: (previous) => previous })
  const data = query.data?.data
  const reporting = data?.reporting_currency
  const number = (value) => formatInteger(value ?? 0, locale)

  const placeColumns = [
    {
      key: 'name',
      label: t('pos.dashboard.columns.place'),
      render: (row) => <span className={row.level === 0 ? `font-medium text-ink ${INDENT[0]}` : `text-ink ${INDENT[row.level]}`}>{row.name}</span>,
    },
    { key: 'count', label: t('pos.dashboard.columns.sales'), align: 'end', numeric: true, render: (row) => number(row.count) },
    { key: 'total', label: t('pos.dashboard.columns.total'), align: 'end', render: (row) => <Amount value={row.total} /> },
    { key: 'average', label: t('pos.dashboard.columns.average'), align: 'end', render: (row) => (row.average ? <Amount value={row.average} /> : '') },
    ...(reporting
      ? [
          {
            key: 'reporting',
            label: t('pos.dashboard.columns.reporting', { currency: reporting }),
            align: 'end',
            render: (row) =>
              row.level !== 0 ? '' : row.reporting ? <Amount value={row.reporting.total} /> : <span className="text-caption text-ink-muted">{t('pos.dashboard.noRate')}</span>,
          },
        ]
      : []),
  ]

  const paymentColumns = [
    { key: 'method', label: t('pos.dashboard.payments.method'), render: (row) => methodLabel(t, row.method_type) },
    { key: 'count', label: t('pos.dashboard.payments.count'), align: 'end', numeric: true, render: (row) => number(row.count) },
    { key: 'amount', label: t('pos.dashboard.payments.amount'), align: 'end', render: (row) => <Amount value={row.amount} /> },
  ]

  const itemColumns = [
    { key: 'name', label: t('pos.dashboard.items.item'), render: (row) => <span className="text-ink">{row.item.name}</span> },
    { key: 'qty', label: t('pos.dashboard.items.qty'), align: 'end', numeric: true, render: (row) => formatDecimal(row.qty, locale) },
    { key: 'sales', label: t('pos.dashboard.items.sales'), align: 'end', numeric: true, render: (row) => number(row.sales_count) },
    { key: 'total', label: t('pos.dashboard.items.total'), align: 'end', render: (row) => <span className="tabular-nums">{row.totals.map(amount).join(' · ')}</span> },
  ]

  const missing = (data?.missing_rates ?? []).map((id) => data.companies.find((entry) => entry.company.id === id)?.company.name).filter(Boolean)

  return (
    <>
      <PageHeader title={t('pos.dashboard.title')} description={t('pos.dashboard.description')} />
      <div className="flex flex-col gap-4">
        <Tabs items={PERIODS.map((value) => ({ value, label: t(`pos.dashboard.periods.${value}`) }))} value={period} onChange={(next) => set({ period: next === 'today' ? '' : next })} />
        <div className="flex flex-wrap items-end gap-3">
          {period === 'custom' ? (
            <>
              <TextField type="date" label={t('pos.dashboard.from')} value={range.from} onChange={(event) => set({ from: event.target.value })} />
              <TextField type="date" label={t('pos.dashboard.to')} value={range.to} min={range.from} onChange={(event) => set({ to: event.target.value })} />
            </>
          ) : null}
          {places.companies.length > 1 ? (
            <Select
              label={t('pos.filters.company')}
              options={[{ value: '', label: t('pos.filters.allCompanies') }, ...places.companies.map((entry) => ({ value: entry.id, label: entry.name }))]}
              value={company}
              onChange={(event) => set({ company: event.target.value, branch: '' })}
              className="w-full sm:w-auto"
            />
          ) : null}
          {places.branches.length > 1 ? (
            <Select
              label={t('pos.filters.branch')}
              options={[
                { value: '', label: t('pos.filters.allBranches') },
                ...places.branches.filter((entry) => !company || entry.company_id === company).map((entry) => ({ value: entry.id, label: entry.name })),
              ]}
              value={branch}
              onChange={(event) => set({ branch: event.target.value })}
              className="w-full sm:w-auto"
            />
          ) : null}
          <Select
            label={t('pos.dashboard.reportingCurrency')}
            options={[{ value: '', label: !currency && reporting ? t('pos.dashboard.defaultCurrencyIs', { currency: reporting }) : t('pos.dashboard.defaultCurrency') }, ...currencies.map((code) => ({ value: code, label: code }))]}
            value={currency}
            onChange={(event) => set({ currency: event.target.value })}
            className="w-full sm:w-auto"
          />
        </div>
      </div>

      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} action={<Button onClick={() => query.refetch()}>{t('common.retry')}</Button>} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {data ? (
        <div className="flex flex-col gap-5">
          {!reporting && data.companies.length > 1 ? <Alert tone="info" title={t('pos.dashboard.chooseCurrency')} /> : null}
          {missing.length ? <Alert tone="warning" title={t('pos.dashboard.missingRates', { currency: reporting, companies: missing.join(', ') })} /> : null}
          <div className="grid gap-4 sm:grid-cols-3">
            <KpiTile label={t('pos.dashboard.kpi.sales')} value={number(data.sales_count)} />
            <KpiTile label={t('pos.dashboard.kpi.total')} value={data.consolidated ? amount(data.consolidated.total) : '—'} />
            <KpiTile label={t('pos.dashboard.kpi.average')} value={data.consolidated?.average_ticket ? amount(data.consolidated.average_ticket) : '—'} />
          </div>
          <Card title={t('pos.dashboard.byPlace')} subtitle={t('pos.dashboard.byPlaceHelp')}>
            <DataTable caption={t('pos.dashboard.byPlace')} columns={placeColumns} rows={placeRows(data.companies)} emptyText={t('pos.dashboard.empty')} />
          </Card>
          <div className="grid gap-5 lg:grid-cols-2">
            <Card title={t('pos.dashboard.payments.title')}>
              <div className="flex flex-col gap-3">
                <DataTable caption={t('pos.dashboard.payments.title')} columns={paymentColumns} rows={data.payments.map((row) => ({ ...row, id: `${row.method_type}-${row.currency}` }))} emptyText={t('pos.dashboard.empty')} />
                {data.change.length ? <p className="text-caption text-ink-muted">{t('pos.dashboard.payments.change', { amounts: data.change.map(amount).join(' · ') })}</p> : null}
              </div>
            </Card>
            <Card title={t('pos.dashboard.items.title')}>
              <DataTable caption={t('pos.dashboard.items.title')} columns={itemColumns} rows={data.top_items.map((row) => ({ ...row, id: row.item.id }))} emptyText={t('pos.dashboard.empty')} />
            </Card>
          </div>
        </div>
      ) : null}
    </>
  )
}
