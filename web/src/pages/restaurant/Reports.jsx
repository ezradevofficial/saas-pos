import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, DataTable, KpiTile, Money, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { restaurantKey, useLocations } from './restaurantData'

/** A calendar day (YYYY-MM-DD) `days` before another. */
const daysBefore = (day, days) => new Date(Date.parse(`${day}T00:00:00Z`) - days * 86400000).toISOString().slice(0, 10)

/**
 * REST-18: covers, orders, sales and tips per currency, average spend per
 * cover, average table turn, sales per waiter and voids after send, for a
 * period and a place (all the places the user sees, or one location).
 */
export default function Reports() {
  const { t } = useTranslation()
  const locale = useLocale()
  const today = todayIn(useTimeZone())
  const { locations } = useLocations()
  const [range, setRange] = useState({ from: '', to: '' })
  const [place, setPlace] = useState('')
  const from = range.from || daysBefore(today, 6)
  const to = range.to || today
  const params = new URLSearchParams({ from, to })
  if (place) params.set('location_id', place)
  const query = useQuery({ queryKey: [...restaurantKey, 'report', params.toString()], queryFn: () => api.get(`restaurant/reports/summary?${params.toString()}`) })
  const report = query.data?.data
  const currencies = Object.entries(report?.currencies ?? {})
  const turn = report?.avg_turn_minutes
  const rows = (list) => (list ?? []).map((row, index) => ({ ...row, id: `${row.waiter_id ?? row.user_id ?? row.reason ?? index}-${row.currency ?? ''}` }))
  const money = (key) => (row) => <Money amount={row[key]} currency={row.currency} />
  const waiterColumns = [
    { key: 'name', label: t('restaurant.reports.waiter'), render: (row) => row.name },
    { key: 'currency', label: t('restaurant.reports.currency'), render: (row) => row.currency },
    { key: 'orders', label: t('restaurant.reports.orders'), numeric: true, align: 'end', render: (row) => row.orders },
    { key: 'covers', label: t('restaurant.reports.covers'), numeric: true, align: 'end', render: (row) => row.covers },
    { key: 'sales', label: t('restaurant.reports.sales'), align: 'end', render: money('sales_minor') },
    { key: 'tips', label: t('restaurant.reports.tips'), align: 'end', render: money('tips_minor') },
  ]
  const voidColumns = [
    { key: 'name', label: t('restaurant.reports.person'), render: (row) => row.name },
    { key: 'currency', label: t('restaurant.reports.currency'), render: (row) => row.currency },
    { key: 'count', label: t('restaurant.reports.voidCount'), numeric: true, align: 'end', render: (row) => row.count },
    { key: 'value', label: t('restaurant.reports.voidValue'), align: 'end', render: money('value_minor') },
  ]
  const reasonColumns = [
    { key: 'reason', label: t('restaurant.reports.reason'), render: (row) => row.reason },
    { key: 'count', label: t('restaurant.reports.voidCount'), numeric: true, align: 'end', render: (row) => row.count },
  ]

  return (
    <>
      <PageHeader title={t('restaurant.reports.title')} description={t('restaurant.reports.description')} />
      <div className="flex flex-col gap-6">
        <div className="grid gap-4 sm:grid-cols-3">
          <TextField label={t('restaurant.reports.from')} type="date" value={from} max={to} onChange={(event) => setRange((current) => ({ ...current, from: event.target.value }))} />
          <TextField label={t('restaurant.reports.to')} type="date" value={to} min={from} onChange={(event) => setRange((current) => ({ ...current, to: event.target.value }))} />
          <Select label={t('restaurant.fields.location')} options={[{ value: '', label: t('restaurant.reports.allPlaces') }, ...locations.map((entry) => ({ value: entry.id, label: entry.name }))]} value={place} onChange={(event) => setPlace(event.target.value)} />
        </div>
        {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {report ? (
          <>
            {currencies.length === 0 ? <p className="text-ink-muted">{t('restaurant.reports.empty')}</p> : null}
            {currencies.map(([code, figures]) => (
              <section key={code} aria-label={code} className="flex flex-col gap-3">
                <h2 className="text-h3 text-ink">{code}</h2>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                  <KpiTile label={t('restaurant.reports.covers')} value={Number(figures.covers).toLocaleString(locale)} />
                  <KpiTile label={t('restaurant.reports.orders')} value={Number(figures.orders).toLocaleString(locale)} />
                  <KpiTile label={t('restaurant.reports.sales')} value={<Money amount={figures.sales_minor} currency={code} size="lg" />} />
                  <KpiTile label={t('restaurant.reports.avgPerCover')} value={<Money amount={figures.avg_per_cover_minor} currency={code} size="lg" />} />
                </div>
              </section>
            ))}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <KpiTile label={t('restaurant.reports.avgTurn')} value={turn == null ? t('restaurant.reports.none') : t('restaurant.reports.minutes', { count: Math.round(Number(turn)) })} />
            </div>
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('restaurant.reports.byWaiter')}</h2>
              <DataTable caption={t('restaurant.reports.byWaiter')} columns={waiterColumns} rows={rows(report.waiters)} emptyText={t('restaurant.reports.empty')} />
            </section>
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('restaurant.reports.voids')}</h2>
              <p className="text-caption text-ink-muted">{t('restaurant.reports.voidsHelp')}</p>
              <DataTable caption={t('restaurant.reports.voids')} columns={voidColumns} rows={rows(report.voids)} emptyText={t('restaurant.reports.noVoids')} />
              {(report.void_reasons ?? []).length ? <DataTable caption={t('restaurant.reports.voidReasons')} columns={reasonColumns} rows={rows(report.void_reasons)} /> : null}
            </section>
          </>
        ) : null}
      </div>
    </>
  )
}
