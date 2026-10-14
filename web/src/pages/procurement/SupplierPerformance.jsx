import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, DataTable, KpiTile, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { formatInteger } from '@/lib/format'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { Amount } from './ProcurementParts'
import { useDownload } from './procurementHelpers'
import { basisPoints, namedOptions, procurementKey, usePlaces } from './procurementData'

/** The mean of the basis points given (rows without a value are left out), or null. */
const meanBp = (values) => {
  const known = values.filter((value) => value !== null && value !== undefined)
  return known.length ? Math.round(known.reduce((sum, value) => sum + value, 0) / known.length) : null
}

/**
 * PRC-18: per supplier and company over a period: POs and receipts,
 * on-time share, quality (accepted ÷ received), average lead time and
 * the price variance in base currency; a summary above, and a CSV export
 * (EXP-01). The API returns the last 90 days by default.
 */
export default function SupplierPerformance() {
  const { t } = useTranslation()
  const locale = useLocale()
  const places = usePlaces()
  const download = useDownload()
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [company, setCompany] = useState('')
  const params = new URLSearchParams()
  if (from) params.set('from', from)
  if (to) params.set('to', to)
  if (company) params.set('company', company)
  const query = useQuery({ queryKey: [...procurementKey, 'performance', from, to, company], queryFn: () => api.get(`procurement/supplier-performance${params.size ? `?${params}` : ''}`) })
  const rows = query.data?.data ?? []
  const period = query.data?.meta
  const csv = new URLSearchParams(params)
  csv.set('format', 'csv')

  const columns = [
    { key: 'party', label: t('procurement.columns.supplier'), render: (row) => row.party?.name },
    { key: 'company', label: t('procurement.columns.company'), render: (row) => row.company?.name },
    { key: 'orders', label: t('procurement.performance.orders'), align: 'end', numeric: true, render: (row) => formatInteger(row.orders, locale) },
    { key: 'receipts', label: t('procurement.performance.receipts'), align: 'end', numeric: true, render: (row) => formatInteger(row.receipts, locale) },
    { key: 'on_time', label: t('procurement.performance.onTime'), align: 'end', numeric: true, render: (row) => basisPoints(row.on_time_bp, locale) },
    { key: 'quality', label: t('procurement.performance.quality'), align: 'end', numeric: true, render: (row) => basisPoints(row.quality_bp, locale) },
    { key: 'lead_time', label: t('procurement.performance.leadTime'), align: 'end', numeric: true, render: (row) => (row.lead_time_days === null ? '—' : t('procurement.performance.days', { days: formatDecimal(row.lead_time_days, locale) })) },
    { key: 'price_variance', label: t('procurement.performance.priceVariance'), align: 'end', render: (row) => <Amount value={row.price_variance} /> },
    { key: 'price_variance_bp', label: t('procurement.performance.priceVariancePercent'), align: 'end', numeric: true, render: (row) => basisPoints(row.price_variance_bp, locale) },
  ]

  return (
    <>
      <PageHeader
        title={t('procurement.performance.title')}
        description={period ? t('procurement.performance.period', { from: formatCalendarDate(period.from, locale), to: formatCalendarDate(period.to, locale) }) : t('procurement.performance.description')}
        actions={
          <Button icon="download" loading={download.isPending} onClick={() => download.mutate({ path: `procurement/supplier-performance?${csv}`, name: 'supplier-performance.csv' })}>
            {t('procurement.performance.export')}
          </Button>
        }
      />
      <div className="flex flex-col gap-5">
        <div className="grid gap-4 sm:grid-cols-3">
          <TextField type="date" label={t('procurement.filters.from')} value={from} onChange={(event) => setFrom(event.target.value)} />
          <TextField type="date" label={t('procurement.filters.to')} value={to} onChange={(event) => setTo(event.target.value)} />
          {places.companies.length > 1 ? (
            <Select label={t('procurement.filters.company')} options={[{ value: '', label: t('procurement.filters.all.company') }, ...namedOptions(places.companies)]} value={company} onChange={(event) => setCompany(event.target.value)} />
          ) : null}
        </div>
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <KpiTile label={t('procurement.performance.suppliers')} value={formatInteger(rows.length, locale)} />
          <KpiTile label={t('procurement.performance.orders')} value={formatInteger(rows.reduce((sum, row) => sum + row.orders, 0), locale)} />
          <KpiTile label={t('procurement.performance.averageOnTime')} value={basisPoints(meanBp(rows.map((row) => row.on_time_bp)), locale)} />
          <KpiTile label={t('procurement.performance.averageQuality')} value={basisPoints(meanBp(rows.map((row) => row.quality_bp)), locale)} />
        </div>
        <DataTable caption={t('procurement.performance.title')} columns={columns} rows={rows.map((row) => ({ ...row, id: `${row.company?.id}-${row.party?.id}` }))} loading={query.isPending} emptyText={t('procurement.performance.empty')} />
      </div>
    </>
  )
}
